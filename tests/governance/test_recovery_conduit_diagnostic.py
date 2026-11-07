"""Actual fixed PHP/projector and native ScopedEnv; no SQL/VM proof."""
from pathlib import Path
import copy,hashlib,json,subprocess,sys,tempfile,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_conduit_failure as p
SOURCE=ROOT/'support/governance/recovery/conduit-diagnostic.php'
class Diagnostic(unittest.TestCase):
 def project(self,value):
  code=SOURCE.read_text()[6:]+'\necho json_encode(governance_recovery_conduit_failure_projection(json_decode(stream_get_contents(STDIN),true)));'
  r=subprocess.run(['php','-r',code],input=json.dumps(value),capture_output=True,text=True,timeout=5);self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(r.stderr,'');return json.loads(r.stdout)
 def test_fixed_projection_exact_metadata_and_unknown_or_raw_content_removed(self):
  x={'stage':'focused-native-conduit-producer-owner','attempt_stage':'physical-cases','error_type':'AphrontQueryException','source_file':'PhorgeRecoveryConduitProducerFence.php','source_line':72,'query_code':1205,'message':'SQL SECRET','sql':'SELECT secret','password':'SECRET','child_available':True,'child':{'error_type':'RuntimeException','source_file':'conduit-producer.php','source_line':35,'message':'SECRET'}}
  r=self.project(x);self.assertEqual(r['query_code'],1205);self.assertEqual(r['child']['source_file'],'conduit-producer.php');self.assertNotIn('SECRET',json.dumps(r));self.assertNotIn('sql',r)
  for k,value,want in [('stage','injected','other'),('attempt_stage','SECRET','other'),('error_type','ExcludedUpstreamType','other'),('source_file','/tmp/credential',None),('source_line',True,None),('query_code','1205',None),('child_available',1,False)]:
   d=copy.deepcopy(x);d[k]=value;self.assertEqual(self.project(d)[k],want)
 def test_actual_native_Env_flatten_retained_scope_secondary_after_primary_diagnostic(self):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');require $argv[1].'/support/governance/recovery/conduit-diagnostic.php';$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource())->pushSource(new PhabricatorConfigDictionarySource([]));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);$flags=PhabricatorEnv::beginScopedEnv();$saved=PhabricatorEnv::getConfigSourceStack();$flat=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource())->pushSource(new PhabricatorConfigDictionarySource([]));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$flat);$primary=new RuntimeException('PRIVATE primary SQL/password');$attempt='physical-cases';$s=file_get_contents($argv[1].'/support/governance/recovery/conduit-focused.php');$a=strpos($s,'  }catch(Throwable$error){$primary=$error;');$b=strpos($s,"\n  finally{",$a);$block=substr($s,$a+3,$b-$a-3);$caught=null;try{eval('try{throw $primary;}'.$block);}catch(Throwable$e){$caught=$e;}$secondary=null;try{unset($flags);}catch(Throwable$e){$secondary=$e;}echo json_encode(['same_primary'=>$caught===$primary,'secondary_class'=>$secondary?get_class($secondary):null,'secondary_file'=>$secondary?basename($secondary->getFile()):null,'retained_flat_stack'=>PhabricatorEnv::getConfigSourceStack()===$flat]);'''
  r=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,fsockopen,stream_socket_client','-r',code,str(ROOT)],capture_output=True,text=True,timeout=5);self.assertEqual(r.returncode,0,r.stderr);d=json.loads(r.stdout);self.assertTrue(d['same_primary']and d['retained_flat_stack']);self.assertEqual(d['secondary_file'],'PhabricatorEnv.php');safe=json.loads(r.stderr.strip());self.assertEqual(safe['attempt_stage'],'physical-cases');self.assertEqual(safe['error_type'],'RuntimeException');self.assertNotIn('PRIVATE',r.stderr)
 def test_actual_projector_failed_receipt_only_and_unavailable_no_raw_output(self):
  with tempfile.TemporaryDirectory()as tmp:
   path=Path(tmp).resolve()/'receipt';path.write_text(json.dumps({'batch':'native-conduit-producer-owner','passed':False,'failure':{'stage':'focused-native-conduit-producer-owner','error_type':'RuntimeException','message':'PRIVATE'}}));path.chmod(0o600)
   out=p.project(path);self.assertTrue(out['diagnostic_available']);self.assertNotIn('PRIVATE',json.dumps(out))
   for raw in ['{"passed":true}', '{"passed":false,"passed":false}', 'INVALID PRIVATE']:
    path.write_text(raw);out=p.project(path);self.assertFalse(out['diagnostic_available']);self.assertNotIn('PRIVATE',json.dumps(out))
 def test_changed_captured_projector_refuses_before_execution(self):
  with tempfile.TemporaryDirectory()as tmp:
   path=Path(tmp).resolve()/'receipt';path.write_text(json.dumps({'batch':'native-conduit-producer-owner','passed':False,'failure':{}}));path.chmod(0o600)
   with patch.object(p.gate,'read_source_checked',return_value=b'<?php\nthrow new Exception("PRIVATE");'),patch.object(p.subprocess,'run',side_effect=AssertionError('untrusted execution')):
    self.assertFalse(p.project(path)['diagnostic_available'])
 def test_dedicated_child_handle_reader_projects_only_fixed_fields(self):
  code=SOURCE.read_text()[6:]+r'''function governance_native_borrowed_conduit_handle($h){return ['private'=>'owned','mysql_thread'=>'1'];}function governance_recovery_borrowed_worker_record($p,$n){return $n==='result.json'?['status'=>'failed','error_type'=>'Error','source_file'=>'conduit-producer.php','source_line'=>31,'password'=>'SECRET']:['status'=>'identity-started','mysql_thread'=>'1'];}echo json_encode(governance_recovery_conduit_child_diagnostic([]));'''
  r=subprocess.run(['php','-r',code],capture_output=True,text=True,timeout=5);self.assertEqual(r.returncode,0,r.stderr);d=json.loads(r.stdout);self.assertTrue(d['child_available']and d['child_identity_started']and d['child_result_available']);self.assertEqual(d['child_status'],'failed');self.assertEqual(d['child']['source_line'],31);self.assertNotIn('SECRET',r.stdout)
if __name__=='__main__':unittest.main()
