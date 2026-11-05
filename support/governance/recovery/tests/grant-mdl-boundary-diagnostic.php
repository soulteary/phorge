<?php
require_once dirname(__DIR__).'/organization-grant-mdl-cutpoint-diagnostic.php';
function boundary_assert($ok){if($ok!==true){throw new RuntimeException('boundary_host_control_failed');}}
function boundary_row($start,$end){return array('THREAD_ID'=>'1001','EVENT_ID'=>$start,'END_EVENT_ID'=>$end,'NESTING_EVENT_ID'=>'2','NESTING_EVENT_TYPE'=>'TRANSACTION','EVENT_NAME'=>'statement/sql/select');}
function boundary_summary($rows,$point){return governance_grant_cutpoint_summary(array('statement'=>$rows,'stage'=>array(),'wait'=>array()),$point,array(array('THREAD_ID'=>'1001','EVENT_ID'=>'2','END_EVENT_ID'=>null,'STATE'=>'ACTIVE')),'1001');}
// Internal end counter 20 is exposed by MySQL as SQL END_EVENT_ID 19.
$s=boundary_summary(array(boundary_row('10','19')),'20');
boundary_assert($s['exact_matches']['statement']===0&&$s['interval_matches']['statement']===0&&$s['statement_counter_boundary_count']===1&&$s['statement_extended_interval_count']===1&&$s['statement_boundary_parent_active_transaction_count']===1&&$s['statement_select_extended_count']===1&&$s['statement_extended_interval_ambiguous']===false&&$s['complete_nesting_or_blocker_proved']===false);
$s=boundary_summary(array(boundary_row('10','19'),boundary_row('20',null)),'20');
boundary_assert($s['statement_boundary_overlap_with_next_start']===true&&$s['statement_extended_interval_ambiguous']===true&&$s['exact_matches']['statement']===1);
$s=boundary_summary(array(boundary_row('10','19')),'19');boundary_assert($s['interval_matches']['statement']===1&&$s['statement_counter_boundary_count']===0);
$s=boundary_summary(array(boundary_row('10','19')),'21');boundary_assert($s['statement_extended_interval_count']===0);
$s=boundary_summary(array(boundary_row('10',null)),'20');boundary_assert($s['statement_counter_boundary_count']===0&&$s['statement_extended_interval_count']===1);
$s=boundary_summary(array(boundary_row('10','19'),boundary_row('11','19')),'20');boundary_assert($s['statement_extended_interval_count']===2&&$s['statement_extended_interval_ambiguous']===true);
$wrong=boundary_row('10','19');$wrong['EVENT_NAME']='statement/sql/revoke';$s=boundary_summary(array($wrong),'20');boundary_assert($s['statement_select_extended_count']===0&&$s['statement_counter_boundary_count']===1);
$wrong=boundary_row('10','19');$wrong['NESTING_EVENT_ID']='3';$s=boundary_summary(array($wrong),'20');boundary_assert($s['statement_boundary_parent_active_transaction_count']===0);
boundary_assert(governance_grant_cutpoint_increment('9223372036854775807')==='9223372036854775808');
boundary_assert(governance_grant_cutpoint_increment('18446744073709551614')==='18446744073709551615'&&governance_grant_cutpoint_increment('18446744073709551615')===null);
boundary_assert(governance_grant_cutpoint_predecessor('18446744073709551615')==='18446744073709551614'&&governance_grant_cutpoint_predecessor('1000')==='999'&&governance_grant_cutpoint_predecessor('1')==='0');
$s=boundary_summary(array(boundary_row('18446744073709551613','18446744073709551614')),'18446744073709551615');boundary_assert($s['statement_counter_boundary_count']===1);
$failed=false;try{governance_grant_cutpoint_increment(true);}catch(RuntimeException$e){$failed=true;}boundary_assert($failed);
echo "13 counter-boundary host controls passed\n";
