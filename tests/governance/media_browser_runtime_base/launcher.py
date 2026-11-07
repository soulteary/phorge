"""Fixed browser-profile composition. No container/service/SQL provisioning.

Root must establish the immutable image/source, two owned HOME leases and actual
three TLS endpoints first. This cannot run with an unreviewed browser tool pin.
"""
from contextlib import contextmanager
import hashlib
import importlib.metadata
import os
from pathlib import Path
import re
import stat
import sys

from . import flow, nss_profile, trust_controls

BROWSER = '/ms-playwright/chromium-1248/chrome-linux/chrome'
BROWSER_SHA256 = None  # Actual immutable-image observation, not caller input.


class LaunchUnproved(RuntimeError):
    def __init__(self, primary=None, cleanup=None):
        super().__init__('owned_browser_launch_or_cleanup_unproved')
        self.primary, self.cleanup = primary, cleanup  # private; never serialize


def executable():
    if type(BROWSER_SHA256) is not str or not re.fullmatch('[a-f0-9]{64}', BROWSER_SHA256):
        raise LaunchUnproved()
    fd = os.open(BROWSER, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_nlink != 1 or \
                not before.st_mode & 0o111 or not 0 < before.st_size <= 536870912:
            raise LaunchUnproved()
        digest = hashlib.sha256()
        remaining = before.st_size
        while remaining:
            raw = os.read(fd, min(65536, remaining))
            if not raw:
                raise LaunchUnproved()
            digest.update(raw)
            remaining -= len(raw)
        after = os.fstat(fd)
        if (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) != \
                (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns) or \
                digest.hexdigest() != BROWSER_SHA256:
            raise LaunchUnproved()
    finally:
        os.close(fd)


def home_identity(path, expected):
    path = Path(path)
    if path.parent != Path('/home/pwuser') or path.resolve() != path or \
            not re.fullmatch('phorge-browser-[a-f0-9]{24}', path.name) or \
            type(expected) is not tuple or len(expected) != 2 or \
            any(type(v) is not int or v < 0 for v in expected):
        raise LaunchUnproved()
    st = path.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid != 1000 or stat.S_IMODE(st.st_mode) != 0o700 or \
            (st.st_dev, st.st_ino) != expected:
        raise LaunchUnproved()
    return path


@contextmanager
def context(playwright, home, identity, profile):
    try:
        home = home_identity(home, identity)
        executable()
        if profile not in {'untrusted-profile', 'trusted-profile'}:
            raise LaunchUnproved()
        # Stable-dirfd creation cannot write into a replacement HOME. Chromium's
        # later path use additionally requires the parent's dedicated, host-mount-
        # free container/process closure; this library does not certify that closure.
        parent_fd = os.open(home, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            parent_stat = os.fstat(parent_fd)
            if (parent_stat.st_dev, parent_stat.st_ino) != identity:
                raise LaunchUnproved()
            os.mkdir(profile, 0o700, dir_fd=parent_fd)
            home_identity(home, identity)
        finally:
            os.close(parent_fd)
    except Exception as error:
        raise LaunchUnproved(error, None) from None
    primary, cleanup_error, browser = None, None, None
    try:
        browser = playwright.chromium.launch_persistent_context(str(home / profile),
            executable_path=BROWSER, headless=True, chromium_sandbox=True,
            ignore_https_errors=False, accept_downloads=False,
            env={'HOME': str(home), 'PATH': '/usr/bin:/bin', 'LANG': 'en_US.UTF-8',
                 'LC_ALL': 'en_US.UTF-8'},
            args=['--no-proxy-server', '--disable-background-networking',
                  '--host-resolver-rules=MAP app.media.localhost 127.0.0.1, '
                  'MAP files.media.localhost 127.0.0.1, '
                  'MAP wrong.media.localhost 127.0.0.1, MAP * ~NOTFOUND'])
        home_identity(home, identity)
        yield browser
    except Exception as error:
        primary = error
    finally:
        if browser is not None:
            try:
                browser.close()
            except Exception as error:
                cleanup_error = error
        try:
            home_identity(home, identity)
        except Exception as error:
            cleanup_error = error
    if primary is not None or cleanup_error is not None:
        raise LaunchUnproved(primary, cleanup_error)


def run(playwright, manifest, *, untrusted_home, untrusted_identity, trusted_home,
        trusted_identity, owner, root_DER_sha256, wrong_leaf_origin):
    # Caller's browser object is only the fixed parent's actual pinned driver.
    # No production route or arbitrary JSON input invokes this function.
    if sys.platform != 'linux' or os.geteuid() != 1000 or \
            importlib.metadata.version('playwright') != '1.64.1' or \
            Path(untrusted_home) == Path(trusted_home):
        raise LaunchUnproved()
    app, _ = flow.validate_manifest(manifest)
    executable()
    # These exact separate HOME namespaces are required: Chromium may initialize
    # NSS during the untrusted probe. Never reuse that database for the import.
    with context(playwright, untrusted_home, untrusted_identity, 'untrusted-profile') as browser:
        untrusted = trust_controls.negative(browser, app, 'untrusted-root')
    nss = nss_profile.prepare(trusted_home, owner=owner, device=trusted_identity[0],
        inode=trusted_identity[1], root_DER_sha256=root_DER_sha256)
    if type(nss) is not dict or nss.get('private_root_import_export_exact') is not True:
        raise LaunchUnproved()
    with context(playwright, trusted_home, trusted_identity, 'trusted-profile') as browser:
        wrong_leaf = trust_controls.negative(browser, wrong_leaf_origin, 'wrong-leaf')
        wrong_host = trust_controls.negative(browser, app.replace('app.media.localhost',
                                            'wrong.media.localhost'), 'wrong-hostname')
        actual_flow = flow.run(browser, manifest)
    if type(actual_flow) is not dict or actual_flow.get('passed') is not True or \
            any(type(item) is not dict or item.get('actual_document_certificate_failure_observed') is not True
                for item in [untrusted, wrong_leaf, wrong_host]):
        raise LaunchUnproved()
    return {'schema_version': 1, 'scope': 'owned-browser-negative-and-native-flow-composition',
            'passed': True, 'certificate_negatives': [untrusted, wrong_leaf, wrong_host],
            'private_NSS': nss, 'native_browser_flow': actual_flow,
            'actual_endpoint_and_source_binding_proved': False,
            'SQL_and_container_cleanup_proved': False, 'global_trust_changed': False,
            'restoration_authorized': False, 'deployment': False}
