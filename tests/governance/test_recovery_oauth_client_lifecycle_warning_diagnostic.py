"""Pure diagnostic controls, not SQL proof."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class WarningDiagnostic(unittest.TestCase):
 def php(self,code):
  q=subprocess.run(['php','-r','require $argv[1];'+code,str(ROOT/'support/governance/recovery/oauth-client-lifecycle-diagnostic.php')],capture_output=True,text=True)
  self.assertEqual(q.returncode,0,q.stderr);self.assertEqual(q.stderr,'');return json.loads(q.stdout)
 def test_fixed_paths_integer_lines_and_secret_redaction(self):
  d=self.php("$r=['attempt_stage'=>'durable-submit','message'=>'CANARY','trace'=>[['source'=>'support/governance/recovery/oauth-client-lifecycle-cases.php','line'=>127,'args'=>['CANARY']],['source'=>'/private/CANARY','line'=>9],['source'=>'src/unknown.php','line'=>10],['source'=>'support/governance/recovery/oauth-client-lifecycle-cases.php','line'=>'127']]];echo json_encode(governance_recovery_oauth_client_lifecycle_failure_projection($r));")
  self.assertEqual(d['attempt_stage'],'durable-submit');self.assertEqual(d['trace'],[{'source':'support/governance/recovery/oauth-client-lifecycle-cases.php','line':127}]);self.assertNotIn('CANARY',json.dumps(d));self.assertFalse(d['passed'])
 def test_bound_reprojection_idempotent(self):
  d=self.php("$r=['trace'=>array_fill(0,30,['source'=>'support/runtime/src/error/PhutilErrorHandler.php','line'=>270])];$a=governance_recovery_oauth_client_lifecycle_failure_projection($r);echo json_encode(['count'=>count($a['trace']),'same'=>$a===governance_recovery_oauth_client_lifecycle_failure_projection($a)]);")
  self.assertEqual(d,{'count':8,'same':True})
 def test_stage_before_operation_and_original_48_obligations_unchanged(self):
  p='support/governance/recovery/oauth-client-lifecycle-cases.php';s=(ROOT/p).read_text();old=subprocess.check_output(['git','-C',str(ROOT),'show','HEAD:'+p]).decode();mark='function governance_recovery_oauth_client_lifecycle_store_cases';self.assertEqual(s[:s.index(mark)],old[:old.index(mark)]);self.assertLess(s.index("='durable-submit'"),s.index('$receipt=PhorgeRecoveryRestoreService::submit'))
  self.assertEqual(self.php("echo json_encode(governance_recovery_oauth_client_lifecycle_failure_projection(['attempt_stage'=>'durable-disable-subject']));")['attempt_stage'],'durable-disable-subject')
if __name__=='__main__':unittest.main()
