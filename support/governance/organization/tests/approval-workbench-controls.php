<?php
/** Actual value/schema/route functions only; no DB, HTTP server or browser proof. */
$root=$argv[1];$GLOBALS['workbench_paths']=array();
function phutil_register_library_map($map){foreach($map['class']as$class=>$path){$GLOBALS['workbench_paths'][$class]=$GLOBALS['workbench_base'].'/'.$path;}}
foreach(array('/support/runtime/src','/src')as$base){$GLOBALS['workbench_base']=$root.$base;require$root.$base.'/__phutil_library_map__.php';}
spl_autoload_register(function($class){if(isset($GLOBALS['workbench_paths'][$class])){require_once$GLOBALS['workbench_paths'][$class];}});
require$root.'/support/runtime/src/utils/utils.php';
function wb_check($value){if($value!==true){throw new RuntimeException('workbench_native_control_failed');}}
function wb_refuse(callable$call){$caught=null;try{$call();}catch(Throwable$error){$caught=$error;}wb_check($caught!==null);}
$checks=array();
$plan=static fn($rows,$limit=1,$after=null)=>PhorgeGovernanceNativeApprovalAdapter::planWorkbenchRows($rows,$limit,$after);
$row=static fn($id,$n=7,$a=null)=>array('approvalID'=>$id,'bindingBytes'=>$n,'approverBytes'=>$a,'sourceParticipantVersion'=>'1','sourceSubjectPHID'=>'PHID-USER-'.str_repeat('s',20),'sourceRequesterPHID'=>'PHID-USER-'.str_repeat('a',20),'sourceExecutorPHID'=>'PHID-USER-'.str_repeat('a',20),'approverPHID'=>null,'rowVersion'=>'1');
wb_check($plan(array())===array('plan'=>array(),'next_cursor'=>null,'payload_bytes'=>0,'participant_count'=>0));
$page=$plan(array($row('A:1','7'),$row('a:2',8)),1);wb_check($page['next_cursor']==='A:1'&&count($page['plan'])===1);
wb_check($plan(array($row('a:2',8)),1,'A:1')['next_cursor']===null);$checks[]='opaque-binary-ID-pagination-not-time-sorted';
foreach(array(0,26,true,'1',null)as$limit){wb_refuse(static fn()=>$plan(array(),$limit));}
foreach(array('','bad/route',str_repeat('x',97),true)as$cursor){wb_refuse(static fn()=>$plan(array(),1,$cursor));}
foreach(array(0,'0','01',32769,'32769',null,true,1.0)as$length){wb_refuse(static fn()=>$plan(array($row('a',$length))));}
foreach(array(0,'0','01',32769,'32769',true,1.0)as$length){wb_refuse(static fn()=>$plan(array($row('a',1,$length))));}
wb_check($plan(array($row('a','32768','32768')))['plan'][0]['bindingBytes']===32768);$checks[]='limits-and-both-payload-lengths-strict-before-load';
foreach(array(array($row('b'),$row('a')),array($row('a'),$row('a')),array($row('a'),$row('b'),$row('c')))as$rows){wb_refuse(static fn()=>$plan($rows));}
wb_refuse(static fn()=>$plan(array($row('a')),1,'b'));$checks[]='duplicates-and-nonprogress-cursors-refused';
$GLOBALS['workbench_magic_invoked']=false;
class WorkbenchMagicSentinel{public function __toString(){$GLOBALS['workbench_magic_invoked']=true;throw new LogicException('callback-must-not-run');}public function __serialize(){$GLOBALS['workbench_magic_invoked']=true;throw new LogicException('callback-must-not-run');}}
foreach(array(array_replace($row('a'),array('approvalID'=>new WorkbenchMagicSentinel())),$row('a',new WorkbenchMagicSentinel()))as$bad){$error=null;try{$plan(array($bad));}catch(Throwable$caught){$error=$caught;}wb_check($error!==null&&$GLOBALS['workbench_magic_invoked']===false);}$checks[]='untrusted-values-never-invoke-magic';
$intent=array('action'=>'member.add-and-appoint','approval_id'=>'request-a','operation_id'=>'operation-a','team'=>'team-a','target_user'=>'PHID-USER-'.str_repeat('s',20),'expected_team_version'=>1,'expected_member_version'=>0,'expected_appointment_version'=>0,'impact_acknowledged'=>true,'member_from'=>0,'member_until'=>null,'appointment_from'=>0,'appointment_until'=>null);
$data=array('action'=>$intent['action'],'parameters'=>array('subject'=>$intent['target_user'],'valid_from'=>0,'valid_until'=>null,'compound'=>$intent),'team'=>'team-a','issuedAt'=>1,'expiresAt'=>100,'requester'=>array('userPHID'=>'PHID-USER-'.str_repeat('a',20),'secret'=>'SYNTHETIC_PRIVATE_SENTINEL'),'executor'=>array('userPHID'=>'PHID-USER-'.str_repeat('a',20)));
$record=array('approvalID'=>'request-a','operationID'=>'operation-a','state'=>'submitted','version'=>'1','approverPHID'=>null);
$adapter=(new ReflectionClass(PhorgeGovernanceNativeApprovalAdapter::class))->newInstanceWithoutConstructor();$method=new ReflectionMethod(PhorgeGovernanceNativeApprovalAdapter::class,'workbenchProjection');$view=$method->invoke($adapter,$record,$data);
wb_check($view['may_approve']===false&&$view['may_execute']===false&&$view['UI_hints_only']===true&&strpos(json_encode($view),'SYNTHETIC_PRIVATE_SENTINEL')===false&&!array_key_exists('sources',$view)&&!array_key_exists('bindingJSON',$view));
$record['operationID']='different';wb_refuse(static fn()=>$method->invoke($adapter,$record,$data));$record['operationID']='operation-a';$unknown=$data;$unknown['action']='unknown-future-action';wb_refuse(static fn()=>$method->invoke($adapter,$record,$unknown));$checks[]='projection-is-whitelisted-and-never-an-authority-token';
$routes=(new PhabricatorPeopleApplication())->getRoutes();wb_check($routes['/people/']['organization/approvals/']==='PhorgeOrganizationApprovalController');$checks[]='actual-native-People-route-registration';
echo json_encode(array('passed'=>true,'checks'=>$checks,'SQL_executed'=>false,'HTTP_server_executed'=>false,'browser_executed'=>false));
