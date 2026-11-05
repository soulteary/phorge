"""Actual native model semantics and scalar-reader controls; no SQL/wait proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class TaskOwnerFacts(unittest.TestCase):
 def test_actual_native_owner_author_lock_and_disabled_controls(self):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/task-owner-native-controls.php'),str(ROOT)],capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertNotIn('PHID-',p.stdout);d=json.loads(p.stdout);self.assertEqual(6,len(d['checks']));self.assertTrue(d['passed']);self.assertFalse(d['SQL_executed'])
 def test_actual_row_function_rejects_unknowns_without_magic(self):
  code=r'''require $argv[1].'/support/runtime/src/object/Phobject.php';require $argv[1].'/src/applications/transactions/editengine/PhabricatorEditEngineSubtype.php';require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryTaskOwnerSource.php';$v=json_decode(stream_get_contents(STDIN),true);$user='PHID-USER-'.str_repeat('u',20);$row=['id'=>'1','phid'=>'PHID-TASK-'.str_repeat('t',20),'ownerPHID'=>$user,'authorPHID'=>'PHID-USER-'.str_repeat('a',20),'viewPolicy'=>'users','editPolicy'=>'users','spacePHID'=>null,'status'=>'open','subtype'=>'default','dateModified'=>'1'];$rows=[$row];$GLOBALS['called']=false;
class TaskMagic {function __toString(){$GLOBALS['called']=true;return 'secret';}function __serialize(){$GLOBALS['called']=true;return [];}}
if(isset($v['row'])){$rows[0]=array_replace($row,$v['row']);}if(isset($v['object'])){$rows[0]['viewPolicy']=new TaskMagic();}if(isset($v['deep'])){$rows[0]['status']=[[[new TaskMagic()]]];}if(isset($v['rows129'])){$rows=array_fill(0,129,$row);}if(isset($v['duplicate'])){$rows[]=$row;}if(isset($v['bytebudget'])){$rows=[];for($i=1;$i<=128;$i++){$r=$row;$r['id']=(string)$i;$r['status']=str_repeat('s',256);$r['viewPolicy']=str_repeat('v',64);$r['editPolicy']=str_repeat('e',64);$rows[]=$r;}}
$accepted=false;try{(new ReflectionMethod(PhorgeRecoveryTaskOwnerSource::class,'rows'))->invoke(null,$rows,$user);$accepted=true;}catch(RuntimeException$error){}echo json_encode(['accepted'=>$accepted,'callback'=>$GLOBALS['called']]);'''
  cases=[({},True),({'row':{'ownerPHID':None}},False),({'row':{'authorPHID':'wrong'}},False),({'row':{'subtype':'custom'}},False),({'row':{'dateModified':False}},False),({'row':{'status':'s'*257}},False),({'row':{'phid':'PHID-TASK-'+'t'*15}},False),({'object':True},False),({'deep':True},False),({'rows129':True},False),({'duplicate':True},False),({'bytebudget':True},False)]
  for data,accepted in cases:
   p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(data),capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertEqual({'accepted':accepted,'callback':False},json.loads(p.stdout))
 def test_fixed_route_and_no_large_payload_or_permission_transition(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryTaskOwnerSource.php').read_text();self.assertIn('FORCE INDEX (ownerPHID) WHERE ownerPHID=%s ORDER BY status,id LIMIT 129 LOCK IN SHARE MODE',s);self.assertIn('$scope->getFacts()',s);self.assertIn("FORCE INDEX (phid) WHERE phid=%s",s);self.assertIn("hash_hmac('sha256'",s)
  for forbidden in ['SELECT *','SET isDisabled','->save(','title,','description,','properties,','mailKey,','caller_complete']:
   self.assertNotIn(forbidden,s)
  self.assertNotIn('PhorgeRecoveryTaskOwnerSource',(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text())


 def test_actual_wait_decoder_strict_roles_engine_owned_range_and_cleanup(self):
  code=r"""require $argv[1].'/support/governance/recovery/task-owner-source-cases.php';$r=['requester'=>'11','blocker'=>'12','schema_name'=>'governance_native_'.str_repeat('a',16).'_maniphest','table_name'=>'maniphest_task','index_name'=>'ownerPHID','mode'=>'X,GAP,INSERT_INTENTION','status'=>'WAITING'];$ok=governance_task_owner_wait_rows([$r],'11','12');$cases=[];foreach(['requester'=>'12','blocker'=>'11','schema_name'=>'foreign','table_name'=>'user','index_name'=>'PRIMARY','mode'=>'S','status'=>'GRANTED']as$k=>$v){$bad=$r;$bad[$k]=$v;$cases[]=governance_task_owner_wait_rows([$bad],'11','12');}$cases[]=governance_task_owner_wait_rows([$r,$r],'11','12');$cases[]=governance_task_owner_wait_rows([],'11','12');class TaskCleanupSeam{function __construct(private $value){}function abort(){return $this->value;}}$clean=governance_task_owner_abort(new TaskCleanupSeam(['cleanup_proved'=>true]));$badcount=0;foreach([null,false,[],['cleanup_proved'=>1],['cleanup_proved'=>false]]as$bad){try{governance_task_owner_abort(new TaskCleanupSeam($bad));}catch(RuntimeException $e){$badcount++;}}echo json_encode(['positive'=>$ok,'negative'=>$cases,'cleanup_positive'=>$clean['cleanup_proved'],'cleanup_rejected'=>$badcount]);"""
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertEqual({'positive':True,'negative':[False]*9,'cleanup_positive':True,'cleanup_rejected':5},json.loads(p.stdout))
 def test_real_producer_fixture_uses_native_transactions_and_bounded_owner_wait(self):
  s=(ROOT/'support/governance/recovery/task-owner-source-cases.php').read_text()
  for required in ['ManiphestTask::initializeNewTask','ManiphestTransactionEditor','ManiphestTaskOwnerTransaction::TRANSACTIONTYPE','newOwnedSetupConnection','w.ENGINE AND','TRX_REQUESTED_LOCK_ID','b.TRX_ID=w.BLOCKING_ENGINE_TRANSACTION_ID','usleep(250000)','reap_async_query','task_owner_empty_range_precondition_failed','governance_task_owner_abort']:
   self.assertIn(required,s)
  self.assertNotIn('abortAfterFailure',s);self.assertNotIn('REQUESTING_THREAD_ID',s);self.assertNotIn('SET isDisabled',s)
