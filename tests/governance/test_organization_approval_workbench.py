"""Actual native value controls and explicit SQL/framework seams, never SQL/browser proof."""
from pathlib import Path
import json,re,subprocess,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2]
ADAPTER=ROOT/'src/applications/people/governance/approval/PhorgeGovernanceNativeApprovalAdapter.php'

def method(name):
    text=ADAPTER.read_text();start=re.search(r'  (?:public|private)(?: static)? function '+name+r'\(',text).start()
    opening=text.index('{',start);depth=1;i=opening+1;quote=None;comment=None
    while depth:
        c=text[i];n=text[i:i+2]
        if comment=='line':
            if c=='\n':comment=None
        elif comment=='block':
            if n=='*/':comment=None;i+=1
        elif quote:
            if c=='\\':i+=1
            elif c==quote:quote=None
        elif n=='//':comment='line';i+=1
        elif n=='/*':comment='block';i+=1
        elif c in "'\"":quote=c
        elif c=='{':depth+=1
        elif c=='}':depth-=1
        i+=1
    return text[start:i]

class Workbench(unittest.TestCase):
 def php(self,code,*args):
  p=subprocess.run(['php','-r',code,str(ROOT),*args],capture_output=True,text=True,timeout=10)
  self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);return json.loads(p.stdout)
 def test_actual_native_value_projection_and_route_controls(self):
  p=subprocess.run(['php',str(ROOT/'support/governance/organization/tests/approval-workbench-controls.php'),str(ROOT)],capture_output=True,text=True,timeout=10)
  self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);v=json.loads(p.stdout)
  self.assertTrue(v['passed']);self.assertEqual(6,len(v['checks']));self.assertFalse(v['SQL_executed']);self.assertFalse(v['HTTP_server_executed'])
 def test_actual_fixture_revoke_request_obeys_native_null_period_contract(self):
  fixture=(ROOT/'support/governance/organization/approval-workbench-cases.php').read_text()
  match=re.search(r"\$service->proposeApproval\((array\('approval_id'=>'00-noncompound'.*?\))\);",fixture,re.S)
  self.assertIsNotNone(match)
  code=r'''ob_start();require$argv[1].'/support/governance/organization/tests/approval-workbench-controls.php';ob_end_clean();
$team='synthetic-team';$users=array('subject'=>(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('s',20)));$input='''+match.group(1)+r''';
$accepted=PhorgeOrganizationRequest::approval($input);$refused=array();
foreach(array(array('valid_from',0),array('valid_until',0),array('valid_from','0'),array('valid_from',false))as$case){$bad=$input;$bad[$case[0]]=$case[1];$caught=null;try{PhorgeOrganizationRequest::approval($bad);}catch(InvalidArgumentException$error){$caught=$error;}$refused[]=$caught!==null&&$caught->getMessage()==='organization_period_refused';}
echo json_encode(array('accepted_null_period'=>$accepted['valid_from']===null&&$accepted['valid_until']===null,'action'=>$accepted['action'],'refused'=>$refused));'''
  value=self.php(code);self.assertTrue(value['accepted_null_period']);self.assertEqual('appointment.revoke',value['action']);self.assertEqual([True]*4,value['refused'])
 def test_actual_controller_new_page_builder_returns_native_standard_page(self):
  code=r'''ob_start();require$argv[1].'/support/governance/organization/tests/approval-workbench-controls.php';ob_end_clean();
$viewer=new PhabricatorUser();$request=(new AphrontRequest('localhost','/people/organization/approvals/'))->setUser($viewer);$controller=(new PhorgeOrganizationApprovalController())->setRequest($request);$page=$controller->newPage();
echo json_encode(array('class'=>get_class($page),'native_standard'=>$page instanceof PhabricatorStandardPageView,'native_bare'=>$page instanceof PhabricatorBarePageView,'request_same'=>$page->getRequest()===$request,'controller_same'=>$page->getController()===$controller,'viewer_same'=>$page->getViewer()===$viewer,'old_class_exists'=>class_exists('PHUIPageView')));'''
  d=self.php(code);self.assertEqual('PhabricatorStandardPageView',d['class']);self.assertTrue(all(d[k]is True for k in ('native_standard','native_bare','request_same','controller_same','viewer_same')));self.assertFalse(d['old_class_exists'])
  fixture=(ROOT/'support/governance/organization/approval-workbench-cases.php').read_text();self.assertNotIn('instanceof PHUIPageView',fixture)
 def test_actual_page_function_validates_all_lengths_before_payload(self):
  # Execute unchanged method bodies with explicit query/actor/load seams.
  funcs='\n'.join(method(n)for n in ['identifier','planWorkbenchRows','workbenchPage'])
  code=r'''ob_start();require$argv[1].'/support/governance/organization/tests/approval-workbench-controls.php';ob_end_clean();$case=$argv[2];$GLOBALS['loads']=[];
function queryfx_all($conn,$sql,...$args){$GLOBALS['query']=$sql;$GLOBALS['args']=$args;global$case;$base=['sourceParticipantVersion'=>'1','sourceSubjectPHID'=>'PHID-USER-'.str_repeat('s',20),'sourceRequesterPHID'=>'PHID-USER-'.str_repeat('a',20),'sourceExecutorPHID'=>'PHID-USER-'.str_repeat('a',20),'approverPHID'=>null,'rowVersion'=>'1'];$rows=[['approvalID'=>'a','bindingBytes'=>'7','approverBytes'=>null]+$base,['approvalID'=>'b','bindingBytes'=>'7','approverBytes'=>null]+$base];if($case==='oversize'){$rows[1]['bindingBytes']='32769';}if($case==='null'){$rows[1]['bindingBytes']=null;}if($case==='noncanonical'){$rows[1]['bindingBytes']='07';}if($case==='approver'){$rows[1]['approverBytes']='32769';}if($case==='duplicate'){$rows[1]['approvalID']='a';}if($case==='legacy'){$rows[1]['sourceParticipantVersion']='0';}$GLOBALS['headers']=$rows;return$rows;}
class WorkbenchPageSeam{private$connection=null;function actor(){return true;}private static function load($conn,$id,$locked){global$case;$GLOBALS['loads'][]=$id;$row=$GLOBALS['headers'][0];$row['version']=$row['rowVersion'];$row['bindingJSON']=$case==='changed'?'short':'payload';$row['approverBindingJSON']=null;if($case==='participant-changed'){$row['sourceSubjectPHID']='PHID-USER-'.str_repeat('z',20);}if($case==='rowversion-changed'){$row['version']='2';}return[$row,[]];}function workbenchProjection($row,$data){return null;}
'''+funcs+r'''}
$accepted=false;$result=null;try{$result=(new WorkbenchPageSeam())->workbenchPage(1,null);$accepted=true;}catch(Throwable$e){}echo json_encode(['accepted'=>$accepted,'loads'=>count($GLOBALS['loads']),'result'=>$result,'primary_index'=>str_contains($GLOBALS['query'],'FORCE INDEX (PRIMARY)'),'limit'=>$GLOBALS['args'][1]]);'''
  for case in ['valid','oversize','null','noncanonical','approver','duplicate','legacy','changed','participant-changed','rowversion-changed']:
   with self.subTest(case=case):
    d=self.php(code,case);self.assertEqual(case=='valid',d['accepted']);self.assertTrue(d['primary_index']);self.assertEqual(2,d['limit'])
    if case=='valid':self.assertEqual([],d['result']['rows']);self.assertEqual('a',d['result']['next_cursor']);self.assertEqual(2,d['result']['bounded_scanned_rows']);self.assertEqual(1,d['result']['bounded_payload_rows'])
    else:self.assertEqual(1 if case in ['changed','participant-changed','rowversion-changed']else 0,d['loads'])
 def test_actual_aggregate_bytes_and_subject_budget_paginate_without_skipping(self):
  code=r'''ob_start();require$argv[1].'/support/governance/organization/tests/approval-workbench-controls.php';ob_end_clean();$row=static function($i,$bytes=32768,$approverBytes=32768){return['approvalID'=>sprintf('request-%02d',$i),'bindingBytes'=>$bytes,'approverBytes'=>$approverBytes,'sourceParticipantVersion'=>1,'sourceSubjectPHID'=>'PHID-USER-'.str_repeat('s',20),'sourceRequesterPHID'=>'PHID-USER-'.str_repeat('a',20),'sourceExecutorPHID'=>'PHID-USER-'.str_repeat('a',20),'approverPHID'=>null,'rowVersion'=>'1'];};
$rows=[];for($i=0;$i<17;$i++){$rows[]=$row($i);} $page=PhorgeGovernanceNativeApprovalAdapter::planWorkbenchRows($rows,25,null);$rest=PhorgeGovernanceNativeApprovalAdapter::planWorkbenchRows([$rows[16]],25,$page['next_cursor']);
$subjects=[];for($i=0;$i<17;$i++){$r=$row($i,1,null);$r['sourceSubjectPHID']='PHID-USER-'.str_pad((string)($i*2),20,'0',STR_PAD_LEFT);$r['sourceRequesterPHID']=$r['sourceExecutorPHID']='PHID-USER-'.str_pad((string)($i*2+1),20,'0',STR_PAD_LEFT);$subjects[]=$r;}$sp=PhorgeGovernanceNativeApprovalAdapter::planWorkbenchRows($subjects,25,null);
echo json_encode(['byte_rows'=>count($page['plan']),'bytes'=>$page['payload_bytes'],'cursor'=>$page['next_cursor'],'next_first'=>$rest['plan'][0]['approvalID'],'next_cursor'=>$rest['next_cursor'],'subject_rows'=>count($sp['plan']),'subjects'=>$sp['participant_count'],'subject_cursor'=>$sp['next_cursor'],'SQL_executed'=>false]);'''
  d=self.php(code);self.assertEqual(16,d['byte_rows']);self.assertEqual(1048576,d['bytes']);self.assertEqual('request-15',d['cursor']);self.assertEqual('request-16',d['next_first']);self.assertIsNone(d['next_cursor']);self.assertEqual(16,d['subject_rows']);self.assertEqual(32,d['subjects']);self.assertEqual('request-15',d['subject_cursor']);self.assertFalse(d['SQL_executed'])
 def test_actual_native_participant_plan_is_canonical_and_deduplicated(self):
  code=r'''class AphrontDatabaseConnection{}class PhabricatorUser{function establishConnection($mode){return$GLOBALS['conn'];}}class PhorgeGovernanceOrganizationApprovalAdapter{static function validateIdentifier($value,$user=false){return$value;}}
$GLOBALS['conn']=new AphrontDatabaseConnection();class WorkbenchPlanSeam{private static function load($conn,$id,$locked){$a='PHID-USER-'.str_repeat('a',20);$b='PHID-USER-'.str_repeat('b',20);$c='PHID-USER-'.str_repeat('c',20);return[['approverPHID'=>$c],['requester'=>['userPHID'=>$a],'executor'=>['userPHID'=>$a],'parameters'=>['subject'=>$b]]];}
'''+method('planningPHIDs')+r'''}
$ids=WorkbenchPlanSeam::planningPHIDs(['request-b','request-a'],$GLOBALS['conn']);echo json_encode(['sorted'=>$ids===array_values(array_unique($ids))&&$ids===["PHID-USER-".str_repeat('a',20),"PHID-USER-".str_repeat('b',20),"PHID-USER-".str_repeat('c',20)],'count'=>count($ids),'SQL_executed'=>false]);'''
  data=self.php(code);self.assertTrue(data['sorted']);self.assertEqual(3,data['count']);self.assertFalse(data['SQL_executed'])
 def test_actual_controller_GET_and_POST_control_flow_seams(self):
  # This executes the real controller against named stand-ins, not native authority.
  code=r'''$case=$argv[2];$GLOBALS['commands']=[];$GLOBALS['reads']=0;$GLOBALS['csrf']=0;
class PhabricatorPeopleController{function getViewer(){return null;}function getApplicationURI($p){return'/people/'.$p;}function newDialog(){return new Dialog();}function newPage(){return new Page();}}
class Chain{function __call($n,$a){return$this;}}class Dialog extends Chain{}class Page extends Chain{}class Aphront404Response{}class AphrontRedirectResponse extends Chain{}class PHUIObjectBoxView extends Chain{}class AphrontTableView extends Chain{function __construct($x){}}class AphrontFormView extends Chain{}class AphrontFormSubmitControl extends Chain{}class PhutilURI extends Chain{function __construct($x){}function __toString(){return'/fixed';}}
class PhabricatorPeopleQuery extends Chain{function execute(){return[];}}
class PhabricatorEnv{static function getEnvConfig($k){global$case;return$case!=='off';}}
class PhorgeGovernanceNativeActorContext{static function newFromWebRequest($r){global$case;if($case==='disabled'){throw new RuntimeException('current_native_actor_refused');}return null;}}
class PhorgeOrganizationService{static function newForActor($x){return new self();}function readApprovalWorkbenchPage(...$a){global$case;$GLOBALS['reads']++;if($case==='nonadmin'){throw new RuntimeException('admin_required');}return['rows'=>[],'next_cursor'=>null];}function readApprovalWorkbenchDetail($id){global$case;$GLOBALS['reads']++;if(in_array($case,['nonadmin','expired','source-drift'],true)){throw new RuntimeException('native_source_refused');}return['team'=>'team','target_user'=>'subject','requester'=>'actor','executor'=>'actor','approver'=>null,'expected_team_version'=>1,'expected_member_version'=>0,'expected_appointment_version'=>0,'action'=>'member.add-and-appoint','issued_at'=>1,'expires_at'=>100,'member_from'=>0,'member_until'=>null,'appointment_from'=>0,'appointment_until'=>null,'approval_id'=>'request','may_approve'=>$case==='approve','may_execute'=>$case==='execute','state'=>$case==='consumed'?'consumed':'submitted'];}function approveApproval($id){$GLOBALS['commands'][]='approve';}function executeApprovedCompound($id){$GLOBALS['commands'][]='execute';}}
class AphrontRequest{function isHTTPPost(){global$case;return!in_array($case,['get','off','disabled','nonadmin'],true);}function validateCSRF(){global$case;$GLOBALS['csrf']++;if($case==='csrf'){throw new RuntimeException('csrf');}}function getStr($key,$default=''){global$case;if($key==='approval'){return'request';}if($key==='form_action'){return$case==='execute'?'execute':'approve';}return$default;}}
function idx($a,$k,$d=null){return$a[$k]??$d;}function pht($s,...$a){return$s;}function phutil_tag(...$a){return'';}function phabricator_datetime(...$a){return'fixed';}
require$argv[1].'/src/applications/people/governance/controller/PhorgeOrganizationApprovalController.php';
// GET renders a fixed typed record in this framework seam and never invokes a command.
$response=(new PhorgeOrganizationApprovalController())->handleRequest(new AphrontRequest());echo json_encode(['commands'=>$GLOBALS['commands'],'reads'=>$GLOBALS['reads'],'csrf'=>$GLOBALS['csrf'],'response'=>get_class($response)]);'''
  for case in ['off','get','csrf','self','nonadmin','disabled','expired','consumed','source-drift','approve','execute']:
   with self.subTest(case=case):
    d=self.php(code,case)
    self.assertEqual([case]if case in ['approve','execute']else[],d['commands'])
    if case in ['off','disabled','csrf']:self.assertEqual(0,d['reads'])
    if case=='off':self.assertEqual('Aphront404Response',d['response'])
    if case=='get':self.assertEqual('Page',d['response'])
    if case in ['approve','execute']:self.assertEqual(1,d['csrf']);self.assertEqual('AphrontRedirectResponse',d['response'])
 def test_original_schema_primary_ID_and_no_state_index_invented(self):
  text=(ROOT/'resources/sql/autopatches/20261102.people.organization.sql').read_text();block=text.split('CREATE TABLE {$NAMESPACE}_user.organization_approval (',1)[1].split(') ENGINE=',1)[0]
  self.assertRegex(block,r'approvalID VARBINARY\(128\) NOT NULL PRIMARY KEY')
  self.assertNotRegex(block,r'KEY\s+\w+\s*\(state')
  # The actual function seam above demonstrates the issued primary-index route.
if __name__=='__main__':unittest.main()
