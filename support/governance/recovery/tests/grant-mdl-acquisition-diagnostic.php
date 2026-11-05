<?php
require_once dirname(__DIR__).'/organization-grant-mdl-cutpoint-diagnostic.php';
require_once dirname(__DIR__).'/organization-grant-mdl-acquisition-diagnostic.php';
function assert_acquisition($ok){if($ok!==true){throw new RuntimeException('host_control_failed');}}
function refuses_acquisition(callable$f){try{$f();}catch(RuntimeException$e){return true;}return false;}
$ticket=array('OBJECT_INSTANCE_BEGIN'=>'9001','LOCK_TYPE'=>'SHARED_WRITE','LOCK_DURATION'=>'TRANSACTION','LOCK_STATUS'=>'GRANTED','OWNER_THREAD_ID'=>'1001','OWNER_EVENT_ID'=>'20','PROCESSLIST_ID'=>'101');
$tx=array('THREAD_ID'=>'1001','EVENT_ID'=>'2','STATE'=>'ACTIVE','END_EVENT_ID'=>null);
$physical=array('TRX_ID'=>'3001','THREAD_ID'=>'1001','PROCESSLIST_ID'=>'101');
$identity=governance_grant_acquisition_identity(array($ticket),array($tx),array($physical),'101');
assert_acquisition($identity['ticket']===$ticket&&$identity['physical']===$physical);
foreach(array('OWNER_THREAD_ID'=>'1002','PROCESSLIST_ID'=>'102','LOCK_DURATION'=>'STATEMENT')as$key=>$value){$bad=$ticket;$bad[$key]=$value;assert_acquisition(refuses_acquisition(fn()=>governance_grant_acquisition_identity(array($bad),array($tx),array($physical),'101')));}
$bad=$physical;$bad['THREAD_ID']='1002';assert_acquisition(refuses_acquisition(fn()=>governance_grant_acquisition_identity(array($ticket),array($tx),array($bad),'101')));
$bad=$tx;$bad['STATE']='COMMITTED';assert_acquisition(refuses_acquisition(fn()=>governance_grant_acquisition_identity(array($ticket),array($bad),array($physical),'101')));
assert_acquisition(refuses_acquisition(fn()=>governance_grant_acquisition_identity(array($ticket,$ticket),array($tx),array($physical),'101')));
assert_acquisition(refuses_acquisition(fn()=>governance_grant_acquisition_identity(array($ticket),array($tx),array($physical,$physical),'101')));
$public=governance_grant_acquisition_unavailable();$public['unavailable']=false;$public['before_after_identity_stable']=true;$public['unique_ticket']=true;$public['active_event']=true;$public['unique_physical_transaction']=true;
$capture=array('private_identity'=>$identity,'public'=>$public);$p=governance_grant_acquisition_project($capture,$identity);
assert_acquisition($p['late_identity_stable']===true&&$p['statement_interval_count']===0&&$p['complete_nesting_or_blocker_proved']===false);
$changed=$identity;$changed['physical']['TRX_ID']='3002';assert_acquisition(governance_grant_acquisition_project($capture,$changed)['late_identity_stable']===false);
$bad=$capture;$bad['public']['statement_interval_count']=true;assert_acquisition(governance_grant_acquisition_project($bad,$identity)['unavailable']===true);
$bad=$capture;$bad['public']['private-sentinel']='secret';assert_acquisition(governance_grant_acquisition_project($bad,$identity)['unavailable']===true);
$bad=$capture;$bad['public']['complete_nesting_or_blocker_proved']=true;assert_acquisition(governance_grant_acquisition_project($bad,$identity)['unavailable']===true);
assert_acquisition(!str_contains(json_encode($p),'9001')&&!str_contains(json_encode($p),'1001')&&!str_contains(json_encode($p),'3001'));
echo "13 acquisition host controls passed\n";
