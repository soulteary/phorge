#!/usr/bin/env python3
"""Offline operations for a single-host Compose deployment. Python stdlib only."""
import argparse
from contextlib import contextmanager
import fcntl
import datetime as dt
import hashlib
import json
import math
import signal
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
SYSTEM_DATABASES = {'mysql', 'information_schema', 'performance_schema', 'sys'}


def command(args, *, output=None, input_file=None, timeout=3600):
    try:
        result = subprocess.run(args, stdout=output if output is not None else subprocess.PIPE,
                                stdin=input_file, stderr=subprocess.PIPE, timeout=timeout)
    except subprocess.TimeoutExpired:
        raise RuntimeError('Operation timed out; command category: '+args[0]) from None
    if result.returncode:
        # Commands may include credentials; never print argv/stderr.
        raise RuntimeError('Operation failed; command category: '+args[0])
    return result.stdout if output is None else b''


@contextmanager
def backup_lock(mysql_id):
    # One lock per invoking account/database, independent of TMPDIR and Compose order.
    lock_dir = Path('/tmp')/('phorge-ops-locks-'+str(os.getuid()))
    lock_dir.mkdir(mode=0o700, exist_ok=True)
    if lock_dir.is_symlink() or lock_dir.stat().st_uid != os.getuid() or lock_dir.stat().st_mode & 0o077:
        raise ValueError('Unsafe backup lock directory')
    name = hashlib.sha256(mysql_id.encode()).hexdigest()+'.lock'
    fd = os.open(lock_dir/name, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ValueError('Another backup is already running for this database') from None
        try:
            yield
        finally:
            fcntl.flock(lock, fcntl.LOCK_UN)


def wait_mysql(container, timeout=180):
    deadline = time.monotonic()+timeout
    while True:
        try:
            if sql(container, 'SELECT @@GLOBAL.skip_networking').strip() == '0':
                return
        except RuntimeError:
            pass
        if time.monotonic() >= deadline:
            raise RuntimeError('Isolated MySQL readiness timeout')
        time.sleep(1)


def cleanup_resources(container, volumes):
    failures = []
    # A failed docker run may still have created a container.
    for args in ([['docker', 'rm', '-f', container]] if container else []) + [
            ['docker', 'volume', 'rm', name] for name in reversed(volumes)]:
        try:
            command(args)
        except (RuntimeError, OSError):
            failures.append(args[-1])
    if failures:
        raise RuntimeError('Cleanup incomplete; inspect these isolated resources: '+', '.join(failures))


def helper_run(args, **kwargs):
    """Give transient archive helpers an identity for interrupted-run cleanup."""
    name = 'phorge-ops-helper-'+uuid.uuid4().hex[:12]
    try:
        return command(args[:2]+['--name', name, '--label', 'phorge.ops.helper=true']+args[2:], **kwargs)
    except BaseException:
        try:
            # rm --force is harmless for this unique helper if it still exists.
            command(['docker', 'rm', '-f', name], timeout=30)
        except (RuntimeError, OSError):
            print('Inspect interrupted helper: '+name, file=sys.stderr)
        raise


def sha(path):
    h = hashlib.sha256()
    with path.open('rb') as f:
        for block in iter(lambda: f.read(1024*1024), b''):
            h.update(block)
    return h.hexdigest()


def sync_path(path):
    fd = os.open(path, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def save_json(path, data):
    fd, name = tempfile.mkstemp(prefix='.'+path.name+'-', dir=path.parent)
    temporary = Path(name)
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump(data, f, indent=2, ensure_ascii=False, allow_nan=False)
            f.flush()
            os.fsync(f.fileno())
        temporary.replace(path)
        sync_path(path.parent)
    finally:
        temporary.unlink(missing_ok=True)


def safe_tar(path):
    with tarfile.open(path) as archive:
        for member in archive:
            name = PurePosixPath(member.name)
            if name.is_absolute() or '..' in name.parts:
                raise ValueError('Unsafe archive path')
            if not (member.isfile() or member.isdir() or member.issym() or member.islnk()):
                raise ValueError('Unsupported archive special file')
            if member.issym() or member.islnk():
                link = PurePosixPath(member.linkname)
                if link.is_absolute() or '..' in link.parts:
                    raise ValueError('Unsafe archive link')


def tar_inventory(path):
    """Compare restored bytes, links, permissions and POSIX ownership."""
    inventory = []
    with tarfile.open(path) as archive:
        for member in archive:
            content = None
            if member.isfile():
                h = hashlib.sha256()
                with archive.extractfile(member) as source:
                    for block in iter(lambda: source.read(1024*1024), b''):
                        h.update(block)
                content = h.hexdigest()
            inventory.append((str(PurePosixPath(member.name)), member.type.decode(),
                              member.mode, member.uid, member.gid, member.linkname, content))
    return sorted(inventory)


def timestamp(value):
    if not isinstance(value, str):
        raise ValueError('Missing timestamp')
    parsed = dt.datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        raise ValueError('Timestamp must include timezone')
    return parsed.timestamp()


def read_receipt(directory, manifest):
    path = directory/'restore-test.json'
    if path.is_symlink():
        raise ValueError('Symlink restore receipt is unsupported')
    receipt = json.loads(path.read_text())
    if not isinstance(receipt, dict) or type(receipt.get('format')) is not int or receipt.get('format') != 1 or receipt.get('state') != 'restore-tested' or receipt.get('manifestSHA256') != sha(directory/'manifest.json'):
        raise ValueError('Invalid or stale restore receipt')
    tested = timestamp(receipt.get('testedAt'))
    if tested < timestamp(manifest['createdAt']):
        raise ValueError('Restore test predates backup')
    if type(receipt.get('databases')) is not int or receipt['databases'] != len(manifest['databases']) or type(receipt.get('volumes')) is not int or receipt['volumes'] != len(manifest['volumes']):
        raise ValueError('Restore receipt inventory mismatch')
    if receipt.get('businessIntegrity') != 'not_verified' or receipt.get('scope') != 'checksum-volume-bytes-and-permissions-sql-import-schema-counts':
        raise ValueError('Unsupported restore evidence scope')
    return receipt


def verify(directory):
    directory = directory.resolve()
    if (directory/'manifest.json').is_symlink():
        raise ValueError('Symlink manifest is unsupported')
    manifest = json.loads((directory/'manifest.json').read_text())
    if not isinstance(manifest, dict):
        raise ValueError('Invalid backup manifest')
    timestamp(manifest.get('createdAt'))
    if type(manifest.get('format')) is not int or manifest.get('format') != 1 or manifest.get('state') != 'complete':
        raise ValueError('Backup incomplete or unsupported')
    files = manifest.get('files', {})
    databases = manifest.get('databases')
    if not isinstance(files, dict) or not isinstance(databases, list) or not databases or any(not isinstance(n, str) for n in databases) or len(set(databases)) != len(databases):
        raise ValueError('Invalid database/file inventory')
    if 'database.sql' not in files:
        raise ValueError('Missing database backup')
    for name, checksum in files.items():
        if not isinstance(name, str) or name in ('.', '..', 'manifest.json', 'restore-test.json') or not re.fullmatch(r'[A-Za-z0-9_.-]+', name) or not isinstance(checksum, str) or not re.fullmatch(r'[0-9a-f]{64}', checksum):
            raise ValueError('Unsafe backup member')
        path = directory/name
        if path.is_symlink() or not path.is_file() or sha(path) != checksum:
            raise ValueError('Backup integrity check failed: '+name)
        if name.endswith('.tar'):
            safe_tar(path)
    if not isinstance(manifest.get('mysqlImage'), str) or not re.fullmatch(r'(?:sha256:)?[0-9a-f]{64}', manifest['mysqlImage']):
        raise ValueError('Restore requires an immutable local MySQL image ID')
    counts = manifest.get('tablesPerDatabase', {})
    if not isinstance(counts, dict) or set(counts) != set(manifest['databases']) or any(not re.fullmatch(r'[A-Za-z0-9_$]+', n) or type(counts[n]) is not int or counts[n] < 0 for n in counts):
        raise ValueError('Invalid database inventory')
    volumes = manifest.get('volumes')
    if not isinstance(volumes, list) or any(not isinstance(v, dict) or not isinstance(v.get('artifact'), str) or not re.fullmatch(r'volume-[0-9]+\.tar', v['artifact']) or not isinstance(v.get('sourceName'), str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*', v['sourceName']) for v in volumes):
        raise ValueError('Invalid volume inventory')
    if len({v['artifact'] for v in volumes}) != len(volumes) or len({v['sourceName'] for v in volumes}) != len(volumes):
        raise ValueError('Duplicate volume inventory')
    expected = {'database.sql'} | {v['artifact'] for v in volumes}
    if not expected.issubset(files):
        raise ValueError('Missing volume checksum')
    return manifest

def require_volume_path(container, value, key):
    if not isinstance(value, str):
        raise ValueError('Invalid persistent path: '+key)
    data_path = PurePosixPath(value)
    candidates = [m for m in container.get('Mounts', []) if data_path == PurePosixPath(m['Destination']) or PurePosixPath(m['Destination']) in data_path.parents]
    covered = max(candidates, key=lambda m: len(PurePosixPath(m['Destination']).parts), default={})
    if not data_path.is_absolute() or '..' in data_path.parts or covered.get('Type') != 'volume':
        raise ValueError('File persistence path is outside captured volumes: '+key)



class Stack:
    def __init__(self, files):
        self.files = [Path(p).resolve() for p in files]
        self.base = ['docker', 'compose']
        for path in self.files:
            self.base += ['-f', str(path)]

    def inspect(self):
        ids = command(self.base+['ps', '-a', '-q']).decode().split()
        if not ids:
            raise ValueError('No Compose containers found')
        containers = json.loads(command(['docker', 'inspect', *ids]))
        mysql = [c for c in containers if c['Config'].get('Labels', {}).get('com.docker.compose.service') == 'mysql']
        if len(mysql) != 1 or not mysql[0]['State']['Running']:
            raise ValueError('Exactly one running bundled mysql is required')
        volumes = {}
        for c in containers:
            service = c['Config'].get('Labels', {}).get('com.docker.compose.service', '')
            if service in ('integrations', 'gorge-integrations', 'gorge-search'):
                raise ValueError('External integration/search state requires a separate coordinated backup adapter')
            env = dict(e.split('=', 1) for e in c['Config'].get('Env', []) if '=' in e)
            if env.get('GORGE_TASKQUEUE_BACKEND', 'mysql') != 'mysql' or env.get('GORGE_FILE_S3_BUCKET'):
                raise ValueError('Redis or S3 requires a separate coordinated backup procedure')
            for key, value in env.items():
                if value and (key.endswith('_MYSQL_HOST') or key == 'MYSQL_HOST') and value != 'mysql':
                    raise ValueError('External MySQL node is outside bundled backup scope')
                if value and key.endswith('_DSN') and not re.fullmatch(r'.*@tcp\(mysql:3306\)/[A-Za-z0-9_$]+(?:\?[^\r\n]*)?', value):
                    raise ValueError('External or unsupported DSN is outside backup scope')
            for key in ('GORGE_FILE_LOCAL_DISK_PATH', 'GORGE_FILE_UPLOAD_ROOT'):
                if env.get(key):
                    require_volume_path(c, env[key], key)
            for mount in c.get('Mounts', []):
                if mount['Type'] == 'bind' and mount.get('RW'):
                    raise ValueError('Writable bind mount requires an explicit backup adapter')
                if mount['Type'] == 'volume' and c['Id'] != mysql[0]['Id']:
                    volumes[mount['Name']] = mount
        # Refuse writers outside the captured container inventory.
        other_ids = command(['docker', 'ps', '-q']).decode().split()
        others = json.loads(command(['docker', 'inspect', *other_ids])) if other_ids else []
        all_persistent = set(volumes) | {m['Name'] for m in mysql[0].get('Mounts', []) if m['Type'] == 'volume'}
        for c in others:
            if c['Id'] not in {known['Id'] for known in containers}:
                if any(m.get('Name') in all_persistent and m.get('RW') for m in c.get('Mounts', [])):
                    raise ValueError('An untracked running container can write the backup volumes')
        for c in containers:
            if c['Config'].get('Labels', {}).get('com.docker.compose.service') == 'phorge':
                raw = command(['docker', 'cp', c['Id']+':/opt/phorge/phorge/conf/local/local.json', '-'])
                import io
                with tarfile.open(fileobj=io.BytesIO(raw)) as copied:
                    members = copied.getmembers()
                    if len(members) != 1 or not members[0].isfile():
                        raise ValueError('Phorge local configuration is not a regular file')
                    with copied.extractfile(members[0]) as config:
                        local = json.load(config)
                if not isinstance(local, dict):
                    raise ValueError('Invalid Phorge local configuration')
                if local.get('mysql.host') and local['mysql.host'] != 'mysql' or local.get('mysql.port') is not None and str(local['mysql.port']) != '3306':
                    raise ValueError('Phorge local configuration uses an external database')
                require_volume_path(c, '/opt/phorge/phorge/conf/local/local.json', 'phorge config')
                for key in ('storage.local-disk.path','repository.default-local-path'):
                    if local.get(key): require_volume_path(c, local[key], key)
                if local.get('storage.s3.bucket') or local.get('cluster.databases'):
                    raise ValueError('Legacy S3 or custom database cluster requires a separate backup adapter')
        return containers, mysql[0], volumes


def assert_quiescent(stack, containers, mysql, volumes):
    current, current_mysql, current_volumes = stack.inspect()
    if current_mysql['Id'] != mysql['Id'] or {c['Id'] for c in current} != {c['Id'] for c in containers} or set(current_volumes) != set(volumes):
        raise ValueError('Stack topology changed during backup')
    if any(c['State']['Running'] for c in current if c['Id'] != mysql['Id']):
        raise ValueError('Application writer restarted during backup')
    scheduler_on = sql(mysql['Id'], "SHOW VARIABLES LIKE 'event_scheduler'").strip().endswith('\tON')
    if scheduler_on and int(sql(mysql['Id'], "SELECT COUNT(*) FROM information_schema.events WHERE status='ENABLED'").strip()):
        raise ValueError('MySQL event writer became active during backup')


def sql(container, query):
    return command(['docker', 'exec', container, 'sh', '-c',
                    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "$1"', 'sh', query], timeout=30).decode()


def backup(args):
    if not args.exclusive_access:
        raise ValueError('Use --exclusive-access only after excluding external CLI/DB writers')
    _, mysql, _ = Stack(args.compose).inspect()
    with backup_lock(mysql['Id']):
        backup_locked(args, expected_mysql=mysql['Id'])


def backup_locked(args, expected_mysql=None):
    if not args.exclusive_access:
        raise ValueError('Use --exclusive-access only after excluding external CLI/DB writers')
    stack = Stack(args.compose)
    containers, mysql, volumes = stack.inspect()
    if expected_mysql is not None and mysql['Id'] != expected_mysql:
        raise ValueError('Database container changed while acquiring backup lock')
    running = [c['Id'] for c in containers if c['State']['Running'] and c['Id'] != mysql['Id']]
    # Reject active events; they keep writing when application containers stop.
    scheduler_on = sql(mysql['Id'], "SHOW VARIABLES LIKE 'event_scheduler'").strip().endswith('\tON')
    active_events = int(sql(mysql['Id'], "SELECT COUNT(*) FROM information_schema.events WHERE status='ENABLED'").strip())
    if scheduler_on and active_events:
        raise ValueError('Disable active MySQL event_scheduler writers before backup')
    directory = Path(args.directory).resolve()
    if directory == ROOT or ROOT in directory.parents:
        raise ValueError('Store credential-bearing backups outside the repository')
    directory.mkdir(mode=0o700, parents=True, exist_ok=False)
    directory.chmod(0o700)
    manifest = {'format': 1, 'state': 'incomplete', 'createdAt': dt.datetime.now(dt.timezone.utc).isoformat(),
                'mysqlImage': mysql['Image'], 'images': sorted({c['Image'] for c in containers}),
                'originalRunningContainers': running, 'volumes': [], 'databases': [], 'files': {}, 'scope': 'bundled-mysql-and-named-volumes'}
    save_json(directory/'manifest.json', manifest)
    try:
        if running:
            command(['docker', 'stop', '--time', '60', *running])
        stopped = json.loads(command(['docker', 'inspect', *running])) if running else []
        if any(c['State']['Running'] for c in stopped):
            raise ValueError('Application writers are still running')
        if any(c['State'].get('OOMKilled') or c['State'].get('ExitCode') == 137 for c in stopped):
            raise ValueError('Writer shutdown was forced; verify crash recovery before backup')
        assert_quiescent(stack, containers, mysql, volumes)
        databases = [n for n in sql(mysql['Id'], 'SHOW DATABASES').splitlines() if n not in SYSTEM_DATABASES]
        if not databases or any(not re.fullmatch(r'[A-Za-z0-9_$]+', n) for n in databases):
            raise ValueError('No user databases or unsupported database name')
        manifest['databases'] = databases
        manifest['tablesPerDatabase'] = {n: int(sql(mysql['Id'], "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"+n+"' AND table_type='BASE TABLE'").strip()) for n in databases}
        with (directory/'database.sql').open('wb') as f:
            command(['docker', 'exec', mysql['Id'], 'sh', '-c',
                     'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --events --triggers --hex-blob --set-gtid-purged=OFF --databases "$@"', 'sh', *databases], output=f)
            f.flush(); os.fsync(f.fileno())
        for index, name in enumerate(sorted(volumes)):
            artifact = 'volume-'+str(index)+'.tar'
            with (directory/artifact).open('wb') as f:
                helper_run(['docker', 'run', '--rm', '--network', 'none', '--read-only',
                         '--mount', 'type=volume,src='+name+',dst=/snapshot,readonly',
                         '--entrypoint', 'tar', mysql['Image'], '-cpf', '-', '-C', '/snapshot', '.'], output=f)
                f.flush(); os.fsync(f.fileno())
            safe_tar(directory/artifact)
            manifest['volumes'].append({'sourceName': name, 'artifact': artifact})
        # Preserve actual container environment, including shell-exported overrides.
        save_json(directory/'container-config.json', [
            {'id': c['Id'], 'image': c['Image'], 'config': c['Config'], 'mounts': c.get('Mounts', [])}
            for c in containers])
        # Deployment config volume carries credentials. Protect the entire bundle.
        for index, path in enumerate(stack.files):
            shutil.copyfile(path, directory/('compose-'+str(index)+'.yaml'))
        env = stack.files[0].parent/'.env'
        if env.exists():
            shutil.copyfile(env, directory/'environment.env')
        for path in directory.iterdir():
            path.chmod(0o600)
            sync_path(path)
            if path.name != 'manifest.json':
                manifest['files'][path.name] = sha(path)
        assert_quiescent(stack, containers, mysql, volumes)
        manifest['state'] = 'complete'
        save_json(directory/'manifest.json', manifest)
        verify(directory)
    finally:
        # Resume only containers that were running before, also after partial failure.
        failures = []
        for container in running:
            try:
                command(['docker', 'start', container])
            except (RuntimeError, OSError):
                failures.append(container)
        if failures:
            raise RuntimeError('Application restart incomplete; inspect containers: '+', '.join(failures))
    print(json.dumps({'state': 'complete', 'databases': len(databases), 'volumes': len(volumes), 'originalContainersStarted': True}))


def restore_test(args):
    directory = Path(args.directory).resolve()
    manifest = verify(directory)
    manifest_digest = sha(directory/'manifest.json')
    stage = Path(args.destination).resolve() if getattr(args, 'destination', None) else None
    if stage:
        if stage == ROOT or ROOT in stage.parents:
            raise ValueError('Restore staging must remain outside the repository')
        stage.mkdir(mode=0o700, parents=True, exist_ok=False)
        stage.chmod(0o700)
    prefix = 'phorge-restore-'+uuid.uuid4().hex[:12]
    created = []
    container = prefix+'-mysql'
    started = False
    retained = False
    root_password = uuid.uuid4().hex
    journal = (stage or directory)/('.'+prefix+'-resources.json')
    def record_resources(state='incomplete'):
        save_json(journal, {'format':1, 'state':state, 'mysqlContainer':container if started else None,
                            'volumes':created, 'sourceBackup':str(directory)})
    record_resources()
    if stage:
        save_json(stage/'credentials.json', {'MYSQL_ROOT_PASSWORD':root_password})
    try:
        for volume in manifest['volumes']:
            name = prefix+'-'+str(len(created))
            created.append(name); record_resources()
            command(['docker', 'volume', 'create', name])
            with (directory/volume['artifact']).open('rb') as f:
                helper_run(['docker', 'run', '--rm', '-i', '--network', 'none', '--read-only',
                         '--mount', 'type=volume,src='+name+',dst=/restore', '--entrypoint', 'tar',
                         manifest['mysqlImage'], '-xpf', '-', '-C', '/restore'], input_file=f)
            with tempfile.TemporaryDirectory(prefix='phorge-volume-check-') as temp:
                restored = Path(temp)/'restored.tar'
                with restored.open('wb') as f:
                    helper_run(['docker', 'run', '--rm', '--network', 'none', '--read-only',
                             '--mount', 'type=volume,src='+name+',dst=/restored,readonly',
                             '--entrypoint', 'tar', manifest['mysqlImage'], '-cpf', '-', '-C', '/restored', '.'], output=f)
                if tar_inventory(restored) != tar_inventory(directory/volume['artifact']):
                    raise ValueError('Restored file bytes, identity or permissions differ')
        db_volume = prefix+'-database'
        created.append(db_volume); record_resources()
        command(['docker', 'volume', 'create', db_volume])
        # No published ports, no application consumers, no outgoing network.
        started = True
        record_resources()
        command(['docker', 'run', '-d', '--name', container, '--network', 'none',
                 '-e', 'MYSQL_ROOT_PASSWORD='+root_password,
                 '--mount', 'type=volume,src='+db_volume+',dst=/var/lib/mysql',
                 manifest['mysqlImage'], '--event-scheduler=OFF'])
        wait_mysql(container)
        with (directory/'database.sql').open('rb') as f:
            command(['docker', 'exec', '-i', container, 'sh', '-c',
                     'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'], input_file=f)
        for name, count in manifest['tablesPerDatabase'].items():
            restored = int(sql(container, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"+name+"' AND table_type='BASE TABLE'").strip())
            if restored != count:
                raise ValueError('Restored schema count mismatch')
        verify(directory)
        if sha(directory/'manifest.json') != manifest_digest:
            raise ValueError('Backup changed during restore; evidence refused')
        receipt = {'format': 1, 'state': 'restore-tested', 'testedAt': dt.datetime.now(dt.timezone.utc).isoformat(),
                   'manifestSHA256': sha(directory/'manifest.json'), 'databases': len(manifest['databases']),
                   'volumes': len(manifest['volumes']), 'businessIntegrity': 'not_verified',
                   'scope': 'checksum-volume-bytes-and-permissions-sql-import-schema-counts'}
        save_json(directory/'restore-test.json', receipt)
        if stage:
            save_json(stage/'credentials.json', {'MYSQL_ROOT_PASSWORD': root_password})
            save_json(stage/'restore.json', {'format': 1, 'state': 'isolated-restored',
                'mysqlContainer': container, 'mysqlDataVolume': db_volume,
                'applicationVolumes': [{'artifact': v['artifact'], 'sourceName': v['sourceName'], 'restoredVolume': created[i]} for i,v in enumerate(manifest['volumes'])],
                'image': manifest['mysqlImage'], 'manifestSHA256': sha(directory/'manifest.json'),
                'applicationAccounts': 'not_provisioned', 'businessIntegrity': 'not_verified',
                'consumersStarted': False, 'network': 'none'})
            record_resources('isolated-restored')
            retained = True
    finally:
        if not retained:
            cleanup_resources(container if started else None, created)
            journal.unlink(missing_ok=True)
    print(json.dumps(receipt))


def evaluate(report, previous=None, *, now=None, bundle=None):
    now = time.time() if now is None else now
    alerts = []
    def alert(code, severity, detail):
        alerts.append({'code': code, 'severity': severity, 'detail': detail})
    if previous is not None and not isinstance(previous, dict):
        raise ValueError('Previous audit must be an object')
    if not isinstance(report, dict):
        raise ValueError('Audit must be an object')
    required_domains = {'scheduler', 'cleanup', 'files', 'deletions', 'queue', 'feedOutbox', 'mailOutbox', 'inboundReceipts', 'persistentCapacity', 'fact'}
    if report.get('reportVersion') != 1 or not isinstance(report.get('services'), dict) or not isinstance(report.get('inventory'), dict) or not required_domains.issubset(report.get('inventory', {})):
        alert('AUDIT_INCOMPLETE', 'critical', 'Required audit sections or report version are missing')
    epoch = report.get('generatedEpoch')
    if type(epoch) not in (int, float) or not 0 <= epoch <= 10**15 or now-epoch > 900 or epoch > now+60:
        alert('AUDIT_STALE', 'critical', 'Audit missing, stale or future-dated')
    if report.get('schedulerIdentityMatch') is False:
        alert('SCHEDULER_IDENTITY_MISMATCH', 'critical', 'Scheduler and queue database identities differ')
    if report.get('queuePhysicalIdentityMatch') is False:
        alert('QUEUE_IDENTITY_MISMATCH', 'critical', 'PHP and queue database identities differ')
    def object_at(value, path):
        if not isinstance(value, dict):
            alert('AUDIT_INVALID', 'critical', path)
            return {}
        return value
    def nested(value, *keys):
        for key in keys:
            value = object_at(value, 'audit.'+'.'.join(keys)).get(key, {})
        return object_at(value, 'audit.'+'.'.join(keys))
    def counter(value):
        if type(value) is int and value >= 0:
            return value
        if isinstance(value, str) and re.fullmatch(r'[0-9]{1,30}', value):
            return int(value)
        raise ValueError('Invalid nonnegative operational counter')
    services = nested(report, 'services')
    if not {'render','conduit','image','file','taskqueue','webhook','db','mailer','search','integrations'}.issubset(services):
        alert('AUDIT_INCOMPLETE', 'critical', 'Required service sections are missing')
    if nested(services, 'taskqueue').get('configured') and report.get('queuePhysicalIdentityMatch') is None:
        alert('QUEUE_IDENTITY_UNOBSERVED', 'critical', 'Configured queue identity was not observed')
    domains = nested(report, 'inventory')
    for name in required_domains & domains.keys():
        item = domains[name]
        if not isinstance(item, dict) or item.get('state') not in ('observed', 'unavailable', 'unknown') or item.get('state') == 'observed' and 'data' not in item:
            alert('AUDIT_INVALID', 'critical', 'audit.inventory.'+name)
    uploads = nested(services, 'file', 'uploadObservation')
    uploads_disabled = uploads.get('state') == 'observed' and nested(uploads, 'capabilities').get('enabled') is False
    def scan(value, path='audit', depth=0):
        if depth > 64:
            raise ValueError('Audit nesting exceeds supported depth')
        if path == 'audit.uploadUsage' and uploads_disabled:
            return
        if isinstance(value, dict):
            if value.get('state') == 'unknown':
                alert('UNKNOWN_EFFECT', 'critical', path)
            if value.get('state') == 'unavailable':
                alert('OBSERVATION_UNAVAILABLE', 'warning', path)
            for key, child in value.items():
                codes = {'unknown':'UNRESOLVED_STATE', 'invalidmanifests':'UNRESOLVED_STATE',
                         'unknowneffects':'UNKNOWN_EFFECT', 'unknowninbound':'UNKNOWN_EFFECT',
                         'oldestoverdueseconds':'BACKLOG_OVERDUE'}
                if key.lower() in codes:
                    try:
                        count = counter(child)
                        if count > (300 if key.lower() == 'oldestoverdueseconds' else 0):
                            alert(codes[key.lower()], 'critical', path+'.'+key)
                    except ValueError:
                        alert('COUNTER_INVALID', 'critical', path+'.'+key)
                if key == 'truncated' and child is True:
                    alert('CAPACITY_PARTIAL', 'warning', path)
                scan(child, path+'.'+key, depth+1)
        elif isinstance(value, list):
            for i, child in enumerate(value): scan(child, path+'.'+str(i), depth+1)
    scan(report)
    if nested(services, 'taskqueue').get('configured') and nested(services, 'worker', 'observation').get('state') != 'observed':
        alert('WORKER_UNOBSERVED', 'warning', 'Queue is configured but Worker was not observed; supply a runtime descriptor')
    inventory = nested(domains, 'persistentCapacity', 'data')
    old = nested(previous or {}, 'inventory', 'persistentCapacity', 'data')
    prior_epoch = (previous or {}).get('generatedEpoch')
    elapsed = epoch-prior_epoch if type(epoch) in (int,float) and 0 <= epoch <= 10**15 and type(prior_epoch) in (int,float) and 0 <= prior_epoch <= 10**15 and epoch <= now+60 else 0
    capacity = []
    for name, item in inventory.items():
        item = object_at(item, 'audit.inventory.persistentCapacity.'+name)
        if item.get('state') != 'observed': continue
        data = nested(item, 'data')
        try:
            size = counter(data.get('approximateDataBytes'))+counter(data.get('approximateIndexBytes'))
            records = counter(data.get('records'))
        except ValueError:
            alert('CAPACITY_INVALID', 'critical', name)
            continue
        row = {'table': name, 'records': records, 'allocatedBytesApprox': size}
        before = object_at(old.get(name, {}), 'previous.capacity.'+name)
        if elapsed > 0 and item.get('physicalDatabaseIdentity') and item.get('physicalDatabaseIdentity') == before.get('physicalDatabaseIdentity') and before.get('state') == 'observed':
            prior = nested(before, 'data')
            try:
                rate = (size-counter(prior.get('approximateDataBytes'))-counter(prior.get('approximateIndexBytes')))*86400/elapsed
                if math.isfinite(rate):
                    row['netAllocatedBytesPerDay'] = round(rate, 2)
            except (ValueError, OverflowError):
                alert('PREVIOUS_CAPACITY_INVALID', 'warning', name)
        capacity.append(row)
    if not uploads_disabled:
        usage = nested(report, 'uploadUsage')
        volume = object_at(usage.get('data', usage), 'audit.uploadUsage.data')
        free = volume.get('availableBytes')
        if free is not None:
            try:
                if counter(free) < 1024**3:
                    alert('VOLUME_LOW_SPACE', 'critical', 'Upload volume has less than 1 GiB available')
            except ValueError:
                alert('VOLUME_CAPACITY_INVALID', 'critical', 'Invalid available bytes')
        elif uploads.get('state') == 'observed' and nested(uploads, 'capabilities').get('enabled') is True:
            alert('VOLUME_CAPACITY_UNOBSERVED', 'critical', 'Enabled upload capacity is missing')
    if bundle:
        try:
            manifest = verify(bundle)
            created = timestamp(manifest['createdAt'])
            if created > now+60:
                alert('BACKUP_FUTURE','critical','Backup timestamp is in the future')
            if now-created > 86400: alert('BACKUP_STALE','critical','Last supplied backup is older than 24 hours')
            try:
                receipt = read_receipt(bundle, manifest)
                tested = timestamp(receipt['testedAt'])
                if tested > now+60:
                    alert('RESTORE_TEST_FUTURE', 'critical', 'Restore test timestamp is in the future')
                if now-tested > 30*86400:
                    alert('RESTORE_TEST_STALE','warning','Restore test is older than 30 days')
            except (OSError, ValueError, KeyError, TypeError, AttributeError):
                alert('RESTORE_UNVERIFIED','critical','Restore evidence cannot be verified')
        except (OSError, ValueError, KeyError, TypeError, AttributeError, tarfile.TarError):
            alert('BACKUP_UNVERIFIED','critical','Backup or restore receipt cannot be verified')
    else:
        alert('BACKUP_UNOBSERVED','warning','No backup bundle supplied')
    return {'format': 1, 'alerts': alerts, 'capacity': capacity,
            'businessArchive': {'mode':'plan-only','onlineDeletionAllowed':False,
                'requires':['preserve identity/digest/terminal state','prove late replay safety','paired restore test','bounded export with integrity verification']}}


def archive_bundle(source, destination):
    source = source.resolve()
    destination = destination.resolve()
    if source == destination or source in destination.parents or destination in source.parents:
        raise ValueError('Archive source and destination must not overlap')
    with backup_lock('archive-'+str(destination)):
        archive_locked(source, destination)


def archive_locked(source, destination):
    manifest = verify(source)
    destination = destination.resolve()
    if destination.exists():
        raise ValueError('Archive destination already exists')
    if destination == ROOT or ROOT in destination.parents:
        raise ValueError('Archive must remain outside the repository')
    destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    temporary = destination.with_name(destination.name+'.partial-'+uuid.uuid4().hex)
    temporary.mkdir(mode=0o700)
    try:
        for name in ['manifest.json', *manifest['files']]:
            shutil.copyfile(source/name, temporary/name)
            (temporary/name).chmod(0o600)
            sync_path(temporary/name)
        if (source/'restore-test.json').exists():
            receipt = read_receipt(source, manifest)
            save_json(temporary/'restore-test.json', receipt)
        verify(temporary)
        if (temporary/'restore-test.json').exists():
            read_receipt(temporary, manifest)
        sync_path(temporary)
        temporary.rename(destination)
        sync_path(destination.parent)
        print('{"state":"archived","sourceRetained":true}')
    except BaseException:
        if temporary.exists():
            shutil.rmtree(temporary)
        raise


def main():
    os.umask(0o077)
    def interrupted(signum, frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, interrupted)
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='action', required=True)
    b = sub.add_parser('backup')
    b.add_argument('directory'); b.add_argument('--compose', action='append', required=True)
    b.add_argument('--exclusive-access', action='store_true')
    for action in ['verify', 'restore-test']:
        sub.add_parser(action).add_argument('directory')
    stage = sub.add_parser('restore')
    stage.add_argument('directory'); stage.add_argument('destination')
    a = sub.add_parser('archive')
    a.add_argument('directory'); a.add_argument('destination')
    r = sub.add_parser('report')
    r.add_argument('audit'); r.add_argument('--previous'); r.add_argument('--backup')
    args = parser.parse_args()
    try:
        if args.action == 'backup': backup(args)
        elif args.action == 'verify':
            verify(Path(args.directory)); print('{"state":"verified"}')
        elif args.action in ('restore-test', 'restore'): restore_test(args)
        elif args.action == 'archive': archive_bundle(Path(args.directory).resolve(), Path(args.destination))
        else:
            report = evaluate(json.loads(Path(args.audit).read_text()),
                              json.loads(Path(args.previous).read_text()) if args.previous else None,
                              bundle=Path(args.backup) if args.backup else None)
            print(json.dumps(report, indent=2, ensure_ascii=False))
            return 2 if any(a['severity']=='critical' for a in report['alerts']) else (1 if report['alerts'] else 0)
    except KeyboardInterrupt:
        print('Operations interrupted; inspect the saved recovery metadata', file=sys.stderr)
        return 130
    except (ValueError, RuntimeError, OSError, KeyError, TypeError, AttributeError, OverflowError, RecursionError, tarfile.TarError) as error:
        message = str(error) if isinstance(error, RuntimeError) else type(error).__name__+'; inspect local inputs and recovery metadata'
        print('Operations failed: '+message, file=sys.stderr)
        return 3
    return 0


if __name__ == '__main__':
    sys.exit(main())
