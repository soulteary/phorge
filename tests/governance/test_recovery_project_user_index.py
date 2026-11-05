"""Actual schema validator and fixed route controls; synthetic metadata, no database proof."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class NativeUserIndex(unittest.TestCase):
 def test_actual_schema_validator_exact_missing_column_and_unique(self):
  code=r"""require $argv[1].'/support/runtime/src/object/Phobject.php';require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php';require $argv[1].'/support/runtime/src/utils/utils.php';
$v=json_decode(stream_get_contents(STDIN),true);$GLOBALS['variant']=$v;$GLOBALS['indices']=[];
function queryfx_one($conn,$sql,...$args){return ['ENGINE'=>'InnoDB'];}
function queryfx_all($conn,$sql,...$args){if(str_contains($sql,'WHERE 1=0')){return [];}$index=$args[2];$GLOBALS['indices'][]=$index;if($index==='PRIMARY'){return [['COLUMN_NAME'=>'id','NON_UNIQUE'=>'0']];}if($index!=='phid'){throw new RuntimeException('unexpected_index');}return match($GLOBALS['variant']){'missing'=>[],'wrong-column'=>[['COLUMN_NAME'=>'id','NON_UNIQUE'=>'0']],'nonunique'=>[['COLUMN_NAME'=>'phid','NON_UNIQUE'=>'1']],default=>[['COLUMN_NAME'=>'phid','NON_UNIQUE'=>'0']]};}
$accepted=false;try{(new ReflectionMethod(PhorgeRecoveryProjectSourceFacts::class,'schema'))->invoke(null,null,'synthetic_user','user',['PRIMARY'=>['id'],'phid'=>['phid']]);$accepted=true;}catch(RuntimeException$e){}echo json_encode(['accepted'=>$accepted,'indices'=>$GLOBALS['indices']]);"""
  for kind in ['valid','missing','wrong-column','nonunique']:
   p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(kind),capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertEqual({'accepted':kind=='valid','indices':['PRIMARY','phid']},json.loads(p.stdout))
 def test_fixed_native_routes_and_current_quickstart_contract(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php').read_text();self.assertIn("$prefix.'_user','user',array('PRIMARY'=>array('id'),'phid'=>array('phid'))",s);self.assertIn('FROM %T.user FORCE INDEX (phid) WHERE phid=%s',s);self.assertNotIn('FROM %T.user FORCE INDEX (key_phid)',s);self.assertIn("$prefix.'_project','project',array('PRIMARY'=>array('id'),'key_phid'=>array('phid')",s)
  quick=(ROOT/'resources/sql/quickstart.sql').read_text();user=quick.split('CREATE TABLE `user` (',1)[1].split('ENGINE=',1)[0];self.assertIn('UNIQUE KEY `phid` (`phid`)',user);self.assertNotIn('KEY `key_phid`',user)
if __name__=='__main__':unittest.main()
