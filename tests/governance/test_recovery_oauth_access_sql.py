"""Actual fixed driver selection/whole cleanup and strict synthetic receipts; no SQL."""
from n05_legacy_audit_test_view import historical_source_inputs
from pathlib import Path
import unittest,sys,subprocess,json,tempfile,hashlib,copy
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_oauth_access_producer_v3 as gate

import test_recovery_session_lease_gate as previous
class OAuthAccessWiring(unittest.TestCase):
 def template(self,digest='b'*64):
  d=previous.template(digest);labels=sorted(gate.obligations());d.update(batch='native-oauth-access-producer-owner',scope='owned-native-oauth-access-producer-owner-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40));return d
 def test_actual_driver_selector_and_exact_prior_driver_inverse(self):
  text=(ROOT/'support/governance/native-organization/test-oauth-access-owner-mysql.php').read_text();a=text.index('$arguments=');b=text.index('$workbench_http=',a);block=text[a:b]
  for argv,want in [(['--oauth-access-producer-owner'],'oauth-access-producer-owner'),(['--oauth-access-producer-owner','--session-borrowed-owner'],'invalid'),(['--oauth-access-producer-owner','--oauth-access-producer-owner'],'invalid'),(['--conduit'],'invalid')]:
   code='$argv=array_merge(["driver"],json_decode($argv[1],true));'+block+'echo json_encode($phase);';p=subprocess.run(['php','-r',code,json.dumps(argv)],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual(want,json.loads(p.stdout))
  # Remove only the exact reviewed Calendar additions; recover prior Conduit driver.
  original=ROOT/'support/governance/native-organization/test-native-organization-mysql.php'
  import recovery_conduit_source_delta as calendar_delta
  from check_recovery_file_parent_policy import admitted_native_driver_source,read_native_driver_checked
  raw=read_native_driver_checked(original)
  prior=admitted_native_driver_source(ROOT,raw)
  with self.assertRaises(ValueError):admitted_native_driver_source(ROOT,raw+b'unknown')
  self.assertEqual(calendar_delta.reduce_calendar_wire_union(prior),subprocess.check_output(['git','-C',str(ROOT),'show','e804125e8ad0b716cbbbba59818fb140691bf4f0:'+str(original.relative_to(ROOT))]))
 def test_actual_whole_driver_finally_unknown_before_any_generic_cleanup(self):
  for mode in ['unknown','forged-flags','early','good','old']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/oauth-access-driver-cleanup-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout)
   if mode in ['unknown','forged-flags','early']:self.assertEqual([0]*5,[d[k]for k in ['rollbacks','pool_closes','disposes','drops','control_closes']]);self.assertEqual('current',d['env'])
   else:self.assertEqual(1,d['drops']);self.assertEqual(1,d['control_closes'])
  source=(ROOT/'support/governance/native-organization/test-oauth-access-owner-mysql.php').read_text();self.assertLess(source.index("$GLOBALS['governance_recovery_oauth_access_cleanup_proved']=$phase!=="),source.index('$fixture = null;'))
 def test_source_derived_8_SQL_18_total_48_sources_and_strict_tamper(self):
  d=self.template();v=gate.validate(d,source_revision='a'*40,file_digest='b'*64);self.assertEqual((8,18,50),(v['SQL_obligations'],v['checks'],v['source_inputs']))
  for mutate in [lambda b:b['checks'].pop(),lambda b:b['native'].__setitem__('all_sources_complete',True),lambda b:b['native'].__setitem__('continuous_admin_lease_proved',True),lambda b:b['native'].__setitem__('source_revision','c'*40),lambda b:b['native']['observer_cleanup'].__setitem__('cleanup_proved',False),lambda b:b['file_service_cleanup'].__setitem__('process_stopped',False),lambda b:b.__setitem__('unknown',False)]:
   b=copy.deepcopy(d);mutate(b)
   with self.assertRaises(ValueError):gate.validate(b,source_revision='a'*40,file_digest='b'*64)
 def test_actual_public_CLI_requires_independent_binary_and_rejects_duplicate_receipt(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp);binary=p/'file-data';binary.write_bytes(b'binary data only; never executed');binary.chmod(0o700);digest=hashlib.sha256(binary.read_bytes()).hexdigest();receipt=p/'receipt';receipt.touch(mode=0o600);body=json.dumps(self.template(digest));cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_oauth_access_producer_v3.py'),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest]
   for raw,good in [(body,True),(body.replace('"passed": true','"passed": true,"passed": true',1),False),(body[:-1]+',"unknown":false}',False)]:
    receipt.write_text(raw);r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(good,r.returncode==0,r.stderr)
   receipt.write_text(body);binary.write_bytes(b'changed');r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertNotEqual(0,r.returncode)
 def test_actual_worker_unknown_does_not_explicitly_rollback_close_or_replace_primary(self):
  for mode in ['failed','retained','good','old']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/oauth-access-worker-cleanup-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout)
   self.assertEqual([0 if mode in ['failed','retained']else 1]*4,[d[k]for k in ['rollbacks','closes','disposes','proof_closes']])
   if mode=='failed':self.assertTrue(d['original_failure_preserved'])
   if mode=='retained':self.assertEqual('failed',d['status'])


class FixedOAuthEntry(unittest.TestCase):
 def test_fixed_worker_rejects_other_actions_before_directory_or_process_allocation(self):
  code=r'''require $argv[1].'/support/governance/native-organization/oauth-access-workers.php';
$f=new class{public $owned=0;function assertOwned(){$this->owned++;}};$failed=false;
try{governance_oauth_native_start_worker($f,['action'=>$argv[2]]);}catch(RuntimeException $e){$failed=true;}
echo json_encode(['failed'=>$failed,'owned'=>$f->owned,'registered'=>count($GLOBALS['governance_oauth_native_workers'])]);'''
  for action in ['recovery.conduit.borrowed-owner.issue','recovery.oauth.authenticate','org.list','']:
   p=subprocess.run(['php','-d','disable_functions=proc_open,mkdir,mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT),action],capture_output=True,text=True,timeout=5)
   self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),{'failed':True,'owned':1,'registered':0})
 def test_data_source_manifest_matches_versioned_consumer_without_execution_claim(self):
  manifest=json.loads((ROOT/'docs/governance/recovery-oauth-access-SQL-source-v3.json').read_text())
  self.assertEqual(manifest['source_sha256'],historical_source_inputs('scripts/governance/check_recovery_oauth_access_producer_v3.py'))
  for key in ['runtime_certified','physical_SQL_cases_executed','all_13_sources_complete','permit','restoration_authorized']:
   self.assertIs(manifest[key],False)
 def test_actual_native_factory_and_SQL_writer_route_are_distinct(self):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource())->pushSource(new PhabricatorConfigDictionarySource(['storage.default-namespace'=>'governance_native_aaaaaaaaaaaaaaaa','governance.account-recovery.enabled'=>false]));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);
$guard=new AphrontWriteGuard(static function(){});LiskDAO::beginIsolateAllLiskEffectsToCurrentProcess();
$user=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));$client=(new PhabricatorOAuthServerClient())->setPHID('PHID-OASC-'.str_repeat('b',20));
$token=(new PhabricatorOAuthServer())->setUser($user)->setClient($client)->generateAccessToken();
echo json_encode(['native'=>get_class($token),'inserted'=>$token->getID()!==null,'userdb'=>$user->getAphrontRefDatabaseName(),'tokendb'=>$token->getAphrontRefDatabaseName(),'clientdb'=>$client->getAphrontRefDatabaseName(),'token_writer_queries'=>count($token->establishConnection('w')->getQueryTranscript()),'forced'=>(new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($token)!==null]);'''
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT)],capture_output=True,text=True,timeout=5)
  self.assertEqual(p.returncode,0,p.stderr);d=json.loads(p.stdout);self.assertTrue(d['inserted']);self.assertFalse(d['forced']);self.assertNotEqual(d['userdb'],d['tokendb']);self.assertEqual(d['clientdb'],d['tokendb']);self.assertEqual(1,d['token_writer_queries'])

class ActualOAuthWorkerFinish(unittest.TestCase):
 def test_owned_real_child_reap_and_actual_result_decoder_without_SQL(self):
  code=r'''require $argv[1].'/support/governance/native-organization/oauth-access-workers.php';
$dir=$argv[2];$v=lstat($dir);$nonce=str_repeat('a',64);$p=proc_open([PHP_BINARY,'-r','exit('.$argv[3].');'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
$handle=['index'=>0,'private'=>$dir,'dev'=>$v['dev'],'ino'=>$v['ino'],'nonce'=>$nonce,'action'=>'recovery.oauth-access.borrowed-owner.issue','process'=>$p,'cleaned'=>false,'owner_ready'=>true];$GLOBALS['governance_oauth_native_workers'][]=$handle;
try{$result=governance_oauth_native_finish_worker($handle);echo json_encode(['accepted'=>true,'status'=>$result['status'],'reaped'=>$GLOBALS['governance_oauth_native_workers'][0]['process']===null]);}
catch(Throwable $e){echo json_encode(['accepted'=>false,'error'=>get_class($e)]);}
finally{if(is_resource($p)){proc_close($p);}}'''
  good={'status':'committed','token_id_digest':'b'*64,'profile':'borrowed-owner-native-oauth-access-v1','restoration_authorized':False,'native_admin_preflight_before_owner':True}
  for bad,child_exit in [(False,0),(True,0),(False,7)]:
   with tempfile.TemporaryDirectory(prefix='governance-native-worker-',dir=Path(tempfile.gettempdir()).resolve())as tmp:
    d=Path(tmp);d.chmod(0o700);(d/'owner').write_text('a'*64);(d/'owner').chmod(0o600)
    value=copy.deepcopy(good)
    if bad:value['restoration_authorized']=True
    (d/'result.json').write_text(json.dumps(value));(d/'result.json').chmod(0o600)
    r=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT),str(d),str(child_exit)],capture_output=True,text=True,timeout=5)
    self.assertEqual(r.returncode,0,r.stderr);out=json.loads(r.stdout);self.assertEqual(out['accepted'],not bad and child_exit==0)
    if not bad and child_exit==0:self.assertTrue(out['reaped'])

class RegistryIsolation(unittest.TestCase):
 def test_dedicated_registry_cannot_reset_existing_Conduit_workers(self):
  code=r'''require $argv[1].'/support/governance/native-organization/workers.php';$GLOBALS['governance_native_workers']=[['historical'=>'must-remain']];require $argv[1].'/support/governance/native-organization/oauth-access-workers.php';echo json_encode(['old'=>$GLOBALS['governance_native_workers'],'new'=>$GLOBALS['governance_oauth_native_workers']]);'''
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=5)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),{'old':[{'historical':'must-remain'}],'new':[]})

if __name__=='__main__':unittest.main()
