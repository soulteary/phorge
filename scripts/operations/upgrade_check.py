#!/usr/bin/env python3
"""Read-only upgrade inventory. Reports are not deployment approvals."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import re
import subprocess
import sys

COMPONENTS = {'phorge'} | {'gorge-' + s for s in (
    'conduit', 'db-api', 'file-storage', 'mailer', 'notification', 'render',
    'taskqueue', 'webhook', 'worker')}
ROLES = COMPONENTS | {'phorge-daemon'}
FLAGS = {
    'governance.' + s for s in (
        'media-exchange.http-enabled', 'media-exchange.enabled',
        'notifications.enabled', 'account-recovery.enabled',
        'account-recovery.owned-rr-scope.enabled',
        'account-recovery.session-lease.enabled', 'organization.enabled',
        'private-mail.enabled', 'project-content-grants.enabled',
        'project-approval.enabled', 'recovery-stop-ledger.enabled',
        'task-content-grants.enabled')}
EXTERNAL_GATES = (
    'database-redis-images', 'real-otp-login', 'backup-and-business-restore',
    'image-signatures-and-provenance', 'authenticated-business-and-attachments')
DIGEST = re.compile(r'sha256:[0-9a-f]{64}')
HEX_ID = re.compile(r'[0-9a-f]{64}')
PHP_FLAGS = (
    'require "/opt/phorge/phorge/scripts/init/init-script.php"; '
    '$r=array(); foreach (PhabricatorApplicationConfigOptions::loadAllOptions() as $k=>$o) {'
    'if (strpos($k,"governance.")===0 && '
    '(substr($k,-8)===".enabled" || substr($k,-8)==="-enabled")) {'
    '$r[$k]=PhabricatorEnv::getEnvConfig($k);}} echo json_encode($r);')


class CheckError(Exception):
    """Only fixed error codes may cross the report boundary."""


def fail(code):
    raise CheckError(code)


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            fail('INPUT_AMBIGUOUS')
        value[key] = item
    return value


def check_depth(value):
    pending = [(value, 0)]
    while pending:
        item, depth = pending.pop()
        if depth > 64:
            fail('INPUT_UNREADABLE')
        if isinstance(item, (dict, list)):
            pending.extend((child, depth + 1) for child in (
                item.values() if isinstance(item, dict) else item))
    return value


def read_json(path):
    try:
        with Path(path).open() as stream:
            raw = stream.read(8 * 1024 * 1024 + 1)
        if len(raw) > 8 * 1024 * 1024:
            fail('INPUT_UNREADABLE')
        return check_depth(json.loads(raw, object_pairs_hook=unique_object))
    except (OSError, ValueError, RecursionError):
        fail('INPUT_UNREADABLE')


def docker(args):
    try:
        p = subprocess.run(['docker', *args], capture_output=True, timeout=30)
    except (OSError, subprocess.SubprocessError):
        fail('DOCKER_UNAVAILABLE')
    if p.returncode:
        fail('DOCKER_COMMAND_FAILED')
    if len(p.stdout) > 8 * 1024 * 1024:
        fail('DOCKER_RESPONSE_INVALID')
    try:
        return check_depth(json.loads(p.stdout, object_pairs_hook=unique_object))
    except (ValueError, UnicodeError, RecursionError):
        fail('DOCKER_RESPONSE_INVALID')


def validate_bindings(bindings):
    if not isinstance(bindings, dict) or not ROLES <= bindings.keys():
        fail('BINDINGS_INVALID')
    if bindings.keys() - (ROLES | {'preserved-app'}):
        fail('BINDINGS_INVALID')
    if any(not isinstance(v, str) or not HEX_ID.fullmatch(v)
           for v in bindings.values()):
        fail('BINDINGS_INVALID')
    if len(set(bindings.values())) != len(bindings):
        fail('BINDINGS_INVALID')


def stable(container):
    return (container['Id'], container['Image'], container['RestartCount'],
            container['State']['StartedAt'], container['State']['Running'],
            container['State'].get('Health', {}).get('Status'))


def collect(bindings):
    validate_bindings(bindings)
    first = docker(['inspect', *bindings.values()])
    if not isinstance(first, list):
        fail('DOCKER_RESPONSE_INVALID')
    by_id = {c['Id']: c for c in first}
    if set(by_id) != set(bindings.values()):
        fail('CONTAINER_IDENTITY_CHANGED')
    rows = {}
    for role, cid in bindings.items():
        c = by_id[cid]
        images = docker(['image', 'inspect', c['Image']])
        if not isinstance(images, list) or len(images) != 1:
            fail('IMAGE_IDENTITY_INVALID')
        image = images[0]
        if image['Id'] != c['Image']:
            fail('IMAGE_IDENTITY_INVALID')
        labels = image['Config'].get('Labels') or {}
        rows[role] = {
            'image_id': image['Id'], 'configured_image': c['Config']['Image'],
            'repo_digests': image.get('RepoDigests') or [],
            'architecture': image['Architecture'], 'os': image['Os'],
            'revision': labels.get('org.opencontainers.image.revision'),
            'version': labels.get('org.opencontainers.image.version'),
            'source': labels.get('org.opencontainers.image.source'),
            'running': c['State']['Running'],
            'health_declared': bool(c['Config'].get('Healthcheck')),
            'health': c['State'].get('Health', {}).get('Status')}
    flags = {role: docker(['exec', bindings[role], 'php', '-r', PHP_FLAGS])
             for role in ('phorge', 'phorge-daemon')}
    last = docker(['inspect', *bindings.values()])
    if not isinstance(last, list) or len(last) != len(first):
        fail('CONTAINER_IDENTITY_CHANGED')
    if {c['Id']: stable(c) for c in last} != {c['Id']: stable(c) for c in first}:
        fail('CONTAINER_IDENTITY_CHANGED')
    return {'schema_version': 1, 'captured_at': dt.datetime.now(dt.timezone.utc).isoformat(),
            'services': rows, 'optional_flags': flags}


def write_private(path, value):
    """Create a new 0600 snapshot under an existing user-owned 0700 directory."""
    path = Path(path)
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        st = os.fstat(directory)
        if st.st_uid != os.getuid() or st.st_mode & 0o077:
            fail('OUTPUT_DIRECTORY_UNSAFE')
        fd = os.open(path.name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                     0o600, dir_fd=directory)
        with os.fdopen(fd, 'w') as stream:
            json.dump(value, stream, indent=2)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.fsync(directory)
    finally:
        os.close(directory)


def catalog_check(catalog):
    if (not isinstance(catalog, dict) or type(catalog.get('schema_version')) is not int
            or catalog.get('schema_version') != 1):
        fail('CATALOG_INVALID')
    if not isinstance(catalog.get('release'), str) or not re.fullmatch(
            r'20\d{2}\.\d{2}\.\d{2}-r[1-9]\d*', catalog['release']):
        fail('CATALOG_INVALID')
    components = catalog.get('components')
    if not isinstance(components, dict) or set(components) != COMPONENTS:
        fail('CATALOG_INVALID')
    for role, item in components.items():
        repo = 'ghcr.io/soulteary/' + ('phorge' if role == 'phorge' else 'gorge')
        if (not isinstance(item, dict) or item.get('repository') != repo
                or not isinstance(item.get('index_digest'), str)
                or not DIGEST.fullmatch(item['index_digest'])
                or not isinstance(item.get('source_revision'), str)
                or not re.fullmatch(r'[0-9a-f]{40}', item['source_revision'])
                or not isinstance(item.get('platforms'), list)
                or set(item['platforms']) != {'amd64', 'arm64'}):
            fail('CATALOG_INVALID')
        platforms = item.get('platform_digests', {})
        if not isinstance(platforms, dict) or platforms and (
                set(platforms) != {'amd64', 'arm64'} or any(
                    not isinstance(v, str) or not DIGEST.fullmatch(v)
                    for v in platforms.values())):
            fail('CATALOG_INVALID')
    if len({item['source_revision'] for role, item in components.items()
            if role.startswith('gorge-')}) != 1:
        fail('CATALOG_INVALID')


def snapshot_time(snapshot, now, max_age):
    if (not isinstance(snapshot, dict) or type(snapshot.get('schema_version')) is not int
            or snapshot.get('schema_version') != 1):
        fail('SNAPSHOT_INVALID')
    try:
        stamp = dt.datetime.fromisoformat(snapshot['captured_at'])
        if stamp.utcoffset() is None:
            fail('SNAPSHOT_TIME_INVALID')
        age = (now - stamp).total_seconds()
        if age < -60 or age > max_age:
            fail('SNAPSHOT_STALE')
    except (KeyError, TypeError, ValueError):
        fail('SNAPSHOT_TIME_INVALID')
    return stamp


def snapshot_check(snapshot):
    rows = snapshot.get('services')
    if (not isinstance(rows, dict) or not ROLES <= rows.keys()
            or rows.keys() - (ROLES | {'preserved-app'})):
        fail('SNAPSHOT_INVALID')
    for row in rows.values():
        if (not isinstance(row, dict) or not isinstance(row.get('image_id'), str)
                or not DIGEST.fullmatch(row['image_id'])
                or type(row.get('running')) is not bool
                or type(row.get('health_declared')) is not bool
                or not isinstance(row.get('repo_digests'), list)
                or any(not isinstance(v, str) for v in row['repo_digests'])
                or row.get('health') not in (None, 'healthy', 'starting', 'unhealthy')):
            fail('SNAPSHOT_INVALID')


def image_matches(row, target, release):
    digests = {target['index_digest']}
    child = target.get('platform_digests', {}).get(row.get('architecture'))
    if child:
        digests.add(child)
    refs = {target['repository'] + '@' + digest for digest in digests}
    return (row.get('os') == 'linux' and row.get('architecture') in target['platforms']
            and row.get('revision') == target['source_revision']
            and row.get('source') == target['repository'].replace('ghcr.io/', 'https://github.com/')
            and row.get('version') == release
            and bool(set(row['repo_digests']) & refs)
            and row.get('configured_image') in refs | {row['image_id']})


def flags_off(snapshot):
    flags = snapshot.get('optional_flags')
    return (isinstance(flags, dict) and set(flags) == {'phorge', 'phorge-daemon'}
            and all(isinstance(group, dict) and set(group) == FLAGS
                    and all(v is False for v in group.values()) for group in flags.values()))


def assess(catalog, snapshot, baseline=None, now=None):
    catalog_check(catalog)
    now = now or dt.datetime.now(dt.timezone.utc)
    after = snapshot_time(snapshot, now, 3600)
    snapshot_check(snapshot)
    if baseline is not None:
        before = snapshot_time(baseline, now, 86400)
        snapshot_check(baseline)
        if before > after or set(baseline['services']) != set(snapshot['services']):
            fail('BASELINE_MISMATCH')
    checks = []
    for role, row in sorted(snapshot['services'].items()):
        ready = row['running'] and row['health_declared'] and row['health'] == 'healthy'
        # No configured health probe is unknown, not healthy by default.
        checks.append({'role': role, 'check': 'runtime-health',
                       'status': 'passed' if ready else 'not_verified'})
        if baseline is not None:
            matched = (row['image_id'] == baseline['services'][role]['image_id']
                       if role == 'preserved-app' else image_matches(
                           row, catalog['components']['phorge' if role == 'phorge-daemon' else role],
                           catalog['release']))
            checks.append({'role': role, 'check': 'image-identity',
                           'status': 'passed' if matched else 'failed'})
    checks.append({'check': 'optional-features-off',
                   'status': 'passed' if flags_off(snapshot) else 'failed'})
    if baseline is not None:
        checks.append({'check': 'baseline-optional-features-off',
                       'status': 'passed' if flags_off(baseline) else 'failed'})
    good = all(row['status'] == 'passed' for row in checks)
    return {'schema_version': 1, 'release': catalog['release'],
            'phase': 'recheck' if baseline is not None else 'preflight',
            'technical_status': 'passed' if good else 'failed', 'acceptance': 'incomplete',
            'checks': checks, 'external_gates': [
                {'id': gate, 'status': 'not_verified'} for gate in EXTERNAL_GATES]}


class Parser(argparse.ArgumentParser):
    def error(self, message):
        fail('ARGUMENTS_INVALID')


def main(argv=None):
    try:
        parser = Parser(description=__doc__)
        commands = parser.add_subparsers(dest='action', required=True, parser_class=Parser)
        capture = commands.add_parser('collect')
        capture.add_argument('--bindings', required=True)
        capture.add_argument('--output', required=True)
        for action in ('preflight', 'recheck'):
            p = commands.add_parser(action)
            p.add_argument('--catalog', required=True)
            p.add_argument('--snapshot', required=True)
            if action == 'recheck':
                p.add_argument('--baseline', required=True)
        args = parser.parse_args(argv)
        if args.action == 'collect':
            write_private(args.output, collect(read_json(args.bindings)))
            print(json.dumps({'snapshot_created': True, 'acceptance': 'not_assessed'}))
            return 0
        report = assess(read_json(args.catalog), read_json(args.snapshot),
                        read_json(args.baseline) if args.action == 'recheck' else None)
        print(json.dumps(report, sort_keys=True))
        return 2 if report['technical_status'] == 'passed' else 1
    except CheckError as exc:
        print(json.dumps({'error': str(exc), 'acceptance': 'incomplete'}))
    except (OSError, ValueError, TypeError, KeyError, AttributeError, OverflowError):
        print(json.dumps({'error': 'INPUT_OR_IO_INVALID', 'acceptance': 'incomplete'}))
    except KeyboardInterrupt:
        print(json.dumps({'error': 'INTERRUPTED', 'acceptance': 'incomplete'}))
    return 1


if __name__ == '__main__':
    sys.exit(main())
