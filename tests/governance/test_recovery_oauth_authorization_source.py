"""Actual new DAO/core/Store paths; explicit transport/identity collaborators.
No physical SQL, optional OAuth activation, native HTTP or complete-family claim.
"""
from pathlib import Path
import copy,json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
DISABLED='mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect'
class NativeEntry(unittest.TestCase):
 def invoke(self,mode):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');$mode=$argv[2];$flag=str_starts_with($mode,'on-')?true:($mode==='malformed'?'true':false);$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource())->pushSource(new PhabricatorConfigDictionarySource(['storage.default-namespace'=>'governance_native_aaaaaaaaaaaaaaaa','governance.account-recovery.enabled'=>$flag]));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);$guard=new AphrontWriteGuard(static function(){});$u=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));$client=(new PhabricatorOAuthServerClient())->setPHID('PHID-OASC-'.str_repeat('b',20));$error=null;$x=null;$c=null;$factory=false;
if($mode==='factory-off'){LiskDAO::beginIsolateAllLiskEffectsToCurrentProcess();$x=(new PhabricatorOAuthServer())->setUser($u)->setClient($client)->authorizeClient([]);$factory=true;$c=$x->establishConnection('w');$x->setScope([])->save();LiskDAO::endIsolateAllLiskEffectsToCurrentProcess();}
else{$c=new AphrontIsolatedDatabaseConnection([]);$x=(new PhabricatorOAuthClientAuthorization())->setUserPHID($u->getPHID())->setClientPHID($client->getPHID())->setScope([])->setForcedConnection($c);if($mode==='on-update')$x->setID(1);if($mode==='retained-off'){class_exists(PhorgeRecoveryConduitProducerFence::class);(new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->setValue(null,[new stdClass()]);}try{if($mode==='on-replace')$x->replace();else$x->save();}catch(Throwable$e){$error=$e;}}
echo json_encode(['failed'=>$error!==null,'factory'=>$factory,'class'=>get_class($x),'id'=>$x->getID(),'scope'=>$x->getScope(),'queries'=>count($c->getQueryTranscript()),'native_scope_map'=>PhabricatorOAuthServerScope::getScopeMap()]);'''
  p=subprocess.run(['php','-d','disable_functions='+DISABLED,'-r',code,str(ROOT),mode],capture_output=True,text=True,timeout=7);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_native_factory_and_actual_DAO_scope_UPDATE_OFF(self):
  d=self.invoke('factory-off');self.assertTrue(d['factory']);self.assertFalse(d['failed']);self.assertIsNotNone(d['id']);self.assertEqual(d['queries'],2);self.assertEqual(d['scope'],[]);self.assertEqual(d['native_scope_map'],[])
 def test_actual_native_ON_forced_insert_update_replace_and_invalidflag_refuse_noSQL(self):
  for mode in ['on-insert','on-update','on-replace','malformed','retained-off']:
   d=self.invoke(mode);self.assertTrue(d['failed']);self.assertEqual(d['queries'],0)
class AssessmentOwner(unittest.TestCase):
 def invoke(self,mode):
  p=subprocess.run(['php','-d','disable_functions='+DISABLED,str(ROOT/'support/governance/recovery/tests/assessment-oauth-authorization-owner-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=8);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_real_Store_Assessment_five_sources_and_end_revalidation(self):
  d=self.invoke('good');self.assertIsNone(d['error']);b=d['facts']['before'];self.assertEqual(b['schema_version'],6);self.assertEqual(b['credential_readset']['provider_schema'],3);self.assertEqual(sorted(b['credential_readset']['sources']),['conduit','oauth-access','oauth-authorization','oauth-code','session']);self.assertTrue(d['facts']['blocked']);self.assertGreaterEqual(d['reads'].count('oauth-authorization'),2);self.assertFalse(d['physical_SQL_executed'])
 def test_actual_owner_scope_drift_permission_budget_shape_thread_and_primary_refuse(self):
  for mode in ['mutate-oauth-authorization','unsupported-oauth-authorization','budget-oauth-authorization','shape-oauth-authorization','same-auth-thread','auth-thread-drift','auth-foreign-primary']:
   d=self.invoke(mode);self.assertIsNotNone(d['error'],mode);self.assertFalse(d['physical_SQL_executed'])
 def test_actual_second_Store_owner_rejects_stale_authorization_readset(self):
  unchanged=self.invoke('next-same-source-new-OAuth-thread');self.assertIsNone(unchanged['error']);self.assertEqual(unchanged['facts']['digest'],unchanged['facts']['after']['digest'])
  d=self.invoke('next-oauth-authorization');self.assertIsNone(d['error']);self.assertNotEqual(d['facts']['digest'],d['facts']['after']['digest']);self.assertIsNotNone(self.invoke('stale-oauth-authorization')['error'])
 def test_unknown_owner_retains_without_retry_and_primary_rollback(self):
  d=self.invoke('unknown-reentry');self.assertTrue(d['retained_owner']);self.assertIsNotNone(d['retry_error']);self.assertFalse(d['cleanup_proved'])
  d=self.invoke('primary');self.assertEqual(d['error'],'host_primary');self.assertEqual((d['commits'],d['rollbacks']),(0,1))
 def test_v6_v5_v4_stored_readsets_remain_separate_no_complete_bit(self):
  source=self.invoke('good')['facts']['before'];values=[source]
  for version in [5,4]:
   old=copy.deepcopy(source);old['schema_version']=version;old['selected_producer_profile']='native-selected-Session-Conduit-OAuth-access-code-INSERT-v2-not-all-producers'if version==5 else'native-selected-Session-Conduit-OAuth-INSERT-v1-not-all-producers';r=old['credential_readset'];r['provider_schema']=2 if version==5 else 1;r['scope']='selected-native-credential-participants-v'+str(r['provider_schema']);del r['sources']['oauth-authorization']
   if version==4:del r['sources']['oauth-code']
   values.append(old)
  bad=copy.deepcopy(source);bad['credential_readset']=values[1]['credential_readset'];values.append(bad);bad=copy.deepcopy(source);bad['credential_readset']['complete']=True;values.append(bad)
  script='require $argv[1]."/support/runtime/src/init/init-library.php";phutil_load_library($argv[1]."/src/");$out=[];foreach(json_decode(stream_get_contents(STDIN),true)as$x){try{$out[]=PhorgeRecoveryRestoreRequest::sourceVersion($x);}catch(Throwable$e){$out[]=false;}}echo json_encode($out);'
  p=subprocess.run(['php','-r',script,str(ROOT)],input=json.dumps(values),capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[6,5,4,False,False])

# The actual production producer method runs with explicit connection/query
# collaborators. Native DAO dispatch is exercised separately above.
from test_recovery_conduit_participant import BOOT
class AuthorizationProducer(unittest.TestCase):
 def invoke(self,mode):
  boot=BOOT.replace('class PhabricatorConduitToken extends','class PhabricatorOAuthClientAuthorization extends').replace('getObjectPHID','getUserPHID').replace("['synthetic-profile']","['namespace'=>'governance_native_aaaaaaaaaaaaaaaa']").replace("'owned_conduit'","'governance_native_aaaaaaaaaaaaaaaa_oauth_server'")
  boot=boot.replace("function getToken(){return 'synthetic';}","function getDateCreated(){return '1';}function getPHID(){return 'PHID-OASA-'.str_repeat('f',20);}function getClientPHID(){return 'PHID-OASC-'.str_repeat('b',20);}function getScope(){return $GLOBALS['mode']==='bad-scope'?['bad'=>new stdClass()]:[];}")
  boot=boot.replace("function queryfx_one($c,$q,...$a){",r'''function queryfx_one($c,$q,...$a){
if(str_contains($q,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u')){$id=$c->name==='user'?'1':'2';if($GLOBALS['mode']==='same-thread')$id='1';if(($GLOBALS['mode']==='thread-drift'&&$GLOBALS['saved'])||($GLOBALS['mode']==='post-commit-thread-drift'&&$GLOBALS['saved']&&$c->name==='conduit'&&$c->depth===0))$id='9';return ['id'=>$id,'u'=>$GLOBALS['mode']==='foreign-primary'&&$c->name==='conduit'?'foreign':'same-server'];}
if(str_contains($q,'SELECT userPHID,clientPHID,phid')){$GLOBALS['events'][]=str_contains($q,'FOR UPDATE')?'old-identity-lock':'postcondition';$row=['userPHID'=>'PHID-USER-'.str_repeat($GLOBALS['mode']==='old-owner'?'z':'a',20),'clientPHID'=>'PHID-OASC-'.str_repeat($GLOBALS['mode']==='old-client'?'z':'b',20),'phid'=>'PHID-OASA-'.str_repeat($GLOBALS['mode']==='old-phid'?'z':'f',20)];if(str_contains($q,',dateCreated'))$row['dateCreated']=$GLOBALS['mode']==='old-created'?'2':'1';if(str_contains($q,',scope'))$row['scope']=$GLOBALS['mode']==='post-scope'?'{"different":"different"}':'[]';return$GLOBALS['mode']==='missing-old'&&str_contains($q,'FOR UPDATE')?null:$row;}
if(str_contains($q,'FORCE INDEX(PRIMARY)')&&str_contains($q,'SELECT phid'))$GLOBALS['events'][]='User-PRIMARY';
''',1)
  code=boot+r'''require $argv[1];$mode=$argv[2];$events=[];$saved=false;$owner=new AphrontMySQLiDatabaseConnection('user');$writer=new AphrontMySQLiDatabaseConnection('conduit');$primary=$mode==='body-lost'?new mysqli_sql_exception('synthetic transport',2013):new RuntimeException('primary');if($mode==='outer-user')$owner->depth=1;if($mode==='outer-conduit')$writer->depth=1;$t=new PhabricatorOAuthClientAuthorization();$update=str_starts_with($mode,'update')||in_array($mode,['old-owner','old-client','old-phid','old-created','missing-old']);if($update)$t->id=7;$error=null;$result=null;try{$result=PhorgeRecoveryConduitProducerFence::saveOAuthClientAuthorization($t,$update?'UPDATE':'INSERT',function()use($t,$mode){$GLOBALS['events'][]='save-body';if(in_array($mode,['body-error','body-lost']))throw$GLOBALS['primary'];if($t->id===null)$t->id=1;$GLOBALS['saved']=true;return$t;});}catch(Throwable$e){$error=$e;}$retained=(new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->getValue();echo json_encode(['failed'=>$error!==null,'error'=>$error?$error->getMessage():null,'same_primary'=>$error===$primary,'events'=>$events,'retained'=>count($retained),'returned_same'=>$result===$t]);'''
  p=subprocess.run(['php','-d','disable_functions='+DISABLED,'-r',code,str(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php'),mode],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_real_producer_INSERT_UPDATE_User_primary_before_OAuth_begin_MDL_ENGINE_commit(self):
  for mode in ['insert','update']:
   d=self.invoke(mode);self.assertFalse(d['failed'],d);self.assertTrue(d['returned_same']);self.assertEqual(d['retained'],0);e=d['events'];self.assertLess(e.index('User-PRIMARY'),e.index('conduit.begin'));self.assertLess(e.index('conduit.MDL'),e.index('conduit.ENGINE'));self.assertLess(e.index('conduit.ENGINE'),e.index('save-body'));self.assertLess(e.index('save-body'),e.index('conduit.commit'));self.assertLess(e.index('conduit.commit'),e.index('user.rollback'))
   if mode=='update':self.assertLess(e.index('old-identity-lock'),e.index('save-body'))
 def test_prior_owner_client_phid_and_missing_row_refuse_before_update(self):
  for mode in ['old-owner','old-client','old-phid','old-created','missing-old']:
   d=self.invoke(mode);self.assertTrue(d['failed']);self.assertNotIn('save-body',d['events']);self.assertEqual(d['retained'],0)
 def test_two_thread_primary_outer_scope_engine_and_postcondition_guards(self):
  for mode in ['same-thread','foreign-primary','outer-user','outer-conduit','bad-scope','engine-conduit','thread-drift','post-scope']:
   d=self.invoke(mode);self.assertTrue(d['failed'],mode)
   if mode in ['same-thread','foreign-primary','outer-user','outer-conduit','bad-scope']:self.assertEqual(d['events'],[])
 def test_surfaced_unknown_begin_commit_and_postcommit_thread_drift_retained(self):
  for mode in ['begin-unknown','commit-unknown','post-commit-thread-drift','body-lost']:
   d=self.invoke(mode);self.assertTrue(d['failed']);self.assertGreater(d['retained'],0);self.assertFalse(any(x.endswith('.rollback')for x in d['events']),d)
  d=self.invoke('body-error');self.assertTrue(d['same_primary']);self.assertEqual(d['retained'],0);self.assertIn('conduit.rollback',d['events']);self.assertIn('user.rollback',d['events'])

if __name__=='__main__':unittest.main()
