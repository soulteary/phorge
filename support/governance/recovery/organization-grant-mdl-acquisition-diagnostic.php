<?php
/** Isolated failure diagnostics only. Private identities never enter a receipt. */
function governance_grant_acquisition_identity(array$tickets,array$transactions,array$physical,$process){
  if(!governance_grant_cutpoint_uint($process)||$process==='0'||count($tickets)>64){throw new RuntimeException('grant_acquisition_unknown');}
  $holders=array();
  foreach($tickets as$row){
    $keys=array_keys($row);sort($keys);
    if($keys!==array('LOCK_DURATION','LOCK_STATUS','LOCK_TYPE','OBJECT_INSTANCE_BEGIN','OWNER_EVENT_ID','OWNER_THREAD_ID','PROCESSLIST_ID')){throw new RuntimeException('grant_acquisition_unknown');}
    foreach(array('OBJECT_INSTANCE_BEGIN','OWNER_EVENT_ID','OWNER_THREAD_ID','PROCESSLIST_ID')as$key){if(!governance_grant_cutpoint_uint($row[$key])||$row[$key]==='0'){throw new RuntimeException('grant_acquisition_unknown');}}
    if($row['PROCESSLIST_ID']===$process&&$row['LOCK_STATUS']==='GRANTED'&&$row['LOCK_TYPE']==='SHARED_WRITE'&&$row['LOCK_DURATION']==='TRANSACTION'){$holders[]=$row;}
  }
  if(count($holders)!==1||count($transactions)!==1||count($physical)!==1){throw new RuntimeException('grant_acquisition_unknown');}
  $ticket=$holders[0];$tx=$transactions[0];$phy=$physical[0];$keys=array_keys($tx);sort($keys);
  if($keys!==array('END_EVENT_ID','EVENT_ID','STATE','THREAD_ID')||$tx['THREAD_ID']!==$ticket['OWNER_THREAD_ID']||!governance_grant_cutpoint_uint($tx['EVENT_ID'])||$tx['EVENT_ID']==='0'||$tx['STATE']!=='ACTIVE'||$tx['END_EVENT_ID']!==null){throw new RuntimeException('grant_acquisition_unknown');}
  $keys=array_keys($phy);sort($keys);
  if($keys!==array('PROCESSLIST_ID','THREAD_ID','TRX_ID')||$phy['PROCESSLIST_ID']!==$process||$phy['THREAD_ID']!==$tx['THREAD_ID']||!governance_grant_cutpoint_uint($phy['TRX_ID'])||$phy['TRX_ID']==='0'){throw new RuntimeException('grant_acquisition_unknown');}
  return array('ticket'=>$ticket,'transaction'=>$tx,'physical'=>$phy);
}
function governance_grant_acquisition_read(mysqli$control,mysqli$fencer){
  $process=(string)$fencer->thread_id;
  $tickets=governance_recovery_grant_mdl_tickets($control);
  $tx=governance_recovery_grant_mdl_rows($control,'SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,CAST(e.END_EVENT_ID AS CHAR) END_EVENT_ID FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID='.(int)$fencer->thread_id.' LIMIT 2');
  $physical=governance_recovery_grant_mdl_rows($control,"SELECT DISTINCT CAST(b.TRX_ID AS CHAR) TRX_ID,CAST(l.THREAD_ID AS CHAR) THREAD_ID,CAST(b.TRX_MYSQL_THREAD_ID AS CHAR) PROCESSLIST_ID FROM performance_schema.data_locks l JOIN information_schema.INNODB_TRX b ON b.TRX_ID=CAST(l.ENGINE_TRANSACTION_ID AS CHAR) WHERE l.ENGINE='INNODB' AND b.TRX_MYSQL_THREAD_ID=".(int)$fencer->thread_id." AND l.OBJECT_SCHEMA='mysql' AND l.OBJECT_NAME='user' AND l.INDEX_NAME='PRIMARY' AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='GRANTED' AND l.LOCK_MODE IN ('X','X,REC_NOT_GAP') LIMIT 2");
  return governance_grant_acquisition_identity($tickets,$tx,$physical,$process);
}
function governance_grant_acquisition_unavailable(){
  return array('diagnostic_only'=>true,'unavailable'=>true,'before_after_identity_stable'=>false,
    'unique_ticket'=>false,'active_event'=>false,'unique_physical_transaction'=>false,
    'statement_exact_count'=>0,'statement_interval_count'=>0,'statement_parent_active_transaction_count'=>0,
    'duplicate_event_versions'=>false,'rows_truncated'=>false,'late_identity_stable'=>false,
    'statement_counter_boundary_count'=>0,'statement_extended_interval_count'=>0,
    'statement_boundary_parent_active_transaction_count'=>0,'statement_select_extended_count'=>0,
    'statement_boundary_overlap_with_next_start'=>false,'statement_extended_interval_ambiguous'=>false,
    'complete_nesting_or_blocker_proved'=>false);
}
function governance_grant_acquisition_capture($fixture,mysqli$control,mysqli$fencer){
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||(getenv('PHORGE_GOVERNANCE_GRANT_MDL_DIAGNOSTIC')!=='1'&&getenv('PHORGE_GOVERNANCE_GRANT_MDL_V3')!=='1')||get_class($fixture)!==PhorgeGovernanceCompletionFixture::class){throw new RuntimeException('grant_acquisition_test_profile_required');}
  $fixture->assertOwned();$before=governance_grant_acquisition_read($control,$fencer);
  $thread=$before['ticket']['OWNER_THREAD_ID'];$point=$before['ticket']['OWNER_EVENT_ID'];$families=array('statement'=>array(),'stage'=>array(),'wait'=>array());$truncated=false;$storageCounts=array();
  $lower=governance_grant_cutpoint_predecessor($point);
  foreach(array('current','history')as$storage){
    $rows=governance_recovery_grant_mdl_rows($control,'SELECT CAST(THREAD_ID AS CHAR) THREAD_ID,CAST(EVENT_ID AS CHAR) EVENT_ID,CAST(END_EVENT_ID AS CHAR) END_EVENT_ID,EVENT_NAME,CAST(NESTING_EVENT_ID AS CHAR) NESTING_EVENT_ID,NESTING_EVENT_TYPE FROM performance_schema.events_statements_'.$storage.' WHERE THREAD_ID='.$thread.' AND (EVENT_ID='.$point.' OR (EVENT_ID<'.$point.' AND (END_EVENT_ID>='.$lower.' OR END_EVENT_ID IS NULL))) ORDER BY EVENT_ID DESC LIMIT 17');
    $storageCounts[$storage]=count($rows);if(count($rows)>16){$truncated=true;}$families['statement']=array_merge($families['statement'],$rows);
  }
  $counts=governance_grant_cutpoint_summary($families,$point,array($before['transaction']),$thread);
  $after=governance_grant_acquisition_read($control,$fencer);$out=governance_grant_acquisition_unavailable();
  $out['unavailable']=false;$out['before_after_identity_stable']=$before===$after;
  $out['unique_ticket']=true;$out['active_event']=true;$out['unique_physical_transaction']=true;
  $out['statement_exact_count']=$counts['exact_matches']['statement'];$out['statement_interval_count']=$counts['interval_matches']['statement'];
  $out['statement_parent_active_transaction_count']=$counts['statement_parent_active_transaction'];
  $out['duplicate_event_versions']=$counts['duplicate_event_versions'];$out['rows_truncated']=$truncated;
  foreach(array('statement_counter_boundary_count','statement_extended_interval_count','statement_boundary_parent_active_transaction_count','statement_select_extended_count','statement_boundary_overlap_with_next_start','statement_extended_interval_ambiguous')as$key){$out[$key]=$counts[$key];}
  return array('private_identity'=>$before,'private_after_identity'=>$after,'private_statement_rows'=>$families['statement'],'private_statement_counts'=>$storageCounts,'public'=>$out);
}
/** Copy only a fixed typed projection, never arbitrary captured fields. */
function governance_grant_acquisition_project(array$capture,$late){
  $out=governance_grant_acquisition_unavailable();
  if(!isset($capture['public'])||!is_array($capture['public'])){return$out;}
  $keys=array_keys($capture['public']);$expected=array_keys($out);sort($keys);sort($expected);
  if($keys!==$expected){return$out;}
  foreach($out as$key=>$value){
    $actual=$capture['public'][$key];
    if(is_bool($value)?!is_bool($actual):(!is_int($actual)||$actual<0||$actual>34)){return governance_grant_acquisition_unavailable();}
    $out[$key]=$actual;
  }
  if($out['diagnostic_only']!==true||$out['complete_nesting_or_blocker_proved']!==false){return governance_grant_acquisition_unavailable();}
  $out['late_identity_stable']=isset($capture['private_identity'])&&is_array($late)&&$capture['private_identity']===$late;
  return$out;
}
