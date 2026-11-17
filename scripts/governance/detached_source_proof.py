"""Verify explicit project-signed merge provenance; never a runtime grant."""
import json
import os
from pathlib import Path
import re
import stat
import subprocess

SIGNER = 'CD85ED7465AFF2FFA2FFB686DBCDD8CBF440F8DE'
SCHEMA = 'phorge-project-merge-proof-v1'


def need(value):
    if not value:
        raise ValueError('detached_source_proof_refused')


def read_owned(path, maximum):
    path = Path(path)
    need(path.is_absolute() and path.resolve(strict=True) == path)
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and
         before.st_uid == os.geteuid() and not before.st_mode & 0o022 and
         0 < before.st_size <= maximum)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        stamp = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid,
                           s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        need(stamp(before) == stamp(os.fstat(fd)))
        raw = os.read(fd, maximum + 1)
        need(len(raw) == before.st_size and os.read(fd, 1) == b'')
        need(stamp(before) == stamp(os.fstat(fd)) == stamp(path.lstat()))
        return raw
    finally:
        os.close(fd)


def decode(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            need(key not in result)
            result[key] = value
        return result
    value = json.loads(raw, object_pairs_hook=pairs)
    need(type(value) is dict and set(value) == {
        'schema', 'repository', 'commit', 'tree', 'parents', 'reviewed_head',
        'pull_request', 'ci_head', 'ci_runs'})
    need(value['schema'] == SCHEMA and value['repository'] == 'soulteary/phorge')
    for key in ('commit', 'tree', 'reviewed_head', 'ci_head'):
        need(type(value[key]) is str and re.fullmatch('[a-f0-9]{40}', value[key]))
    need(type(value['parents']) is list and len(value['parents']) == 2)
    need(value['parents'][0] != value['parents'][1])
    need(all(type(x) is str and re.fullmatch('[a-f0-9]{40}', x) for x in value['parents']))
    need(value['parents'][1] == value['reviewed_head'] == value['ci_head'])
    need(type(value['pull_request']) is int and value['pull_request'] > 0)
    runs = value['ci_runs']
    need(type(runs) is list and runs and all(type(x) is int and x > 0 for x in runs))
    need(len(runs) == len(set(runs)))
    need(raw == (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode())
    return value


def verify(root, expected_commit, proof_path, signature_path, public_home):
    """Authenticate exact bytes and lineage. CI IDs are signed attestations,
    not live CI checks; issuer must check required gates before signing."""
    need(type(expected_commit) is str and re.fullmatch('[a-f0-9]{40}', expected_commit))
    raw = read_owned(proof_path, 16384)
    signature = read_owned(signature_path, 16384)
    value = decode(raw)
    need(value['commit'] == expected_commit)
    home = Path(public_home)
    need(home.is_absolute() and home.resolve(strict=True) == home)
    ring = home / '.gnupg'
    for directory in (home, ring):
        info = directory.lstat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid() and
             stat.S_IMODE(info.st_mode) == 0o700 and directory.resolve() == directory)
    need({x.name for x in home.iterdir()} == {'.gnupg'})
    need({x.name for x in ring.iterdir()} <= {'pubring.kbx', 'trustdb.gpg', 'private-keys-v1.d'})
    for name in ('pubring.kbx', 'trustdb.gpg'):
        read_owned(ring / name, 4 * 1024 * 1024)
    private = ring / 'private-keys-v1.d'
    if private.exists():
        need(private.is_dir() and not private.is_symlink() and not tuple(private.iterdir()))
    env = {'PATH': '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin',
           'HOME': str(home), 'LANG': 'C', 'GIT_CONFIG_NOSYSTEM': '1',
           'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_NO_REPLACE_OBJECTS': '1',
           'GIT_OPTIONAL_LOCKS': '0', 'GIT_NO_LAZY_FETCH': '1',
           'GIT_TERMINAL_PROMPT': '0'}
    # Verify the already-read signature over already-read proof bytes. A private
    # temporary signature avoids reopening caller-controlled paths in GPG.
    import tempfile
    with tempfile.TemporaryDirectory(prefix='phorge-proof-') as directory:
        sig = Path(directory) / 'proof.sig'
        sig.write_bytes(signature)
        result = subprocess.run(['gpg', '--no-options', '--no-autostart', '--batch',
            '--homedir', str(home / '.gnupg'), '--status-fd', '2', '--verify',
            str(sig), '-'], input=raw, capture_output=True, timeout=30, env=env)
    need(result.returncode == 0)
    need(re.findall(rb'^\[GNUPG:\] VALIDSIG ([A-F0-9]{40}) ', result.stderr, re.M)
         == [SIGNER.encode()])
    need(not re.search(rb'^\[GNUPG:\] (?:BADSIG|ERRSIG|EXPSIG|EXPKEYSIG|REVKEYSIG|KEYREVOKED|SIGEXPIRED|KEYEXPIRED)(?: |$)', result.stderr, re.M))
    def git(*args):
        return subprocess.run(['git', '--no-replace-objects', '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.fsmonitor=false', '-C', str(root), *args], env=env,
            stdin=subprocess.DEVNULL, capture_output=True, timeout=30, check=True).stdout.decode().strip()
    need(git('rev-parse', 'HEAD') == expected_commit)
    need(git('rev-parse', expected_commit + '^{tree}') == value['tree'])
    need(git('show', '-s', '--format=%P', expected_commit).split() == value['parents'])
    need(git('rev-parse', value['reviewed_head'] + '^{tree}') == value['tree'])
    return value


class ProofLease:
    """Immutable proof identity retained by the issuing source operation."""
    def __init__(self, root, expected_commit, proof_path, signature_path, public_home):
        self.root = Path(root)
        self.expected_commit = expected_commit
        self.proof_path = Path(proof_path)
        self.signature_path = Path(signature_path)
        self.public_home = Path(public_home)
        self.raw = read_owned(self.proof_path, 16384)
        self.signature = read_owned(self.signature_path, 16384)
        self.identities = (self._identity(self.proof_path), self._identity(self.signature_path))
        ring = self.public_home / '.gnupg'
        self.ring_paths = (ring, ring / 'pubring.kbx', ring / 'trustdb.gpg')
        self.value = verify(root, expected_commit, proof_path, signature_path, public_home)
        self.ring_identities = tuple(self._identity(x) for x in self.ring_paths)
        self.closed = False
        self.check()

    @staticmethod
    def _identity(path):
        s = path.lstat()
        return (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid,
                s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)

    def check(self):
        need(not self.closed)
        need(tuple(self._identity(x) for x in self.ring_paths) == self.ring_identities)
        need((self._identity(self.proof_path), self._identity(self.signature_path)) == self.identities)
        need(read_owned(self.proof_path, 16384) == self.raw)
        need(read_owned(self.signature_path, 16384) == self.signature)

    def close(self):
        try:
            self.check()
        finally:
            self.closed = True
