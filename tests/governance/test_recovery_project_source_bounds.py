"""Actual private-reader and public primitive-bound controls with synthetic query functions.
No real connection, SQL authorization, absence or lease proof is supplied here.
"""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
BOOT="require $argv[1].'/support/runtime/src/object/Phobject.php';require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php';"
class ProjectBounds(unittest.TestCase):
 def php(self,code,value):
  p=subprocess.run(['php','-r',BOOT+code,str(ROOT)],input=json.dumps(value),capture_output=True,text=True,check=True)
  self.assertEqual('',p.stderr);self.assertNotIn('PHID-',p.stdout);self.assertNotIn('PRIVATE_SENTINEL',p.stdout)
  return json.loads(p.stdout)
 def test_no_magic_callback_for_object_closure_resource_or_overflow(self):
  code=r'''
class MagicProjectSentinel {public function __serialize(){ $GLOBALS['callback']=true;return array(); }
 public function __toString(){ $GLOBALS['callback']=true;return 'PRIVATE_SENTINEL';}}
$v=json_decode(stream_get_contents(STDIN),true);$GLOBALS['callback']=false;$resource=null;
$bad=match($v['kind']){'object'=>new MagicProjectSentinel(),'closure'=>function(){$GLOBALS['callback']=true;},
 'resource'=>$resource=fopen('php://memory','r+'),'deep'=>array(),'nodes'=>array_fill(0,8193,0),
 'bytes'=>str_repeat('x',524289)};
if($v['kind']==='deep'){for($i=0;$i<20;$i++){$bad=array($bad);}}
$x=array('subject_row'=>array('id'=>'1','phid'=>'PHID-USER-'.str_repeat('u',20)),
 'projects'=>array(),'selected'=>array(),'children'=>array(),'user_edges'=>array(),'project_edges'=>array(),'history'=>array($bad));
$accepted=false;try{PhorgeRecoveryProjectSourceFacts::describeUntrustedRows('PHID-USER-'.str_repeat('u',20),$x);$accepted=true;}catch(RuntimeException$e){}
if($resource){fclose($resource);}echo json_encode(array('accepted'=>$accepted,'callback'=>$GLOBALS['callback']));'''
  for kind in ('object','closure','resource','deep','nodes','bytes'):
   with self.subTest(kind=kind):self.assertEqual({'accepted':False,'callback':False},self.php(code,dict(kind=kind)))
 def reader(self,case):
  code=r'''
$v=json_decode(stream_get_contents(STDIN),true);$object='PHID-PROJ-'.str_repeat('p',20);
$plan=array('id'=>'1','phid'=>'PHID-XACT-PROJ-'.str_repeat('x',15),'objectPHID'=>$object,'transactionType'=>'edge','oldBytes'=>'2','newBytes'=>'2','metadataBytes'=>'2');
$row=array('id'=>'1','phid'=>$plan['phid'],'objectPHID'=>$object,'authorPHID'=>'PHID-USER-'.str_repeat('u',20),'transactionType'=>'edge','oldValue'=>'[]','newValue'=>'{}','metadata'=>'{}','dateCreated'=>'1');
$GLOBALS['plans']=array($plan);$GLOBALS['rows']=array($row);$GLOBALS['payload_calls']=0;$GLOBALS['plan_calls']=0;
if(isset($v['plan'])){foreach($v['plan']as$k=>$value){$GLOBALS['plans'][0][$k]=$value;}}
if(isset($v['second_bad'])){$second=$plan;$second['id']='2';$second['oldBytes']='16385';$GLOBALS['plans'][]=$second;}
if(isset($v['row'])){foreach($v['row']as$k=>$value){$GLOBALS['rows'][0][$k]=$value;}}
if(isset($v['row_missing'])){$GLOBALS['rows'][0]=null;}
if(isset($v['rows65'])){$GLOBALS['plans']=array_fill(0,65,$plan);}
function queryfx_all($conn,$sql,...$args){
 $GLOBALS['plan_calls']++;
 if(!str_contains($sql,'FORCE INDEX (key_object)')||!str_contains($sql,'OCTET_LENGTH(oldValue) oldBytes')||str_contains($sql,'authorPHID')){throw new Exception('wrong_plan_query');}
 return $GLOBALS['plans'];
}
function queryfx_one($conn,$sql,...$args){
 $i=$GLOBALS['payload_calls']++;
 if(!str_contains($sql,'FORCE INDEX (PRIMARY)')||!str_contains($sql,'AND OCTET_LENGTH(oldValue)=%d')||!str_contains($sql,'AND OCTET_LENGTH(newValue)=%d')||!str_contains($sql,'AND OCTET_LENGTH(metadata)=%d')){throw new Exception('unbounded_payload_query');}
 return $GLOBALS['rows'][$i]??null;
}
$history=array();$bytes=$v['used']??0;$args=array(null,'governance_native_'.str_repeat('a',16).'_project','project_transaction',$object,&$history,&$bytes);$accepted=false;
try{(new ReflectionMethod(PhorgeRecoveryProjectSourceFacts::class,'readHistory'))->invokeArgs(null,$args);$accepted=true;}catch(RuntimeException$e){}
echo json_encode(array('accepted'=>$accepted,'payload_calls'=>$GLOBALS['payload_calls'],'plan_calls'=>$GLOBALS['plan_calls'],'history_count'=>count($history)));'''
  return self.php(code,case)
 def test_valid_plan_then_every_bad_length_refuses_before_any_payload(self):
  self.assertEqual(dict(accepted=True,payload_calls=1,plan_calls=1,history_count=1),self.reader({}))
  for field in ('oldBytes','newBytes','metadataBytes'):
   for value in (None,True,False,0.0,'00','01','1e3','-1',-1,16385,'16385'):
    with self.subTest(field=field,value=value):
     out=self.reader(dict(plan={field:value}));self.assertFalse(out['accepted']);self.assertEqual(0,out['payload_calls'])
 def test_cumulative_rows_and_later_invalid_plan_have_no_payload_fallback(self):
  for case in [dict(second_bad=True),dict(used=524288-4096-5),dict(rows65=True),dict(plan={'id':'00'}),dict(plan={'objectPHID':'other'}),dict(plan={'phid':'wrong'})]:
   out=self.reader(case);self.assertFalse(out['accepted']);self.assertEqual(0,out['payload_calls']);self.assertEqual(1,out['plan_calls'])
 def test_changed_payload_identity_null_and_size_refused(self):
  for case in [dict(row_missing=True),dict(row={'id':'2'}),dict(row={'phid':'different'}),dict(row={'objectPHID':'different'}),dict(row={'transactionType':'project:parent'}),dict(row={'oldValue':None}),dict(row={'newValue':'changed'}),dict(row={'metadata':'longer'})]:
   out=self.reader(case);self.assertFalse(out['accepted']);self.assertEqual(1,out['payload_calls']);self.assertEqual(0,out['history_count'])
 def test_actual_snapshot_calls_only_two_phase_history_reader(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php').read_text()
  before_reader=s.split('  private static function historyLength',1)[0]
  self.assertIn("self::readHistory($conn,$prefix.'_'.$app",before_reader)
  self.assertNotIn('SELECT id,phid,objectPHID,authorPHID,transactionType,oldValue',before_reader)
  budget=s.split('  private static function budget(',1)[1].split('  private static function profile',1)[0]
  self.assertIn('self::primitiveBudget(',budget);self.assertNotIn('serialize(',budget)
if __name__=='__main__':unittest.main()
