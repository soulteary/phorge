"""Source/offline lifecycle SQL controls. No SQL or component is executed."""
from pathlib import Path
import copy,json,subprocess,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2]
DISABLED='mysqli_init,mysqli_connect,stream_socket_client,fsockopen,socket_connect'
TRACE=r'''
class PhorgeGovernanceCompletionFixture{function assertOwned(){}function getNamespace(){return 'governance_native_aaaaaaaaaaaaaaaa';}}
class PhabricatorOAuthServerClient{function getID(){return 7;}function getPHID(){return 'PHID-OASC-'.str_repeat('b',20);}function getTableName(){return 'oauth_server_client';}}
class PhabricatorOAuthClientAuthorization{function getTableName(){return 'oauth_clientauthorization';}}
class PhabricatorOAuthServerAccessToken{function getTableName(){return 'oauth_server_accesstoken';}}
class PhabricatorOAuthServerAuthorizationCode{function getTableName(){return 'oauth_server_authorizationcode';}}
require $argv[1].'/support/runtime/src/init/init-library.php';
require $argv[1].'/support/governance/recovery/oauth-client-lifecycle-query-trace.php';
$mode=$argv[2];$t=null;$pauses=[];
$t=governance_oauth_client_lifecycle_query_trace(new PhorgeGovernanceCompletionFixture(),new PhabricatorOAuthServerClient(),$mode==='delete'?'authorization-delete':'trust',function()use(&$t,&$pauses){$pauses[]=count($t->events);});$t->active=true;
$p=PhutilServiceProfiler::getInstance();
$client="SELECT 'CANARY_SECRET' FROM `governance_native_aaaaaaaaaaaaaaaa_oauth_server`.`oauth_server_client` FORCE INDEX (PRIMARY) WHERE id IN (7) AND BINARY phid IN ('PHID-OASC-".str_repeat('b',20)."') ORDER BY id LOCK IN SHARE MODE";
$authz='SELECT id,scope FROM `governance_native_aaaaaaaaaaaaaaaa_oauth_server`.`oauth_clientauthorization` WHERE id IN (8) LOCK IN SHARE MODE';
if($mode==='bad-primary')$client=str_replace(' FORCE INDEX (PRIMARY)','',$client);
if($mode==='wrong-id')$client=str_replace('id IN (7)','id IN (9)',$client);
if($mode==='foreign')$client=str_replace('governance_native_aaaaaaaaaaaaaaaa','governance_native_cccccccccccccccc',$client);
$event=function($q,$end=true)use($p){$id=$p->beginServiceCall(['type'=>'query','query'=>$q,'config'=>['password'=>'CANARY_SECRET']]);if($end)$p->endServiceCall($id,[]);};
if($mode==='bad-order')$event($authz);
$event($client,$mode!=='pending');
if(!in_array($mode,['pending','foreign'])){$event($client);$event($authz);}
$t->active=false;
echo json_encode(['events'=>$t->events,'pauses'=>$pauses,'unknown'=>$t->unknown,'pending'=>(bool)$t->pending,'qualified'=>!$t->unknown&&$t->first_returned&&$t->paused&&!$t->pending,'physical_SQL_executed'=>false]);
'''
class NativeQueryTrace(unittest.TestCase):
 def run_trace(self,mode):
  p=subprocess.run(['php','-d','disable_functions='+DISABLED,'-r',TRACE,str(ROOT),mode],capture_output=True,text=True,timeout=5)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');self.assertNotIn('CANARY_SECRET',p.stdout);v=json.loads(p.stdout);self.assertFalse(v['physical_SQL_executed']);return v
 def test_actual_runtime_profiler_client_first_callback_is_once_and_secret_free(self):
  v=self.run_trace('good');self.assertTrue(v['qualified']);self.assertEqual(v['pauses'],[2]);self.assertEqual(v['events'][0:2],[{'phase':'begin','kind':'client'},{'phase':'end','kind':'client'}])
 def test_authorization_delete_pauses_at_authorization_share_not_client_share(self):
  v=self.run_trace('delete');self.assertTrue(v['qualified']);self.assertEqual(v['pauses'],[6]);self.assertEqual(v['events'][-1],{'phase':'end','kind':'authorization'})
 def test_wrong_order_primary_id_foreign_namespace_and_unended_query_cannot_qualify(self):
  for mode in ['bad-order','bad-primary','wrong-id','foreign','pending']:
   with self.subTest(mode=mode):self.assertFalse(self.run_trace(mode)['qualified'])
class NativeLifecycleSQLBoundary(unittest.TestCase):
 def php(self,code,data=None,*args,disabled=DISABLED):
  p=subprocess.run(['php','-d','disable_functions='+disabled,'-r',code,str(ROOT),*args],input=None if data is None else json.dumps(data),capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def fixture_result(self,op='trust'):
  return {'status':'committed','native_terminal_known':True,'profile':'native-oauth-client-lifecycle-v1','operation':op,'subject_id_digest':'a'*64,'subject_phid_digest':'b'*64,'client_id_digest':'c'*64,'client_phid_digest':'d'*64,'authorization_id_digest':'e'*64,'before_client_digest':'f'*64,'after_client_digest':None if op=='client-cascade'else('f'*64 if op=='authorization-delete'else'1'*64),'remaining_rows':[0,0,0]if op=='client-cascade'else([0,1,1]if op=='authorization-delete'else[1,1,1]),'native_admin_preflight_before_owner':True,'continuous_admin_lease_proved':False,'restoration_authorized':False}
 def test_strict_native_result_preserves_operation_specific_delete_and_update_facts(self):
  code="require $argv[1].'/support/governance/recovery/oauth-client-lifecycle-producer.php';try{$v=governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_result(json_decode(stream_get_contents(STDIN),true));echo json_encode(['accepted'=>true,'result'=>$v]);}catch(Throwable$e){echo json_encode(['accepted'=>false]);}"
  for op in ['disabled','redirect','trust','secret','authorization-delete','client-cascade']:
   v=self.fixture_result(op);self.assertEqual(self.php(code,dict(reversed(list(v.items())))),{'accepted':True,'result':v})
  for key,value in [('native_terminal_known',False),('restoration_authorized',True),('operation','INSERT'),('subject_id_digest','bad'),('remaining_rows',[True,1,1]),('after_client_digest','f'*64),('extra','CANARY_SECRET')]:
   v=self.fixture_result();v[key]=value;self.assertFalse(self.php(code,v)['accepted'])
  for op in ['authorization-delete','client-cascade']:
   v=self.fixture_result(op);v['remaining_rows']=[1,1,1];self.assertFalse(self.php(code,v)['accepted'])
 def test_fixed_worker_bad_action_or_client_refuses_before_directory_and_process(self):
  code="require $argv[1].'/support/governance/native-organization/oauth-client-lifecycle-workers.php';class SyntheticOwner{function assertOwned(){}}try{governance_oauth_client_lifecycle_native_start_worker(new SyntheticOwner(),json_decode(stream_get_contents(STDIN),true));echo json_encode(['refused'=>false]);}catch(Throwable$e){echo json_encode(['refused'=>get_class($e)==='RuntimeException'&&$e->getMessage()==='native_worker_action_refused','registered'=>count($GLOBALS['governance_oauth_client_lifecycle_native_workers'])]);}"
  good={'action':'recovery.oauth-client-lifecycle.borrowed-owner.save','operation':'trust','subject':'PHID-USER-'+'a'*20,'client':'PHID-OASC-'+'b'*20,'token':'synthetic'}
  for key,value in [('action','other'),('operation','UPDATE'),('client','PHID-USER-'+'b'*20),('subject','other'),('extra',True)]:
   v={**good,key:value};self.assertEqual(self.php(code,v,disabled=DISABLED+',mkdir,proc_open'),{'refused':True,'registered':0})
 def test_actual_child_exit7_after_proc_close_cannot_authorize_private_cleanup(self):
  code=r'''require $argv[1].'/support/governance/native-organization/oauth-client-lifecycle-workers.php';require $argv[1].'/support/governance/recovery/oauth-client-lifecycle-producer.php';function governance_recovery_borrowed_cleanup_ready(){return false;}$p=$argv[2];$s=lstat($p);$h=['index'=>0,'private'=>$p,'dev'=>$s['dev'],'ino'=>$s['ino'],'nonce'=>str_repeat('a',64),'action'=>'recovery.oauth-client-lifecycle.borrowed-owner.save','cleaned'=>false,'owner_ready'=>true];$process=proc_open([PHP_BINARY,'-r','exit(7);'],[0=>['file','/dev/null','r'],1=>['file',$p.'/stdout.log','w'],2=>['file',$p.'/stderr.log','w']],$pipes);$h['process']=$process;$GLOBALS['governance_oauth_client_lifecycle_native_workers']=[$h];$GLOBALS['governance_recovery_oauth_client_lifecycle_cleanup_proved']=false;$refused=false;try{governance_oauth_client_lifecycle_native_finish_worker($h);}catch(RuntimeException$e){$refused=$e->getMessage()==='oauth_client_lifecycle_worker_nonzero_or_unknown_exit';}$cleanup=governance_oauth_client_lifecycle_native_cleanup_workers();echo json_encode(['refused'=>$refused,'process_consumed'=>$GLOBALS['governance_oauth_client_lifecycle_native_workers'][0]['process']===null,'retained'=>is_file($p.'/owner'),'remaining'=>$cleanup['private_directories_remaining'],'cleanup_failures'=>$cleanup['cleanup_failures']]);'''
  with tempfile.TemporaryDirectory()as td:
   p=Path(td).resolve();p.chmod(0o700);(p/'owner').write_text('a'*64);(p/'owner').chmod(0o600)
   self.assertEqual(self.php(code,None,str(p)),{'refused':True,'process_consumed':True,'retained':True,'remaining':1,'cleanup_failures':1})
 def test_source_has_genuine_native_calls_and_no_additional_unknown_end_or_signal(self):
  writer=(ROOT/'support/governance/recovery/oauth-client-lifecycle-producer.php').read_text();cases=(ROOT/'support/governance/recovery/oauth-client-lifecycle-cases.php').read_text()
  for needle in ['$rows[0]->delete()','$client->destroyObjectPermanently(new PhabricatorDestructionEngine())','$client->save()','PhorgeGovernanceNativeIdentitySource::lockCurrent','CONNECTION_ID() AS CHAR'] :self.assertIn(needle,writer)
  for needle in ['PhorgeRecoveryRestoreService::captureAssessmentCredentialSources','PhorgeRecoveryNativeCaptureScope::captureForRestoration','performance_schema.data_lock_waits','PhorgeRecoveryRestoreService::submit','PhorgeRecoveryRestoreService::review','recovery_restore_sources_stale','PhorgeRecoveryRestoreService::execute']:self.assertIn(needle,cases)
  for path in list((ROOT/'support/governance/recovery').glob('oauth-client-lifecycle-*.php'))+list((ROOT/'support/governance/native-organization').glob('*oauth-client-lifecycle*.php')):
   text=path.read_text();self.assertNotIn('->killTransaction(',text);self.assertNotIn('proc_terminate(',text)
 def test_fifty_two_literal_obligations_do_not_relabel_original_twenty(self):
  code="require $argv[1].'/support/governance/recovery/oauth-client-lifecycle-cases.php';require $argv[1].'/support/governance/recovery/oauth-authorization-cases.php';echo json_encode([governance_recovery_oauth_client_lifecycle_obligations(),governance_recovery_borrowed_oauth_authorization_obligations()]);"
  a,b=self.php(code);self.assertEqual(len(a),52);self.assertEqual(len(set(a)),52);self.assertEqual(len(b),20);self.assertFalse(set(a)&set(b))
if __name__=='__main__':unittest.main()
