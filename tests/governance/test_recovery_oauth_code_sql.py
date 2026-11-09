"""Fixed native code SQL fixture controls, no SQL executed."""
from pathlib import Path
import unittest,sys,subprocess,json,tempfile,hashlib,copy
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_oauth_code_producer_v1 as gate
class OAuthCodeSQL(unittest.TestCase):
 def test_owned_real_child_reap_and_actual_result_decoder_without_SQL(self):
  code=r'''require $argv[1].'/support/governance/native-organization/oauth-code-workers.php';
$dir=$argv[2];$v=lstat($dir);$nonce=str_repeat('a',64);$p=proc_open([PHP_BINARY,'-r','exit('.$argv[3].');'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
$handle=['index'=>0,'private'=>$dir,'dev'=>$v['dev'],'ino'=>$v['ino'],'nonce'=>$nonce,'action'=>'recovery.oauth-code.borrowed-owner.issue','process'=>$p,'cleaned'=>false,'owner_ready'=>true];$GLOBALS['governance_oauth_code_native_workers'][]=$handle;
try{$result=governance_oauth_code_native_finish_worker($handle);echo json_encode(['accepted'=>true,'status'=>$result['status'],'reaped'=>$GLOBALS['governance_oauth_code_native_workers'][0]['process']===null]);}
catch(Throwable $e){echo json_encode(['accepted'=>false,'error'=>get_class($e)]);}
finally{if(is_resource($p)){proc_close($p);}}'''
  good={'status':'committed','code_id_digest':'b'*64,'profile':'borrowed-owner-native-oauth-code-v1','restoration_authorized':False,'native_admin_preflight_before_owner':True}
  for bad,child_exit in [(False,0),(True,0),(False,7)]:
   with tempfile.TemporaryDirectory(prefix='governance-native-worker-',dir=Path(tempfile.gettempdir()).resolve())as tmp:
    d=Path(tmp);d.chmod(0o700);(d/'owner').write_text('a'*64);(d/'owner').chmod(0o600)
    value=copy.deepcopy(good)
    if bad:value['restoration_authorized']=True
    (d/'result.json').write_text(json.dumps(value));(d/'result.json').chmod(0o600)
    r=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT),str(d),str(child_exit)],capture_output=True,text=True,timeout=5)
    self.assertEqual(r.returncode,0,r.stderr);out=json.loads(r.stdout);self.assertEqual(out['accepted'],not bad and child_exit==0)
    if not bad and child_exit==0:self.assertTrue(out['reaped'])

 def test_fixed_CLI_single_selector_and_real_source_obligations(self):
  text=(ROOT/'support/governance/native-organization/test-oauth-code-owner-mysql.php').read_text();a=text.index('$arguments=');b=text.index('$workbench_http=',a);block=text[a:b]
  for argv,want in [(['--oauth-code-producer-owner'],'oauth-code-producer-owner'),(['--oauth-code-producer-owner','--session-borrowed-owner'],'invalid'),(['--oauth-access-producer-owner'],'invalid')]:
   code='$argv=array_merge(["driver"],json_decode($argv[1],true));'+block+'echo json_encode($phase);';p=subprocess.run(['php','-r',code,json.dumps(argv)],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),want)
  self.assertEqual(len(gate.SOURCE_INPUTS),51);self.assertEqual(len(gate.SQL_obligations()),8);self.assertEqual(len(gate.obligations()),18)
 def test_exact_six_source_inverse_and_unrelated_byte_refusal(self):
  import recovery_oauth_code_source_delta as delta
  self.assertEqual(len(delta.SOURCE_INPUTS),6)
  for path in delta.SOURCE_INPUTS:
   raw=(ROOT/path).read_bytes();old=delta.reduce_current(path,raw);self.assertEqual(hashlib.sha256(old).hexdigest(),delta.BASE_PINS[path])
   with self.assertRaises(ValueError):delta.reduce_current(path,raw+b'// unrelated source')
 def test_strict_synthetic_receipt_requires_every_obligation_and_terminal(self):
  import test_native_organization as native
  d=native.template();labels=sorted(gate.obligations());d.update(batch='native-oauth-code-producer-owner',scope='owned-native-oauth-code-producer-owner-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40),file_service_cleanup={'kind':'file','binary_sha256':'b'*64,'process_stopped':True,'private_storage_removed':True},successful_owned_file_service_startups=1,worker_cleanup={'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2})
  self.assertTrue(gate.validate(d,source_revision='a'*40,file_digest='b'*64)['passed'])
  cases=[]
  for label in labels:
   x=copy.deepcopy(d);x['checks'].remove(label);x['check_count']-=1;cases.append(x)
  for key,value in [('both_owned_native_SQL_threads_observed',False),('owned_child_exit0_proved',False),('restoration_authorized',True),('production_observer_factory_available',True)]:
   x=copy.deepcopy(d);x['native'][key]=value;cases.append(x)
  x=copy.deepcopy(d);x['cleanup']['ownedSchemasRemoved']=54;cases.append(x)
  x=copy.deepcopy(d);x['file_service_cleanup']['process_stopped']=False;cases.append(x)
  for x in cases:
   with self.assertRaises(ValueError):gate.validate(x,source_revision='a'*40,file_digest='b'*64)
if __name__=='__main__':unittest.main()
