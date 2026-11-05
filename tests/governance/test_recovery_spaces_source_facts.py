"""Native cache controls and actual bounded-reader seam; no MySQL/runtime proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class SpacesFacts(unittest.TestCase):
 def test_actual_native_query_and_cache_controls(self):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/spaces-native-controls.php'),str(ROOT)],capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertNotIn('PHID-',p.stdout);self.assertNotIn('SYNTHETIC_SECRET',p.stdout);d=json.loads(p.stdout);self.assertTrue(d['passed']);self.assertEqual(10,len(d['checks']));self.assertFalse(d['SQL_executed'])
 def test_actual_length_reader_refuses_bad_plans_before_any_payload(self):
  code=r'''require $argv[1].'/support/runtime/src/object/Phobject.php';require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php';
$v=json_decode(stream_get_contents(STDIN),true);$GLOBALS['payload_calls']=0;$row=['id'=>'1','phid'=>'PHID-SPCE-'.str_repeat('s',20),'namespaceName'=>'x','viewPolicy'=>'users','editPolicy'=>'admin','isDefaultNamespace'=>'1','dateCreated'=>'1','dateModified'=>'1','description'=>'','isArchived'=>'0'];
$plan=['id'=>'1','phidBytes'=>'30','nameBytes'=>'1','viewBytes'=>'5','editBytes'=>'5','descriptionBytes'=>'0'];$GLOBALS['plans']=[$plan];$GLOBALS['row']=$row;
if(isset($v['plan'])){$GLOBALS['plans'][0]=array_replace($plan,$v['plan']);}if(isset($v['row'])){$GLOBALS['row']=array_replace($row,$v['row']);}if(isset($v['later_bad'])){$plan['id']='2';$plan['descriptionBytes']='16385';$GLOBALS['plans'][]=$plan;}
function queryfx_all($conn,$sql,...$args){if(!str_contains($sql,'OCTET_LENGTH(description) descriptionBytes')||!str_contains($sql,'FORCE INDEX (PRIMARY)')){throw new Exception('unbounded_plan');}return$GLOBALS['plans'];}
function queryfx_one($conn,$sql,...$args){$GLOBALS['payload_calls']++;if(!str_contains($sql,'AND OCTET_LENGTH(description)=%d')||!str_contains($sql,'FORCE INDEX (PRIMARY)')){throw new Exception('unbounded_payload');}return$GLOBALS['row'];}
$accepted=false;try{(new ReflectionMethod(PhorgeRecoverySpacesSourceFacts::class,'readRows'))->invoke(null,null,'synthetic_spaces');$accepted=true;}catch(RuntimeException$e){}echo json_encode(['accepted'=>$accepted,'payload_calls'=>$GLOBALS['payload_calls']]);'''
  for value,accepted,calls in [({},True,1),({'plan':{'descriptionBytes':None}},False,0),({'plan':{'descriptionBytes':'01'}},False,0),({'plan':{'descriptionBytes':'16385'}},False,0),({'later_bad':True},False,0),({'row':{'id':'2'}},False,1),({'row':{'description':'changed'}},False,1),({'row':{'phid':None}},False,1)]:
   p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(value),capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertEqual({'accepted':accepted,'payload_calls':calls},json.loads(p.stdout))
 def test_actual_schema_validator_refuses_missing_wrong_or_nonunique_index(self):
  code=r'''require $argv[1].'/support/runtime/src/object/Phobject.php';require $argv[1].'/support/runtime/src/utils/utils.php';require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php';$GLOBALS['mode']=json_decode(stream_get_contents(STDIN),true);
function queryfx_one($conn,$sql,...$args){return ['ENGINE'=>'InnoDB'];}
function queryfx_all($conn,$sql,...$args){if(str_contains($sql,'WHERE 1=0')){return [];}if(str_contains($sql,'information_schema.COLUMNS')){return array_map(fn($v)=>['COLUMN_NAME'=>$v],['id','phid','namespaceName','viewPolicy','editPolicy','isDefaultNamespace','dateCreated','dateModified','description','isArchived']);}$index=$args[2];$column=match($index){'PRIMARY'=>'id','key_phid'=>'phid','key_default'=>'isDefaultNamespace',default=>throw new RuntimeException('unexpected_index')};if($index==='key_default'&&$GLOBALS['mode']==='missing'){return [];}return [['COLUMN_NAME'=>$index==='key_default'&&$GLOBALS['mode']==='wrong'?'id':$column,'NON_UNIQUE'=>$index==='key_default'&&$GLOBALS['mode']==='nonunique'?'1':'0','SEQ_IN_INDEX'=>'1']];}
$accepted=false;try{(new ReflectionMethod(PhorgeRecoverySpacesSourceFacts::class,'schema'))->invoke(null,null,'synthetic_spaces');$accepted=true;}catch(RuntimeException$e){}echo json_encode(['accepted'=>$accepted]);'''
  for kind in ['valid','missing','wrong','nonunique']:
   p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(kind),capture_output=True,text=True);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);self.assertEqual({'accepted':kind=='valid'},json.loads(p.stdout))
 def test_no_production_wiring_or_ordinary_secret_hash(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php').read_text();self.assertIn("hash_hmac('sha256'",s);self.assertIn("$this->finished=true",s)
  for token in ['setIsDisabled','->save(', '->getKeys(', 'getEnvConfig(', 'is_callable(', 'unserialize(']:
   if token=='unserialize(':continue
   self.assertNotIn(token,s)
  self.assertNotIn('PhorgeRecoverySpacesSourceFacts',(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text())
if __name__=='__main__':unittest.main()
