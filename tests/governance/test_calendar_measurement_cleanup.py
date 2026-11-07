"""Actual case body failure chain; dependency and native service calls explicit collaborators."""
import subprocess,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class Cleanup(unittest.TestCase):
 def invoke(self,cleanup):
  code=r'''
class PhutilAggregateException extends Exception{public $errors;function __construct($m,$e){parent::__construct($m);$this->errors=$e;}}
$primary=new RuntimeException('primary');$cleanup=new RuntimeException('cleanup');
function governance_completion_start_gorge(...$x){return ['owned'=>true];}
function governance_calendar_reminder_scope(...$x){throw $GLOBALS['primary'];}
function governance_completion_stop_gorge($q){$GLOBALS['stopped']++;if($GLOBALS['fail_cleanup'])throw $GLOBALS['cleanup'];return [];}
$fail_cleanup=$argv[2]==='1';$stopped=0;$s=file_get_contents($argv[1]);$count=0;
// Remove only source bootstrap requires; execute the complete unchanged case function.
$s=preg_replace('/require_once [^;]+;/','',$s,-1,$count);if($count!==3)throw new RuntimeException('dependency_count_changed');eval(substr($s,5));
$f=new class{function assertOwned(){}};$caught=null;try{governance_calendar_revocation_measurement_cases($f,function(){throw new RuntimeException('unexpectedcheck');});}catch(Throwable$e){$caught=$e;}
echo json_encode(['primary_preserved'=>$caught===$primary,'aggregate'=>$caught instanceof PhutilAggregateException,'same_primary'=>$caught instanceof PhutilAggregateException&&$caught->errors['primary']===$primary,'same_cleanup'=>$caught instanceof PhutilAggregateException&&$caught->errors['queue_cleanup']===$cleanup,'stopped'=>$stopped]);'''
  p=subprocess.run(['php','-r',code,str(ROOT/'support/governance/revocation-measurement/calendar-cases.php'),'1'if cleanup else'0'],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);return json.loads(p.stdout)
 def test_primary_preserved_when_cleanup_succeeds(self):
  d=self.invoke(False);self.assertTrue(d['primary_preserved']);self.assertEqual(1,d['stopped'])
 def test_actual_primary_and_cleanup_objects_both_retained(self):
  d=self.invoke(True);self.assertTrue(d['aggregate']);self.assertTrue(d['same_primary']);self.assertTrue(d['same_cleanup']);self.assertEqual(1,d['stopped'])

 def test_actual_finally_cleanup_only_failure_is_not_success(self):
  code=r'''class PhutilAggregateException extends Exception{public $errors;function __construct($m,$e){parent::__construct($m);$this->errors=$e;}}$cleanup=new RuntimeException('cleanup');function governance_completion_stop_gorge($q){throw $GLOBALS['cleanup'];}$s=file_get_contents($argv[1]);$block='finally{'.explode('finally{',$s,2)[1];$block=explode('  $check(is_array($cleanup)',$block,2)[0];$body='function actual_finally(){ $primary=null;$scope=new stdClass();$queue=["owned"=>true];try{return "nominal-success";}'.$block.'}';eval($body);$caught=null;try{actual_finally();}catch(Throwable$e){$caught=$e;}echo json_encode(['aggregate'=>$caught instanceof PhutilAggregateException,'keys'=>$caught instanceof PhutilAggregateException?array_keys($caught->errors):[],'same_cleanup'=>$caught instanceof PhutilAggregateException&&$caught->errors['queue_cleanup']===$cleanup]);'''
  p=subprocess.run(['php','-r',code,str(ROOT/'support/governance/revocation-measurement/calendar-cases.php')],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout);self.assertTrue(d['aggregate']);self.assertEqual(['queue_cleanup'],d['keys']);self.assertTrue(d['same_cleanup'])
