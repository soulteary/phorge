"""Pure protocol controls; these do not claim real SQL or child lifecycle proof."""
import copy
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / 'support/governance/native-execution/project-policy-nested-process.php'
WORKER = ROOT / 'support/governance/native-execution/project-policy-nested-worker.php'


class NestedPolicyProtocolTest(unittest.TestCase):
    def setUp(self):
        self.owner = 'a' * 64
        self.ready = dict(cid=37, owner=self.owner, principal='fixture@%', uuid='synthetic')
        self.result = dict(cid=37, owner=self.owner, status='expected-quarantine',
                           configuration_sources_match=True, real_update_before_error=True, nested_depth=2, rollback_count=0,
                           primary_error_preserved=True, unknown_not_acknowledged=True,
                           retry_attempted=False)

    def invoke(self, ready, result):
        program = ('require $argv[1]; $p=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);'
                   'try{governance_policy_nested_result($p[0],$p[1],$p[2]);echo "accepted";}'
                   'catch(Throwable $e){echo "refused";}')
        run = subprocess.run(['php', '-r', program, str(HELPER)],
                             input=json.dumps([ready, result, self.owner]), text=True,
                             capture_output=True, timeout=5, check=True)
        self.assertEqual(run.stderr, '')
        return run.stdout

    def test_expected_unknown_is_protocol_only_not_rollback_ack(self):
        self.assertEqual(self.invoke(self.ready, self.result), 'accepted')

    def test_every_identity_effect_and_quarantine_field_is_required(self):
        mutations = dict(cid=38, owner='b' * 64, status='success', configuration_sources_match=False, real_update_before_error=False,
                         nested_depth=1, rollback_count=1, primary_error_preserved=False,
                         unknown_not_acknowledged=False, retry_attempted=True)
        for key, value in mutations.items():
            with self.subTest(key=key):
                changed = dict(self.result, **{key: value})
                self.assertEqual(self.invoke(self.ready, changed), 'refused')
        for key in self.result:
            with self.subTest(missing=key):
                changed = copy.copy(self.result)
                del changed[key]
                self.assertEqual(self.invoke(self.ready, changed), 'refused')

    def test_bool_number_missing_and_extra_fields_fail_closed(self):
        for key, value in [('cid', True), ('nested_depth', '2'), ('rollback_count', False)]:
            with self.subTest(key=key):
                self.assertEqual(self.invoke(self.ready, dict(self.result, **{key: value})), 'refused')
        self.assertEqual(self.invoke(dict(self.ready, cid=True), self.result), 'refused')
        self.assertEqual(self.invoke(dict(self.ready, owner='b' * 64), self.result), 'refused')
        self.assertEqual(self.invoke(self.ready, dict(self.result, extra='untrusted')), 'refused')
        self.assertEqual(self.invoke(dict(self.ready, extra='untrusted'), self.result), 'refused')

    def test_worker_has_no_deployment_or_no_argument_fallback(self):
        env = dict(os.environ, PHORGE_GOVERNANCE_ISOLATED_TEST='1')
        run = subprocess.run(['php', str(WORKER)], env=env, capture_output=True, timeout=5)
        self.assertEqual(run.returncode, 74)
        self.assertEqual(run.stdout, b'')

    def test_fixture_never_repairs_unknown_native_state_or_closes_other_pools(self):
        source = HELPER.read_text() + WORKER.read_text()
        for forbidden in ['closeAllConnections(', 'killTransaction(', "setValue($conn,", 'proc_terminate(']:
            self.assertNotIn(forbidden, source)
        self.assertIn("$last_observed=$observe();if($last_observed===array(0,0))", source)
        self.assertIn("if(!$absent){", source)
        self.assertIn("$final_snapshot=$snapshot();", source)
        self.assertIn("if($final_snapshot!==$before){", source)
        cleanup = source.index("$GLOBALS['governance_policy_nested_cleanup_permitted']=true;")
        self.assertLess(source.index("if(!$absent){"), cleanup)
        self.assertLess(source.index("if($final_snapshot!==$before){"), cleanup)
        self.assertIn("$terminal['signaled']", source)
        self.assertIn("array(73,255)", source)
        self.assertIn("$rollbacks===0", source)
        self.assertIn("'retry_attempted'=>false", source)


if __name__ == '__main__':
    unittest.main()
