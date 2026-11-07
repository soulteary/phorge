"""Real PHP fixture include/bootstrap and actual symbol resolution. Never SQL."""
from pathlib import Path
import json,subprocess,unittest,tempfile
ROOT=Path(__file__).resolve().parents[2]
CODE=r'''
require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException('dependency_read_refused',0,$severity,$file,$line);});
$case=$argv[2];require $case;
$sentinel=new RuntimeException('dependency_boundary_reached');
$fixture=new class{public $calls=0;function assertOwned(){$this->calls++;}};
$actor=new class{function getUserPHID(){throw $GLOBALS['sentinel'];}};
$control=new mysqli();$caught=null;
try{governance_recovery_borrowed_conduit_cases($fixture,$control,['issuer_auth'=>['context'=>$actor],'owner_auth'=>['context'=>$actor],'subjects'=>[]],static function(){throw new RuntimeException('unexpected_SQL_case_record');});}catch(Throwable$e){$caught=$e;}
// Resolve ALL actual include statements, including failure-only diagnostic.
$source=file_get_contents($case);preg_match_all('/require_once\s+([^;]+);/',$source,$matches);$paths=[];
foreach($matches[1]as$expression){$expression=str_replace('__DIR__',var_export(dirname($case),true),$expression);$path=eval('return '.$expression.';');if(!is_file($path)){echo json_encode(['reached_after_dependencies'=>$caught===$sentinel,'missing_dependency'=>true]);exit(0);}require_once$path;$paths[]=basename($path);}
$symbols=[];foreach(['governance_recovery_borrowed_with_observer','governance_recovery_conduit_native_producer','governance_native_wait_borrowed_conduit_preflight','governance_native_continue_borrowed_conduit','governance_recovery_borrowed_worker_diagnostic','governance_recovery_session_lease_workers_ended','governance_native_start_worker','governance_native_finish_worker','governance_native_stop_fixture_handles']as$name){$r=new ReflectionFunction($name);$symbols[$name]=['file'=>basename($r->getFileName()),'required'=>$r->getNumberOfRequiredParameters(),'total'=>$r->getNumberOfParameters()];}
$methods=[];foreach([[PhabricatorConduitToken::class,'initializeNewToken'],[PhabricatorConduitToken::class,'insertRecordIntoDatabase'],[PhorgeRecoveryConduitProducerFence::class,'insert'],[PhorgeRecoveryRestoreService::class,'captureBorrowedConduitFacts'],[PhorgeRecoverySessionProducerFence::class,'borrowedCaptureConduit']]as[$class,$method]){$r=new ReflectionMethod($class,$method);$methods[$class.'::'.$method]=['required'=>$r->getNumberOfRequiredParameters(),'protected'=>$r->isProtected()];}
echo json_encode(['reached_after_dependencies'=>$caught===$sentinel,'missing_dependency'=>false,'owned_assertions'=>$fixture->calls,'paths'=>$paths,'symbols'=>$symbols,'methods'=>$methods,'DB_functions_disabled'=>!function_exists('mysqli_connect')]);
'''
class ConduitDependencies(unittest.TestCase):
 def invoke(self,case):
  r=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',CODE,str(ROOT),str(case)],capture_output=True,text=True,timeout=10);self.assertEqual(0,r.returncode,r.stderr);return json.loads(r.stdout)
 def test_actual_case_entry_reaches_first_actor_only_after_loading_real_dependencies(self):
  d=self.invoke(ROOT/'support/governance/recovery/conduit-cases.php');self.assertTrue(d['reached_after_dependencies']);self.assertFalse(d['missing_dependency']);self.assertEqual(1,d['owned_assertions']);self.assertTrue(d['DB_functions_disabled']);self.assertEqual({'borrowed-session-observer.php','conduit-producer.php','session-lease-cases.php','workers.php','borrowed-session-diagnostic.php'},set(d['paths']))
  self.assertEqual('borrowed-session-observer.php',d['symbols']['governance_recovery_borrowed_with_observer']['file']);self.assertEqual((3,3),(d['symbols']['governance_recovery_borrowed_with_observer']['required'],d['symbols']['governance_recovery_borrowed_with_observer']['total']))
  self.assertEqual('borrowed-session-diagnostic.php',d['symbols']['governance_recovery_borrowed_worker_diagnostic']['file']);self.assertEqual(4,d['symbols']['governance_recovery_borrowed_worker_diagnostic']['required'])
  self.assertTrue(d['methods']['PhabricatorConduitToken::insertRecordIntoDatabase']['protected']);self.assertEqual(3,d['methods']['PhorgeRecoveryConduitProducerFence::insert']['required']);self.assertEqual(2,d['methods']['PhorgeRecoveryRestoreService::captureBorrowedConduitFacts']['required'])
 def test_frozen_blocked_case_actually_fails_before_dependency_boundary(self):
  old=subprocess.check_output(['git','-C',str(ROOT),'show','9b3d14056253cbf5fa563424a3a4e2fbc39b33b0:support/governance/recovery/conduit-cases.php'])
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   case=Path(tmp)/'conduit-cases.php';case.write_bytes(old);d=self.invoke(case);self.assertFalse(d['reached_after_dependencies']);self.assertTrue(d['missing_dependency'])
if __name__=='__main__':unittest.main()
