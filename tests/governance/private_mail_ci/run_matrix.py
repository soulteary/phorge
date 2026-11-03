#!/usr/bin/env python3
"""Real Linux-local security/provider matrix; test-only, never a browser proof.

Uses actual native fixture/HTTP callbacks, paired mailer, Owlmail and an owned
SMTP fault provider. The historical139 count is not a hardcoded pass condition.
"""
import argparse
import base64
import json
import os
from pathlib import Path
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from email.parser import BytesParser
from email import policy
from email.utils import getaddresses
from urllib.parse import quote
from runtime import OwnedRuntime, load_config, loopback_request, private_file, validate_config, digest
from run_pending import preflight, wait_until


def load_fault_manifest(path, config):
    value = json.loads(private_file(path).read_text())
    if type(value) is not dict or set(value) != {'path', 'sha256', 'source_revision', 'source_sha256'}:
        raise ValueError('fault_manifest_shape_refused')
    binary = Path(value['path'])
    if not binary.is_absolute() or binary.is_symlink() or not binary.is_file() or not os.access(binary, os.X_OK):
        raise ValueError('fault_binary_scope_refused')
    if value['source_revision'] != config['source_revision'] or digest(binary) != value['sha256'] or digest(Path(config['source_root']) / 'support/governance/notification/smtp-fault.go') != value['source_sha256']:
        raise ValueError('fault_source_binding_refused')
    return value


def run_matrix(config, fault_manifest):
    runtime = OwnedRuntime(config)
    source = Path(config['source_root']); owner_dir = runtime.directory
    guest = str(owner_dir); owner = runtime.owner; php_binary = config['php']
    binary = {key: value['path'] for key, value in config['binaries'].items()}
    fault_binary = fault_manifest['path']
    checks = []; state = {}; prepared = False; passed = False
    fixture = None; native_clean = False; cleanup = None; failure = None
    http_port, mailer_port, fault_port, observer_port = runtime.ports()
    mailer_token = os.urandom(32).hex(); callback_token = os.urandom(32).hex()

    def check(value, name):
        if not value or name in checks:
            raise RuntimeError('assertion_' + name)
        checks.append(name)

    def request(url, method='GET', data=None, headers=None):
        status, raw = loopback_request(url, method, data, headers)
        return {'status': status, 'body': raw}

    def php(program, data=None):
        return runtime.php(program, data)

    def write_guest(path, content):
        path = Path(path)
        if path.parent != owner_dir:
            raise ValueError('owner_signal_path_refused')
        runtime.write(path.name, content.encode())

    def launch(path, env, arguments=(), capture=False):
        return runtime.spawn([path, *arguments], env, 'service-' + str(len(runtime.children)) + '.log')

    def process_ready(process, kind):
        def read_ready():
            log = owner_dir / ('service-' + str(runtime.children.index(process)) + '.log')
            if not log.exists():
                return None
            raw = private_file(log).read_bytes()
            if b'\n' not in raw:
                return None
            value = json.loads(raw.splitlines()[0])
            return value if value.get('ready') is True else None
        value = wait_until(read_ready, process, 10)
        check(value.get('ready') is True, 'owned-loopback-' + kind + '-started')
        return value

    def stop_mailer(process, phase):
        if process not in runtime.children:
            raise ValueError('unregistered_mailer_refused')
        runtime._stop_group(process)
        check(process.poll() is not None and not runtime._members(process.pid), 'owned-mailer-stopped-before-' + phase)

    def mailer_ready():
        try:
            return request(origin + '/readyz')['status'] == 200
        except ConnectionRefusedError:
            return False

    def observations():
        path = owner_dir / 'callback-observations.jsonl'
        return [json.loads(line) for line in private_file(path, 1024 * 1024).read_text().splitlines() if line]

    def native_control(action, case):
        return subprocess.run([php_binary, str(source / 'support/governance/notification/control.php'), action, case], env=runtime.env(), capture_output=True, check=True, timeout=30)

    def callback_mode(mode):
        if mode not in ('forward', 'redirect', 'unavailable', 'missing-allowed'):
            raise ValueError('callback_mode_refused')
        runtime.write('callback-mode.next', (owner + '\n' + mode).encode())
        os.replace(owner_dir / 'callback-mode.next', owner_dir / 'callback-mode')

    def force_retry_due(snapshot, phase):
        code = r"""$v=json_decode(stream_get_contents(STDIN),true);$c=$v['database'];mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=new mysqli($c['host'],$c['user'],$c['password'],$c['database'],$c['port']);try{$count=$db->prepare('SELECT COUNT(*) FROM gorge_mail_attempt WHERE deliveryID=?');$count->execute([$v['id']]);$before=(int)$count->get_result()->fetch_row()[0];$update=$db->prepare("UPDATE gorge_mail_delivery SET nextAttempt=0 WHERE deliveryID=? AND state='retry_wait'");$update->execute([$v['id']]);$changed=$update->affected_rows;$count->execute([$v['id']]);$after=(int)$count->get_result()->fetch_row()[0];echo json_encode(['forced_due'=>$changed===1,'attempt_history_unchanged'=>$before===$after]);}finally{$db->close();}"""
        result = json.loads(php(code, {'database': ready['mailer_database'], 'id': snapshot['deliveryID']}))
        check(result == {'forced_due': True, 'attempt_history_unchanged': True}, 'owned-ledger-retry-forced-due-with-history-preserved-' + phase)

    try:
        unit = subprocess.run([php_binary, str(source / 'tests/governance/run.php')], cwd=source, env=runtime.env(), capture_output=True, timeout=180)
        runtime.write('pure.json', unit.stdout); pure = json.loads(unit.stdout)
        check(unit.returncode == 0 and pure.get('passed') is True and pure.get('runtime') == '8.5.12' and pure.get('expected_methods') == config['expected_pure_methods'], 'actual_supported_pure_suite')
        db = config['database']
        env = {'PHORGE_GOVERNANCE_FIXTURE_DSN': 'mysql:host=127.0.0.1;port=' + str(db['port']) + ';dbname=governance_fixture_bootstrap;charset=utf8mb4',
               'PHORGE_GOVERNANCE_FIXTURE_USER': db['user'], 'PHORGE_GOVERNANCE_FIXTURE_PASSWORD': os.environ[db['password_env']],
               'PHORGE_GOVERNANCE_PRIVATE_MAIL_HTTP_PORT': str(http_port), 'PHORGE_GOVERNANCE_PRIVATE_MAIL_MAILER_PORT': str(mailer_port),
               'PHORGE_GOVERNANCE_PRIVATE_MAIL_MAILER_TOKEN': mailer_token, 'PHORGE_GOVERNANCE_PRIVATE_MAIL_CALLBACK_TOKEN': callback_token,
               'PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS': '600'}
        for role, key in [('file', 'GORGE_FILE'), ('taskqueue', 'GORGE_TASKQUEUE'), ('owlmail', 'OWLMAIL')]:
            env['PHORGE_GOVERNANCE_' + key + '_BINARY'] = binary[role]
            env['PHORGE_GOVERNANCE_' + key + '_SHA256'] = config['binaries'][role]['sha256']
        fixture = runtime.spawn([php_binary, str(source / 'support/governance/native-organization/test-native-organization-mysql.php'), '--private-mail-wire-hold'], env, 'fixture.log')
        wait_until(lambda: (owner_dir / 'ready.json').is_file(), fixture, 240)
        ready = runtime.read_json('ready.json')
        check(ready['owner'] == owner, 'native-owner-manifest-matched'); prepared = True
        server = runtime.spawn([php_binary, '-S', '127.0.0.1:' + str(http_port), str(source / 'support/governance/notification/router.php')], log='http.log')
        observer = launch(binary['observer'], {}, ('--directory', guest, '--owner', owner, '--listen', '127.0.0.1:' + str(observer_port), '--upstream', ready['callback_origin']))
        check(process_ready(observer, 'callback-observer').get('native_origin_fixed') is True, 'callback-observer-forwards-fixed-native-origin')
        db = ready['mailer_database']; dsn = db['user'] + ':' + db['password'] + '@tcp(127.0.0.1:' + str(db['port']) + ')/' + db['database'] + '?parseTime=true'
        mailer_env = {'GORGE_LISTEN_ADDR': '127.0.0.1:' + str(mailer_port), 'GORGE_SERVICE_TOKEN': mailer_token,
          'GORGE_MAILER_CONFIG': json.dumps([{'key':'capture','type':'smtp','options':{'host':'127.0.0.1','port':str(ready['smtp_port']),'protocol':''}}]),
          'GORGE_MAILER_DELIVERY_DSN': dsn, 'GORGE_MAILER_AUTHORIZATION_CONDUIT_URI': 'http://127.0.0.1:' + str(observer_port),
          'GORGE_MAILER_AUTHORIZATION_CONDUIT_TOKEN': callback_token}
        mailer = launch(binary['mailer'], mailer_env); origin = 'http://127.0.0.1:' + str(mailer_port)
        headers = {'X-Service-Token': mailer_token, 'Content-Type': 'application/json'}
        wait_until(mailer_ready, mailer, 20)
        capabilities = request(origin + '/api/mailer/delivery-capabilities', headers=headers)
        check(request(ready['capture_uri'] + '/api/v1/emails?limit=1')['status'] == 401, 'actual-owlmail-capture-rejects-anonymous-api')
        check(capabilities['status'] == 200 and json.loads(capabilities['body'])['data']['perPhysicalAttemptAuthorization'] is True, 'actual-paired-mailer-callback-capability-ready')
        capture_headers = {'Authorization': ready['capture_authorization']}
        def captured():
            response = request(ready['capture_uri'] + '/api/v1/emails?limit=100', headers=capture_headers)
            if response['status'] != 200:
                raise RuntimeError('capture_authenticated_read_failed')
            return json.loads(response['body'])
        check(captured().get('total') == 0, 'actual-owlmail-sink-starts-empty')
        snapshot=ready['snapshots']['allow'];result=request(origin+'/api/mailer/prepare','POST',snapshot,headers);check(result['status']==200,'actual-safe-native-snapshot-prepared')
        result=request(origin+'/api/mailer/execute','POST',{'deliveryID':snapshot['deliveryID'],'allowSend':True},headers);outcome=json.loads(result['body']);state['first_delivery_http_status']=result['status'];state['first_delivery_state']=(outcome.get('data')or{}).get('state');check(result['status']==200 and state['first_delivery_state']=='accepted','actual-native-callback-and-owlmail-smtp-accepted')
        deadline=time.monotonic()+10
        while captured().get('total')!=1:
         if time.monotonic()>deadline:raise RuntimeError('accepted_mail_not_captured')
         time.sleep(.05)
        captured_mail=captured();check(len(captured_mail['emails'])==1,'actual-owlmail-captured-exactly-one-accepted-message')
        mail_id=captured_mail['emails'][0]['id'];check(isinstance(mail_id,str)and len(mail_id)<256,'actual-capture-message-id-bounded')
        raw=request(ready['capture_uri']+'/api/v1/emails/'+quote(mail_id,safe='')+'/raw',headers=capture_headers)
        check(raw['status']==200,'actual-owlmail-raw-mime-retrieved-authenticated')
        message=BytesParser(policy=policy.default).parsebytes(raw['body'])
        content='\n'.join(str(v)for _,v in message.items())
        for part in message.walk():
         content+='\n'+str(part.get_payload(decode=True)or b'')
        check('SYNTHETIC_PRIVATE'not in content and message['Subject']=='Private notification'and
         all(part.get_content_type()!='text/html'and part.get_filename()is None for part in message.walk()),
         'actual-decoded-mime-has-generic-subject-no-private-content-html-or-attachment')
        replay=request(origin+'/api/mailer/execute','POST',{'deliveryID':snapshot['deliveryID'],'allowSend':True},headers)
        check(replay['status']==200 and json.loads(replay['body'])['data']['state']=='accepted'and captured().get('total')==1,
         'actual-accepted-delivery-replay-does-not-send-second-message')
        denied=ready['snapshots']['before-revoke'];check(request(origin+'/api/mailer/prepare','POST',denied,headers)['status']==200,'actual-before-revoke-native-snapshot-prepared')
        mutation=native_control('revoke-source','before-revoke')
        check(json.loads(mutation.stdout).get('passed')is True,'actual-native-file-source-revoked-after-prepare')
        refused=request(origin+'/api/mailer/execute','POST',{'deliveryID':denied['deliveryID'],'allowSend':True},headers)
        check(refused['status']==200 and json.loads(refused['body'])['data']['state']=='failed'and captured().get('total')==1,
         'actual-physical-callback-current-revocation-refuses-second-smtp-message')
        changed=ready['snapshots']['mutated'];changed['message']['subject']='SYNTHETIC_PRIVATE_INJECTED'
        check(request(origin+'/api/mailer/prepare','POST',changed,headers)['status']==200,'actual-mutation-negative-snapshot-prepared')
        refused=request(origin+'/api/mailer/execute','POST',{'deliveryID':changed['deliveryID'],'allowSend':True},headers)
        check(refused['status']==200 and json.loads(refused['body'])['data']['state']=='failed'and captured().get('total')==1,
         'actual-native-callback-refuses-mutated-immutable-message-without-smtp-send')
        audit=observations();state['callback_observations']=audit;check([x.get('native_allowed')for x in audit]==[True,False,False],'actual-forwarded-native-callback-observations-match-business-decisions')
        stop_mailer(mailer,'fallback-configuration')
        fault=launch(fault_binary,{},('--directory',guest,'--owner',owner,'--listen','127.0.0.1:'+str(fault_port)),capture=True)
        check(process_ready(fault,'rejection-provider').get('accepts_data')is False,'first-provider-rejects-envelope-without-accepting-data')
        mailer_env['GORGE_MAILER_CONFIG']=json.dumps([{'key':'first-fault','type':'smtp','priority':10,'options':{'host':'127.0.0.1','port':str(fault_port),'protocol':''}},{'key':'capture','type':'smtp','options':{'host':'127.0.0.1','port':str(ready['smtp_port']),'protocol':''}}])
        mailer=launch(binary['mailer'],mailer_env)
        deadline=time.monotonic()+10
        while not mailer_ready():
         if mailer.poll()is not None or time.monotonic()>deadline:raise RuntimeError('fallback_mailer_not_ready')
         time.sleep(.1)
        between=ready['snapshots']['between-attempts'];check(request(origin+'/api/mailer/prepare','POST',between,headers)['status']==200,'actual-between-attempts-native-snapshot-prepared')
        with ThreadPoolExecutor(max_workers=1)as executor:
         pending=executor.submit(request,origin+'/api/mailer/execute','POST',{'deliveryID':between['deliveryID'],'allowSend':True},headers)
         deadline=time.monotonic()+10
         while not (owner_dir / 'smtp-rejection-ready').is_file():
          if pending.done()or fault.poll()is not None or time.monotonic()>deadline:raise RuntimeError('first_provider_not_reached')
          time.sleep(.02)
         check([x.get('native_allowed')for x in observations()][-1]is True,'first-physical-provider-followed-positive-native-callback')
         mutation=native_control('revoke-source','between-attempts')
         check(json.loads(mutation.stdout).get('passed')is True,'native-source-revoked-after-first-envelope-before-fallback')
         committed=time.perf_counter_ns();write_guest(guest+'/smtp-rejection-release',owner);refused=pending.result(timeout=30)
         state['isolated_fallback_denial_observation_ns']=time.perf_counter_ns()-committed
        check(refused['status']==200 and json.loads(refused['body'])['data']['state']=='failed'and captured().get('total')==1,'native-revocation-prevents-fallback-physical-send-after-explicit-nonacceptance')
        audit=observations();state['callback_observations']=audit;check([x.get('native_allowed')for x in audit]==[True,False,False,True,False],'second-physical-callback-observed-actual-native-denial')
        check(fault.wait(timeout=5)==0,'owned-rejection-provider-terminated-without-accepting-message')
        state['revocation_measurement_scope']='Single isolated post-commit-to-observed-denial interval, not an atomic SMTP guarantee or SLA.'
        stop_mailer(mailer,'unknown-provider-outcome')
        fault=launch(fault_binary,{},('--directory',guest,'--owner',owner,'--listen','127.0.0.1:'+str(fault_port),'--mode','drop-data-ack'),capture=True)
        check(process_ready(fault,'data-ack-drop-provider').get('accepts_data')is True,'owned-provider-will-receive-data-and-drop-final-acknowledgement')
        mailer_env['GORGE_MAILER_CONFIG']=json.dumps([{'key':'unknown-outcome','type':'smtp','priority':10,'options':{'host':'127.0.0.1','port':str(fault_port),'protocol':''}},{'key':'capture','type':'smtp','options':{'host':'127.0.0.1','port':str(ready['smtp_port']),'protocol':''}}]);mailer=launch(binary['mailer'],mailer_env)
        deadline=time.monotonic()+10
        while not mailer_ready():
         if mailer.poll()is not None or time.monotonic()>deadline:raise RuntimeError('unknown_outcome_mailer_not_ready')
         time.sleep(.1)
        unknown=ready['snapshots']['unknown-outcome'];check(request(origin+'/api/mailer/prepare','POST',unknown,headers)['status']==200,'actual-unknown-provider-outcome-native-snapshot-prepared')
        before_unknown_calls=len(observations())
        unknown_result=request(origin+'/api/mailer/execute','POST',{'deliveryID':unknown['deliveryID'],'allowSend':True},headers)
        check(unknown_result['status']==200 and json.loads(unknown_result['body'])['data']['state']=='unknown','actual-data-ack-loss-enters-terminal-unknown-state')
        check(fault.wait(timeout=5)==0,'owned-provider-received-complete-data-without-final-response')
        check(captured().get('total')==1 and len(observations())==before_unknown_calls+1,'unknown-acceptance-does-not-failover-to-second-provider')
        unknown_replay=request(origin+'/api/mailer/execute','POST',{'deliveryID':unknown['deliveryID'],'allowSend':True},headers)
        check(unknown_replay['status']==200 and json.loads(unknown_replay['body'])['data']['state']=='unknown'and captured().get('total')==1 and len(observations())==before_unknown_calls+1,'terminal-unknown-replay-does-not-authorize-or-submit-again')
        state['unknown_provider_outcome_proved']=True
        stop_mailer(mailer,'transport-matrix')
        mailer_env['GORGE_MAILER_CONFIG']=json.dumps([{'key':'capture','type':'smtp','options':{'host':'127.0.0.1','port':str(ready['smtp_port']),'protocol':''}}]);mailer=launch(binary['mailer'],mailer_env)
        deadline=time.monotonic()+10
        while not mailer_ready():
         if mailer.poll()is not None or time.monotonic()>deadline:raise RuntimeError('transport_mailer_not_ready')
         time.sleep(.1)
        unavailable=ready['snapshots']['unavailable'];check(request(origin+'/api/mailer/prepare','POST',unavailable,headers)['status']==200,'actual-transport-negative-native-snapshot-prepared')
        for index,mode in enumerate(['unavailable','redirect','missing-allowed']):
         callback_mode(mode)
         if index:force_retry_due(unavailable,mode)
         denied_transport=request(origin+'/api/mailer/execute','POST',{'deliveryID':unavailable['deliveryID'],'allowSend':True},headers)
         check(denied_transport['status']==200 and json.loads(denied_transport['body'])['data']['state']=='retry_wait'and captured().get('total')==1,'actual-callback-'+mode+'-sends-no-message')
        check(not any(x.get('redirect_target_hit')for x in observations()),'actual-callback-client-does-not-follow-service-token-redirect')
        stop_mailer(mailer,'missing-source-configuration');mailer_env.pop('GORGE_MAILER_AUTHORIZATION_CONDUIT_URI');mailer_env.pop('GORGE_MAILER_AUTHORIZATION_CONDUIT_TOKEN');mailer=launch(binary['mailer'],mailer_env)
        deadline=time.monotonic()+10
        while not mailer_ready():
         if mailer.poll()is not None or time.monotonic()>deadline:raise RuntimeError('unconfigured_mailer_not_ready')
         time.sleep(.1)
        force_retry_due(unavailable,'missing-source-configuration');before_calls=len(observations());missing_source=request(origin+'/api/mailer/execute','POST',{'deliveryID':unavailable['deliveryID'],'allowSend':True},headers)
        check(missing_source['status']==200 and json.loads(missing_source['body'])['data']['state']=='failed'and captured().get('total')==1 and len(observations())==before_calls,'actual-missing-callback-configuration-refuses-physical-send')
        stop_mailer(mailer,'security-flow-matrix');callback_mode('forward')
        mailer_env['GORGE_MAILER_AUTHORIZATION_CONDUIT_URI']='http://127.0.0.1:'+str(observer_port);mailer_env['GORGE_MAILER_AUTHORIZATION_CONDUIT_TOKEN']=callback_token
        mailer_env['GORGE_MAILER_CONFIG']=json.dumps([{'key':'capture','type':'smtp','options':{'host':'127.0.0.1','port':str(ready['smtp_port']),'protocol':''}}]);mailer=launch(binary['mailer'],mailer_env)
        deadline=time.monotonic()+10
        while not mailer_ready():
         if mailer.poll()is not None or time.monotonic()>deadline:raise RuntimeError('security_mailer_not_ready')
         time.sleep(.1)
        positives=['login-allow','welcome-allow','verify-allow','queue-allow','invite-allow','old-primary-allow','new-primary-allow']
        negatives={'login-disable':'security-disable','login-secret-rotate':'security-rotate-secret','login-primary-switch':'security-switch-primary','welcome-demote':'security-demote-admin','queue-demote':'security-demote-admin','verify-code-rotate':'security-rotate-code','verify-completed':'security-complete-verification','invite-code-rotate':'security-rotate-invite','invite-demote':'security-demote-admin'}
        security=ready['security_snapshots'];check(set(security)==set(positives)|set(negatives),'security-private-manifest-matches-fixed-sixteen-case-allowlist')
        def canonical_text(value):return value.replace('\r\n','\n').rstrip('\n')
        for case in positives:
         snap=security[case];before=captured();old_ids={x['id']for x in before['emails']};count=before['total']
         prepared_security=request(origin+'/api/mailer/prepare','POST',snap,headers)
         if prepared_security['status']!=200:
          state['security_prepare_failure']={'case':case,'http_status':prepared_security['status'],'from_address_present':bool(snap.get('message',{}).get('from',{}).get('address')),'to_count':len(snap.get('message',{}).get('to',[])),'header_value_types':sorted({type(h.get('value')).__name__ for h in snap.get('message',{}).get('headers',[])})}
         check(prepared_security['status']==200,'security-'+case+'-actual-native-snapshot-prepared')
         before_calls=len(observations());result=request(origin+'/api/mailer/execute','POST',{'deliveryID':snap['deliveryID'],'allowSend':True},headers)
         decisions=observations()[before_calls:];check(len(decisions)==1 and decisions[0].get('http_status')==200 and decisions[0].get('native_json_valid')is True and decisions[0].get('native_allowed')is True,'security-'+case+'-observed-real-native-allow')
         check(result['status']==200 and json.loads(result['body'])['data']['state']=='accepted','security-'+case+'-actual-native-callback-and-smtp-accepted')
         after=captured();new_ids={x['id']for x in after['emails']}-old_ids;check(after['total']==count+1 and len(new_ids)==1,'security-'+case+'-exactly-one-capture')
         raw=request(ready['capture_uri']+'/api/v1/emails/'+quote(next(iter(new_ids)),safe='')+'/raw',headers=capture_headers);check(raw['status']==200,'security-'+case+'-authenticated-raw-mime-read')
         message=BytesParser(policy=policy.default).parsebytes(raw['body']);parts=list(message.walk());texts=[part.get_payload(decode=True).decode(part.get_content_charset()or'utf-8')for part in parts if part.get_content_type()=='text/plain']
         expected=snap['message']
         actual_to=[address for _,address in getaddresses(message.get_all('To',[]))];actual_cc=[address for _,address in getaddresses(message.get_all('Cc',[]))]
         check(actual_to==[entry['address']for entry in expected.get('to',[])] and actual_cc==[entry['address']for entry in expected.get('cc',[])] and message.get_all('Bcc')is None,'security-'+case+'-mime-recipient-headers-match-exact-native-destination')
         check(str(message['Subject'])==expected['subject'] and len(texts)==1 and canonical_text(texts[0])==canonical_text(expected['textBody']) and all(part.get_content_type()!='text/html'and part.get_filename()is None for part in parts),'security-'+case+'-native-payload-survives-smtp-without-added-html-or-attachment')
         if case in ['login-allow','welcome-allow','verify-allow','invite-allow']:
          route={'login-allow':'/login/once/','welcome-allow':'/login/once/','verify-allow':'/emailverify/','invite-allow':'/auth/invite/'}[case]
          check(route in texts[0],'security-'+case+'-genuine-native-challenge-route-preserved')
        for case,action in negatives.items():
         snap=security[case];count=captured()['total'];check(request(origin+'/api/mailer/prepare','POST',snap,headers)['status']==200,'security-'+case+'-actual-native-snapshot-prepared')
         mutation=native_control(action,case)
         check(json.loads(mutation.stdout).get('passed')is True,'security-'+case+'-actual-fixed-native-source-mutated')
         before_calls=len(observations());result=request(origin+'/api/mailer/execute','POST',{'deliveryID':snap['deliveryID'],'allowSend':True},headers)
         decisions=observations()[before_calls:];check(len(decisions)==1 and decisions[0].get('http_status')==200 and decisions[0].get('native_json_valid')is True and decisions[0].get('native_allowed')is False,'security-'+case+'-observed-real-native-denial')
         check(result['status']==200 and json.loads(result['body'])['data']['state']=='failed'and captured()['total']==count,'security-'+case+'-current-source-refuses-smtp')
        state['security_physical_candidate_cases_passed']=16;state['security_flow_matrix_complete']=False
        # Distinct additive native capability profile; never included in16.
        invite=ready['invite_capability_snapshots']
        check(set(invite)=={'invite-capability-allow','invite-capability-revoke'},'invite-capability-two-fixed-native-snapshots')
        grant=native_control('security-grant-invite-capability','invite-capability-allow')
        check(json.loads(grant.stdout).get('passed')is True,'invite-capability-fixed-native-grant-after-original-sixteen-cases')
        for case in ('invite-capability-allow','invite-capability-revoke'):
         snap=invite[case];before=captured();count=before['total']
         check(request(origin+'/api/mailer/prepare','POST',snap,headers)['status']==200,'security-'+case+'-actual-snapshot-prepared')
         if case.endswith('revoke'):
          mutation=native_control('security-revoke-invite-capability',case)
          check(json.loads(mutation.stdout).get('passed')is True,'security-'+case+'-native-app-capability-revoked')
         before_calls=len(observations());result=request(origin+'/api/mailer/execute','POST',{'deliveryID':snap['deliveryID'],'allowSend':True},headers)
         decisions=observations()[before_calls:];allowed=case.endswith('allow')
         check(len(decisions)==1 and decisions[0].get('http_status')==200 and decisions[0].get('native_json_valid')is True and decisions[0].get('native_allowed')is allowed,'security-'+case+'-fresh-native-capability-decision')
         check(result['status']==200 and json.loads(result['body'])['data']['state']==('accepted'if allowed else'failed') and captured()['total']==count+(1 if allowed else 0),'security-'+case+'-exact-smtp-count')
         if allowed:
          new_ids={x['id']for x in captured()['emails']}-{x['id']for x in before['emails']}
          check(len(new_ids)==1,'security-'+case+'-exactly-one-capture')
          raw=request(ready['capture_uri']+'/api/v1/emails/'+quote(next(iter(new_ids)),safe='')+'/raw',headers=capture_headers)
          msg=BytesParser(policy=policy.default).parsebytes(raw['body']);parts=list(msg.walk());texts=[p.get_payload(decode=True).decode(p.get_content_charset()or'utf-8')for p in parts if p.get_content_type()=='text/plain']
          actual_to=[address for _,address in getaddresses(msg.get_all('To',[]))];actual_cc=[address for _,address in getaddresses(msg.get_all('Cc',[]))]
          check(raw['status']==200 and str(msg['Subject'])==snap['message']['subject'] and len(texts)==1 and canonical_text(texts[0])==canonical_text(snap['message']['textBody']) and '/auth/invite/'in texts[0] and actual_to==[entry['address']for entry in snap['message'].get('to',[])] and actual_cc==[entry['address']for entry in snap['message'].get('cc',[])] and msg.get_all('Bcc')is None and all(p.get_content_type()!='text/html'and p.get_filename()is None for p in parts),'security-'+case+'-native-challenge-mime-exact')
          count_after=captured()['total'];replay=request(origin+'/api/mailer/execute','POST',{'deliveryID':snap['deliveryID'],'allowSend':True},headers)
          check(replay['status']==200 and json.loads(replay['body'])['data']['state']=='accepted'and captured()['total']==count_after,'security-'+case+'-accepted-replay-does-not-resend')
        state['invite_capability_physical_cases_passed']=2
        # Separate exact native producers retain payloads beyond the former64KiB
        # ceiling. They are not synthesized from the smaller security snapshots.
        large = ready['large_security_snapshots']
        large_positive = ('large-body-allow', 'large-attachment-allow')
        large_negative = ('large-body-revoked', 'large-attachment-revoked')
        check(set(large) == set(large_positive + large_negative), 'large-security-private-manifest-matches-fixed-four-case-allowlist')
        for case in large_positive:
            snap = large[case]; expected = snap['message']
            check(len(json.dumps(expected, separators=(',', ':')).encode()) > 65536, 'security-' + case + '-actual-native-payload-exceeds-former-budget')
            before = captured(); old_ids = {item['id'] for item in before['emails']}
            check(request(origin + '/api/mailer/prepare', 'POST', snap, headers)['status'] == 200, 'security-' + case + '-actual-native-snapshot-prepared')
            before_calls = len(observations())
            response = request(origin + '/api/mailer/execute', 'POST', {'deliveryID': snap['deliveryID'], 'allowSend': True}, headers)
            decisions = observations()[before_calls:]
            check(len(decisions) == 1 and decisions[0].get('http_status') == 200 and decisions[0].get('native_json_valid') is True and decisions[0].get('native_allowed') is True, 'security-' + case + '-observed-current-native-allow')
            check(response['status'] == 200 and json.loads(response['body'])['data']['state'] == 'accepted', 'security-' + case + '-actual-native-callback-and-smtp-accepted')
            after = captured(); new_ids = {item['id'] for item in after['emails']} - old_ids
            check(after['total'] == before['total'] + 1 and len(new_ids) == 1, 'security-' + case + '-exactly-one-capture')
            raw = request(ready['capture_uri'] + '/api/v1/emails/' + quote(next(iter(new_ids)), safe='') + '/raw', headers=capture_headers)
            check(raw['status'] == 200, 'security-' + case + '-authenticated-raw-mime-read')
            message = BytesParser(policy=policy.default).parsebytes(raw['body']); parts = list(message.walk())
            texts = [part.get_payload(decode=True).decode(part.get_content_charset() or 'utf-8') for part in parts if part.get_content_type() == 'text/plain' and part.get_filename() is None]
            actual_attachments = [(part.get_filename(), part.get_content_type(), part.get_payload(decode=True)) for part in parts if part.get_filename() is not None]
            expected_attachments = [(item['filename'], item['mimeType'], base64.b64decode(item['data'], validate=True)) for item in expected.get('attachments', [])]
            actual_to = [address for _, address in getaddresses(message.get_all('To', []))]
            actual_cc = [address for _, address in getaddresses(message.get_all('Cc', []))]
            check(actual_to == [item['address'] for item in expected.get('to', [])] and actual_cc == [item['address'] for item in expected.get('cc', [])] and message.get_all('Bcc') is None, 'security-' + case + '-mime-ToCc-exact-native-destination')
            check(str(message['Subject']) == expected['subject'] and len(texts) == 1 and canonical_text(texts[0]) == canonical_text(expected['textBody']) and '/login/once/' in texts[0] and all(part.get_content_type() != 'text/html' for part in parts) and actual_attachments == expected_attachments, 'security-' + case + '-full-native-body-and-attachment-bytes-survive-smtp')
            if case == 'large-body-allow':
                check(len(expected['textBody'].encode()) > 65536 and not expected_attachments, 'security-large-body-size-and-no-attachment-positive-control')
            else:
                check(len(expected_attachments) == 1 and len(expected_attachments[0][2]) == 50 * 1024, 'security-large-attachment-decoded-50KiB-positive-control')
        for case in large_negative:
            snap = large[case]; count = captured()['total']
            check(request(origin + '/api/mailer/prepare', 'POST', snap, headers)['status'] == 200, 'security-' + case + '-actual-native-snapshot-prepared')
            mutation = native_control('security-rotate-secret', case)
            check(json.loads(mutation.stdout).get('passed') is True, 'security-' + case + '-actual-native-secret-rotated')
            before_calls = len(observations())
            response = request(origin + '/api/mailer/execute', 'POST', {'deliveryID': snap['deliveryID'], 'allowSend': True}, headers)
            decisions = observations()[before_calls:]
            check(len(decisions) == 1 and decisions[0].get('http_status') == 200 and decisions[0].get('native_json_valid') is True and decisions[0].get('native_allowed') is False, 'security-' + case + '-observed-current-native-denial')
            check(response['status'] == 200 and json.loads(response['body'])['data']['state'] == 'failed' and captured()['total'] == count, 'security-' + case + '-source-change-refuses-smtp')
        state['large_security_cases_passed'] = 4
        validate_config(config); load_fault_manifest_value = fault_manifest
        check(digest(Path(load_fault_manifest_value['path'])) == load_fault_manifest_value['sha256'], 'fault_binary_unchanged_after_wire')
        revision = subprocess.check_output(['git','-C',str(source),'rev-parse','HEAD'], text=True).strip()
        dirty = subprocess.check_output(['git','-C',str(source),'status','--porcelain','--untracked-files=no'])
        check(revision == config['source_revision'] and not dirty, 'source_and_binary_binding_unchanged_after_wire')
        passed = True
    except Exception as error:
        failure = {'class': type(error).__name__, 'stage': str(error) if isinstance(error, RuntimeError) else 'transport_or_fixture'}
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
    return {'schema': 1, 'scenario': 'provider-security-matrix', 'passed': passed,
      'checks': checks, 'check_count': len(checks), 'failure': failure, 'cleanup': cleanup,
      'native_cleanup_proved': native_clean, 'source_revision': config['source_revision'],
      'gorge_revision': config['gorge_revision'], 'binary_sha256': {k:v['sha256'] for k,v in config['binaries'].items()},
      'fault_binary_sha256': fault_manifest['sha256'], 'security_cases_passed': state.get('security_physical_candidate_cases_passed', 0),
      'large_security_cases_passed': state.get('large_security_cases_passed', 0),
      'invite_capability_cases_passed': state.get('invite_capability_physical_cases_passed', 0),
      'browser_proof': False, 'public_ports': 0, 'deployment': False,
      'portable_runtime_executed': True, 'full_matrix_business_assertions_passed': passed}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--fault-manifest', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    config = load_config(args.config); preflight(config)
    fault = load_fault_manifest(args.fault_manifest, config)
    result = run_matrix(config, fault)
    fd = os.open(args.output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w') as stream:
        json.dump(result, stream, indent=2); stream.write('\n')
    return 0 if result['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
