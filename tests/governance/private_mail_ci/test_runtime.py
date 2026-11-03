import copy
import ctypes
import signal
import time
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('wire_runtime', Path(__file__).with_name('runtime.py'))
wire = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wire)


class PortableRuntimeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name).resolve()
        source = self.root / 'source'; source.mkdir()
        hashes = {}
        for relative in wire.SOURCE_FILES:
            target = source / relative; target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('synthetic configuration validation only\n')
            hashes[relative] = wire.digest(target)
        binary = self.root / 'synthetic-binary'; binary.write_text('#!/bin/sh\nexit 0\n'); binary.chmod(0o700)
        revision = 'a' * 40
        self.config = {'schema': 1, 'engine': 'linux-local', 'source_root': str(source), 'source_revision': revision,
                       'source_sha256': hashes, 'php': str(Path(sys.executable).resolve()),
                       'binaries': {role: {'path': str(binary), 'sha256': wire.digest(binary), 'source_revision': revision} for role in wire.ROLES},
                       'gorge_revision': revision, 'database': {'host': '127.0.0.1', 'port': 3306, 'database': 'governance_fixture_bootstrap',
                                                              'user': 'root', 'password_env': 'TEST_PASSWORD'}, 'expected_pure_methods': 1}

    def tearDown(self):
        self.tmp.cleanup()

    def test_config_is_explicit_and_bound_to_source_and_binary_bytes(self):
        wire.validate_config(self.config)
        (Path(self.config['source_root']) / next(iter(wire.SOURCE_FILES))).write_text('changed')
        with self.assertRaisesRegex(ValueError, 'source_content_mismatch'):
            wire.validate_config(self.config)

    def test_foreign_database_and_unknown_role_are_not_test_profiles(self):
        for schema in (True, 1.0):
            data = copy.deepcopy(self.config); data['schema'] = schema
            with self.assertRaisesRegex(ValueError, 'configuration_shape_refused'):
                wire.validate_config(data)
        for key, value in [('host', 'localhost'), ('host', '0.0.0.0'), ('database', 'production'), ('port', True)]:
            data = copy.deepcopy(self.config); data['database'][key] = value
            with self.assertRaisesRegex(ValueError, 'disposable_database_profile_refused'):
                wire.validate_config(data)
        data = copy.deepcopy(self.config); del data['binaries']['observer']
        with self.assertRaisesRegex(ValueError, 'binary_roles_refused'):
            wire.validate_config(data)

    def test_paired_revision_and_binary_mismatch_are_not_accepted(self):
        data = copy.deepcopy(self.config); data['binaries']['mailer']['source_revision'] = 'b' * 40
        with self.assertRaisesRegex(ValueError, 'paired_gorge_revision_mismatch'):
            wire.validate_config(data)
        data = copy.deepcopy(self.config); data['binaries']['owlmail']['sha256'] = '0' * 64
        with self.assertRaisesRegex(ValueError, 'binary_content_mismatch'):
            wire.validate_config(data)

    def test_private_config_and_zero_selector_guard(self):
        config = self.root / 'config.json'; config.write_text(json.dumps(self.config)); config.chmod(0o600)
        with patch.dict(os.environ, {'ATOMIC_TEST_DISPOSABLE': '1', 'PHORGE_CONTROL_PLANE': '0'}):
            with self.assertRaisesRegex(ValueError, 'deployment_selector_refused'):
                wire.load_config(config)
        config.chmod(0o644)
        with self.assertRaisesRegex(ValueError, 'private_file_scope_refused'):
            wire.private_file(config)

    def test_foreign_http_and_redirect_protocols_are_refused_before_connect(self):
        for url in ['https://127.0.0.1:8080/', 'http://localhost:8080/', 'http://0.0.0.0:8080/', 'http://127.0.0.1:8080/#x', 'http://user@127.0.0.1:8080/']:
            with self.assertRaisesRegex(ValueError, 'loopback_request_refused'):
                wire.loopback_request(url)

    def test_owned_process_group_and_storage_are_actually_cleaned(self):
        runtime = wire.OwnedRuntime(self.config)
        process = runtime.spawn([sys.executable, '-c', 'import time; time.sleep(30)'])
        result = runtime.close(remove=True)
        self.assertIsNotNone(process.returncode)
        self.assertEqual(0, result['live_children'])
        self.assertTrue(result['private_directory_removed'])

    def test_marker_replacement_never_deletes_unproved_directory(self):
        runtime = wire.OwnedRuntime(self.config)
        owner = runtime.directory / 'owner'; owner.write_text('foreign')
        with self.assertRaises(ValueError):
            runtime.close(remove=True)
        self.assertTrue(runtime.directory.is_dir())
        owner.write_text(runtime.owner); owner.chmod(0o600)
        self.assertTrue(runtime.close(remove=True)['private_directory_removed'])

    def test_owner_initialization_failure_does_not_leave_unregistered_directory(self):
        before = set(Path('/tmp').glob('phorge-private-mail-wire-*'))
        with patch.object(wire.OwnedRuntime, 'write', side_effect=RuntimeError('owned_fault')):
            with self.assertRaisesRegex(RuntimeError, 'owned_fault'):
                wire.OwnedRuntime(self.config)
        self.assertEqual(before, set(Path('/tmp').glob('phorge-private-mail-wire-*')))

    @unittest.skipUnless(sys.platform == 'linux', 'Linux process ownership proof')
    def test_owned_term_resistant_process_is_killed_and_reaped(self):
        runtime = wire.OwnedRuntime(self.config)
        marker = runtime.directory / 'ready'
        code = "import signal,time;signal.signal(signal.SIGTERM,signal.SIG_IGN);open(" + repr(str(marker)) + ",'w').write('ready');time.sleep(30)"
        process = runtime.spawn([sys.executable, '-c', code])
        try:
            deadline = time.monotonic() + 5
            while not marker.exists():
                self.assertLess(time.monotonic(), deadline)
                time.sleep(.01)
            result = runtime.close(remove=True)
            self.assertEqual(-signal.SIGKILL, process.returncode)
            self.assertEqual(0, result['live_children'])
            self.assertTrue(result['private_directory_removed'])
        finally:
            if not runtime.closed:
                runtime.close(remove=True)

    @unittest.skipUnless(sys.platform == 'linux', 'Linux process ownership proof')
    def test_exited_leader_with_live_owned_descendant_is_reaped(self):
        self._orphan_case(unproved=False)

    @unittest.skipUnless(sys.platform == 'linux', 'Linux process ownership proof')
    def test_unknown_orphan_group_is_not_killed_or_reported_clean(self):
        self._orphan_case(unproved=True)

    def _orphan_case(self, unproved):
        # Test-only subreaper makes this exact orphan reappable by the test,
        # instead of relying on an arbitrary container PID1 to reap zombies.
        libc = ctypes.CDLL(None, use_errno=True)
        old = ctypes.c_int()
        self.assertEqual(0, libc.prctl(37, ctypes.byref(old), 0, 0, 0))
        self.assertEqual(0, libc.prctl(36, 1, 0, 0, 0))
        runtime = wire.OwnedRuntime(self.config)
        marker = runtime.directory / 'descendant.pid'
        child = None
        identity = None
        try:
            if unproved:
                child_code = "import os,time;open(" + repr(str(marker)) + ",'w').write(str(os.getpid()));time.sleep(30)"
                code = "import os,sys;pid=os.fork();os.execve(sys.executable,[sys.executable,'-c'," + repr(child_code) + "],{'PATH':'/usr/bin:/bin'}) if pid==0 else os._exit(0)"
            else:
                code = "import os,time;pid=os.fork();(open(" + repr(str(marker)) + ",'w').write(str(os.getpid())),time.sleep(30)) if pid==0 else os._exit(0)"
            leader = runtime.spawn([sys.executable, '-c', code])
            self.assertEqual(0, leader.wait(timeout=5))
            deadline = time.monotonic() + 5
            while not marker.exists():
                self.assertLess(time.monotonic(), deadline)
                time.sleep(.01)
            child = int(marker.read_text())
            identity = runtime._identity(child)
            self.assertEqual(leader.pid, identity['group'])
            if unproved:
                with self.assertRaisesRegex(ValueError, 'owned_group_descendants_unverified'):
                    runtime.close(remove=True)
                self.assertTrue(runtime.directory.is_dir())
                self.assertIsNotNone(runtime._identity(child))
            else:
                result = runtime.close(remove=True)
                self.assertEqual(0, result['live_children'])
                self.assertTrue(result['private_directory_removed'])
                self.assertIsNone(runtime._identity(child))
        finally:
            if child is not None:
                now = runtime._identity(child)
                if now is not None and identity is not None and now['start'] == identity['start'] and now['uid'] == os.getuid():
                    os.kill(child, signal.SIGKILL)
                    os.waitpid(child, 0)
            if not runtime.closed:
                runtime.close(remove=True)
            self.assertEqual(0, libc.prctl(36, old.value, 0, 0, 0))


if __name__ == '__main__':
    unittest.main()
