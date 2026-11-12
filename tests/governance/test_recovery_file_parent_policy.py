"""Actual bounded consumer with explicit collaborators; no SQL/snapshot proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]

class FileParentPolicy(unittest.TestCase):
    def run_case(self, case):
        p = subprocess.run(['php', str(ROOT / 'support/governance/recovery/tests/file-parent-policy-query-seam.php'), str(ROOT), case], capture_output=True, text=True, timeout=10)
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertEqual(p.stderr, '')
        data = json.loads(p.stdout)
        self.assertFalse(data['SQL_executed'])
        return data

    def test_actual_queries_are_viewer_aware_and_exact_bounded(self):
        d = self.run_case('admitted')
        self.assertIsNone(d['error'])
        r = d['result']
        self.assertRegex(r['review_binding'], r'^[a-f0-9]{64}$')
        self.assertEqual(next(iter(r['observations'].values()))['state'], 'admitted')
        self.assertEqual([q[0] for q in d['queries']], ['ManiphestTaskQuery', 'PhabricatorFileQuery'] * 2)
        self.assertEqual(d['viewer_exact'], [True] * 4)
        for key in ('same_transaction_snapshot_proved', 'durable_review_compatible', 'dependency_budget_proved', 'all_object_classes_covered', 'all_sources_complete', 'complete', 'restoration_authorized'):
            self.assertIs(r[key], False, key)
        self.assertTrue(r['execution_blocked'])
        self.assertEqual(r['review_binding'], self.run_case('admitted')['result']['review_binding'])

    def test_denied_and_unsupported_are_distinct_and_remain_blocked(self):
        for case, state in [('denied', 'denied'), ('no-parent', 'unsupported'), ('profile', 'unsupported'), ('unsupported-parent', 'unsupported'), ('deleted-parent', 'denied')]:
            d = self.run_case(case)
            self.assertIsNone(d['error'])
            self.assertEqual(next(iter(d['result']['observations'].values()))['state'], state)
            self.assertTrue(d['result']['execution_blocked'])
            if case == 'no-parent':
                self.assertEqual(d['queries'], [])
                self.assertFalse(d['result']['native_policy_queries_executed'])
        self.assertNotEqual(self.run_case('denied')['result']['review_binding'], self.run_case('admitted')['result']['review_binding'])

    def test_file_author_observation_does_not_claim_parent_admission(self):
        r = self.run_case('parent-denied')['result']
        item = next(iter(r['observations'].values()))
        self.assertEqual(item['state'], 'admitted')
        self.assertEqual(list(item['Task_parents'].values()), ['denied'])
        self.assertFalse(r['durable_review_compatible'])

    def test_account_admission_precedes_native_automatic_author_grants(self):
        for case in ('anonymous', 'omnipotent', 'disabled'):
            d = self.run_case(case)
            self.assertEqual(d['error'], 'recovery_file_parent_viewer_refused')
            self.assertIsNone(d['result'])
            self.assertEqual(d['queries'], [])

    def test_topology_and_invisible_parent_policy_mutations_stale_capture(self):
        for case in ('topology-mutation', 'policy-mutation'):
            d = self.run_case(case)
            self.assertEqual(d['error'], 'recovery_file_parent_review_stale')
            self.assertIsNone(d['result'])

    def test_query_errors_and_foreign_duplicate_stale_native_rows_fail_closed(self):
        for case, reason in [('duplicate', 'recovery_file_parent_query_result_unknown'), ('foreign', 'recovery_file_parent_query_identity_changed'), ('stale-native', 'recovery_file_parent_native_rows_changed'), ('query-error', 'original-query-error')]:
            d = self.run_case(case)
            self.assertEqual(d['error'], reason)
            self.assertIsNone(d['result'])
            self.assertEqual(d['original'], case == 'query-error')

    def test_real_native_result_decoder_accepts_only_exact_models(self):
        # Load actual native model implementations; no query/SQL substitutes.
        code = r'''ob_start();require $argv[1].'/support/governance/recovery/tests/file-source-native-controls.php';ob_end_clean();$m=new ReflectionMethod(PhorgeRecoveryFileSourceFacts::class,'exactPolicyResult');$p='PHID-TASK-'.str_repeat('t',20);$t=(new ManiphestTask())->setPHID($p);$ok=$m->invoke(null,[$t],ManiphestTask::class,$p)===$t&&$m->invoke(null,[],ManiphestTask::class,$p)===null;$refused=0;foreach([[$t,$t],[(new ManiphestTask())->setPHID('PHID-TASK-'.str_repeat('x',20))],[new PhabricatorFile()],[new stdClass()],[null]]as$rows){try{$m->invoke(null,$rows,ManiphestTask::class,$p);}catch(RuntimeException $e){$refused++;}}echo json_encode(['ok'=>$ok,'refused'=>$refused]);'''
        p = subprocess.run(['php', '-r', code, str(ROOT)], capture_output=True, text=True, timeout=10)
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertEqual(p.stderr, '')
        self.assertEqual(json.loads(p.stdout), {'ok': True, 'refused': 5})

class FileParentWireSourceRoles(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import sys
        sys.path.insert(0, str(ROOT / 'scripts/governance'))
        import check_recovery_file_parent_policy
        cls.gate = check_recovery_file_parent_policy

    def copied_sources(self, target):
        import shutil
        for name in self.gate.SOURCE_INPUTS:
            path = target / name
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / name, path)
            path.chmod(0o644)

    def receipt(self):
        import test_recovery_partial_composition_gate as predecessor
        gate = self.gate
        data = predecessor.template()
        checks = sorted(gate.source_obligations(ROOT))
        data.update(batch='native-File-parent-policy-endpoints',
            scope='owned-native-File-parent-policy-endpoints-no-permit',
            checks=checks, check_count=len(checks), native=gate.summary('a' * 40, root=ROOT))
        data['file_service_cleanup']['binary_sha256'] = 'b' * 64
        return data

    def test_current_raw_wire_and_historical_obligation_hashes_are_distinct_roles(self):
        import hashlib
        gate = self.gate
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        old = dict(gate.SOURCE_INPUTS)
        raw = gate.read_source_checked(ROOT / driver)
        prior = gate.native_driver_view.reduce_policy_cleanup_driver(raw)
        self.assertNotEqual(raw, prior)
        self.assertEqual(hashlib.sha256(prior).hexdigest(), old[driver])
        summary = gate.summary('a' * 40, root=ROOT)
        expected = dict(old)
        expected[driver] = hashlib.sha256(raw).hexdigest()
        self.assertEqual(summary['source_inputs_sha256'], expected)
        self.assertEqual(gate.SOURCE_INPUTS, old)
        self.assertEqual(len(gate.source_obligations(ROOT)), 16)

    def test_real_historical_driver_root_keeps_original_pin_and_explicit_root(self):
        import hashlib, tempfile
        gate = self.gate
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            self.copied_sources(root)
            historical = subprocess.check_output(['git', '--no-replace-objects', '-C', str(ROOT),
                'show', '20b138b4cd9a2cf61522613b4a8e5e21eca7341b:' + driver])
            self.assertEqual(hashlib.sha256(historical).hexdigest(), gate.SOURCE_INPUTS[driver])
            (root / driver).write_bytes(historical)
            observed = gate.summary('b' * 40, root=root)
            self.assertEqual(observed['source_inputs_sha256'], gate.SOURCE_INPUTS)
            self.assertEqual(len(gate.source_obligations(root)), 16)
            current = self.receipt()
            # This rejects at native wire equality, before missing restore inputs.
            with self.assertRaisesRegex(ValueError, '^Fileparent evidence refused$'):
                gate.validate(current, root=root, source_revision='a' * 40, file_digest='b' * 64)

    def test_raw_wire_receipt_accepts_current_but_never_normalized_or_self_reported_digest(self):
        import copy
        gate = self.gate
        data = self.receipt()
        self.assertTrue(gate.validate(data, root=ROOT, source_revision='a' * 40,
            file_digest='b' * 64)['passed'])
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        for digest in (gate.SOURCE_INPUTS[driver], '0' * 64):
            bad = copy.deepcopy(data)
            bad['native']['source_inputs_sha256'][driver] = digest
            with self.assertRaisesRegex(ValueError, '^Fileparent evidence refused$'):
                gate.validate(bad, root=ROOT, source_revision='a' * 40, file_digest='b' * 64)

    def test_unknown_driver_other_source_and_unsafe_mode_refuse_before_wire_summary(self):
        import tempfile
        gate = self.gate
        driver = 'support/governance/native-organization/test-native-organization-mysql.php'
        other = 'src/applications/people/storage/PhabricatorUser.php'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            self.copied_sources(root)
            for name in (driver, other):
                path = root / name
                before = path.read_bytes()
                path.write_bytes(before + b'\nunknown unreviewed source')
                try:
                    with self.subTest(path=name), self.assertRaises(ValueError):
                        gate.summary('c' * 40, root=root)
                finally:
                    path.write_bytes(before)
            path = root / driver
            path.chmod(0o666)
            try:
                with self.assertRaises(ValueError):
                    gate.summary('c' * 40, root=root)
            finally:
                path.chmod(0o644)
