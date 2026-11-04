"""Actual native notice-only generation functions, host/no SQL; map is still pending."""
import json,subprocess,unittest
from pathlib import Path
R=Path(__file__).resolve().parents[2]
class Generation(unittest.TestCase):
 def test_actual_uint64_notice_counter_has_no_float_wrap_or_bool_alias(self):
  code="""require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
require $argv[1].'/src/infrastructure/governance/notification/PhorgeGovernanceNoticeIdentityScope.php';
$out=[];foreach(array(0,'0','1','9223372036854775807','18446744073709551614','18446744073709551615',true,false,'01','-1','18446744073709551616')as$v){
try{$out[]=PhorgeGovernanceNoticeIdentityScope::nextGeneration($v);}catch(RuntimeException$e){$out[]='refused';}}
echo json_encode($out,JSON_THROW_ON_ERROR);"""
  p=subprocess.run(['php','-r',code,str(R)],capture_output=True,text=True,check=True)
  self.assertEqual('',p.stderr);self.assertEqual(['1','1','2','9223372036854775808','18446744073709551615']+['refused']*6,json.loads(p.stdout))
 def test_actual_fastpath_native_User_canonical_controls(self):
  code="""require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
require $argv[1].'/src/infrastructure/governance/notification/PhorgeGovernanceNoticeIdentityScope.php';
$test=new PhorgeGovernanceNoticeEventTestCase();$test->testNoticeIneligibleFastPathUsesOnlyCanonicalNativePolicyFields();
echo 'passed';"""
  p=subprocess.run(['php','-r',code,str(R)],capture_output=True,text=True,check=True)
  self.assertEqual('',p.stderr);self.assertEqual('passed',p.stdout)
 def test_missing_table_classification_is_exact_native_class_code_and_owned_message(self):
  code="""require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
require $argv[1].'/support/governance/notification/notice-identity-fence-cases.php';
$schema='governance_native_'.str_repeat('a',16).'_user';$message="#1146: Table '".$schema.".governance_notice_identityepoch' doesn't exist";
$out=array(governance_notice_identity_missing_table(new AphrontSchemaQueryException($message),$schema),
governance_notice_identity_missing_table(new AphrontSchemaQueryException($message,1146),$schema),
governance_notice_identity_missing_table(new AphrontQueryException($message),$schema),
governance_notice_identity_missing_table(new AphrontSchemaQueryException($message),'foreign'),
governance_notice_identity_missing_table(new AphrontSchemaQueryException('other'),$schema));echo json_encode($out);"""
  p=subprocess.run(['php','-r',code,str(R)],capture_output=True,text=True,check=True)
  self.assertEqual('',p.stderr);self.assertEqual([True,False,False,False,False],json.loads(p.stdout))
 def test_recovery_and_legacy_hooks_are_not_replaced(self):
  source=(R/'src/applications/people/storage/PhabricatorUser.php').read_text()
  self.assertIn('if(!PhorgeGovernanceNoticeOutbox::enabled()){return $this->updateWithRecoveryGuard();}',source)
  self.assertIn('PhorgeGovernanceRecoveryGuard::isEmergencyDisabled($this)||!PhorgeGovernanceRecoveryGuard::enabled()',source)
  self.assertIn('PhorgeGovernanceIdentityEpoch::advanceLegacyRole($user)',(R/'src/applications/people/editor/PhabricatorUserEditor.php').read_text())
if __name__=='__main__':unittest.main()
