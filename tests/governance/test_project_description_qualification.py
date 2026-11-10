"""Native observation validator and fixture safety, never positive SQL evidence."""
import json,subprocess,unittest
from pathlib import Path
from test_project_description_execution import php,ROOT
CASES=ROOT/'support/governance/project-description-qualification/cases.php'
PRE='require '+json.dumps(str(CASES))+';'
VECTOR=r'''
$target='PHID-PROJ-'.str_repeat('a',20);$actor='PHID-USER-'.str_repeat('b',20);$grant=str_repeat('c',64);
$before=['identities'=>[['isAdmin'=>'0']],'project'=>['phid'=>$target,'editPolicy'=>'admin','dateModified'=>'1'],'description'=>'old','other_fields'=>[['value'=>'keep']],'edges'=>[['dst'=>'keep']],'transactions'=>[],'audit'=>[],'tasks'=>[],'task_data'=>[]];
$after=$before;$after['description']='new';$after['project']['dateModified']='2';
$after['transactions']=[['transactionType'=>PhorgeProjectDescriptionExecutor::TYPE,'authorPHID'=>$actor,'phid'=>'x1']];
$after['audit']=[['grantID'=>$grant,'action'=>'description-updated','actorPHID'=>$actor]];
$after['tasks']=[['id'=>'1','taskClass'=>'PhabricatorApplicationTransactionPublishWorker']];
$after['task_data']=['1'=>['objectPHID'=>$target,'actorPHID'=>$actor,'xactionPHIDs'=>['x1'],'state'=>['mailShouldSend'=>true,'feedShouldPublish'=>true,'mailToPHIDs'=>[$actor]]]];
'''
class QualificationTests(unittest.TestCase):
 def test_actual_native_token_factory_and_storage_contract_without_database(self):
  out=php(PRE+r'''$id='PHID-USER-'.str_repeat('a',20);$t=PhabricatorConduitToken::initializeNewToken($id,PhabricatorConduitToken::TYPE_COMMANDLINE);$d=(new PhabricatorWorkerTaskData())->setData(['objectPHID'=>$id]);echo json_encode(['owner'=>$t->getObjectPHID(),'kind'=>$t->getTokenType(),'token_grammar'=>(bool)preg_match('/\Acli-[a-zA-Z0-9]{28}\z/D',$t->getToken()),'worker_data_roundtrip'=>$d->getData()===['objectPHID'=>$id]]);''')
  self.assertEqual(out,{'owner':'PHID-USER-'+'a'*20,'kind':'cli','token_grammar':True,'worker_data_roundtrip':True})
 def test_detached_exact_native_delta_accepts_with_honest_effect_limits(self):
  out=php(PRE+VECTOR+"echo json_encode(dpositive_delta($before,$after,$target,$actor,$grant,'new'));")
  self.assertTrue(out['description_and_use_audit_persisted']);self.assertFalse(out['native_EDIT_granted']);self.assertFalse(out['publish_worker_executed']);self.assertFalse(out['mail_feed_search_delivery_verified'])
 def test_detached_delta_rejects_security_audit_and_native_queue_corruption(self):
  code=PRE+VECTOR+r'''
$bad=[];
$x=$after;$x['project']['editPolicy']='users';$bad[]=$x;
$x=$after;$x['identities'][0]['isAdmin']='1';$bad[]=$x;
$x=$after;$x['edges']=[];$bad[]=$x;
$x=$after;$x['other_fields']=[];$bad[]=$x;
$x=$after;$x['audit'][0]['grantID']=str_repeat('d',64);$bad[]=$x;
$x=$after;$x['transactions'][0]['transactionType']='core:edit-policy';$bad[]=$x;
$x=$after;$x['task_data']['1']['xactionPHIDs']=['wrong'];$bad[]=$x;
$x=$after;$x['task_data']['1']['state']['mailShouldSend']=false;$bad[]=$x;
$x=$after;$x['task_data']['1']['state']['feedShouldPublish']=false;$bad[]=$x;
$n=0;foreach($bad as$x){try{dpositive_delta($before,$x,$target,$actor,$grant,'new');}catch(RuntimeException$e){$n++;}}
echo json_encode(['rejected'=>$n]);'''
  self.assertEqual(php(code),{'rejected':9})
 def test_existing_transaction_audit_and_task_bytes_cannot_be_rewritten(self):
  code=PRE+VECTOR+r'''
$before['transactions']=[['phid'=>'old']];array_unshift($after['transactions'],['phid'=>'changed']);$n=0;
try{dpositive_delta($before,$after,$target,$actor,$grant,'new');}catch(RuntimeException$e){$n++;}
$before['transactions']=[];array_shift($after['transactions']);$before['audit']=[['action'=>'old']];array_unshift($after['audit'],['action'=>'changed']);
try{dpositive_delta($before,$after,$target,$actor,$grant,'new');}catch(RuntimeException$e){$n++;}
$before['audit']=[];array_shift($after['audit']);$before['tasks']=[['id'=>'2','taskClass'=>'keep']];$before['task_data']['2']=['keep'=>true];
try{dpositive_delta($before,$after,$target,$actor,$grant,'new');}catch(RuntimeException$e){$n++;}
echo json_encode(['rejected'=>$n]);'''
  self.assertEqual(php(code),{'rejected':3})
 def test_actual_dstate_retains_description_raw_storage_id_at_equal_decoded_value(self):
  out=php(PRE+r'''define('N05_DESCRIPTION_QUALIFICATION_LIBRARY_ONLY',true);$phid='PHID-PROJ-'.str_repeat('a',20);$field=dfield((new PhabricatorProject())->setPHID($phid));$rows=[['id'=>'7','objectPHID'=>$phid,'fieldIndex'=>$field->getFieldIndex(),'fieldValue'=>'same semantic description']];
$read=static function($kind,$arg=null)use(&$rows,$phid){return match($kind){'project'=>['id'=>'1','phid'=>$phid],'fields'=>$rows,'identities'=>[['id'=>'1'],['id'=>'2'],['id'=>'3']],'edges','transactions','audit','tasks'=>[],default=>throw new Exception('unexpected read')};};
$before=dstate(['target_phid'=>$phid],$read);$rows[0]['id']='8';$after=dstate(['target_phid'=>$phid],$read);echo json_encode(['same_description'=>$before['description']===$after['description'],'raw_before'=>$before['storage_rows'][0]['id'],'raw_after'=>$after['storage_rows'][0]['id'],'strict_refusal_comparison_detects'=>$before!==$after]);''')
  self.assertEqual(out,{'same_description':True,'raw_before':'7','raw_after':'8','strict_refusal_comparison_detects':True})
 def test_snapshot_reader_hook_rejects_without_explicit_library_only_control(self):
  out=php(PRE+r'''$calls=0;$blocked=false;try{dstate([],function()use(&$calls){$calls++;return[];});}catch(RuntimeException$e){$blocked=true;}echo json_encode(['blocked'=>$blocked,'reader_calls'=>$calls]);''')
  self.assertEqual(out,{'blocked':True,'reader_calls':0})
 def test_exact_refusal_contract_rejects_unrelated_runtime_or_query_failure(self):
  out=php(PRE+r'''echo json_encode([drefusal_matches(new AphrontQueryException('private',1644),'audit-fault'),drefusal_matches(new AphrontQueryException('private',1213),'audit-fault'),drefusal_matches(new RuntimeException('description_current_grant_required'),'description_current_grant_required'),drefusal_matches(new RuntimeException('unrelated'),'description_current_grant_required'),drefusal_matches(new Error('private'),'description_current_grant_required')]);''')
  self.assertEqual(out,[True,False,True,False,False])
 def test_unreviewed_entry_refuses_before_native_bootstrap_or_any_fixture_io(self):
  q=subprocess.run(['php',str(ROOT/'support/governance/project-description-qualification/native.php'),'/nonexistent','/nonexistent'],capture_output=True,text=True)
  self.assertEqual(q.returncode,1);self.assertEqual(q.stdout,'');self.assertEqual(q.stderr,'description_qualification_refused_retain_no_retry\n')
 def test_fixture_uses_real_sources_and_no_business_writer_or_permission_patch(self):
  s=CASES.read_text();self.assertIn('newFromAuthenticatedAPIToken',s);self.assertIn('PhorgeProjectContentGrantService::call',s);self.assertIn('PhorgeProjectDescriptionExecutor::execute',s);self.assertIn('PhabricatorUserDisableTransaction::TRANSACTIONTYPE',s)
  self.assertNotIn('getOmnipotentUser',s);self.assertNotIn('setIsAdmin',s);self.assertNotIn('UPDATE governance_contentgrant',s);self.assertNotIn('INSERT INTO governance_contentgrant',s)
  self.assertIn("$e instanceof AphrontQueryException&&(int)$e->getCode()===1644",s);self.assertIn('DROP TRIGGER',s)
if __name__=='__main__':unittest.main()
