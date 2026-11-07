"""Linux private NSS setup seam. Disabled until an actual tool pin is reviewed.

No system trust, hosts file or existing browser profile is modified. This is not
normal-browser TLS proof; the browser must still reject all three negatives.
"""
import hashlib
import os
from pathlib import Path
import re
import ssl
import stat
import subprocess
import sys

CERTUTIL = '/usr/bin/certutil'
CERTUTIL_SHA256 = None  # Root's actual immutable-image/tool observation is required.
HOME_PARENT = Path('/home/pwuser')


class NSSRefused(RuntimeError):
    pass


def runtime(home):
    if sys.platform != 'linux' or os.geteuid() != 1000 or os.environ.get('HOME') != str(home):
        raise NSSRefused('browser_private_NSS_runtime_unknown')
    return os.geteuid()


def executable():
    if type(CERTUTIL_SHA256) is not str or not re.fullmatch('[a-f0-9]{64}', CERTUTIL_SHA256):
        raise NSSRefused('browser_NSS_tool_unpinned')
    fd = os.open(CERTUTIL, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or st.st_nlink != 1 or \
                not st.st_mode & 0o111 or not 0 < st.st_size <= 67108864:
            raise NSSRefused('browser_NSS_tool_unknown')
        digest = hashlib.sha256()
        remaining = st.st_size
        while remaining:
            data = os.read(fd, min(65536, remaining))
            if not data:
                raise NSSRefused('browser_NSS_tool_changed')
            digest.update(data)
            remaining -= len(data)
        after = os.fstat(fd)
        if (st.st_dev, st.st_ino, st.st_size, st.st_mtime_ns, st.st_ctime_ns) != \
                (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns) or \
                digest.hexdigest() != CERTUTIL_SHA256:
            raise NSSRefused('browser_NSS_tool_changed')
    finally:
        os.close(fd)


def prepare(home, *, owner, device, inode, root_DER_sha256):
    home = Path(home)
    if home.parent != HOME_PARENT or not re.fullmatch('phorge-browser-[a-f0-9]{24}', home.name) or \
            any(type(v) is not str or not re.fullmatch('[a-f0-9]{64}', v)
                for v in [owner, root_DER_sha256]) or \
            type(device) is not int or device < 0 or type(inode) is not int or inode <= 0:
        raise NSSRefused('browser_private_NSS_external_owner_unknown')
    uid = runtime(home)
    executable()
    fd = None
    try:
        fd = os.open(home, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        def identity():
            st = os.fstat(fd)
            entry = os.stat(home, follow_symlinks=False)
            if not stat.S_ISDIR(entry.st_mode) or st.st_uid != uid or \
                    stat.S_IMODE(st.st_mode) != 0o700 or \
                    (st.st_dev, st.st_ino) != (device, inode) or \
                    (entry.st_dev, entry.st_ino) != (device, inode):
                raise NSSRefused('browser_private_NSS_owner_changed')
        identity()
        def read(name):
            child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
            try:
                st = os.fstat(child)
                if not stat.S_ISREG(st.st_mode) or st.st_uid != uid or st.st_nlink != 1 or \
                        stat.S_IMODE(st.st_mode) != 0o600 or not 0 < st.st_size <= 16384:
                    raise NSSRefused('browser_private_NSS_input_unknown')
                raw = os.read(child, 16385)
                after = os.fstat(child)
                entry = os.stat(name, dir_fd=fd, follow_symlinks=False)
                if len(raw) != st.st_size or (st.st_dev, st.st_ino, st.st_mtime_ns, st.st_ctime_ns) != \
                        (after.st_dev, after.st_ino, after.st_mtime_ns, after.st_ctime_ns) or \
                        (entry.st_dev, entry.st_ino) != (st.st_dev, st.st_ino):
                    raise NSSRefused('browser_private_NSS_input_changed')
                return raw
            finally:
                os.close(child)
        if read('owner') != owner.encode():
            raise NSSRefused('browser_private_NSS_owner_changed')
        root_pem = read('test-ca.pem')
        try:
            der = ssl.PEM_cert_to_DER_cert(root_pem.decode('ascii'))
        except Exception:
            raise NSSRefused('browser_private_NSS_root_unknown') from None
        if hashlib.sha256(der).hexdigest() != root_DER_sha256:
            raise NSSRefused('browser_private_NSS_root_changed')
        # No reuse or adoption of an existing trust database, even if empty.
        os.mkdir('.pki', 0o700, dir_fd=fd)
        pki = os.open('.pki', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        try:
            pki_initial = os.fstat(pki)
            os.mkdir('nssdb', 0o700, dir_fd=pki)
            database = os.open('nssdb', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=pki)
        finally:
            os.close(pki)
        try:
            initial = os.fstat(database)
            def database_identity():
                identity()
                current_pki = os.stat(home / '.pki', follow_symlinks=False)
                current = os.stat(home / '.pki/nssdb', follow_symlinks=False)
                if not stat.S_ISDIR(current_pki.st_mode) or \
                        (current_pki.st_dev, current_pki.st_ino) != (pki_initial.st_dev, pki_initial.st_ino) or \
                        (current.st_dev, current.st_ino) != (initial.st_dev, initial.st_ino) or \
                        current.st_uid != uid or stat.S_IMODE(current.st_mode) != 0o700:
                    raise NSSRefused('browser_private_NSS_database_changed')
            def invoke(args):
                database_identity()
                executable()
                if read('owner') != owner.encode() or read('test-ca.pem') != root_pem:
                    raise NSSRefused('browser_private_NSS_input_changed')
                result = subprocess.run([CERTUTIL] + args, cwd=home,
                    env={'HOME': str(home), 'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'},
                    capture_output=True, timeout=30, shell=False)
                database_identity()
                if result.returncode != 0 or result.stderr != b'':
                    raise NSSRefused('browser_private_NSS_command_failed')
                return result.stdout
            db = 'sql:' + str(home / '.pki/nssdb')
            if invoke(['-N', '--empty-password', '-d', db]) != b'':
                raise NSSRefused('browser_private_NSS_command_output')
            if invoke(['-A', '-d', db, '-n', 'phorge-owned-test-root', '-t', 'C,,',
                       '-i', str(home / 'test-ca.pem')]) != b'':
                raise NSSRefused('browser_private_NSS_command_output')
            exported = invoke(['-L', '-d', db, '-n', 'phorge-owned-test-root', '-a'])
            try:
                exported_der = ssl.PEM_cert_to_DER_cert(exported.decode('ascii'))
            except Exception:
                raise NSSRefused('browser_private_NSS_export_unknown') from None
            if hashlib.sha256(exported_der).hexdigest() != root_DER_sha256:
                raise NSSRefused('browser_private_NSS_export_changed')
            return {'profile': 'owned-Linux-NSS-root-only', 'private_root_import_export_exact': True,
                    'browser_normal_TLS_proved': False, 'global_trust_changed': False}
        finally:
            os.close(database)
    except NSSRefused:
        raise
    except Exception:
        raise NSSRefused('browser_private_NSS_unproved') from None
    finally:
        if fd is not None:
            os.close(fd)
