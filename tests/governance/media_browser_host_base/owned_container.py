"""Stable-container-ID lifecycle seam. No launcher, VM or automatic invocation.

The reviewed parent must first prove the exact engine/context and dependency
observation. `command` is that parent's fixed argv executor, not JSON or CLI
input. Raw command streams stay private. No SQL cleanup is inferred here.
"""
import json
import os
from pathlib import Path
import re
import stat


class OwnershipUnknown(RuntimeError):
    pass


class LifecycleFailure(RuntimeError):
    def __init__(self, primary, cleanup):
        super().__init__('browser_container_lifecycle_unproved')
        # Private exception references preserve the primary failure; never dump
        # these objects or their messages into a public receipt.
        self.primary = primary
        self.cleanup = cleanup


def hex_value(value, size):
    return type(value) is str and re.fullmatch('[a-f0-9]{' + str(size) + '}', value) is not None


def snapshot(raw, name, owner, source, image_id):
    if type(raw) is not list or len(raw) != 1 or type(raw[0]) is not dict:
        raise OwnershipUnknown('browser_container_inspect_shape')
    row = raw[0]
    config = row.get('Config')
    if type(config) is not dict:
        raise OwnershipUnknown('browser_container_inspect_shape')
    labels = config.get('Labels')
    state = row.get('State')
    if not hex_value(row.get('Id'), 64) or row.get('Name') != name or \
            row.get('Image') != image_id or type(labels) is not dict or \
            any(labels.get(k) != v for k, v in {
                'phorge.browser.owner': owner, 'phorge.browser.source': source,
                'phorge.browser.profile': 'isolated-media-browser-v1'}.items()) or \
            type(state) is not dict or type(state.get('Running')) is not bool:
        raise OwnershipUnknown('browser_container_identity_unknown')
    return row['Id'], state['Running']


class OwnedContainer:
    def __init__(self, command, lease_path, *, nonce, owner, source, image_id,
                 image_reference, php_container_id):
        if not hex_value(nonce, 24) or not hex_value(owner, 64) or \
                not hex_value(source, 40) or not hex_value(php_container_id, 64) or \
                type(image_id) is not str or not re.fullmatch('sha256:[a-f0-9]{64}', image_id) or \
                type(image_reference) is not str or not re.fullmatch(
                    '(?:mcr\\.microsoft\\.com/playwright/python@sha256:|sha256:)[a-f0-9]{64}', image_reference) or \
                image_reference.startswith('sha256:') and image_reference != image_id:
            raise OwnershipUnknown('browser_container_external_profile_unknown')
        self.command, self.lease_path = command, Path(lease_path)
        if not self.lease_path.is_absolute() or self.lease_path.name != \
                'browser-' + nonce + '-lease.private.json':
            raise OwnershipUnknown('browser_container_external_lease_path')
        self.name, self.owner, self.source = 'phorge-media-browser-' + nonce, owner, source
        self.image_id, self.image_reference = image_id, image_reference
        self.php_container_id = php_container_id
        self.attempted, self.container_id = False, None

    def prepare_lease(self):
        # Parent owns and validates this directory; O_EXCL refuses old/foreign
        # leases. Persist BEFORE any create request, including lost replies.
        record = {'schema_version': 1, 'name': self.name, 'owner': self.owner,
                  'source': self.source, 'image_id': self.image_id,
                  'image_reference': self.image_reference,
                  'network_namespace_owner_container': self.php_container_id}
        parent = self.lease_path.parent
        if parent.resolve() != parent:
            raise OwnershipUnknown('browser_container_lease_parent_alias')
        directory = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            before = os.fstat(directory)
            if before.st_uid != os.geteuid() or stat.S_IMODE(before.st_mode) != 0o700:
                raise OwnershipUnknown('browser_container_lease_parent_owner')
            fd = os.open(self.lease_path.name,
                         os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                         0o600, dir_fd=directory)
            try:
                raw = (json.dumps(record, sort_keys=True) + '\n').encode()
                with os.fdopen(fd, 'wb', closefd=False) as stream:
                    stream.write(raw)
                    stream.flush()
                    os.fsync(fd)
            finally:
                os.close(fd)
            os.fsync(directory)
            after = os.stat(parent, follow_symlinks=False)
            if (before.st_dev, before.st_ino) != (after.st_dev, after.st_ino) or \
                    not stat.S_ISDIR(after.st_mode):
                raise OwnershipUnknown('browser_container_lease_parent_replaced')
        finally:
            os.close(directory)

    def inspect(self, selector):
        result = self.command(['container', 'inspect', selector])
        # Executor classifies exact engine-specific absence into this sole
        # sentinel. Unknown stderr/connection/timeout is never an absence.
        if type(result) is dict and set(result) == {'absent'} and result['absent'] is True:
            return None
        return snapshot(result, self.name, self.owner, self.source, self.image_id)

    def create(self):
        self.prepare_lease()
        self.attempted = True
        args = ['create', '--name', self.name,
            '--label', 'phorge.browser.owner=' + self.owner,
            '--label', 'phorge.browser.source=' + self.source,
            '--label', 'phorge.browser.profile=isolated-media-browser-v1',
            '--user', '1000:1000', '--network', 'container:' + self.php_container_id,
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--read-only', '--ipc', 'private', '--shm-size', '256m',
            '--tmpfs', '/home/pwuser:rw,nosuid,nodev,noexec,uid=1000,gid=1000,mode=0700,size=256m',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,mode=1777,size=256m',
            self.image_reference, '/bin/sleep', '1800']
        self.command(args)
        observed = self.inspect(self.name)
        if observed is None or observed[1]:
            raise OwnershipUnknown('browser_container_create_unproved')
        self.container_id = observed[0]
        return self.container_id

    def cleanup(self):
        # Inspect even when create raised after daemon-side creation. Name alone
        # never authorizes stop/remove. Unknown retains the external lease.
        if not self.attempted:
            return {'attempted': False, 'removed': False, 'lease_retained': True}
        observed = self.inspect(self.name)
        if observed is None:
            return {'attempted': True, 'removed': True, 'lease_retained': True}
        identity, running = observed
        if self.container_id is not None and identity != self.container_id:
            raise OwnershipUnknown('browser_container_replaced')
        self.container_id = identity
        if running:
            self.command(['stop', '--time', '10', identity])
            observed = self.inspect(identity)
            if observed is None or observed != (identity, False):
                raise OwnershipUnknown('browser_container_stop_unproved')
        self.command(['rm', identity])
        if self.inspect(identity) is not None:
            raise OwnershipUnknown('browser_container_remove_unproved')
        return {'attempted': True, 'removed': True, 'lease_retained': True,
                'SQL_cleanup_proved': False}

    def execute(self, action):
        """Trusted fixed parent's action, with primary+cleanup error retention."""
        primary, cleanup_error, result, cleanup = None, None, None, None
        try:
            identity = self.create()
            result = action(identity)
        except Exception as error:
            primary = error
        finally:
            try:
                cleanup = self.cleanup()
            except Exception as error:
                cleanup_error = error
        if primary is not None or cleanup_error is not None:
            raise LifecycleFailure(primary, cleanup_error)
        if type(cleanup) is not dict or cleanup.get('removed') is not True:
            raise LifecycleFailure(None, OwnershipUnknown('browser_container_cleanup_unproved'))
        return result, cleanup
