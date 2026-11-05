<?php
require_once dirname(__DIR__).'/organization-grant-mdl-cutpoint-diagnostic.php';
function demand($ok){if($ok!==true){throw new RuntimeException('host_control_failed');}}
function row($id,$end,$parent,$type){return array('THREAD_ID'=>'1001','EVENT_ID'=>$id,'END_EVENT_ID'=>$end,'NESTING_EVENT_ID'=>$parent,'NESTING_EVENT_TYPE'=>$type,'EVENT_NAME'=>'private-event-sentinel');}
function rejected(callable$f){try{$f();}catch(RuntimeException$e){return true;}return false;}
$tx=array(array('THREAD_ID'=>'1001','EVENT_ID'=>'2','END_EVENT_ID'=>null,'STATE'=>'ACTIVE'));
$families=array('statement'=>array(row('10','20','2','TRANSACTION')),'stage'=>array(),'wait'=>array());
$s=governance_grant_cutpoint_summary($families,'20',$tx,'1001');
demand($s['exact_matches']['statement']===0&&$s['interval_matches']['statement']===1&&$s['statement_end_equals_cutpoint']===1&&$s['statement_parent_active_transaction']===1&&$s['complete_nesting_or_blocker_proved']===false);
$families['wait']=array(row('20',null,'10','STATEMENT'));
$s=governance_grant_cutpoint_summary($families,'20',$tx,'1001');demand($s['exact_chain_to_active_transaction']===1);
demand(strpos(json_encode($s),'1001')===false&&strpos(json_encode($s),'private-event-sentinel')===false);
$bad=$families;$bad['wait'][0]['THREAD_ID']='1002';demand(rejected(fn()=>governance_grant_cutpoint_summary($bad,'20',$tx,'1001')));
$bad=$families;$bad['wait'][0]['SQL_TEXT']='secret';demand(rejected(fn()=>governance_grant_cutpoint_summary($bad,'20',$tx,'1001')));
$badtx=$tx;$badtx[0]['THREAD_ID']='1002';demand(rejected(fn()=>governance_grant_cutpoint_summary($families,'20',$badtx,'1001')));
demand(rejected(fn()=>governance_grant_cutpoint_summary($families,'20',array_merge($tx,$tx),'1001')));
$missing=$families;$missing['statement']=array();$s=governance_grant_cutpoint_summary($missing,'20',$tx,'1001');demand($s['exact_chain_parent_unavailable']===1&&$s['complete_nesting_or_blocker_proved']===false);
$cycle=$families;$cycle['wait'][0]['NESTING_EVENT_ID']='20';$cycle['wait'][0]['NESTING_EVENT_TYPE']='WAIT';$s=governance_grant_cutpoint_summary($cycle,'20',$tx,'1001');demand($s['exact_chain_cycle_or_depth_limit']===1);
demand(governance_grant_cutpoint_compare('18446744073709551615','9223372036854775808')>0);
demand(governance_grant_cutpoint_uint('18446744073709551616')===false&&governance_grant_cutpoint_uint(true)===false);
echo "11 host controls passed\n";
