"""Host-only worker result contracts; no native authority or SQL evidence."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


def validate(action, result):
    script = r'''
require $argv[1];
try {
  $value=governance_native_stop_worker_result($argv[2],json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));
  echo json_encode(array('accepted'=>true,'value'=>$value));
}catch(RuntimeException $error){echo json_encode(array('accepted'=>false));}
'''
    completed = subprocess.run(
        ['php', '-r', script, str(ROOT / 'support/governance/native-organization/workers.php'),
         action, json.dumps(result)], capture_output=True, text=True, check=True)
    if completed.stderr:
        raise AssertionError('unexpected host PHP output')
    return json.loads(completed.stdout)


class StopWorkerResultTest(unittest.TestCase):
    def test_only_fixed_current_authority_rejections_are_accepted(self):
        for reason in ('recovery_stop_admin_required', 'governance_native_credential_ineligible'):
            self.assertTrue(validate('recovery.stop.record', {'status': 'rejected', 'reason': reason})['accepted'])
        for reason in ('organization_system_admin_required', 'recovery_stop_unknown', 'SQLSTATE45000', True):
            self.assertFalse(validate('recovery.stop.record', {'status': 'rejected', 'reason': reason})['accepted'])

    def test_record_result_never_grants_recovery_or_coerces_replay(self):
        valid = {'status': 'committed', 'replayed': False, 'recovery_authorized': False}
        self.assertTrue(validate('recovery.stop.record', valid)['accepted'])
        for key, value in (('replayed', 0), ('recovery_authorized', True), ('status', 'failed')):
            changed = dict(valid, **{key: value})
            self.assertFalse(validate('recovery.stop.record', changed)['accepted'])
        self.assertFalse(validate('recovery.stop.record', dict(valid, hidden_subject='private'))['accepted'])

    def test_history_is_a_bounded_count_and_cannot_reuse_record_shape(self):
        for count in (0, 1):
            self.assertTrue(validate('recovery.stop.history', {'status': 'committed', 'count': count})['accepted'])
        for count in (True, -1, 2, '1'):
            self.assertFalse(validate('recovery.stop.history', {'status': 'committed', 'count': count})['accepted'])
        self.assertFalse(validate('recovery.stop.history', {'status': 'committed', 'count': 1, 'rows': []})['accepted'])

    def test_unknown_actions_do_not_reuse_the_new_result_contract(self):
        for action in ('org.list', 'recovery.stop.execute', '', True):
            self.assertFalse(validate(str(action), {'status': 'committed', 'count': 0})['accepted'])
