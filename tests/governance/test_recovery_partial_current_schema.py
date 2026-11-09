"""Actual fixture closures with explicit preview collaborator, no native SQL."""
from pathlib import Path
import copy,json,subprocess,sys,unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_partial_composition as gate
from test_recovery_partial_composition_gate import template
class PartialCurrentSchema(unittest.TestCase):
 def php(self,code,*args):
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT),*args],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_failed_condition_reports_failed_fixed_label_not_last_success(self):
  code=r'''require$argv[1].'/support/governance/recovery/partial-composition-focused.php';require$argv[1].'/support/governance/recovery/partial-failure-diagnostic.php';$driver=file_get_contents($argv[1].'/support/governance/native-organization/test-native-organization-mysql.php');$a=strpos($driver,'$check = function(');$b=strpos($driver,'};',$a)+2;$checks=[];$stage='preflight';eval(substr($driver,$a,$b-$a));$source=file_get_contents($argv[1].'/support/governance/recovery/partial-composition-focused.php');$a=strpos($source,'  $record=static function(');$b=strpos($source,'  $result=governance_recovery_restore_review_cases',$a);eval('function fixed_record($check){$checks=[];'.substr($source,$a,$b-$a).'return$record;}');$out=[];foreach(['recovery-restore-live-independent-admin-preview-exposes-blockers-not-credentials-or-permit','SECRET_CANARY_LABEL']as$label){unset($GLOBALS['governance_recovery_partial_attempt']);$record=fixed_record($check);$record(true,'recovery-restore-submit-ledger-replay-no-second-audit');try{$record(false,$label);}catch(Throwable$e){$failure=['stage'=>$GLOBALS['governance_recovery_partial_attempt']??$stage,'error_type'=>get_class($e)];$out[]=governance_recovery_partial_failure_projection('recovery-partial-composition',false,$failure);}}echo json_encode($out);'''
  d=self.php(code);self.assertEqual(d[0]['stage'],'recovery-restore-live-independent-admin-preview-exposes-blockers-not-credentials-or-permit');self.assertEqual(d[1]['stage'],'focused-native-recovery-partial-composition');self.assertNotIn('SECRET_CANARY',json.dumps(d))
 def test_real_current_Assessment_schema_accepts_exact_preview_and_refuses_old_or_drift(self):
  # The schema input comes from the actual current Assessment/Store host method,
  # not a second independent model of the version. Its SQL/identity are collaborators.
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/assessment-oauth-code-owner-host.php'),str(ROOT),'good'],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);actual=json.loads(p.stdout);self.assertIsNone(actual['error']);schema=actual['facts']['before']['schema_version'];self.assertEqual(schema,5)
  code=r'''class PhorgeRecoveryRestoreService{static function preview(...$args){$GLOBALS['calls']++;$v=['category'=>'technical-pause','expires_at'=>1,'families'=>[],'incomplete_families'=>array_fill(0,13,'uncovered'),'permit_state'=>'blocked','preview_is_authorization'=>false,'request_id'=>'synthetic','restoration_authorized'=>false,'review_state'=>'submitted-blocked','source_digest'=>str_repeat('a',64),'source_schema_version'=>$GLOBALS['schema'],'version'=>'1'];if($GLOBALS['drift']&&$GLOBALS['calls']===2)$v['source_digest']=str_repeat('b',64);return$v;}}$s=file_get_contents($argv[1].'/support/governance/recovery/restore-review-cases.php');$a=strpos($s,'    $before=$fingerprint();$preview=');$b=strpos($s,"    require_once __DIR__.'/partial-composition-cases.php';",$a);$body=substr($s,$a,$b-$a);$out=[];foreach([[(int)$argv[2],false],[(int)$argv[2],true],[4,false]]as[$schema,$drift]){$GLOBALS['schema']=$schema;$GLOBALS['drift']=$drift;$GLOBALS['calls']=0;$accepted=null;$check=static function($ok,$name)use(&$accepted){$accepted=$ok;};$fingerprint=static fn()=>'unchanged-ledger';$approver=['context'=>new stdClass()];$id='synthetic';eval($body);$out[]=['accepted'=>$accepted,'calls'=>$GLOBALS['calls']];}echo json_encode($out);'''
  self.assertEqual(self.php(code,str(schema)),[{'accepted':True,'calls':2},{'accepted':False,'calls':2},{'accepted':False,'calls':2}])
 def test_current_summary_rejects_historical_profile_and_schema(self):
  code=r'''$revision=str_repeat('a',40);$s=file_get_contents($argv[1].'/support/governance/recovery/partial-composition-focused.php');$a=strpos($s,"  return array('profile'=>");$b=strpos($s,");\n}",$a)+2;echo json_encode(eval(substr($s,$a,$b-$a)));'''
  self.assertEqual(self.php(code),gate.summary('a'*40))
  current=template();self.assertEqual(current['native']['assessment_schema'],5);self.assertEqual(current['native']['credential_readset_schema'],2);self.assertTrue(gate.validate(current,source_revision='a'*40,file_digest='b'*64)['passed'])
  for changes in [{'assessment_schema':4},{'credential_readset_schema':1},{'profile':'native-recovery-partial-composition-file-v3-assessment-readset'}]:
   bad=copy.deepcopy(current);bad['native'].update(changes)
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
if __name__=='__main__':unittest.main()
