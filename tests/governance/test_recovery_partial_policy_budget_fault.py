"""Execute actual source adapter and fixture fault helper with explicit SQL seams."""
from pathlib import Path
import subprocess,json,unittest
ROOT=Path(__file__).resolve().parents[2]
class PolicyBudgetFault(unittest.TestCase):
 def run_case(self,case):
  code=r'''
require $argv[1].'/support/runtime/src/object/Phobject.php';
require $argv[1].'/support/runtime/src/utils/utils.php';
require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryRestorePolicySource.php';
require $argv[1].'/support/governance/recovery/partial-composition-cases.php';
class AphrontDatabaseConnection {public $active=false;function isInsideTransaction(){return $this->active;}function openTransaction(){$this->active=true;}function killTransaction(){$this->active=false;}}
class PhabricatorUser{function establishConnection($mode){return $GLOBALS['conn'];}}
class PhabricatorPolicy {function getID(){return 7;}function getPHID(){return 'PHID-PLCY-'.str_repeat('p',20);}}
class PhorgeRecoveryStopRequest {static function transactionID($v){if(!preg_match('/\A[1-9][0-9]*\z/D',$v))throw new Exception('bad-id');return $v;}}
class PhorgeRecoveryRestoreProfile {static function assertOwnedWriter($c){if($c!==$GLOBALS['conn']||!$c->active)throw new Exception('wrong writer');}}
class FixtureSeam {function assertOwned(){}function getNamespace(){return 'governance_native_'.str_repeat('a',16);}}
function qsprintf($conn,$query){return $query;}
function queryfx($conn,$sql,...$args){
 $GLOBALS['writes'][]=$sql;
 if($GLOBALS['case']==='write-error')throw $GLOBALS['original'];
 if($sql!=='UPDATE %T.policy SET rules=%s WHERE id=%s AND phid=%s'||$args[0]!==$GLOBALS['ns'].'_policy'||$args[2]!=='7'||$args[3]!==$GLOBALS['phid'])throw new Exception('unbounded write');
 if($GLOBALS['case']!=='no-update')$GLOBALS['rules']=$args[1];
}
function queryfx_one($conn,$sql,...$args){
 if(str_contains($sql,'DATABASE()'))return ['db'=>$GLOBALS['ns'].'_user'];
 if(str_contains($sql,'information_schema.TABLES'))return ['ENGINE'=>'InnoDB'];
 if(str_contains($sql,'rule_bytes'))return ['id'=>'7','phid'=>$GLOBALS['case']==='wrong-identity'?'foreign':$GLOBALS['phid'],'rule_bytes'=>(string)strlen($GLOBALS['rules']),'rules_sha'=>$GLOBALS['case']==='wrong-hash'?'bad':hash('sha256',$GLOBALS['rules'])];
 if(str_contains($sql,'SELECT %Q')){
  $GLOBALS['consumed'][]=$args[3];$out=[];foreach(['id'=>'7','phid'=>$GLOBALS['phid'],'rules'=>$GLOBALS['rules'],'defaultAction'=>'deny','dateCreated'=>'1','dateModified'=>'1']as$key=>$v)$out[$key]=hash('sha256','V'.strlen($v).':'.$v);
  if($GLOBALS['case']==='wrong-selected-phid')$out['phid']=hash('sha256','foreign');return $out;
 }
 throw new Exception('unexpected scalar SQL');
}
function queryfx_all($conn,$sql,...$args){
 if(str_contains($sql,'WHERE 1=0'))return [];
 if(str_contains($sql,'information_schema.STATISTICS'))return [['COLUMN_NAME'=>'id','SEQ_IN_INDEX'=>'1']];
 if(str_contains($sql,'information_schema.COLUMNS')){
  $columns=['policy'=>['id','phid','rules','defaultAction','dateCreated','dateModified'],'policy_namedpolicy'=>['id','phid','effectivePolicy','name','description','viewPolicy','editPolicy','targetObjectType','dateCreated','dateModified'],'spaces_namespace'=>['id','phid','namespaceName','viewPolicy','editPolicy','isDefaultNamespace','description','isArchived','dateCreated','dateModified']][$args[1]];return array_map(fn($v)=>['COLUMN_NAME'=>$v],$columns);
 }
 if(str_contains($sql,' bytes FROM ')){
  $GLOBALS['size_queries'][]=$sql;
  if($args[2]!=='policy')return [];
  if($GLOBALS['case']==='not-selected')return [];
  $bytes=strlen($GLOBALS['rules'])+60;
  if(strlen($GLOBALS['rules'])>16384&&$GLOBALS['case']==='reader-misses-fault')$bytes=60;
  return [['id'=>'7','bytes'=>(string)$bytes]];
 }
 throw new Exception('unexpected rows SQL');
}
$GLOBALS['case']=$argv[2];$GLOBALS['conn']=new AphrontDatabaseConnection();$GLOBALS['ns']='governance_native_'.str_repeat('a',16);$GLOBALS['phid']=(new PhabricatorPolicy())->getPHID();$GLOBALS['rules']='[]';$GLOBALS['writes']=[];$GLOBALS['consumed']=[];$GLOBALS['size_queries']=[];$GLOBALS['original']=new RuntimeException('original-write-error');
$error=null;$same=false;try{governance_recovery_partial_policy_budget_fault(new FixtureSeam(),$GLOBALS['conn'],new PhabricatorPolicy());}catch(Throwable $e){$error=$e->getMessage();$same=$e===$GLOBALS['original'];}
echo json_encode(['error'=>$error,'original'=>$same,'writes'=>count($GLOBALS['writes']),'baseline_consumed_exact_row'=>in_array('7',$GLOBALS['consumed'],true),'size_queries'=>$GLOBALS['size_queries'],'active'=>$GLOBALS['conn']->active,'fault_bytes'=>strlen($GLOBALS['rules']),'SQL_executed'=>false]);
'''
  p=subprocess.run(['php','-r',code,str(ROOT),case],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertFalse(d['SQL_executed']);self.assertFalse(d['active']);return d
 def test_same_actual_reader_consumes_exact_row_then_refuses_real_fault_bytes(self):
  d=self.run_case('good');self.assertIsNone(d['error']);self.assertTrue(d['baseline_consumed_exact_row']);self.assertEqual(d['writes'],1);self.assertGreater(d['fault_bytes'],16384)
  self.assertEqual(len(d['size_queries']),4) # Three baseline tables; policy fault refuses before next table.
  self.assertTrue(all('FORCE INDEX (PRIMARY)'in s and 'ORDER BY id LIMIT %d LOCK IN SHARE MODE'in s and 'WHERE'not in s for s in d['size_queries']))
 def test_missing_or_foreign_selected_identity_refuses_before_mutation(self):
  for case in ('not-selected','wrong-selected-phid'):
   d=self.run_case(case);self.assertEqual(d['error'],'recovery_partial_policy_fault_not_selected');self.assertEqual(d['writes'],0)
 def test_unpersisted_wrong_identity_or_hash_and_unconsumed_fault_refuse(self):
  for case,expected in [('no-update','recovery_partial_policy_fault_bytes_unproved'),('wrong-identity','recovery_partial_policy_fault_bytes_unproved'),('wrong-hash','recovery_partial_policy_fault_bytes_unproved'),('reader-misses-fault','recovery_partial_policy_fault_budget_unproved')]:
   self.assertEqual(self.run_case(case)['error'],expected)
 def test_original_write_error_is_preserved_and_reader_transaction_closed(self):
  d=self.run_case('write-error');self.assertEqual(d['error'],'original-write-error');self.assertTrue(d['original'])
 def test_fault_and_service_refusal_share_existing_no_approval_write_fingerprint(self):
  code=r'''$root=$argv[1];$source=file_get_contents($root.'/support/governance/recovery/restore-review-cases.php');$start=strpos($source,'$fingerprint=static function');$end=strpos($source,'// Initialize ordinary credentials',$start);$guards=substr($source,$start,$end-$start);$partial=file_get_contents($root.'/support/governance/recovery/partial-composition-cases.php');$start=strpos($partial,'$refuse(static function()use($fixture,$conn,$policy,$approver,$id)');$end=strpos($partial,"'recovery-partial-real-policy-budget-fails-closed-without-review-write');",$start)+strlen("'recovery-partial-real-policy-budget-fails-closed-without-review-write');");$body=substr($partial,$start,$end-$start);
class PhabricatorUser{} class Conn {function isInsideTransaction(){return false;}}
function phutil_json_encode($v){return json_encode($v);}
function queryfx_all($c,$sql,$table){$GLOBALS['reads']++;$key=is_string($table)?$table:'users';return $GLOBALS['rows'][$key]??[];}
function governance_recovery_partial_policy_budget_fault($fixture,$conn,$policy){$GLOBALS['order'][]='fault';if($GLOBALS['mode']!=='good')$GLOBALS['rows'][$GLOBALS['mode']]=[['changed'=>'synthetic']];}
class PhorgeRecoveryRestoreService {static function preview($context,$id,$version){$GLOBALS['order'][]='preview';throw new RuntimeException('recovery_policy_byte_budget');}}
$out=[];$tables=['recovery_restore_request','recovery_restore_operation','recovery_restore_audit','organization_member','organization_appointment','organization_primarydepartment','users'];
foreach(array_merge(['good'],$tables)as$mode){$GLOBALS['mode']=$mode;$GLOBALS['reads']=0;$GLOBALS['rows']=[];$GLOBALS['order']=[];$accepted=null;$check=static function($ok,$label)use(&$accepted){$accepted=$ok;};$conn=new Conn();$fixture=new stdClass();$policy=new stdClass();$approver=['context'=>new stdClass()];$id='fixed';eval($guards);eval($body);$out[]=['accepted'=>$accepted,'reads'=>$GLOBALS['reads'],'order'=>$GLOBALS['order']];}echo json_encode($out);'''
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');out=json.loads(p.stdout);self.assertEqual([v['accepted']for v in out],[True]+[False]*7);self.assertTrue(all(v['reads']==14 and v['order']==['fault','preview']for v in out))
