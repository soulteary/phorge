"""Private bounded browser hold, never browser/SMTP or crash-cleanup evidence."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time
from runtime import OwnedRuntime


def need(value):
    if not value: raise ValueError('task_browser_hold_refused')


def sha(raw): return hashlib.sha256(raw).hexdigest()


def pairs(values):
    result = {}
    for key, value in values:
        need(type(key) is str and key not in result)
        result[key] = value
    return result


def stamp(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def read_private(path):
    path = Path(path)
    need(path.is_absolute() and path.parent.resolve(strict=True) == path.parent)
    parent = path.parent.lstat()
    need(stat.S_ISDIR(parent.st_mode) and parent.st_uid == os.getuid() and stat.S_IMODE(parent.st_mode) == 0o700)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == os.getuid() and
             stat.S_IMODE(before.st_mode) == 0o600 and before.st_nlink == 1 and 0 < before.st_size <= 16384)
        raw = bytearray()
        while len(raw) < before.st_size:
            block = os.read(fd, min(4096, before.st_size - len(raw))); need(bool(block)); raw.extend(block)
        need(stamp(before) == stamp(os.fstat(fd)) == stamp(path.lstat()))
        after = path.parent.lstat()
        need((parent.st_dev, parent.st_ino, parent.st_uid, parent.st_gid, parent.st_mode) ==
             (after.st_dev, after.st_ino, after.st_uid, after.st_gid, after.st_mode))
        return bytes(raw)
    finally: os.close(fd)


def write_private(path, value):
    path = Path(path); raw = json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
    need(path.is_absolute() and path.parent.resolve(strict=True) == path.parent and len(raw) <= 16384)
    before = path.parent.lstat(); need(before.st_uid == os.getuid() and stat.S_IMODE(before.st_mode) == 0o700)
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd); need(stat.S_ISREG(info.st_mode) and info.st_uid == os.getuid() and info.st_nlink == 1 and stat.S_IMODE(info.st_mode) == 0o600)
        offset = 0
        while offset < len(raw):
            count = os.write(fd, raw[offset:]); need(count > 0); offset += count
        os.fsync(fd); end = os.fstat(fd)
        need(stamp(end) == stamp(path.lstat()) and end.st_size == len(raw) and end.st_nlink == 1)
        need(os.pread(fd, len(raw) + 1, 0) == raw and stamp(end) == stamp(os.fstat(fd)) == stamp(path.lstat()))
        after = path.parent.lstat()
        need((before.st_dev, before.st_ino, before.st_uid, before.st_gid, before.st_mode) ==
             (after.st_dev, after.st_ino, after.st_uid, after.st_gid, after.st_mode))
    finally: os.close(fd)


def process_identity(pid):
    value = OwnedRuntime._identity(pid)
    need(type(value) is dict and value.get('uid') == os.getuid())
    return {key: value[key] for key in ('pid', 'uid', 'start', 'group', 'session')}


def resource_digest(value):
    keys=('source_revision','owner_sha256','hold_id','runtime_directory','directory_identity',
          'parent_identity','task_path','object_path','task_phid_sha256','publish_task_id','notification_path')
    return sha(json.dumps({key:value[key] for key in keys},sort_keys=True,separators=(',',':')).encode())


class BrowserHold:
    def __init__(self, runtime, ready, browser, source_revision, output, seconds):
        runtime.assert_owned()
        need(type(seconds) is int and 120 <= seconds <= 600 and re.fullmatch('[a-f0-9]{40}', source_revision))
        need(re.fullmatch('PHID-TASK-[A-Za-z0-9]{20}', ready['task_phid']) and
             type(ready['publish_task_id']) is int and ready['publish_task_id'] > 0 and
             re.fullmatch('/T[1-9][0-9]*', ready['task_path']) and
             browser['owner'] == runtime.owner and browser['task_phid'] == ready['task_phid'] and
             browser['task_path'] == ready['task_path'] and browser['publish_task_id'] == ready['publish_task_id'] and
             ((browser.get('notification_key') is None and browser.get('notification_path') is None) or
              (type(browser.get('notification_key')) is str and re.fullmatch('[1-9][0-9]{0,19}',browser['notification_key']) is not None and
               browser.get('notification_path') == '/notification/individual/?key='+browser['notification_key'])) and
             browser['scope'] == 'owned-native-task-browser' and browser['browser_proved_by_preparation'] is False and
             browser.get('source_revision') == source_revision and browser.get('origin') == ready.get('callback_origin') and
             re.fullmatch(r'http://127\.0\.0\.1:[1-9][0-9]{0,4}',browser.get('origin','')) is not None and
             int(browser['origin'].rsplit(':',1)[1])<=65535 and
             browser.get('fixture_writer_close_proof',{}).get('owned_fixture_writer_connection_closed') is True and
             browser.get('fixture_writer_close_proof',{}).get('exact_original_thread_absent') is True)
        self.runtime = runtime; self.source = source_revision; self.seconds = seconds
        self.hold_id = sha((runtime.owner + source_revision + ready['task_phid'] + str(ready['publish_task_id'])).encode())
        self.state = 'held'; self.deadline = time.monotonic() + seconds
        identity = runtime.directory.lstat()
        self.receipt = {'scope': 'owned-native-task-browser-hold-v1', 'source_revision': source_revision,
            'owner_sha256': sha(runtime.owner.encode()), 'hold_id': self.hold_id,
            'runtime_directory': str(runtime.directory),
            'directory_identity': [identity.st_dev, identity.st_ino, identity.st_uid, identity.st_gid, identity.st_mode],
            'parent_identity': process_identity(os.getpid()), 'hold_seconds': seconds,
            'task_path': ready['task_path'], 'notification_path':browser['notification_path'], 'object_path': '/object/' + ready['task_phid'] + '/',
            'task_phid_sha256': sha(ready['task_phid'].encode()), 'publish_task_id': ready['publish_task_id'],
            'native_browser_manifest': str(runtime.directory / 'task-browser-native.json'),
            'browser_proved': False, 'SMTP_proved_by_hold': False, 'cleanup_proved': False,
            'parent_loss_requires_reconciliation': True, 'restart_or_reuse_authorized': False}
        self.resource_sha256=resource_digest(self.receipt)
        self.receipt['resource_identity_sha256']=self.resource_sha256
        write_private(Path(output), self.receipt)

    def poll(self):
        need(self.state == 'held')
        try:return self._poll()
        except BaseException:
            self.state='failed';raise

    def _poll(self):
        self.runtime.assert_owned(); need(self.state == 'held')
        request = self.runtime.directory / 'task-browser-finalize.json'
        if request.exists() or request.is_symlink():
            value = json.loads(read_private(request), object_pairs_hook=pairs)
            need(set(value) == {'scope', 'owner', 'source_revision', 'hold_id', 'resource_identity_sha256', 'sequence', 'action'} and
                 value['scope'] == 'owned-native-task-browser-finalize-v1' and
                 value['owner'] == self.runtime.owner and value['source_revision'] == self.source and
                 value['hold_id'] == self.hold_id and value['resource_identity_sha256']==self.resource_sha256 and
                 type(value['sequence']) is int and value['sequence']==1 and value['action'] == 'finalize')
            self.state = 'finalize-requested'; return self.state
        if time.monotonic() >= self.deadline:
            self.state = 'timed-out'; return self.state
        return 'held'

    def wait(self, fixture, server):
        try:
            while True:
                need(fixture.poll() is None and server.poll() is None)
                value = self.poll()
                if value != 'held': return value
                time.sleep(.1)
        except BaseException:
            self.state='failed';raise


def request_finalize(control, owner_file, source_revision):
    value = json.loads(read_private(Path(control)), object_pairs_hook=pairs)
    required = {'scope','source_revision','owner_sha256','hold_id','runtime_directory','directory_identity','parent_identity','hold_seconds','task_path','notification_path','object_path','task_phid_sha256','publish_task_id','native_browser_manifest','browser_proved','SMTP_proved_by_hold','cleanup_proved','parent_loss_requires_reconciliation','restart_or_reuse_authorized','resource_identity_sha256'}
    need(re.fullmatch('[a-f0-9]{40}',source_revision) is not None and value.get('source_revision')==source_revision)
    need(set(value) == required and value['scope'] == 'owned-native-task-browser-hold-v1' and
         value['browser_proved'] is False and value['cleanup_proved'] is False and value['restart_or_reuse_authorized'] is False)
    need(value['resource_identity_sha256']==resource_digest(value))
    directory = Path(value['runtime_directory'])
    need(directory.is_absolute() and directory.resolve(strict=True) == directory and directory.parent == Path('/tmp').resolve() and
         re.fullmatch('phorge-private-mail-wire-[a-f0-9]{24}', directory.name))
    need(value['native_browser_manifest']==str(directory/'task-browser-native.json'))
    info = directory.lstat(); need([info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode] == value['directory_identity'])
    need(Path(owner_file) == directory / 'owner')
    owner = read_private(Path(owner_file)).decode(); need(re.fullmatch('[a-f0-9]{64}', owner) and sha(owner.encode()) == value['owner_sha256'])
    try: live = process_identity(value['parent_identity']['pid']) == value['parent_identity']
    except (ValueError, OSError): live = False
    if not live:
        # No kill-by-recycled-PID, release, database cleanup or restart is attempted.
        return {'state':'recovery_required','cleanup_authorized':False,'restart_authorized':False}
    write_private(directory / 'task-browser-finalize.json', {'scope':'owned-native-task-browser-finalize-v1',
        'owner':owner,'source_revision':value['source_revision'],'hold_id':value['hold_id'],
        'resource_identity_sha256':value['resource_identity_sha256'],'sequence':1,'action':'finalize'})
    return {'state':'finalize_requested','cleanup_proved':False,'browser_proved':False}
