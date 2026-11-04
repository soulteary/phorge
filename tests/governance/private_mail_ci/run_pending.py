#!/usr/bin/env python3
"""Portable real pending-source wire test. Requires committed N11 test helpers.

No Podman, existing pod, fake callback, receipt replay, public bind or deployment.
Full 139-case security/unknown-outcome wire suite is a separate remaining gate.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
from email.parser import BytesParser
from email import policy
from email.utils import getaddresses
from urllib.parse import quote
import subprocess
import sys
import time
from runtime import OwnedRuntime, load_config, loopback_request, private_file, validate_config


def wait_until(check, process=None, seconds=30):
    deadline = time.monotonic() + seconds
    while True:
        result = check()
        if result:
            return result
        if (process is not None and process.poll() is not None) or time.monotonic() > deadline:
            raise RuntimeError('owned_service_or_manifest_not_ready')
        time.sleep(.05)


def capability_observation(status, raw, process):
    """Bounded failure-only evidence; never project service message or body."""
    code = 'unknown'
    allowed = None
    try:
        value = json.loads(raw)
        error = value.get('error')
        candidate = error.get('code') if type(error) is dict else error
        if candidate in ('ERR_DELIVERY_STORE', 'ERR_SEND_FAILED', 'ERR_INVALID_TOKEN'):
            code = candidate
        data = value.get('data')
        if type(data) is dict and type(data.get('perPhysicalAttemptAuthorization')) is bool:
            allowed = data['perPhysicalAttemptAuthorization']
    except (ValueError, TypeError, AttributeError):
        pass
    exit_code = process.poll()
    return {'http_status': status if type(status) is int and 100 <= status <= 599 else None,
            'error_code': code, 'per_attempt_authorization': allowed,
            'owned_mailer_running': exit_code is None,
            'owned_mailer_exit_code': exit_code if type(exit_code) is int and -255 <= exit_code <= 255 else None}


def preflight(config):
    if sys.platform != 'linux':
        raise RuntimeError('linux_profile_required')
    source = config['source_root']
    version = subprocess.check_output([config['php'], '-r', 'echo PHP_VERSION;'], text=True)
    if version != '8.5.12':
        raise RuntimeError('supported_php_required')
    revision = subprocess.check_output(['git', '-C', source, 'rev-parse', 'HEAD'], text=True).strip()
    dirty = subprocess.check_output(['git', '-C', source, 'status', '--porcelain', '--untracked-files=no'])
    if revision != config['source_revision'] or dirty:
        raise RuntimeError('committed_source_revision_required')
    for relative in config['source_sha256']:
        subprocess.run(['git', '-C', source, 'ls-files', '--error-unmatch', relative], check=True, stdout=subprocess.DEVNULL)
    if not os.environ.get(config['database']['password_env']):
        raise RuntimeError('disposable_database_secret_required')


def run_case(config, case):
    checks = []
    runtime = OwnedRuntime(config)
    fixture = None
    native = None
    native_clean = False
    passed = False
    failure = None
    prepared = False
    cleanup = None
    startup_observation = None
    source = Path(config['source_root'])
    owner_dir = runtime.directory
    php = config['php']
    binary = {key: value['path'] for key, value in config['binaries'].items()}
    http, mailer_port, _fault, observer_port = runtime.ports()
    mailer_token = os.urandom(32).hex()
    callback_token = os.urandom(32).hex()

    def check(value, name):
        if not value or name in checks:
            raise RuntimeError('assertion_' + name)
        checks.append(name)

    def request(url, method='GET', data=None, headers=None):
        return loopback_request(url, method, data, headers)

    def capture(ready):
        status, raw = request(ready['capture_uri'] + '/api/v1/emails?limit=100', headers={'Authorization': ready['capture_authorization']})
        check(status == 200, 'capture_authenticated_' + str(len(checks)))
        return json.loads(raw)

    def quarantined(ready):
        program = r'''$v=json_decode(stream_get_contents(STDIN),true);require $v['helper'];$c=governance_private_mail_config($v['directory']);$d=governance_private_mail_proof($c);try{$q=$d->prepare('SELECT parameters FROM `'.$c['writer']['namespace'].'_metamta`.`metamta_mail` WHERE id=?');$q->execute([$v['id']]);$row=$q->get_result()->fetch_assoc();if(!$row){exit(1);}$p=json_decode($row['parameters'],true,512,JSON_THROW_ON_ERROR);echo json_encode(['quarantined'=>($p['governance.delivery-quarantined']??false)===true]);}finally{$d->close();}'''
        return json.loads(runtime.php(program, {'helper': str(source / 'support/governance/notification/owned-config.php'),
                              'directory': str(owner_dir), 'id': ready['pending_mail_id']}))['quarantined']

    def observe():
        path = owner_dir / 'callback-observations.jsonl'
        return [json.loads(line) for line in private_file(path, 1024 * 1024).read_text().splitlines() if line]

    def control(action, expected):
        result = subprocess.run([php, str(source / 'support/governance/notification/pending-control.php'), action],
                                env=runtime.env(), capture_output=True, timeout=20, check=True)
        check(json.loads(result.stdout)['signal_queued'] is True, 'owner_signal_' + action)
        def acknowledged():
            value = runtime.read_json('pending-state.json')
            return value if value.get('owner') == runtime.owner and value.get('state') == expected else None
        return wait_until(acknowledged, fixture, 20)

    def retry_due(ready, delivery):
        program = r'''$v=json_decode(stream_get_contents(STDIN),true);$c=$v['db'];mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$d=new mysqli($c['host'],$c['user'],$c['password'],$c['database'],$c['port']);try{$count=$d->prepare('SELECT COUNT(*) FROM gorge_mail_attempt WHERE deliveryID=?');$count->execute([$v['delivery']]);$before=(int)$count->get_result()->fetch_row()[0];$q=$d->prepare("UPDATE gorge_mail_delivery SET nextAttempt=0 WHERE deliveryID=? AND state='retry_wait'");$q->execute([$v['delivery']]);$changed=$q->affected_rows;$count->execute([$v['delivery']]);$after=(int)$count->get_result()->fetch_row()[0];echo json_encode(['changed'=>$changed,'history_unchanged'=>$before===$after]);}finally{$d->close();}'''
        result = json.loads(runtime.php(program, {'db': ready['mailer_database'], 'delivery': delivery}))
        check(result == {'changed': 1, 'history_unchanged': True},
              'owned_retry_due_' + str(len(checks)))

    try:
        unit = subprocess.run([php, str(source / 'tests/governance/run.php')], cwd=source, env=runtime.env(), capture_output=True, timeout=180)
        runtime.write('pure.json', unit.stdout)
        pure = json.loads(unit.stdout)
        check(unit.returncode == 0 and pure.get('passed') is True and pure.get('runtime') == '8.5.12' and pure.get('expected_methods') == config['expected_pure_methods'], 'actual_supported_pure_suite')
        db = config['database']
        env = {'PHORGE_GOVERNANCE_FIXTURE_DSN': 'mysql:host=127.0.0.1;port=' + str(db['port']) + ';dbname=governance_fixture_bootstrap;charset=utf8mb4',
               'PHORGE_GOVERNANCE_FIXTURE_USER': db['user'],
               'PHORGE_GOVERNANCE_FIXTURE_PASSWORD': os.environ[db['password_env']],
               'PHORGE_GOVERNANCE_PRIVATE_MAIL_HTTP_PORT': str(http), 'PHORGE_GOVERNANCE_PRIVATE_MAIL_MAILER_PORT': str(mailer_port),
               'PHORGE_GOVERNANCE_PRIVATE_MAIL_MAILER_TOKEN': mailer_token,
               'PHORGE_GOVERNANCE_PRIVATE_MAIL_CALLBACK_TOKEN': callback_token,
               'PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS': '600', 'PHORGE_GOVERNANCE_PRIVATE_MAIL_PENDING_CASE': case}
        for role, key in [('file', 'GORGE_FILE'), ('taskqueue', 'GORGE_TASKQUEUE'), ('owlmail', 'OWLMAIL')]:
            env['PHORGE_GOVERNANCE_' + key + '_BINARY'] = binary[role]
            env['PHORGE_GOVERNANCE_' + key + '_SHA256'] = config['binaries'][role]['sha256']
        fixture = runtime.spawn([php, str(source / 'support/governance/native-organization/test-native-organization-mysql.php'), '--private-mail-pending-hold'], env, 'fixture.log')
        wait_until(lambda: (owner_dir / 'ready.json').is_file(), fixture, 240)
        ready = runtime.read_json('ready.json')
        check(ready['owner'] == runtime.owner and ready['pending_case'] == case and ready['source_transaction_open'] is True, 'real_native_transaction_held')
        prepared = True
        server = runtime.spawn([php, '-S', '127.0.0.1:' + str(http), str(source / 'support/governance/notification/router.php')], log='http.log')
        observer = runtime.spawn([binary['observer'], '--directory', str(owner_dir), '--owner', runtime.owner,
                                  '--listen', '127.0.0.1:' + str(observer_port), '--upstream', ready['callback_origin']], log='observer.log')
        wait_until(lambda: (owner_dir / 'observer.log').stat().st_size > 0, observer, 15)
        observer_ready = json.loads(private_file(owner_dir / 'observer.log').read_text().splitlines()[0])
        check(observer_ready.get('ready') is True and observer_ready.get('native_origin_fixed') is True, 'real_fixed_native_callback_observer')
        database = ready['mailer_database']
        if database['host'] != '127.0.0.1' or not isinstance(database['port'], int):
            raise RuntimeError('native_mailer_database_route_refused')
        dsn = database['user'] + ':' + database['password'] + '@tcp(127.0.0.1:' + str(database['port']) + ')/' + database['database'] + '?parseTime=true'
        mailer = runtime.spawn([binary['mailer']], {'GORGE_LISTEN_ADDR': '127.0.0.1:' + str(mailer_port),
          'GORGE_SERVICE_TOKEN': mailer_token, 'GORGE_MAILER_DELIVERY_DSN': dsn,
          'GORGE_MAILER_CONFIG': json.dumps([{'key': key, 'type': 'smtp', 'options': {'host': '127.0.0.1', 'port': str(ready['smtp_port']), 'protocol': ''}} for key in ('capture-first', 'capture-second')]),
          'GORGE_MAILER_AUTHORIZATION_CONDUIT_URI': 'http://127.0.0.1:' + str(observer_port),
          'GORGE_MAILER_AUTHORIZATION_CONDUIT_TOKEN': callback_token}, 'mailer.log')
        origin = 'http://127.0.0.1:' + str(mailer_port)
        def mailer_ready():
            try:
                return request(origin + '/readyz')[0] == 200
            except OSError:
                return False
        wait_until(mailer_ready, mailer, 20)
        headers = {'X-Service-Token': mailer_token, 'Content-Type': 'application/json'}
        status, raw = request(origin + '/api/mailer/delivery-capabilities', headers=headers)
        startup_observation = capability_observation(status, raw, mailer)
        check(status == 200 and json.loads(raw)['data']['perPhysicalAttemptAuthorization'] is True, 'actual_paired_per_attempt_authorization')
        check(request(ready['capture_uri'] + '/api/v1/emails?limit=1')[0] == 401 and capture(ready)['total'] == 0, 'actual_owlmail_auth_and_empty_capture')
        snapshot = ready['pending_snapshot']
        check(request(origin + '/api/mailer/prepare', 'POST', snapshot, headers)[0] == 200, 'real_paired_snapshot_persisted')

        def attempt(expected):
            before = len(observe()) if (owner_dir / 'callback-observations.jsonl').exists() else 0
            status, raw = request(origin + '/api/mailer/execute', 'POST', {'deliveryID': snapshot['deliveryID'], 'allowSend': True}, headers)
            check(status == 200 and json.loads(raw)['data']['state'] == expected, 'actual_go_state_' + expected + '_' + str(len(checks)))
            events = observe()[before:]
            check(len(events) == 1 and events[0].get('http_status') == 200 and events[0].get('native_json_valid') is True and events[0].get('mode') == 'forward', 'one_actual_native_callback_' + str(len(checks)))
            check(events[0].get('native_source_pending') is (expected == 'retry_wait') and events[0].get('native_allowed') is (None if expected == 'retry_wait' else expected == 'accepted'), 'native_exact_classification_' + str(len(checks)))

        attempt('retry_wait')
        check(capture(ready)['total'] == 0 and not quarantined(ready), 'uncommitted_source_no_smtp_or_quarantine')
        if case == 'commit':
            ack = control('commit', 'committed')
            check(ack['source_transaction_committed'] is True, 'actual_people_commit_acknowledged')
            retry_due(ready, snapshot['deliveryID']); attempt('accepted')
            wait_until(lambda: capture(ready)['total'] == 1, seconds=10)
            item = capture(ready)['emails'][0]
            status, mime = request(ready['capture_uri'] + '/api/v1/emails/' + quote(item['id'], safe='') + '/raw', headers={'Authorization': ready['capture_authorization']})
            message = BytesParser(policy=policy.default).parsebytes(mime)
            parts = list(message.walk())
            texts = [part.get_payload(decode=True).decode(part.get_content_charset() or 'utf-8') for part in parts if part.get_content_type() == 'text/plain']
            normalize = lambda value: value.replace('\r\n', '\n').rstrip('\n')
            check(status == 200 and str(message['Subject']) == snapshot['message']['subject'] and len(texts) == 1 and normalize(texts[0]) == normalize(snapshot['message']['textBody']) and all(part.get_content_type() != 'text/html' and part.get_filename() is None for part in parts), 'actual_native_mime_exact_no_private_attachment')
            addresses = lambda field: sorted(address.lower() for _, address in getaddresses(message.get_all(field, [])))
            check(addresses('To') == sorted(value['address'].lower() for value in snapshot['message'].get('to', [])) and addresses('Cc') == sorted(value['address'].lower() for value in snapshot['message'].get('cc', [])) and message.get('Bcc') is None, 'actual_native_mime_exact_recipients')
            before = len(observe())
            status, raw = request(origin + '/api/mailer/execute', 'POST', {'deliveryID': snapshot['deliveryID'], 'allowSend': True}, headers)
            check(status == 200 and json.loads(raw)['data']['state'] == 'accepted' and capture(ready)['total'] == 1 and len(observe()) == before, 'accepted_replay_no_second_smtp_or_callback')
        else:
            if case == 'rollback':
                control('rollback', 'rolled-back'); retry_due(ready, snapshot['deliveryID']); attempt('retry_wait')
                check(capture(ready)['total'] == 0 and not quarantined(ready), 'young_rollback_no_smtp_or_quarantine'); control('aged', 'aged')
            else:
                control('revoke', 'revoked')
            retry_due(ready, snapshot['deliveryID']); attempt('failed')
            check(capture(ready)['total'] == 0, 'permanent_native_denial_no_smtp_fallback')
        validate_config(config)
        revision_after = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip()
        dirty_after = subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'])
        check(revision_after == config['source_revision'] and not dirty_after, 'source_and_binary_binding_unchanged_after_wire')
        passed = True
    except Exception as error:
        failure = {'class': type(error).__name__, 'stage': str(error) if isinstance(error, RuntimeError) else 'transport_or_fixture'}
        if startup_observation is not None and 'actual_paired_per_attempt_authorization' not in checks:
            failure['capability_observation'] = startup_observation
    finally:
        if fixture is not None and fixture.poll() is None:
            try:
                runtime.write('release', runtime.owner.encode()); fixture.wait(timeout=90)
            except Exception:
                passed = False
        if fixture is not None and fixture.poll() is not None:
            try:
                native = json.loads(private_file(owner_dir / 'fixture.log', 4 * 1024 * 1024).read_text())
                native_clean = native['cleanup'] == {'ownedSchemasRemoved': 55, 'ownedRuntimeUsersRemoved': 3, 'readerRemoved': True} and native['cleanup_failures'] == [] and fixture.returncode == 0
                services = native.get('native', {}).get('owned_service_cleanup')
                native_clean = native_clean and isinstance(services, dict) and set(services) == {'queue', 'capture'} and all(value.get('process_stopped') is True and value.get('private_storage_removed') is True for value in services.values())
                file_cleanup = native.get('file_service_cleanup', {})
                native_clean = native_clean and file_cleanup.get('kind') == 'file' and file_cleanup.get('binary_sha256') == config['binaries']['file']['sha256'] and file_cleanup.get('process_stopped') is True and file_cleanup.get('private_storage_removed') is True
                passed = passed and native['passed'] is True and native_clean
            except Exception:
                passed = False
        else:
            passed = False
        # Keep native receipt and raw process logs private until actual schema
        # cleanup is proved; never delete evidence and upgrade a failed run.
        remove = passed and prepared
        try:
            cleanup = runtime.close(remove=remove)
        except Exception as error:
            cleanup = {'verified': False, 'failure_class': type(error).__name__, 'private_directory_removed': False}
            passed = False
        passed = passed and cleanup.get('live_children') == 0 and cleanup['private_directory_removed']
    return {'schema': 1, 'scenario': case, 'passed': passed, 'checks': checks, 'check_count': len(checks),
            'failure': failure, 'cleanup': cleanup, 'native_cleanup_proved': native_clean,
            'source_revision': config['source_revision'], 'gorge_revision': config['gorge_revision'],
            'binary_sha256': {k: v['sha256'] for k, v in config['binaries'].items()},
            'browser_proof': False, 'public_ports': 0, 'deployment': False,
            'full_139_wire_matrix_proved': False, 'portable_runtime_executed': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--case', required=True, choices=['commit', 'rollback', 'recipient-revoked', 'admin-revoked'])
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    config = load_config(args.config)
    preflight(config)
    result = run_case(config, args.case)
    output = Path(args.output)
    fd = os.open(output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w') as stream:
        json.dump(result, stream, indent=2)
        stream.write('\n')
    return 0 if result['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
