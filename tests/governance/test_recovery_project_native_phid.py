"""Actual native PHID generator/validator controls, not supported suite or SQL proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class NativePHID(unittest.TestCase):
 def test_actual_generator_and_fixed_subtype_contract(self):
  code=r'''require $argv[1].'/support/runtime/src/object/Phobject.php';require $argv[1].'/support/runtime/src/filesystem/Filesystem.php';require $argv[1].'/src/applications/phid/storage/PhabricatorPHID.php';require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php';
$m=new ReflectionMethod(PhorgeRecoveryProjectSourceFacts::class,'phid');$out=[];
foreach(['USER','PROJ','SPCE','XACT-USER','XACT-PROJ']as$type){$parts=explode('-',$type);$v=PhabricatorPHID::generateNewPHID($parts[0],$parts[1]??null);$out[$type]=[$m->invoke(null,$v,$type)===$v,strlen(substr($v,strlen('PHID-'.$type.'-')))];}
foreach([['PHID-XACT-PROJ-'.str_repeat('x',20),'XACT-PROJ'],['PHID-XACT-USER-'.str_repeat('x',20),'XACT-USER'],['PHID-USER-'.str_repeat('u',15),'USER'],['PHID-PROJ-'.str_repeat('p',15),'PROJ'],['PHID-SPCE-'.str_repeat('s',15),'SPCE'],['PHID-XACT-TASK-'.str_repeat('x',15),'XACT-TASK'],['PHID-XACT-USER-'.str_repeat('x',15),'XACT-PROJ'],['PHID-XACT-PROJ-'.str_repeat('x',15)."\n",'XACT-PROJ'],[null,'USER'],['PHID-USER-'.str_repeat('u',20),null]]as$bad){$refused=false;try{$m->invoke(null,$bad[0],$bad[1]);}catch(RuntimeException$e){$refused=$e->getMessage()==='recovery_project_identity_unknown';}$out['negative'][]=$refused;}echo json_encode($out);'''
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertNotIn('PHID-',p.stdout);v=json.loads(p.stdout)
  for t in ['USER','PROJ','SPCE']:self.assertEqual([True,20],v[t])
  for t in ['XACT-USER','XACT-PROJ']:self.assertEqual([True,15],v[t])
  self.assertEqual([True]*10,v['negative'])
if __name__=='__main__':unittest.main()
