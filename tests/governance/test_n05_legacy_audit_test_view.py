"""Negative coverage for exact historical-only test views; no native SQL."""
from pathlib import Path
import shutil, ast, hashlib, subprocess, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
import n05_legacy_audit_test_view as view
ROOT = Path(__file__).resolve().parents[2]

class LegacyView(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        from task_inverse_test_api_v2 import owner
        from task_historical_views_test_api_v2 import in_operation
        root = Path(__file__).resolve().parents[2]
        cls._source_owner = owner(root)
        try:
            cls._source_operation = cls._source_owner.__enter__()
            (cls._legacy, cls._r3) = in_operation(cls._source_operation, root)
        except BaseException:
            import sys
            if hasattr(cls, '_source_operation'):
                cls._source_owner.__exit__(*sys.exc_info())
            raise

    @classmethod
    def tearDownClass(cls):
        try:
            cls._legacy.close_DATA()
        finally:
            cls._source_owner.__exit__(None, None, None)

    def fixture(self, root):
        self._legacy.fixture_DATA(root)
        self._legacy.verify_current_cohort(root)

    def test_native_checker_exact_current_inverse_retains_old_repair_oracle(self):
        path = 'scripts/governance/check_native_organization.py'
        self.assertIn(path, self._legacy.REPAIR_SOURCE_PINS)
        old = self._legacy.git_bytes(self._legacy.CURRENT_REVISION, path)
        actual = (ROOT / path).read_bytes()
        parent = self._legacy.git_bytes(self._legacy.n05.TARGET, path)
        current = self._source_operation._modules['project_description_admission_delta']
        expected_parent_sha = hashlib.sha256(parent).hexdigest()
        self.assertEqual(current.native_pair_parent_DATA(actual, expected_parent_sha), parent)
        for bad in (parent, actual + b'\n', actual.replace(b'retired-wiki-mail-refused-policy-on', b'unknown-wiki-mail-refused-policy-on')):
            with self.subTest(pair_input='unknown-or-historical'), self.assertRaises(ValueError):
                current.native_pair_parent_DATA(bad, expected_parent_sha)
        self.assertNotEqual(actual, old)
        self.assertEqual(hashlib.sha256(old).hexdigest(), self._legacy.REPAIR_SOURCE_PINS[path])
        self.assertEqual(self._legacy.n05._normalize_metadata(path, actual), old)
        self.assertEqual(old, self._legacy.git_bytes('b93916d8a2beb4a9ab28ea1769692c0839cfc60b', path))
        self._legacy.verify_current_cohort(ROOT)
        before = (dict(self._legacy.REPAIR_SOURCE_PINS), tuple(self._legacy.CLOSED_PATHS), tuple(self._legacy.METADATA_PATHS))
        for mutation in ('old-512', 'unknown', 'missing', 'mode', 'symlink', 'patched-pin'):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as td:
                root = Path(td).resolve()
                self.fixture(root)
                p = root / path
                if mutation == 'old-512':
                    p.write_bytes(old)
                elif mutation == 'missing':
                    p.unlink()
                elif mutation == 'mode':
                    p.chmod(493)
                elif mutation == 'symlink':
                    p.unlink()
                    p.symlink_to(ROOT / path)
                else:
                    p.write_bytes(actual + b'unknown')
                if mutation == 'patched-pin':
                    with patch.dict(self._legacy.REPAIR_SOURCE_PINS, {path: hashlib.sha256(p.read_bytes()).hexdigest()}), self.assertRaises((ValueError, OSError)):
                        self._legacy.verify_current_cohort(root)
                else:
                    with self.assertRaises((ValueError, OSError)):
                        self._legacy.verify_current_cohort(root)
        self.assertEqual(before, (dict(self._legacy.REPAIR_SOURCE_PINS), tuple(self._legacy.CLOSED_PATHS), tuple(self._legacy.METADATA_PATHS)))

    def test_six_whole_sources_equal_real_prior_Git_without_changing_runtime_files(self):
        for path in self._legacy.CLOSED_PATHS:
            raw = (ROOT / path).read_bytes()
            current = self._legacy.n05._normalize_metadata(path, raw) if path in self._legacy.n05.CURRENT_METADATA else raw
            self.assertEqual(current, self._legacy.git_bytes(self._legacy.CURRENT_REVISION, path))
            self.assertEqual(self._legacy.historical_tooling_source(path), self._legacy.git_bytes(self._legacy.PRIOR_REVISION, path))
            self.assertEqual((ROOT / path).read_bytes(), raw)
        raw = (ROOT / 'src/__phutil_library_map__.php').read_bytes()
        self.assertEqual(self._legacy.historical_library_map(raw), self._legacy.git_bytes(self._legacy.PRIOR_REVISION, 'src/__phutil_library_map__.php'))
        self.assertNotEqual(raw, self._legacy.historical_library_map(raw))

    def test_changed_current_tooling_cannot_use_old_payload_unknown_bytes_or_mode(self):
        changed = [name for name in self._legacy.CLOSED_PATHS if name in self._legacy.n05.CURRENT_METADATA]
        self.assertEqual(len(changed), 5)
        for path in changed:
            for mutation in ('old-512', 'unknown', 'mode'):
                with self.subTest(path=path, mutation=mutation), tempfile.TemporaryDirectory() as td:
                    root = Path(td).resolve()
                    self.fixture(root)
                    p = root / path
                    if mutation == 'old-512':
                        p.write_bytes(self._legacy.git_bytes(self._legacy.CURRENT_REVISION, path))
                    elif mutation == 'unknown':
                        p.write_bytes(p.read_bytes() + b'unknown')
                    else:
                        p.chmod(493)
                    with self.assertRaises((ValueError, OSError)):
                        self._legacy.historical_tooling_source(path, root)

    def test_repair_guard_is_exact_actual_combined_target_and_test_only(self):
        self.assertEqual(self._legacy.CURRENT_REVISION, '512282be298c7996fc6370156c333d7557b1685a')
        self.assertEqual(len(self._legacy.REPAIR_SOURCE_PINS), 9)
        for (path, pin) in self._legacy.REPAIR_SOURCE_PINS.items():
            raw = (ROOT / path).read_bytes()
            if path in set(self._legacy.METADATA_PATHS) | {'scripts/governance/check_native_organization.py'}:
                prior = self._legacy.n05._normalize_metadata(path, raw)
            elif path == 'scripts/governance/check_native_directory.py':
                self.assertEqual(raw, self._legacy.git_bytes(self._legacy.n05.TARGET, path))
                prior = self._legacy.git_bytes(self._legacy.CURRENT_REVISION, path)
            else:
                prior = self._source_operation._task_contexts[ROOT].parent(path)
            self.assertEqual(prior, self._legacy.git_bytes(self._legacy.CURRENT_REVISION, path))
            self.assertEqual(hashlib.sha256(prior).hexdigest(), pin)
        source = ROOT / 'scripts/governance'
        for p in source.glob('*.py'):
            for node in ast.walk(ast.parse(p.read_text())):
                if isinstance(node, ast.Import):
                    self.assertFalse(any((a.name.endswith('n05_legacy_audit_test_view') for a in node.names)), str(p))
                elif isinstance(node, ast.ImportFrom):
                    self.assertFalse((node.module or '').endswith('n05_legacy_audit_test_view'), str(p))
                elif isinstance(node, ast.Call) and node.args and isinstance(node.args[0], ast.Constant) and (node.args[0].value == 'n05_legacy_audit_test_view'):
                    self.assertFalse(isinstance(node.func, ast.Name) and node.func.id == '__import__' or (isinstance(node.func, ast.Attribute) and node.func.attr == 'import_module'), str(p))

    def test_two_metadata_views_require_exact_combined_current_and_real_prior(self):
        self.assertEqual(set(self._legacy.METADATA_PATHS), {'docs/governance/native-directory-callers.json', 'tests/governance/test_recovery_partial_schema_coordination.py'})
        for path in self._legacy.METADATA_PATHS:
            raw = (ROOT / path).read_bytes()
            old = self._legacy.git_bytes(self._legacy.METADATA_PRIOR_REVISION, path)
            self.assertEqual(self._legacy.historical_metadata_source(path, raw), old)
            self.assertEqual((ROOT / path).read_bytes(), raw)
            with self.assertRaises(ValueError):
                self._legacy.historical_metadata_source(path, raw + b'unknown')
        with self.assertRaises(ValueError):
            self._legacy.historical_metadata_source('tests/unreviewed.py', b'unknown')

    def test_new_metadata_refuses_exact_previous_current_payload_and_checker_mixed_sources(self):
        for path in self._legacy.METADATA_PATHS:
            current = (ROOT / path).read_bytes()
            previous = self._legacy.git_bytes(self._legacy.CURRENT_REVISION, path)
            self.assertNotEqual(current, previous)
            with self.subTest(path=path), self.assertRaises(ValueError):
                self._legacy.historical_metadata_source(path, previous)
        path = 'scripts/governance/check_native_directory.py'
        for mode in ('unknown', 'old-512', 'executable', 'missing'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as td:
                root = Path(td).resolve()
                self.fixture(root)
                raw = (root / path).read_bytes()
                if mode == 'executable':
                    (root / path).chmod(493)
                elif mode == 'missing':
                    (root / path).unlink()
                else:
                    (root / path).write_bytes(raw + b'unknown' if mode == 'unknown' else self._legacy.git_bytes(self._legacy.CURRENT_REVISION, path))
                with self.assertRaises((ValueError, OSError)):
                    self._legacy.verify_current_cohort(root)
        with patch.object(self._legacy.n05, 'TARGET', self._legacy.CURRENT_REVISION), self.assertRaises(ValueError):
            self._legacy.verify_current_cohort(ROOT)

    def test_missing_mixed_and_double_patched_metadata_cannot_become_historical(self):
        for mode in ('missing', 'mixed', 'double-patched'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as td:
                root = Path(td).resolve()
                self.fixture(root)
                path = self._legacy.METADATA_PATHS[-1]
                p = root / path
                if mode == 'missing':
                    p.unlink()
                elif mode == 'mixed':
                    p.write_bytes(self._legacy.git_bytes(self._legacy.METADATA_PRIOR_REVISION, path))
                else:
                    bad = p.read_bytes() + b'unknown'
                    p.write_bytes(bad)
                    with patch.dict(self._legacy.METADATA_CURRENT_PINS, {path: hashlib.sha256(bad).hexdigest()}), patch.dict(self._legacy.n05.SOURCE_INPUTS, {path: hashlib.sha256(bad).hexdigest()}), self.assertRaises(ValueError):
                        self._legacy.historical_metadata_source(path, bad, root)
                    continue
                with self.assertRaises((ValueError, OSError)):
                    self._legacy.historical_metadata_source(path, b'unknown', root)

    def test_unknown_path_payload_and_patched_pin_never_become_historical_evidence(self):
        with self.assertRaises(ValueError):
            self._legacy.historical_tooling_source('scripts/governance/unreviewed.py')
        with self.assertRaises(ValueError):
            self._legacy.historical_library_map((ROOT / 'src/__phutil_library_map__.php').read_bytes() + b'unknown')
        path = self._legacy.CLOSED_PATHS[0]
        with tempfile.TemporaryDirectory() as td:
            root = Path(td).resolve()
            self.fixture(root)
            raw = (root / path).read_bytes()
            bad = raw + b'unknown'
            (root / path).write_bytes(bad)
            with self.assertRaises(ValueError):
                self._legacy.historical_tooling_source(path, root)
            with patch.dict(self._legacy.CURRENT_PINS, {path: hashlib.sha256(bad).hexdigest()}), patch.dict(self._legacy.n05.SOURCE_INPUTS, {path: hashlib.sha256(bad).hexdigest()}), self.assertRaises(ValueError):
                self._legacy.historical_tooling_source(path, root)

    def test_missing_mixed_or_unreviewed_actual_fixture_cohort_refuses(self):
        path = self._legacy.CLOSED_PATHS[0]
        for mode in ['missing', 'mixed', 'fixture', 'repair-missing', 'repair-unknown', 'repair-patchpin']:
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as td:
                root = Path(td).resolve()
                self.fixture(root)
                if mode == 'missing':
                    (root / self._legacy.CLOSED_PATHS[-1]).unlink()
                elif mode == 'mixed':
                    (root / self._legacy.CLOSED_PATHS[-1]).write_bytes(self._legacy.git_bytes(self._legacy.PRIOR_REVISION, self._legacy.CLOSED_PATHS[-1]))
                elif mode.startswith('repair-'):
                    name = next(iter(self._legacy.REPAIR_SOURCE_PINS))
                    f = root / name
                    if mode == 'repair-missing':
                        f.unlink()
                    else:
                        f.write_bytes(f.read_bytes() + b'unknown')
                        if mode == 'repair-patchpin':
                            with patch.dict(self._legacy.REPAIR_SOURCE_PINS, {name: hashlib.sha256(f.read_bytes()).hexdigest()}), self.assertRaises(ValueError):
                                self._legacy.historical_tooling_source(path, root)
                            continue
                else:
                    f = root / 'support/governance/completion/fixture.php'
                    f.write_bytes(f.read_bytes() + b'unknown')
                with self.assertRaises((ValueError, OSError)):
                    self._legacy.historical_tooling_source(path, root)
if __name__ == '__main__':
    unittest.main()
