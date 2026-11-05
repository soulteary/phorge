"""Actual fixed cleanup function, native config objects, synthetic writer faults; no SQL."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class ProjectFixtureCleanup(unittest.TestCase):
 def run_case(self,case):
  code=r'''
require $argv[1].'/support/runtime/src/object/Phobject.php';
require $argv[1].'/support/runtime/src/error/PhutilAggregateException.php';
foreach(array('PhabricatorConfigSource','PhabricatorConfigDictionarySource','PhabricatorEnv')as$class){require $argv[1].'/src/infrastructure/env/'.$class.'.php';}
require $argv[1].'/support/governance/recovery/project-source-focused.php';
$v=json_decode(stream_get_contents(STDIN),true);$events=array();
$writer=new class($v['fault'],$events){private $fault;private $events;public function __construct($fault,&$events){$this->fault=$fault;$this->events=&$events;}
 public function isInsideTransaction(){$this->events[]='inside';if($this->fault==='inside'){throw new RuntimeException('synthetic inside failure');}return true;}
 public function killTransaction(){$this->events[]='rollback';if($this->fault==='rollback'){throw new RuntimeException('synthetic rollback failure');}}
};
$source=new PhabricatorConfigDictionarySource(array('guard'=>true));$dictionary=new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary');
$cache=new ReflectionProperty(PhabricatorEnv::class,'cache');$original=$cache->getValue();$cache->setValue(null,array('guard'=>true));
$primary=new RuntimeException('PRIVATE_SENTINEL_PRIMARY');$errors=array();$caught=null;
try{
 try{governance_recovery_project_fixture_cleanup($writer,$v['fault']==='dictionary'?new stdClass():$source,array('guard'=>false),array('guard'=>false),$v['primary']?$primary:null);}
 catch(PhutilAggregateException$error){$caught=$error;$errors=$error->getExceptions();}
 echo json_encode(array('dictionary_restored'=>$dictionary->getValue($source)===array('guard'=>false),
 'cache_restored'=>$cache->getValue()===array('guard'=>false),'aggregate'=>$caught!==null,
 'primary_retained'=>isset($errors['primary'])&&$errors['primary']===$primary,
 'error_stages'=>array_keys($errors),'events'=>$events));
}finally{$cache->setValue(null,$original);}
'''
  p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(case),capture_output=True,text=True,check=True)
  self.assertEqual('',p.stderr);self.assertNotIn('PRIVATE_SENTINEL',p.stdout);return json.loads(p.stdout)
 def test_inside_and_rollback_failures_still_restore_native_dict_and_cache(self):
  for fault in ('inside','rollback'):
   result=self.run_case(dict(fault=fault,primary=True));self.assertTrue(result['dictionary_restored']);self.assertTrue(result['cache_restored'])
   self.assertTrue(result['aggregate']);self.assertTrue(result['primary_retained']);self.assertEqual(['primary','transaction-cleanup'],result['error_stages'])
   self.assertEqual(['inside']if fault=='inside'else ['inside','rollback'],result['events'])
 def test_success_does_not_replace_original_error_or_repeat_rollback(self):
  result=self.run_case(dict(fault='none',primary=True));self.assertTrue(result['dictionary_restored']);self.assertTrue(result['cache_restored']);self.assertFalse(result['aggregate']);self.assertEqual(['inside','rollback'],result['events'])
 def test_dictionary_restore_failure_still_restores_cache_and_preserves_primary(self):
  result=self.run_case(dict(fault='dictionary',primary=True));self.assertFalse(result['dictionary_restored']);self.assertTrue(result['cache_restored']);self.assertTrue(result['primary_retained']);self.assertEqual(['primary','dictionary-restoration'],result['error_stages'])
 def test_actual_finally_calls_fixed_cleanup_once(self):
  s=(ROOT/'support/governance/recovery/project-source-focused.php').read_text()
  body=s.split('function governance_recovery_project_source_focused',1)[1]
  self.assertNotIn('killTransaction(',body)
  self.assertEqual(1,body.count('governance_recovery_project_fixture_cleanup('))
  self.assertIn('catch(Throwable$error){$primary=$error;throw$error;}',body)
  self.assertLess(body.index('recovery_project_fixture_outer_transaction_refused'),body.index('$owned_writer=$writer'))
if __name__=='__main__':unittest.main()
