"""Actual selected OAuth core/Store; explicit SQL collaborators, no SQL proof."""
from pathlib import Path
import sys,hashlib,copy
import json, subprocess, unittest
from test_recovery_conduit_participant import BOOT
ROOT=Path(__file__).resolve().parents[2]
class OAuthCodeProducer(unittest.TestCase):
 def run_core(self,mode):
  boot=BOOT.replace('class PhabricatorConduitToken extends','class PhabricatorOAuthServerAuthorizationCode extends').replace('getObjectPHID','getUserPHID').replace("'objectPHID'=>'PHID-USER-'","'userPHID'=>'PHID-USER-'").replace("['synthetic-profile']","['namespace'=>'governance_native_aaaaaaaaaaaaaaaa']").replace("'owned_conduit'","'governance_native_aaaaaaaaaaaaaaaa_oauth_server'")
  boot=boot.replace("function getToken(){return 'synthetic';}","function getCode(){return 'synthetic';}function getClientPHID(){return 'client';}function getClientSecret(){return 'secret';}function getRedirectURI(){return 'uri';}").replace("'token'=>'synthetic'","'clientPHID'=>'client','code'=>'synthetic','clientSecret'=>'secret','redirectURI'=>'uri'")
  code=boot+r'''require $argv[1];$mode=$argv[2];$events=[];$owner=new AphrontMySQLiDatabaseConnection('user');$writer=new AphrontMySQLiDatabaseConnection('conduit');$primary=new RuntimeException('primary');if($mode==='outer-user')$owner->depth=1;if($mode==='outer-conduit')$writer->depth=1;$t=new PhabricatorOAuthServerAuthorizationCode();$error=null;$returned=null;try{$returned=PhorgeRecoveryConduitProducerFence::insertOAuthAuthorizationCode($t,'INSERT',function()use($t,$mode){$GLOBALS['events'][]='native-insert';if($mode==='insert-failure')throw$GLOBALS['primary'];$t->id=1;return$t;});}catch(Throwable$e){$error=$e;}$blocked=false;try{PhorgeRecoveryConduitProducerFence::assertWriterAvailable($owner);}catch(Throwable$e){$blocked=true;}echo json_encode(['failed'=>$error!==null,'same_primary'=>$error===$primary,'events'=>$events,'quarantined'=>$blocked,'returned_same'=>$returned===$t]);'''
  p=subprocess.run(['php','-r',code,str(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php'),mode],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_actual_native_insert_commit_precedes_User_unlock_and_MDL_before_write(self):
  d=self.run_core('success');self.assertFalse(d['failed']);self.assertTrue(d['returned_same']);e=d['events'];self.assertLess(e.index('conduit.MDL'),e.index('conduit.ENGINE'));self.assertLess(e.index('conduit.ENGINE'),e.index('native-insert'));self.assertLess(e.index('native-insert'),e.index('conduit.commit'));self.assertLess(e.index('conduit.commit'),e.index('user.rollback'))
 def test_outer_unknown_and_known_failure_boundaries(self):
  for mode in ['outer-user','outer-conduit','conduit-raw-outer','commit-unknown','begin-unknown','insert-failure']:
   d=self.run_core(mode);self.assertTrue(d['failed'])
   if mode.startswith('outer-'):self.assertEqual(d['events'],[])
   if mode in ['commit-unknown','begin-unknown']:self.assertTrue(d['quarantined']);self.assertFalse(any(x.endswith('rollback')for x in d['events']))
   if mode=='conduit-raw-outer':self.assertNotIn('conduit.rollback',d['events']);self.assertIn('user.rollback',d['events'])
   if mode=='insert-failure':self.assertTrue(d['same_primary']);self.assertFalse(d['quarantined'])
class BorrowedOAuthCode(unittest.TestCase):
 def invoke(self,mode):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/borrowed-oauth-code-owner-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_actual_Store_owner_capture_and_end_recheck(self):
  d=self.invoke('success');self.assertIsNone(d['error']);self.assertTrue(d['cleanup_proved']);self.assertEqual(d['facts']['selected_source'],'native-oauth-authorization-code-rows');self.assertFalse(d['facts']['OAuth_authorization_complete']);self.assertEqual(d['oauth_code_steps'],['MDL','ENGINE','READ']*2)
 def test_drift_budget_engine_or_primary_refuses(self):
  for mode in ['oauth_code-drift','oauth_code-budget','oauth_code-engine','oauth_code-shape','oauth_code-permission','different-primary','RR-owner','catch-source-failure']:
   d=self.invoke(mode);self.assertIsNotNone(d['error']);self.assertIsNone(d['facts']);self.assertFalse(d['physical_SQL_executed'])
 def test_terminal_observer_identity_change_preserves_unknown_owner(self):
  d=self.invoke('observer-end');self.assertIsNotNone(d['error']);self.assertTrue(d['retained_owner']);self.assertFalse(d['cleanup_proved']);self.assertEqual((d['commits'],d['rollbacks']),(1,0))
 def test_primary_rollback_and_observed_commit_ack_preserve_primary(self):
  d=self.invoke('primary');self.assertTrue(d['same_primary']);self.assertEqual((d['commits'],d['rollbacks']),(0,1));self.assertTrue(d['cleanup_proved'])
  d=self.invoke('commit-ACK');self.assertTrue(d['same_primary']);self.assertEqual((d['commits'],d['rollbacks']),(1,0));self.assertEqual(d['terminal']['state'],'committed')
 def test_actual_native_class_and_fixed_insert_override_load_without_DB(self):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');$u='PHID-USER-'.str_repeat('a',20);$x=(new PhabricatorOAuthServerAuthorizationCode())->setUserPHID($u)->setClientPHID('PHID-OASC-'.str_repeat('b',20))->setCode(str_repeat('c',32));$m=new ReflectionMethod($x,'insertRecordIntoDatabase');echo json_encode(['native'=>get_class($x),'unsaved'=>$x->getID()===null,'owner'=>$x->getUserPHID()===$u,'override'=>$m->getDeclaringClass()->getName(),'protected'=>$m->isProtected()]);'''
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT)],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);d=json.loads(p.stdout);self.assertTrue(d['unsaved']and d['owner']and d['protected']);self.assertEqual(d['native'],d['override'])

class NativeOAuthEntry(unittest.TestCase):
 def invoke(self,mode):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');$mode=$argv[2];$flag=$mode==='on-forced'?true:($mode==='malformed-flag'?'true':false);$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource())->pushSource(new PhabricatorConfigDictionarySource(['storage.default-namespace'=>'governance_native_aaaaaaaaaaaaaaaa','governance.account-recovery.enabled'=>$flag]));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);$guard=new AphrontWriteGuard(static function(){});$user='PHID-USER-'.str_repeat('a',20);$client='PHID-OASC-'.str_repeat('b',20);$error=null;$x=null;$c=null;
if($mode==='native-factory-off'){
 LiskDAO::beginIsolateAllLiskEffectsToCurrentProcess();$u=(new PhabricatorUser())->setPHID($user);$cl=(new PhabricatorOAuthServerClient())->setPHID($client);$x=(new PhabricatorOAuthServer())->setUser($u)->setClient($cl)->generateAuthorizationCode(new PhutilURI('https://synthetic.invalid/'));$c=$x->establishConnection('w');LiskDAO::endIsolateAllLiskEffectsToCurrentProcess();
}else{
 $c=new AphrontIsolatedDatabaseConnection([]);$x=(new PhabricatorOAuthServerAuthorizationCode())->setUserPHID($user)->setClientPHID($client)->setCode(str_repeat('c',32))->setForcedConnection($c);
 if($mode==='retained-off'){class_exists(PhorgeRecoveryConduitProducerFence::class);(new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->setValue(null,[new stdClass()]);}
 try{$x->save();}catch(Throwable$e){$error=$e;}
}
echo json_encode(['class'=>get_class($x),'id_assigned'=>$x->getID()!==null,'code32'=>strlen($x->getCode())===32,'owner_and_client'=>$x->getUserPHID()===$user&&$x->getClientPHID()===$client,'forced'=>(new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($x)!==null,'queries'=>count($c->getQueryTranscript()),'failed'=>$error!==null,'error'=>$error?get_class($error):null]);'''
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_native_server_factory_OFF_runs_original_DAO_into_native_isolated_store(self):
  d=self.invoke('native-factory-off');self.assertFalse(d['failed']);self.assertTrue(d['id_assigned']and d['code32']and d['owner_and_client']);self.assertFalse(d['forced']);self.assertEqual(d['queries'],1)
 def test_ON_forced_writer_and_malformed_flag_refuse_before_native_insert(self):
  for mode in ['on-forced','malformed-flag','retained-off']:
   d=self.invoke(mode);self.assertTrue(d['failed']);self.assertFalse(d['id_assigned']);self.assertEqual(d['queries'],0)


class CodeAssessment(unittest.TestCase):
 def run_case(self,mode):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/assessment-oauth-code-owner-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=10)
  self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_actual_assessment_consumes_four_sources_and_blocks_execution(self):
  d=self.run_case('good');self.assertIsNone(d['error']);r=d['facts']['before'];self.assertEqual(r['schema_version'],5);self.assertEqual(r['credential_readset']['provider_schema'],2);self.assertEqual(sorted(r['credential_readset']['sources']),['conduit','oauth-access','oauth-code','session']);self.assertTrue(d['facts']['blocked']);self.assertIn('oauth-code',d['reads'])
 def test_actual_owner_recapture_detects_code_change_and_missing_source(self):
  for mode in ['mutate-oauth-code','unsupported-oauth-code','unknown-end']:
   d=self.run_case(mode);self.assertIsNotNone(d['error'])
 def test_next_owner_recaptures_generation_and_old_readset_refuses(self):
  d=self.run_case('next-oauth-code');self.assertIsNone(d['error']);self.assertNotEqual(d['facts']['digest'],d['facts']['after']['digest'])
  d=self.run_case('stale-oauth-code');self.assertIsNotNone(d['error'])
 def test_unknown_owner_is_retained_without_automatic_reentry(self):
  d=self.run_case('unknown-reentry');self.assertTrue(d['retained_owner']);self.assertIsNotNone(d['retry_error']);self.assertFalse(d['cleanup_proved'])
 def test_current_v5_and_historical_v4_readsets_are_strictly_separate(self):
  source=self.run_case('good')['facts']['before'];old=copy.deepcopy(source);old['schema_version']=4;old['selected_producer_profile']='native-selected-Session-Conduit-OAuth-INSERT-v1-not-all-producers';old['credential_readset']['provider_schema']=1;old['credential_readset']['scope']='selected-native-credential-participants-v1';del old['credential_readset']['sources']['oauth-code']
  bad=copy.deepcopy(source);bad['credential_readset']=old['credential_readset']
  badold=copy.deepcopy(old);badold['credential_readset']=source['credential_readset']
  code="require$argv[1].'/support/runtime/src/object/Phobject.php';require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php';$out=[];foreach(json_decode(stream_get_contents(STDIN),true)as$x){try{$out[]=PhorgeRecoveryRestoreRequest::sourceVersion($x);}catch(Throwable$e){$out[]=false;}}echo json_encode($out);"
  p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps([source,old,bad,badold]),capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[5,4,False,False])
if __name__=='__main__':unittest.main()
