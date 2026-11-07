"""Actual selected OAuth core/Store; explicit SQL collaborators, no SQL proof."""
from pathlib import Path
import sys,hashlib,copy
import json, subprocess, unittest
from test_recovery_conduit_participant import BOOT
ROOT=Path(__file__).resolve().parents[2]
class OAuthProducer(unittest.TestCase):
 def run_core(self,mode):
  boot=BOOT.replace('class PhabricatorConduitToken extends','class PhabricatorOAuthServerAccessToken extends').replace('getObjectPHID','getUserPHID').replace("'objectPHID'=>'PHID-USER-'","'userPHID'=>'PHID-USER-'").replace("['synthetic-profile']","['namespace'=>'governance_native_aaaaaaaaaaaaaaaa']").replace("'owned_conduit'","'governance_native_aaaaaaaaaaaaaaaa_oauth_server'")
  code=boot+r'''require $argv[1];$mode=$argv[2];$events=[];$owner=new AphrontMySQLiDatabaseConnection('user');$writer=new AphrontMySQLiDatabaseConnection('conduit');$primary=new RuntimeException('primary');if($mode==='outer-user')$owner->depth=1;if($mode==='outer-conduit')$writer->depth=1;$t=new PhabricatorOAuthServerAccessToken();$error=null;$returned=null;try{$returned=PhorgeRecoveryConduitProducerFence::insertOAuthAccessToken($t,'INSERT',function()use($t,$mode){$GLOBALS['events'][]='native-insert';if($mode==='insert-failure')throw$GLOBALS['primary'];$t->id=1;return$t;});}catch(Throwable$e){$error=$e;}$blocked=false;try{PhorgeRecoveryConduitProducerFence::assertWriterAvailable($owner);}catch(Throwable$e){$blocked=true;}echo json_encode(['failed'=>$error!==null,'same_primary'=>$error===$primary,'events'=>$events,'quarantined'=>$blocked,'returned_same'=>$returned===$t]);'''
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
class BorrowedOAuth(unittest.TestCase):
 def invoke(self,mode):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/borrowed-oauth-access-owner-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_actual_Store_owner_capture_and_end_recheck(self):
  d=self.invoke('success');self.assertIsNone(d['error']);self.assertTrue(d['cleanup_proved']);self.assertEqual(d['facts']['selected_source'],'native-oauth-access-token-rows');self.assertFalse(d['facts']['OAuth_authorization_complete']);self.assertEqual(d['oauth_access_steps'],['MDL','ENGINE','READ']*2)
 def test_drift_budget_engine_or_primary_refuses(self):
  for mode in ['oauth_access-drift','oauth_access-budget','oauth_access-engine','oauth_access-shape','oauth_access-permission','different-primary','RR-owner','catch-source-failure']:
   d=self.invoke(mode);self.assertIsNotNone(d['error']);self.assertIsNone(d['facts']);self.assertFalse(d['physical_SQL_executed'])
 def test_actual_native_class_and_fixed_insert_override_load_without_DB(self):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');$u='PHID-USER-'.str_repeat('a',20);$x=(new PhabricatorOAuthServerAccessToken())->setUserPHID($u)->setClientPHID('PHID-OASC-'.str_repeat('b',20))->setToken(str_repeat('c',32));$m=new ReflectionMethod($x,'insertRecordIntoDatabase');echo json_encode(['native'=>get_class($x),'unsaved'=>$x->getID()===null,'owner'=>$x->getUserPHID()===$u,'override'=>$m->getDeclaringClass()->getName(),'protected'=>$m->isProtected()]);'''
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT)],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);d=json.loads(p.stdout);self.assertTrue(d['unsaved']and d['owner']and d['protected']);self.assertEqual(d['native'],d['override'])

class NativeOAuthEntry(unittest.TestCase):
 def invoke(self,mode):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');$mode=$argv[2];$flag=$mode==='on-forced'?true:($mode==='malformed-flag'?'true':false);$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource())->pushSource(new PhabricatorConfigDictionarySource(['storage.default-namespace'=>'governance_native_aaaaaaaaaaaaaaaa','governance.account-recovery.enabled'=>$flag]));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);$guard=new AphrontWriteGuard(static function(){});$user='PHID-USER-'.str_repeat('a',20);$client='PHID-OASC-'.str_repeat('b',20);$error=null;$x=null;$c=null;
if($mode==='native-factory-off'){
 LiskDAO::beginIsolateAllLiskEffectsToCurrentProcess();$u=(new PhabricatorUser())->setPHID($user);$cl=(new PhabricatorOAuthServerClient())->setPHID($client);$x=(new PhabricatorOAuthServer())->setUser($u)->setClient($cl)->generateAccessToken();$c=$x->establishConnection('w');LiskDAO::endIsolateAllLiskEffectsToCurrentProcess();
}else{
 $c=new AphrontIsolatedDatabaseConnection([]);$x=(new PhabricatorOAuthServerAccessToken())->setUserPHID($user)->setClientPHID($client)->setToken(str_repeat('c',32))->setForcedConnection($c);
 if($mode==='retained-off'){class_exists(PhorgeRecoveryConduitProducerFence::class);(new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->setValue(null,[new stdClass()]);}
 try{$x->save();}catch(Throwable$e){$error=$e;}
}
echo json_encode(['class'=>get_class($x),'id_assigned'=>$x->getID()!==null,'token32'=>strlen($x->getToken())===32,'owner_and_client'=>$x->getUserPHID()===$user&&$x->getClientPHID()===$client,'forced'=>(new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($x)!==null,'queries'=>count($c->getQueryTranscript()),'failed'=>$error!==null,'error'=>$error?get_class($error):null]);'''
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_native_server_factory_OFF_runs_original_DAO_into_native_isolated_store(self):
  d=self.invoke('native-factory-off');self.assertFalse(d['failed']);self.assertTrue(d['id_assigned']and d['token32']and d['owner_and_client']);self.assertFalse(d['forced']);self.assertEqual(d['queries'],1)
 def test_ON_forced_writer_and_malformed_flag_refuse_before_native_insert(self):
  for mode in ['on-forced','malformed-flag','retained-off']:
   d=self.invoke(mode);self.assertTrue(d['failed']);self.assertFalse(d['id_assigned']);self.assertEqual(d['queries'],0)

class VersionedOAuthSource(unittest.TestCase):
 def test_current_and_all_historical_versions_keep_original_claims_and_pins(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import check_recovery_borrowed_owner_source as c
  for name,count in [('recovery-oauth-access-owner-source-v4.json',7),('recovery-conduit-owner-source-v3.json',6),('recovery-conduit-owner-source-v2.json',4),('recovery-borrowed-owner-source-v1.json',2)]:
   data=json.loads((ROOT/'docs/governance'/name).read_text());result=c.validate(data,ROOT);self.assertEqual(result['source_inputs'],count);self.assertFalse(result['runtime_certified']);self.assertFalse(result['restoration_authorized'])
  data=json.loads((ROOT/'docs/governance/recovery-oauth-access-owner-source-v4.json').read_text())
  for key in ['restoration_authorized','runtime_certified','production_observer_factory_available']:
   bad=copy.deepcopy(data);bad[key]=True
   with self.assertRaises(ValueError):c.validate(bad,ROOT)
 def test_repin_unrelated_or_missing_native_hook_still_refuses_immutable_inverse(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import recovery_oauth_source_delta as d
  for path,pin in list(d.SOURCE_INPUTS.items()):
   raw=(ROOT/path).read_bytes();before=d.reduce_current(path,raw);self.assertEqual(hashlib.sha256(before).hexdigest(),d.BASE_PINS[path])
   for bad in [raw+b'// unrelated change\n',before]:
    d.SOURCE_INPUTS[path]=hashlib.sha256(bad).hexdigest()
    try:
     with self.assertRaises(ValueError):d.reduce_current(path,bad)
    finally:d.SOURCE_INPUTS[path]=pin

if __name__=='__main__':unittest.main()
