"""Linux-only, test-only owned processes; no container engine or deployment API."""
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlsplit

ROLES = frozenset({'file', 'taskqueue', 'mailer', 'observer', 'owlmail'})
SOURCE_FILES = frozenset({
    'support/governance/native-organization/test-native-organization-mysql.php',
    'support/governance/notification/pending-wire-hold.php',
    'support/governance/notification/pending-wire-protocol.php',
    'support/governance/notification/pending-control.php',
    'support/governance/notification/wire-hold.php',
    'support/governance/notification/control.php',
    'support/governance/notification/security-wire-cases.php',
    'support/governance/notification/invite-capability-cases.php',
    'support/governance/notification/owned-config.php',
    'support/governance/notification/race-owned-config.php',
    'support/governance/notification/router.php',
    'support/governance/completion/fixture.php',
    'src/applications/metamta/storage/PhabricatorMetaMTAMail.php',
    'src/applications/metamta/conduit/PhabricatorMailDeliveryConduitAPIMethod.php',
    'src/infrastructure/cluster/PhabricatorGorgeMailerClient.php',
    'support/governance/notification/owned-config.php',
    'support/governance/notification/owlmail-sink.php',
    'support/governance/notification/callback-observer.go',
    'support/governance/notification/smtp-fault.go',
    'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php',
    'src/infrastructure/governance/notification/PhorgePrivateMailSourcePendingException.php',
})


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def private_file(path, max_size=1024 * 1024):
    path = Path(path)
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600 or info.st_uid != os.getuid() or info.st_size > max_size:
        raise ValueError('private_file_scope_refused')
    return path


def validate_config(data):
    keys = {'schema', 'engine', 'source_root', 'source_revision', 'source_sha256',
            'php', 'binaries', 'gorge_revision', 'database', 'expected_pure_methods'}
    if type(data) is not dict or set(data) != keys or type(data['schema']) is not int or data['schema'] != 1 or data['engine'] != 'linux-local':
        raise ValueError('configuration_shape_refused')
    source = Path(data['source_root'])
    if source.is_symlink() or not source.is_dir() or str(source.resolve()) != str(source):
        raise ValueError('source_scope_refused')
    if not re.fullmatch('[a-f0-9]{40}', data['source_revision']) or not re.fullmatch('[a-f0-9]{40}', data['gorge_revision']):
        raise ValueError('source_revision_refused')
    if type(data['source_sha256']) is not dict or not SOURCE_FILES <= set(data['source_sha256']):
        raise ValueError('required_source_hashes_missing')
    for relative, expected in data['source_sha256'].items():
        path = source / relative
        if not isinstance(relative, str) or Path(relative).is_absolute() or '..' in Path(relative).parts or path.is_symlink() or not path.resolve().is_relative_to(source) or not re.fullmatch('[a-f0-9]{64}', expected) or digest(path) != expected:
            raise ValueError('source_content_mismatch')
    for name in ('local.json', 'deployment.json', 'ENVIRONMENT'):
        if (source / 'conf/local' / name).exists():
            raise ValueError('deployment_configuration_refused')
    php = Path(data['php'])
    if not php.is_absolute() or not php.is_file() or not os.access(php, os.X_OK):
        raise ValueError('php_binary_refused')
    if set(data['binaries']) != ROLES:
        raise ValueError('binary_roles_refused')
    for role, binary in data['binaries'].items():
        if type(binary) is not dict or set(binary) != {'path', 'sha256', 'source_revision'}:
            raise ValueError('binary_shape_refused')
        path = Path(binary['path'])
        if not path.is_absolute() or path.is_symlink() or not path.is_file() or not os.access(path, os.X_OK) or not re.fullmatch('[a-f0-9]{64}', binary['sha256']) or digest(path) != binary['sha256']:
            raise ValueError('binary_content_mismatch')
        if not re.fullmatch('[a-f0-9]{40}', binary['source_revision']):
            raise ValueError('binary_source_revision_refused')
        if role == 'observer' and binary['source_revision'] != data['source_revision']:
            raise ValueError('observer_source_revision_mismatch')
        if role in ('file', 'taskqueue', 'mailer') and binary['source_revision'] != data['gorge_revision']:
            raise ValueError('paired_gorge_revision_mismatch')
    db = data['database']
    if type(db) is not dict or set(db) != {'host', 'port', 'database', 'user', 'password_env'} or db['host'] != '127.0.0.1' or type(db['port']) is not int or not 1 <= db['port'] <= 65535 or db['database'] != 'governance_fixture_bootstrap' or not re.fullmatch('[A-Za-z0-9_]+', db['user']) or not re.fullmatch('[A-Z][A-Z0-9_]+', db['password_env']):
        raise ValueError('disposable_database_profile_refused')
    if type(data['expected_pure_methods']) is not int or data['expected_pure_methods'] < 1:
        raise ValueError('pure_obligation_refused')
    return data


def load_config(path):
    if os.environ.get('ATOMIC_TEST_DISPOSABLE') != '1':
        raise ValueError('explicit_disposable_test_opt_in_required')
    for key in ('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG', 'PHORGE_CONTROL_PLANE'):
        if os.environ.get(key, '') != '':
            raise ValueError('deployment_selector_refused')
    return validate_config(json.loads(private_file(path).read_text()))


def loopback_request(url, method='GET', payload=None, headers=None):
    parsed = urlsplit(url)
    if parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or parsed.username or parsed.password or parsed.fragment or not parsed.port:
        raise ValueError('loopback_request_refused')
    conn = http.client.HTTPConnection('127.0.0.1', parsed.port, timeout=30)
    try:
        body = None if payload is None else json.dumps(payload, separators=(',', ':')).encode()
        conn.request(method, parsed.path + ('?' + parsed.query if parsed.query else ''), body=body, headers=headers or {})
        response = conn.getresponse()
        raw = response.read(8 * 1024 * 1024 + 1)
        if len(raw) > 8 * 1024 * 1024:
            raise ValueError('http_response_budget')
        return response.status, raw
    finally:
        conn.close()


def _rename_noreplace(directory_fd, source_leaf, destination_leaf):
    """Native atomic publication only: an existing destination is never replaced."""
    import ctypes
    if (type(directory_fd) is not int or directory_fd < 0 or
            any(type(name) is not str or not name or '/' in name or name in ('.', '..')
                or '\x00' in name for name in (source_leaf, destination_leaf))):
        raise ValueError('owned_atomic_rename_role_refused')
    if sys.platform == 'linux':
        symbol, flag = 'renameat2', 1  # Linux RENAME_NOREPLACE.
    elif sys.platform == 'darwin':
        symbol, flag = 'renameatx_np', 4  # Darwin SDK RENAME_EXCL.
    else:
        raise ValueError('owned_atomic_rename_unavailable')
    library = ctypes.CDLL(None, use_errno=True)
    rename = getattr(library, symbol, None)
    if rename is None:
        raise ValueError('owned_atomic_rename_unavailable')
    rename.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
    rename.restype = ctypes.c_int
    if rename(directory_fd, os.fsencode(source_leaf), directory_fd,
              os.fsencode(destination_leaf), flag) != 0:
        code = ctypes.get_errno()
        raise OSError(code, os.strerror(code))

class OwnedRuntime:
    def __init__(self, config):
        self.config = config
        self.directory = Path('/tmp') / ('phorge-private-mail-wire-' + os.urandom(12).hex())
        self.directory.mkdir(mode=0o700)  # exclusive; never rename over another run
        self.directory.chmod(0o700)
        self.identity = self.directory.stat()
        self.owner = os.urandom(32).hex()
        self.children = []
        self.closed = False
        try:
            self.write('owner', self.owner.encode())
        except BaseException:
            info = self.directory.lstat()
            if (info.st_dev, info.st_ino) == (self.identity.st_dev, self.identity.st_ino):
                marker = self.directory / 'owner'
                if marker.exists() and not marker.is_symlink():
                    marker.unlink()
                self.directory.rmdir()
            raise

    def assert_owned(self):
        info = self.directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or (info.st_dev, info.st_ino, info.st_uid) != (self.identity.st_dev, self.identity.st_ino, os.getuid()) or stat.S_IMODE(info.st_mode) != 0o700 or private_file(self.directory / 'owner', 64).read_text() != self.owner:
            raise ValueError('owned_directory_identity_changed')

    def write(self, name, raw):
        if not re.fullmatch('[a-z][a-z0-9.-]*', name):
            raise ValueError('owned_filename_refused')
        fd = os.open(self.directory / name, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw)

    def write_command(self, sequence, raw):
        # Startup/owner files keep their existing writer; only live commands/owner flags need
        # atomic visibility to the unchanged native held-FD command reader.
        if type(sequence) is not int or not 1 <= sequence <= 64:
            raise ValueError('owned_command_sequence_refused')
        view = memoryview(raw)
        if view.nbytes > 512:
            raise ValueError('owned_command_size_refused')
        self._publish_private_role('task-command-' + str(sequence) + '.json', view.tobytes())

    def write_flag(self, name):
        if name not in ('history-queue-quiesce', 'release'):
            raise ValueError('owned_flag_role_refused')
        self._publish_private_role(name, self.owner.encode())

    def _publish_private_role(self, destination, data):
        if (destination not in ('history-queue-quiesce', 'release') and
                not re.fullmatch(r'task-command-([1-9]|[1-5][0-9]|6[0-4])\.json', destination)):
            raise ValueError('owned_atomic_role_refused')
        self.assert_owned()
        directory_fd = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        token = lambda s: (s.st_dev, s.st_ino, s.st_uid, s.st_gid, s.st_mode)
        expected = token(self.identity)
        def directory_unchanged():
            if (token(os.fstat(directory_fd)) != expected or
                    token(self.directory.lstat()) != expected):
                raise ValueError('owned_command_directory_changed')
        stamp = lambda s: (s.st_dev, s.st_ino, s.st_uid, s.st_gid, s.st_mode,
                           s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        temporary = '.command-write-' + os.urandom(16).hex()
        fd = None
        created = None
        try:
            directory_unchanged()
            fd = os.open(temporary, os.O_CREAT | os.O_EXCL | os.O_RDWR | os.O_NOFOLLOW,
                         0o600, dir_fd=directory_fd)
            created = os.fstat(fd)  # Own this exact inode even if chmod fails.
            os.fchmod(fd, 0o600)
            created = os.fstat(fd)
            if (not stat.S_ISREG(created.st_mode) or created.st_nlink != 1 or
                    stat.S_IMODE(created.st_mode) != 0o600 or
                    (created.st_uid, created.st_gid) != (self.identity.st_uid, self.identity.st_gid)):
                raise ValueError('owned_command_temporary_refused')
            offset = 0
            while offset < len(data):
                count = os.write(fd, data[offset:])
                if count <= 0:
                    raise OSError('owned_command_short_write')
                offset += count
            os.fsync(fd)
            complete = os.fstat(fd)
            if (stamp(complete)[:6] != stamp(created)[:6] or
                    stamp(complete) != stamp(os.stat(temporary, dir_fd=directory_fd, follow_symlinks=False)) or
                    complete.st_size != len(data) or os.pread(fd, len(data) + 1, 0) != data):
                raise ValueError('owned_command_temporary_changed')
            directory_unchanged()
            self.assert_owned()
            _rename_noreplace(directory_fd, temporary, destination)
            published = os.fstat(fd)
            if (stamp(published)[:-1] != stamp(complete)[:-1] or
                    stamp(published) != stamp(os.stat(destination, dir_fd=directory_fd, follow_symlinks=False)) or
                    os.pread(fd, len(data) + 1, 0) != data):
                raise ValueError('owned_command_publication_changed')
            os.fsync(directory_fd)
            directory_unchanged()
            self.assert_owned()
        finally:
            try:
                try:
                    remaining = os.stat(temporary, dir_fd=directory_fd, follow_symlinks=False)
                except FileNotFoundError:
                    pass
                else:
                    if created is None or (remaining.st_dev, remaining.st_ino) != (created.st_dev, created.st_ino):
                        raise ValueError('owned_command_temporary_cleanup_refused')
                    os.unlink(temporary, dir_fd=directory_fd)
                    os.fsync(directory_fd)
            finally:
                if fd is not None:
                    os.close(fd)
                os.close(directory_fd)

    def read_json(self, name):
        self.assert_owned()
        return json.loads(private_file(self.directory / name, 2 * 1024 * 1024).read_text())

    def env(self, extra=None):
        result = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'PHORGE_GOVERNANCE_ISOLATED_TEST': '1',
                  'PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER': self.owner,
                  'PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR': str(self.directory)}
        result.update(extra or {})
        return result

    def spawn(self, command, extra=None, log='process.log'):
        self.assert_owned()
        fd = os.open(self.directory / log, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, 'wb') as output:
            process = subprocess.Popen(command, cwd=self.directory, env=self.env(extra), stdin=subprocess.DEVNULL,
                                       stdout=output, stderr=output, start_new_session=True)
        self.children.append(process)
        return process

    def php(self, program, value=None):
        self.assert_owned()
        return subprocess.run([self.config['php'], '-r', program], input=json.dumps(value).encode(),
                              cwd=self.directory, env=self.env(), check=True, capture_output=True, timeout=40).stdout

    @staticmethod
    def ports(count=4):
        sockets = []
        try:
            for _ in range(count):
                sock = socket.socket()
                sock.bind(('127.0.0.1', 0))
                sockets.append(sock)
            return [sock.getsockname()[1] for sock in sockets]
        finally:
            for sock in sockets:
                sock.close()

    @staticmethod
    def _identity(pid):
        try:
            raw = Path('/proc/' + str(pid) + '/stat').read_text()
            tail = raw.rsplit(')', 1)[1].split()
            return {'pid': pid, 'state': tail[0], 'group': int(tail[2]),
                    'session': int(tail[3]), 'start': int(tail[19]),
                    'uid': Path('/proc/' + str(pid)).stat().st_uid}
        except (FileNotFoundError, ProcessLookupError):
            return None

    @classmethod
    def _members(cls, group):
        result = []
        for path in Path('/proc').iterdir():
            if path.name.isdigit():
                row = cls._identity(int(path.name))
                if row is not None and row['group'] == group:
                    result.append(row)
        return result

    def _nonce_owned(self, row):
        if row['uid'] != os.getuid():
            return False
        try:
            env = Path('/proc/' + str(row['pid']) + '/environ').read_bytes()
            after = self._identity(row['pid'])
            return after is not None and all(after[key] == row[key] for key in ('pid', 'group', 'session', 'start', 'uid')) and (b'PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER=' + self.owner.encode() + b'\0') in env
        except (FileNotFoundError, ProcessLookupError, PermissionError):
            return False

    def _stop_group(self, process):
        group = process.pid
        if sys.platform != 'linux':
            # Non-Linux only supports the narrow unit-test direct-leader case.
            # A dead leader with an existing group is unknown, never "clean".
            live_leader = process.poll() is None
            if live_leader:
                if os.getpgid(process.pid) != group:
                    raise ValueError('owned_group_identity_changed')
                os.killpg(group, signal.SIGTERM)
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(group, signal.SIGKILL); process.wait(timeout=5)
            try:
                os.killpg(group, 0)
            except ProcessLookupError:
                return live_leader
            raise ValueError('owned_group_descendants_unverified')
        if not all(hasattr(os, name) for name in ('pidfd_open', 'P_PIDFD')) or not hasattr(signal, 'pidfd_send_signal'):
            raise ValueError('owned_group_pidfd_profile_unavailable')
        handles = {}
        signaled = False
        fields = ('pid', 'group', 'session', 'start', 'uid')

        def remember(row, session_proved=False):
            current = self._identity(row['pid'])
            if current is None:
                return
            if any(current[key] != row[key] for key in fields):
                raise ValueError('owned_group_identity_changed')
            if row['session'] != group or row['uid'] != os.getuid() or (not session_proved and not self._nonce_owned(row)):
                raise ValueError('owned_group_descendants_unverified')
            try:
                fd = os.pidfd_open(row['pid'], 0)
            except ProcessLookupError:
                return
            after = self._identity(row['pid'])
            if after is None:
                os.close(fd); return
            if any(after[key] != row[key] for key in fields):
                os.close(fd)
                raise ValueError('owned_group_identity_changed')
            prior = handles.get(row['pid'])
            if prior is not None:
                os.close(prior[0])
            handles[row['pid']] = (fd, {key: row[key] for key in fields})

        def send(pid, sig):
            # The held pidfd refers to that actual process even if its numeric
            # PID/PGID is later reused. No Linux killpg call is used.
            try:
                signal.pidfd_send_signal(handles[pid][0], sig)
            except ProcessLookupError:
                pass

        try:
            members = self._members(group)
            alive = [row for row in members if row['state'] != 'Z']
            leader = next((row for row in alive if row['pid'] == group), None)
            leader_owned = process.poll() is None and leader is not None and leader['session'] == group and self._nonce_owned(leader)
            for row in members:
                remember(row, session_proved=leader_owned)
            for row in alive:
                if row['pid'] in handles:
                    send(row['pid'], signal.SIGTERM); signaled = True
            deadline = time.monotonic() + 5
            escalated = False
            while True:
                process.poll()  # only this registered Popen direct child
                for pid, (fd, _identity) in handles.items():
                    if pid == process.pid:
                        continue
                    try:
                        # Reap an adopted descendant by stable pidfd, never a
                        # potentially reused numeric process-group identifier.
                        os.waitid(os.P_PIDFD, fd, os.WEXITED | os.WNOHANG)
                    except ChildProcessError:
                        pass
                members = self._members(group)
                if not members:
                    return signaled
                alive = [row for row in members if row['state'] != 'Z']
                for row in members:
                    prior = handles.get(row['pid'])
                    if prior is not None and all(prior[1][key] == row[key] for key in fields):
                        continue
                    # A newly observed orphan without the private owner nonce
                    # is unknown. Do not infer ownership from PGID alone.
                    remember(row)
                    if row['state'] != 'Z' and row['pid'] in handles:
                        send(row['pid'], signal.SIGTERM); signaled = True
                if time.monotonic() >= deadline:
                    if escalated:
                        raise ValueError('owned_group_cleanup_unverified')
                    for row in alive:
                        if row['pid'] in handles:
                            send(row['pid'], signal.SIGKILL)
                    escalated = True
                    deadline = time.monotonic() + 2
                time.sleep(.02)
        finally:
            for fd, _identity in handles.values():
                os.close(fd)

    def close(self, remove=False):
        if self.closed:
            raise ValueError('owned_runtime_already_closed')
        self.assert_owned()
        stopped = 0
        for process in reversed(self.children):
            stopped += int(self._stop_group(process))
        if remove:
            self.assert_owned()
            for path in self.directory.rglob('*'):
                if path.is_symlink():
                    raise ValueError('owned_cleanup_symlink_refused')
            shutil.rmtree(self.directory)
        self.closed = True
        return {'registered_children': len(self.children), 'signaled_groups': stopped,
                'live_children': 0, 'private_directory_removed': not self.directory.exists()}

    def remove_closed_directory(self):
        """Task-only final removal after independent source operation exit.

        This method grants no source/runtime authority. It only removes this
        exact already-stopped owned directory; other callers keep close().
        """
        if self.closed is not True:
            raise ValueError('owned_runtime_not_closed')
        self.assert_owned()
        before=self.directory.lstat()
        if before.st_gid!=self.identity.st_gid or (self.directory/'owner').lstat().st_nlink!=1:
            raise ValueError('owned_directory_final_identity_changed')
        for child in self.children:
            if child.poll() is None or self._members(child.pid):
                raise ValueError('owned_runtime_final_children_unknown')
        for path in self.directory.rglob('*'):
            info=path.lstat()
            if path.is_symlink() or info.st_uid!=os.getuid() or not (stat.S_ISREG(info.st_mode)or stat.S_ISDIR(info.st_mode)):
                raise ValueError('owned_final_cleanup_entry_refused')
            if stat.S_ISREG(info.st_mode)and info.st_nlink!=1:
                raise ValueError('owned_final_cleanup_link_refused')
        self.assert_owned()
        now=self.directory.lstat()
        if (before.st_dev,before.st_ino,before.st_uid,before.st_gid,before.st_mode)!=(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode):
            raise ValueError('owned_final_cleanup_identity_changed')
        shutil.rmtree(self.directory)
        return {'live_children':0,'private_directory_removed':not self.directory.exists()}
