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
