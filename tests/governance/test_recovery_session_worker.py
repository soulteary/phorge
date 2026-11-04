"""Host-only decoder contract tests. No native session or SQL execution claim."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SessionProducerResultTests(unittest.TestCase):
    def decode(self, value):
        program = (
            "require $argv[1];"
            "$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);"
            "try{$value=governance_native_session_producer_result($input);"
            "echo json_encode(array('accepted'=>true,'value'=>$value));}"
            "catch(RuntimeException $e){"
            "if($e->getMessage()!=='native_session_producer_result_refused'){throw $e;}"
            "echo json_encode(array('accepted'=>false));}"
        )
        php = shutil.which("php")
        self.assertIsNotNone(php, "Host decoder test requires PHP; not a native runtime test.")
        result = subprocess.run(
            [php, "-r", program, str(ROOT / "support/governance/native-organization/workers.php")],
            input=json.dumps(value), text=True, capture_output=True, timeout=5,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_only_fixed_success_shape_is_accepted(self):
        value = {"status": "committed", "session_id_digest": "a" * 64,
                 "restoration_authorized": False}
        self.assertEqual(self.decode(value), {"accepted": True, "value": value})

    def test_backend_errors_cannot_be_projected_as_authorization_denials(self):
        for reason in ("recovery_restore_admin_required", "governance_native_credential_ineligible"):
            with self.subTest(reason=reason):
                self.assertTrue(self.decode({"status": "rejected", "reason": reason})["accepted"])
        for value in (
            {"status": "failed", "error_type": "AphrontQueryException"},
            {"status": "rejected", "reason": "recovery_session_transactional_source_required"},
            {"status": "rejected", "reason": "recovery_session_isolation_unknown"},
            {"status": "rejected", "reason": "governance_native_source_unknown"},
        ):
            with self.subTest(value=value):
                self.assertFalse(self.decode(value)["accepted"])

    def test_types_unknown_fields_and_grant_claims_are_refused(self):
        valid = {"status": "committed", "session_id_digest": "a" * 64,
                 "restoration_authorized": False}
        for key, value in (
            ("restoration_authorized", True), ("restoration_authorized", 0),
            ("session_id_digest", True), ("session_id_digest", "A" * 64),
            ("session_id_digest", "a" * 63), ("can_execute", False),
            ("status", True),
        ):
            changed = dict(valid)
            changed[key] = value
            with self.subTest(key=key, value=value):
                self.assertFalse(self.decode(changed)["accepted"])

    def finish_owned(self, value):
        # Executes the real finish/owner/decoder functions with owned files only.
        # No PHP application bootstrap, native authentication, process or SQL.
        program = r'''
require $argv[1];
$value=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
$path=sys_get_temp_dir().'/session-failure-unit-'.bin2hex(random_bytes(12));
if(!mkdir($path,0700)){throw new Exception('unit_directory_failed');}
$stat=lstat($path);$nonce=bin2hex(random_bytes(32));
try{
  governance_native_worker_atomic($path,'owner',$nonce);
  governance_native_worker_atomic($path,'result.json',$value);
  $handle=array('index'=>0,'private'=>$path,'dev'=>$stat['dev'],'ino'=>$stat['ino'],
    'nonce'=>$nonce,'cleaned'=>false,'action'=>'recovery.session.issue','process'=>null);
  $GLOBALS['governance_native_workers']=array($handle);
  unset($GLOBALS['governance_session_producer_child_failure']);
  $accepted=false;$reason=null;
  try{governance_native_finish_worker($handle);$accepted=true;}
  catch(RuntimeException $error){$reason=$error->getMessage();}
  echo json_encode(array('accepted'=>$accepted,'reason'=>$reason,
    'diagnostic'=>$GLOBALS['governance_session_producer_child_failure']??null));
}finally{
  foreach(array('result.json','owner')as$file){if(is_file($path.'/'.$file)){unlink($path.'/'.$file);}}
  if(!rmdir($path)){throw new Exception('unit_owned_directory_cleanup_failed');}
}
'''
        result = subprocess.run(
            [shutil.which('php'), '-r', program,
             str(ROOT / 'support/governance/native-organization/workers.php')],
            input=json.dumps(value), text=True, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_failed_owned_child_keeps_location_but_never_becomes_denial(self):
        value = {'status': 'failed', 'error_type': 'AphrontQueryException',
                 'source_file': 'LiskDAO.php', 'source_line': 500, 'query_code': 1213}
        outcome = self.finish_owned(value)
        self.assertFalse(outcome['accepted'])
        self.assertEqual('native_session_producer_result_refused', outcome['reason'])
        self.assertEqual({key: item for key, item in value.items() if key != 'status'},
                         outcome['diagnostic'])
        for key, item in (('free_text', 'synthetic-private-sentinel'),
                          ('source_file', '/private/identity.php'),
                          ('query_code', True), ('query_code', 65536),
                          ('source_line', 0)):
            changed = dict(value); changed[key] = item
            with self.subTest(key=key, value=item):
                refused = self.finish_owned(changed)
                self.assertFalse(refused['accepted'])
                self.assertEqual('native_session_producer_result_refused', refused['reason'])
                self.assertEqual({'diagnostic_unavailable': True}, refused['diagnostic'])
                self.assertNotIn('synthetic-private-sentinel', json.dumps(refused))

    def test_committed_owned_result_does_not_emit_failure_diagnostic(self):
        value = {'status': 'committed', 'session_id_digest': 'a' * 64,
                 'restoration_authorized': False}
        self.assertEqual({'accepted': True, 'reason': None, 'diagnostic': None},
                         self.finish_owned(value))
