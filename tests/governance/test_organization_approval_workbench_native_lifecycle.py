"""Real native missing-cache and locale restoration controls, no SQL success claim."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class Lifecycle(unittest.TestCase):
 def php(self,body):
  code="require$argv[1].'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($argv[1].'/src');require$argv[1].'/support/governance/organization/approval-workbench-cases.php';"+body
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_real_native_PHID_user_missing_preferences_is_not_silently_accepted(self):
  d=self.php("$user=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('s',20));$caught=null;try{$user->getTranslation();}catch(Throwable$error){$caught=$error;}echo json_encode(array('actual_missing_cache'=>$caught instanceof PhabricatorDataNotAttachedException,'file'=>basename($caught->getFile())));")
  self.assertEqual(d,{'actual_missing_cache':True,'file':'PhabricatorUser.php'})
 def test_actual_native_translator_clone_and_exact_null_locale_restore(self):
  d=self.php("$prop=new ReflectionProperty(PhabricatorEnv::class,'localeCode');$prop->setValue(null,null);$stackProp=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$cacheProp=new ReflectionProperty(PhabricatorEnv::class,'cache');$oldStack=$stackProp->getValue();$oldCache=$cacheProp->getValue();$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(array('translation.override'=>array('workbench-locale-sentinel'=>'translated-locale-sentinel'))));$stackProp->setValue(null,$stack);$cacheProp->setValue(null,array());$old=PhutilTranslator::getInstance();$oldState=serialize($old);PhutilTranslator::setInstance(clone$old);PhabricatorEnv::setLocaleCode('en_US');$changed=PhabricatorEnv::getLocaleCode()==='en_US'&&(new ReflectionProperty(PhutilTranslator::class,'localeCode'))->getValue(PhutilTranslator::getInstance())==='en_US'&&pht('workbench-locale-sentinel')==='translated-locale-sentinel';$isolated=PhutilTranslator::getInstance()!==$old&&serialize($old)===$oldState;governance_approval_workbench_restore(array('locale'=>static fn()=>$prop->setValue(null,null),'translator'=>static fn()=>PhutilTranslator::setInstance($old),'stack'=>static fn()=>$stackProp->setValue(null,$oldStack),'cache'=>static fn()=>$cacheProp->setValue(null,$oldCache)),null);echo json_encode(array($changed,$isolated,PhutilTranslator::getInstance()===$old,$prop->getValue()===null,serialize($old)===$oldState));")
  self.assertEqual(d,[True]*5)
 def test_failed_restore_still_runs_other_actions_and_retains_primary(self):
  d=self.php("$calls=array();$primary=new RuntimeException('PRIMARY_SENTINEL');$caught=null;try{governance_approval_workbench_restore(array('locale'=>static function(){throw new RuntimeException('RESTORE_SENTINEL');},'translator'=>static function()use(&$calls){$calls[]='translator';},'configuration'=>static function()use(&$calls){$calls[]='configuration';}),$primary);}catch(PhutilAggregateException$error){$caught=$error;}echo json_encode(array('calls'=>$calls,'aggregate'=>$caught instanceof PhutilAggregateException,'primary'=>str_contains($caught->getMessage(),'PRIMARY_SENTINEL')));")
  self.assertEqual(d,{'calls':['translator','configuration'],'aggregate':True,'primary':True})
if __name__=='__main__':unittest.main()
