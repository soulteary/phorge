"""Fixed Podman executor bound to Root's independently measured engine/network.

No shell, arbitrary argv, caller-selected connection, image or network namespace.
Root's private observation bytes are an external pin, not a descriptor's claim.
All actual container-inspect policy remains fail closed pending real schema peer.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess

from . import owned_container

ROOT_OBSERVATION_SHA256 = 'bd9f90ab216d79bdaabed96f5789bc102043b680a072173d081c23fea88aec85'
ROOT_RUNTIME_OBSERVATION_SHA256 = None  # Root's actual image/build observation, not caller input.
SECCOMP_SHA256 = 'cc3e61cabda6bbc1e53e54d27ba4d55a9d3be829b6dd1a596f4a7b31b1cc7849'
BASE_IMAGE = 'mcr.microsoft.com/playwright/python@sha256:e731c79aba1c9d777b77ed85f0c0111531cbaa24ecba90fe65ad7b7438e89c40'
PROFILE = 'isolated-media-browser-v1'
PHP_NAMESPACE = "$s=stat('/proc/self/ns/net');$b=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));echo json_encode(array('device'=>$s['dev'],'inode'=>$s['ino'],'boot_id_sha256'=>hash('sha256',$b)));"
PYTHON_NAMESPACE = "import os,json,hashlib; s=os.stat('/proc/self/ns/net'); d=dict(line.split(':',1) for line in open('/proc/self/status') if ':' in line); print(json.dumps({'device':s.st_dev,'inode':s.st_ino,'boot_id_sha256':hashlib.sha256(open('/proc/sys/kernel/random/boot_id','rb').read().strip()).hexdigest(),'uid':os.geteuid(),'gid':os.getegid(),'NoNewPrivs':d['NoNewPrivs'].strip(),'Seccomp':d['Seccomp'].strip(),'CapEff':d['CapEff'].strip(),'CapPrm':d['CapPrm'].strip(),'CapBnd':d['CapBnd'].strip()}))"


class EngineUnproved(RuntimeError):
    def __init__(self, category='browser_fixed_engine_unproved', primary=None):
        super().__init__(category)
        self.primary = primary  # private only


def exact(actual, expected):
    if type(actual) is not type(expected):
        raise EngineUnproved()
    if type(expected) is dict:
        if set(actual) != set(expected):
            raise EngineUnproved()
        for key in expected:
            exact(actual[key], expected[key])
    elif type(expected) is list:
        if len(actual) != len(expected):
            raise EngineUnproved()
        for left, right in zip(actual, expected):
            exact(left, right)
    elif actual != expected:
        raise EngineUnproved()


def strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise EngineUnproved('browser_engine_duplicate_JSON')
        result[key] = value
    return result


def decode(raw):
    if type(raw) is not bytes or not 0 < len(raw) <= 2097152:
        raise EngineUnproved()
    try:
        return json.loads(raw, object_pairs_hook=strict_object,
                          parse_constant=lambda value: (_ for _ in ()).throw(EngineUnproved()))
    except EngineUnproved:
        raise
    except Exception as error:
        raise EngineUnproved(primary=error) from None


def regular_bytes(path, budget, *, private=False):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or \
                not 0 < before.st_size <= budget or \
                (private and (before.st_uid != os.geteuid() or stat.S_IMODE(before.st_mode) != 0o600)):
            raise EngineUnproved()
        chunks, remaining = [], before.st_size
        while remaining:
            raw = os.read(fd, min(65536, remaining))
            if not raw:
                raise EngineUnproved()
            chunks.append(raw)
            remaining -= len(raw)
        after = os.fstat(fd)
        entry = os.stat(path, follow_symlinks=False)
        def stamp(value):
            return (value.st_dev, value.st_ino, value.st_size, value.st_mtime_ns,
                    value.st_ctime_ns, value.st_mode, value.st_uid, value.st_nlink)
        if stamp(before) != stamp(after) or stamp(after) != stamp(entry):
            raise EngineUnproved()
        return b''.join(chunks)
    finally:
        os.close(fd)


def load_root_observation(path):
    raw = regular_bytes(path, 262144, private=True)
    if hashlib.sha256(raw).hexdigest() != ROOT_OBSERVATION_SHA256:
        raise EngineUnproved('browser_Root_engine_observation_not_pinned')
    value = decode(raw)
    if type(value) is not dict or set(value) != {
        'canonical_executable', 'executable_sha256', 'version', 'selected_connection',
        'host', 'existing_root_owned_container', 'new_container_created', 'browser_started'}:
        raise EngineUnproved()
    executable = value['canonical_executable']
    if type(executable) is not str or not Path(executable).is_absolute() or \
            str(Path(executable).resolve()) != executable or \
            not owned_container.hex_value(value['executable_sha256'], 64):
        raise EngineUnproved()
    connection = value['selected_connection']
    if type(connection) is not dict or set(connection) != {'name', 'default', 'URI_sha256'} or \
            type(connection['name']) is not str or not re.fullmatch('[A-Za-z0-9_.-]{1,128}', connection['name']) or \
            connection['default'] is not True or not owned_container.hex_value(connection['URI_sha256'], 64):
        raise EngineUnproved()
    network = value['existing_root_owned_container']
    if type(network) is not dict or set(network) != {'Id', 'Name', 'Image', 'Running', 'NetworkMode', 'namespace'} or \
            not owned_container.hex_value(network['Id'], 64) or network['Running'] is not True or \
            type(network['Name']) is not str or type(network['NetworkMode']) is not str or \
            type(network['namespace']) is not dict or set(network['namespace']) != {'device', 'inode', 'boot_id_sha256'}:
        raise EngineUnproved()
    for key in ['device', 'inode']:
        if type(network['namespace'][key]) is not int or network['namespace'][key] < (1 if key == 'inode' else 0):
            raise EngineUnproved()
    if not owned_container.hex_value(network['namespace']['boot_id_sha256'], 64) or \
            value['new_container_created'] is not False or value['browser_started'] is not False:
        raise EngineUnproved()
    return value


def load_runtime_observation(path):
    if not owned_container.hex_value(ROOT_RUNTIME_OBSERVATION_SHA256, 64):
        raise EngineUnproved('browser_runtime_observation_unpinned')
    raw = regular_bytes(path, 262144, private=True)
    if hashlib.sha256(raw).hexdigest() != ROOT_RUNTIME_OBSERVATION_SHA256:
        raise EngineUnproved('browser_runtime_observation_not_pinned')
    value = decode(raw)
    if type(value) is not dict or set(value) != {'schema_version', 'profile', 'image_reference',
            'image_id', 'image_labels', 'platform', 'source_manifest', 'source_recipe_sha256',
            'chromium_revision', 'chromium_version', 'browser_sha256', 'certutil_sha256',
            'dependencies_lock_sha256'}:
        raise EngineUnproved()
    exact(value['schema_version'], 1)
    exact(value['profile'], 'Root-observed-isolated-media-browser-runtime-v1')
    exact(value['platform'], 'linux/arm64')
    exact(value['source_manifest'], BASE_IMAGE.split('@', 1)[1])
    exact(value['chromium_revision'], '1248')
    exact(value['chromium_version'], '156.0.8078.4')
    canonical = image_id(value['image_id'])
    if value['image_id'] != canonical or value['image_reference'] not in [BASE_IMAGE, canonical] or \
            type(value['image_labels']) is not dict or any(type(k) is not str or type(v) is not str
                for k, v in value['image_labels'].items()):
        raise EngineUnproved()
    for key in ['source_recipe_sha256', 'browser_sha256', 'certutil_sha256', 'dependencies_lock_sha256']:
        if not owned_container.hex_value(value[key], 64):
            raise EngineUnproved()
    lock = Path(__file__).with_name('requirements-linux-arm64-py312.lock')
    exact(hashlib.sha256(regular_bytes(lock, 65536)).hexdigest(), value['dependencies_lock_sha256'])
    return value


def image_id(value):
    # These two explicit API spellings identify the same immutable image bytes.
    if type(value) is not str or not re.fullmatch('(?:sha256:)?[a-f0-9]{64}', value):
        raise EngineUnproved()
    return 'sha256:' + value.removeprefix('sha256:')


def field(value, *keys):
    for key in keys:
        if type(value) is not dict or key not in value:
            raise EngineUnproved('browser_actual_inspect_field_unavailable')
        value = value[key]
    return value


def no_entries(value):
    # Known JSON API spellings of an explicitly present empty collection.
    if value is None or type(value) in (list, dict) and len(value) == 0:
        return
    raise EngineUnproved()


def browser_policy(row, *, name, owner, source, actual_image, labels, network_id, seccomp_path):
    if type(row) is not dict or not owned_container.hex_value(field(row, 'Id'), 64):
        raise EngineUnproved()
    exact(field(row, 'Name'), name)
    exact(image_id(field(row, 'Image')), image_id(actual_image))
    expected_labels = dict(labels)
    expected_labels.update({'phorge.browser.owner': owner, 'phorge.browser.source': source,
                            'phorge.browser.profile': PROFILE})
    exact(field(row, 'Config', 'Labels'), expected_labels)
    exact(field(row, 'Config', 'User'), '1000:1000')
    exact(field(row, 'Config', 'Cmd'), ['/bin/sleep', '1800'])
    no_entries(field(row, 'Config', 'Entrypoint'))
    exact(field(row, 'HostConfig', 'Privileged'), False)
    exact(field(row, 'HostConfig', 'ReadonlyRootfs'), True)
    exact(field(row, 'HostConfig', 'PublishAllPorts'), False)
    exact(field(row, 'HostConfig', 'NetworkMode'), 'container:' + network_id)
    exact(field(row, 'HostConfig', 'IpcMode'), 'private')
    if field(row, 'HostConfig', 'PidMode') not in ['', 'private']:
        raise EngineUnproved()
    no_entries(field(row, 'HostConfig', 'CapAdd'))
    exact(field(row, 'HostConfig', 'CapDrop'), ['ALL'])
    security = field(row, 'HostConfig', 'SecurityOpt')
    if type(security) is not list or len(security) != 2 or set(security) != {
            'no-new-privileges', 'seccomp=' + str(seccomp_path)}:
        raise EngineUnproved()
    for path in [('HostConfig', 'PortBindings'), ('HostConfig', 'Binds'),
                 ('NetworkSettings', 'Ports'), ('NetworkSettings', 'Networks')]:
        no_entries(field(row, *path))
    exact(field(row, 'HostConfig', 'Tmpfs'), {
        '/home/pwuser': 'rw,nosuid,nodev,noexec,uid=1000,gid=1000,mode=0700,size=256m',
        '/tmp': 'rw,nosuid,nodev,noexec,mode=1777,size=256m'})
    mounts = field(row, 'Mounts')
    if type(mounts) is not list:
        raise EngineUnproved()
    seen = set()
    for mount in mounts:
        destination = field(mount, 'Destination')
        if field(mount, 'Type') != 'tmpfs' or destination not in ['/home/pwuser', '/tmp', '/dev/shm'] or destination in seen:
            raise EngineUnproved()
        seen.add(destination)
    running = field(row, 'State', 'Running')
    if type(running) is not bool:
        raise EngineUnproved()
    return {'Id': row['Id'], 'Name': name, 'Image': image_id(actual_image),
            'Config': {'Labels': {'phorge.browser.owner': owner, 'phorge.browser.source': source,
                                  'phorge.browser.profile': PROFILE}}, 'State': {'Running': running}}


class FixedPodman:
    def __init__(self, observation_path, runtime_path, *, nonce, owner, source):
        self.observation = load_root_observation(observation_path)
        self.runtime = load_runtime_observation(runtime_path)
        if not owned_container.hex_value(nonce, 24) or not owned_container.hex_value(owner, 64) or \
                not owned_container.hex_value(source, 40):
            raise EngineUnproved()
        self.nonce, self.owner, self.source = nonce, owner, source
        self.name, self.identity = 'phorge-media-browser-' + nonce, None
        self.actual_image = self.runtime['image_id']
        self.image_reference = self.runtime['image_reference']
        self.image_labels = dict(self.runtime['image_labels'])
        self.seccomp = Path(__file__).with_name('seccomp-playwright-v1.64.1.json').resolve()
        if hashlib.sha256(regular_bytes(self.seccomp, 65536)).hexdigest() != SECCOMP_SHA256:
            raise EngineUnproved('browser_seccomp_source_not_pinned')

    def _raw(self, args, *, input=None, local=False):
        # `args` only comes from the fixed methods below. No shell or CLI proxy.
        executable = self.observation['canonical_executable']
        if hashlib.sha256(regular_bytes(executable, 268435456)).hexdigest() != self.observation['executable_sha256']:
            raise EngineUnproved('browser_engine_binary_changed')
        if not local:
            selected = self.observation['selected_connection']
            connections = self._json(['system', 'connection', 'list', '--format', 'json'], local=True)
            if type(connections) is not list:
                raise EngineUnproved()
            matches = [c for c in connections if type(c) is dict and c.get('Name') == selected['name']]
            if len(matches) != 1 or matches[0].get('Default') is not True or type(matches[0].get('URI')) is not str or \
                    hashlib.sha256(matches[0]['URI'].encode()).hexdigest() != selected['URI_sha256']:
                raise EngineUnproved('browser_engine_connection_changed')
        prefix = [executable] if local else [executable, '--connection',
            self.observation['selected_connection']['name']]
        try:
            result = subprocess.run(prefix + args, input=input, capture_output=True, timeout=60,
                shell=False, env={'HOME': str(Path.home()), 'PATH': '/usr/bin:/bin:/opt/homebrew/bin',
                                  'LANG': 'C', 'LC_ALL': 'C'})
        except Exception as error:
            raise EngineUnproved(primary=error) from None
        if type(result.returncode) is not int or type(result.stdout) is not bytes or type(result.stderr) is not bytes or \
                len(result.stdout) > 2097152 or len(result.stderr) > 16384:
            raise EngineUnproved()
        return result

    def _json(self, args, *, local=False):
        result = self._raw(args, local=local)
        if result.returncode != 0 or result.stderr != b'':
            raise EngineUnproved()
        return decode(result.stdout)

    def preflight(self):
        selected = self.observation['selected_connection']
        connections = self._json(['system', 'connection', 'list', '--format', 'json'], local=True)
        if type(connections) is not list:
            raise EngineUnproved()
        matches = [c for c in connections if type(c) is dict and c.get('Name') == selected['name']]
        if len(matches) != 1 or matches[0].get('Default') is not True or type(matches[0].get('URI')) is not str or \
                hashlib.sha256(matches[0]['URI'].encode()).hexdigest() != selected['URI_sha256']:
            raise EngineUnproved('browser_engine_connection_changed')
        exact(self._json(['version', '--format', 'json']), self.observation['version'])
        host = field(self._json(['info', '--format', 'json']), 'host')
        projection = {key: field(host, key) for key in ['arch', 'kernel', 'os', 'remoteSocket']}
        exact(projection, self.observation['host'])
        self.network_proof()

    def network_proof(self):
        expected = self.observation['existing_root_owned_container']
        raw = self._json(['container', 'inspect', expected['Id']])
        if type(raw) is not list or len(raw) != 1:
            raise EngineUnproved()
        row = raw[0]
        for key in ['Id', 'Name']:
            exact(field(row, key), expected[key])
        exact(image_id(field(row, 'Image')), image_id(expected['Image']))
        exact(field(row, 'State', 'Running'), True)
        exact(field(row, 'HostConfig', 'NetworkMode'), expected['NetworkMode'])
        namespace = self._json(['exec', expected['Id'], 'php', '-r', PHP_NAMESPACE])
        exact(namespace, expected['namespace'])

    def image_proof(self):
        raw = self._json(['image', 'inspect', self.image_reference])
        if type(raw) is not list or len(raw) != 1:
            raise EngineUnproved()
        row = raw[0]
        exact(image_id(field(row, 'Id')), self.actual_image)
        exact(field(row, 'Os'), 'linux')
        exact(field(row, 'Architecture'), 'arm64')
        labels = field(row, 'Config', 'Labels')
        if labels is None:
            labels = {}
        exact(labels, self.image_labels)
        digests = field(row, 'RepoDigests')
        if self.image_reference == BASE_IMAGE and (type(digests) is not list or BASE_IMAGE not in digests):
            raise EngineUnproved('browser_actual_image_reference_unproved')

    def expected_create(self):
        return ['create', '--name', self.name,
            '--label', 'phorge.browser.owner=' + self.owner,
            '--label', 'phorge.browser.source=' + self.source,
            '--label', 'phorge.browser.profile=' + PROFILE,
            '--user', '1000:1000', '--network', 'container:' + self.observation['existing_root_owned_container']['Id'],
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--read-only', '--ipc', 'private', '--shm-size', '256m',
            '--tmpfs', '/home/pwuser:rw,nosuid,nodev,noexec,uid=1000,gid=1000,mode=0700,size=256m',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,mode=1777,size=256m',
            self.image_reference, '/bin/sleep', '1800']

    def start(self):
        if self.identity is None:
            raise EngineUnproved()
        current = self._inspect_browser(self.identity)
        if type(current) is not list or current[0]['State']['Running'] is not False:
            raise EngineUnproved()
        self.preflight()
        result = self._raw(['start', self.identity])
        if result.returncode != 0 or result.stderr != b'':
            raise EngineUnproved()
        running = self._inspect_browser(self.identity)
        if type(running) is not list or running[0]['State']['Running'] is not True:
            raise EngineUnproved()
        self.network_proof()
        actual = self._json(['exec', '--user', '1000:1000', self.identity,
                             '/usr/bin/python3', '-B', '-c', PYTHON_NAMESPACE])
        expected = dict(self.observation['existing_root_owned_container']['namespace'],
                        uid=1000, gid=1000, NoNewPrivs='1', Seccomp='2',
                        CapEff='0000000000000000', CapPrm='0000000000000000', CapBnd='0000000000000000')
        exact(actual, expected)

    def _inspect_browser(self, selector):
        result = self._raw(['container', 'inspect', selector])
        missing = ('Error: no container with name or ID "' + selector + '" found: no such container\n').encode()
        if result.returncode == 125 and result.stdout == b'' and result.stderr == missing:
            return {'absent': True}
        if result.returncode != 0 or result.stderr != b'':
            raise EngineUnproved('browser_container_absence_or_inspect_unknown')
        raw = decode(result.stdout)
        if type(raw) is not list or len(raw) != 1:
            raise EngineUnproved()
        value = browser_policy(raw[0], name=self.name, owner=self.owner, source=self.source,
            actual_image=self.actual_image, labels=self.image_labels,
            network_id=self.observation['existing_root_owned_container']['Id'], seccomp_path=self.seccomp)
        if self.identity is not None and value['Id'] != self.identity:
            raise EngineUnproved('browser_container_identity_replaced')
        self.identity = value['Id']
        return [value]

    def command(self, args):
        if type(args) is not list or any(type(v) is not str for v in args):
            raise EngineUnproved('browser_argv_not_allowlisted')
        if args[:2] == ['container', 'inspect'] and len(args) == 3 and args[2] in [self.name, self.identity]:
            return self._inspect_browser(args[2])
        if args == self.expected_create():
            self.preflight()
            self.image_proof()
            # Preserve the old lifecycle seam's exact intent; only add the
            # fixed reviewed seccomp file and forbid runtime image fetching.
            actual = args[:-3] + ['--pull=never', '--security-opt', 'seccomp=' + str(self.seccomp)] + args[-3:]
            result = self._raw(actual)
            if result.returncode != 0 or result.stderr != b'':
                raise EngineUnproved()
            identity = result.stdout.decode('ascii').strip()
            if not owned_container.hex_value(identity, 64):
                raise EngineUnproved()
            self.identity = identity
            observed = self._inspect_browser(self.name)
            if type(observed) is not list or observed[0]['State']['Running'] is not False:
                raise EngineUnproved()
            self.network_proof()
            return identity
        if args == ['stop', '--time', '10', self.identity] and self.identity is not None:
            current = self._inspect_browser(self.identity)
            if current == {'absent': True}:
                raise EngineUnproved()
        elif args == ['rm', self.identity] and self.identity is not None:
            current = self._inspect_browser(self.identity)
            if type(current) is not list or current[0]['State']['Running'] is not False:
                raise EngineUnproved()
        else:
            raise EngineUnproved('browser_argv_not_allowlisted')
        result = self._raw(args)
        if result.returncode != 0 or result.stderr != b'':
            raise EngineUnproved()
        return None
