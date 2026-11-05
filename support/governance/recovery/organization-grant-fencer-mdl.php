<?php

/** Fixed MySQL 8.4.13 SRO granted-ticket compatibility, not a generic graph. */
function governance_recovery_grant_mdl_proof(array $tickets,array $transaction,array $statement,$fencerID,$revokerID) {
  $uint=static function($v){return is_string($v)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$v);};
  if(!$uint($fencerID)||!$uint($revokerID)||$fencerID===$revokerID||count($tickets)>64||
    array_keys($transaction)!==array('THREAD_ID','EVENT_ID','STATE','END_EVENT_ID')||
    !$uint($transaction['THREAD_ID'])||!$uint($transaction['EVENT_ID'])||
    $transaction['STATE']!=='ACTIVE'||$transaction['END_EVENT_ID']!==null||
    array_keys($statement)!==array('THREAD_ID','EVENT_ID','NESTING_EVENT_ID','NESTING_EVENT_TYPE')||
    $statement['THREAD_ID']!==$transaction['THREAD_ID']||
    $statement['NESTING_EVENT_ID']!==$transaction['EVENT_ID']||$statement['NESTING_EVENT_TYPE']!=='TRANSACTION'){
    throw new RuntimeException('recovery_grant_mdl_identity_unknown');}
  $compatible=array('SHARED','SHARED_HIGH_PRIO','SHARED_READ','SHARED_UPGRADABLE','SHARED_READ_ONLY','SHARED_NO_WRITE');
  $incompatible=array('SHARED_WRITE','SHARED_WRITE_LOW_PRIO','SHARED_NO_READ_WRITE','EXCLUSIVE');
  $pending=array();$blockers=array();$seen=array();
  foreach($tickets as$row){
    if(array_keys($row)!==array('OBJECT_INSTANCE_BEGIN','LOCK_TYPE','LOCK_DURATION','LOCK_STATUS','OWNER_THREAD_ID','OWNER_EVENT_ID','PROCESSLIST_ID')||
      !$uint($row['OBJECT_INSTANCE_BEGIN'])||!$uint($row['OWNER_THREAD_ID'])||!$uint($row['OWNER_EVENT_ID'])||
      !$uint($row['PROCESSLIST_ID'])||isset($seen[$row['OBJECT_INSTANCE_BEGIN']])||
      !in_array($row['LOCK_TYPE'],array_merge($compatible,$incompatible),true)){
      throw new RuntimeException('recovery_grant_mdl_ticket_unknown');}
    $seen[$row['OBJECT_INSTANCE_BEGIN']]=true;
    if($row['LOCK_STATUS']==='PENDING'){$pending[]=$row;}
    elseif($row['LOCK_STATUS']==='GRANTED'){
      if(in_array($row['LOCK_TYPE'],$incompatible,true)){$blockers[]=$row;}
    }else{throw new RuntimeException('recovery_grant_mdl_ticket_unknown');}
  }
  if(count($pending)!==1||count($blockers)!==1||$pending[0]['PROCESSLIST_ID']!==$revokerID||
    $pending[0]['LOCK_TYPE']!=='SHARED_READ_ONLY'||$blockers[0]['PROCESSLIST_ID']!==$fencerID||
    $blockers[0]['LOCK_TYPE']!=='SHARED_WRITE'||$blockers[0]['LOCK_DURATION']!=='TRANSACTION'||
    $blockers[0]['OWNER_THREAD_ID']!==$transaction['THREAD_ID']||
    $blockers[0]['OWNER_EVENT_ID']!==$statement['EVENT_ID']){
    throw new RuntimeException('recovery_grant_mdl_unique_blocker_unproved');}
  return array('holder'=>$blockers[0],'request'=>$pending[0],'transaction'=>$transaction,'statement'=>$statement);
}

function governance_recovery_grant_mdl_rows(mysqli $control,$sql) {
  $result=$control->query($sql);if(!$result){throw new RuntimeException('recovery_grant_mdl_observation_failed');}
  try{return$result->fetch_all(MYSQLI_ASSOC);}finally{$result->free();}
}
function governance_recovery_grant_mdl_tickets(mysqli $control) {
  return governance_recovery_grant_mdl_rows($control,"SELECT CAST(m.OBJECT_INSTANCE_BEGIN AS CHAR) OBJECT_INSTANCE_BEGIN,m.LOCK_TYPE,m.LOCK_DURATION,m.LOCK_STATUS,CAST(m.OWNER_THREAD_ID AS CHAR) OWNER_THREAD_ID,CAST(m.OWNER_EVENT_ID AS CHAR) OWNER_EVENT_ID,CAST(t.PROCESSLIST_ID AS CHAR) PROCESSLIST_ID FROM performance_schema.metadata_locks m LEFT JOIN performance_schema.threads t ON t.THREAD_ID=m.OWNER_THREAD_ID WHERE m.OBJECT_TYPE='TABLE' AND m.OBJECT_SCHEMA='mysql' AND m.OBJECT_NAME='user' ORDER BY m.OBJECT_INSTANCE_BEGIN LIMIT 65");
}
/** Live observer; failure/absence is unknown, never a successful MDL proof. */
function governance_recovery_grant_mdl_observe(mysqli $control,mysqli $fencer,mysqli $revoker) {
  $enabled=governance_recovery_grant_mdl_rows($control,"SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl'");
  if(count($enabled)!==1||$enabled[0]['ENABLED']!=='YES'){
    throw new RuntimeException('recovery_grant_mdl_instrumentation_unknown');}
  $tickets=governance_recovery_grant_mdl_tickets($control);
  $transactions=governance_recovery_grant_mdl_rows($control,"SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,e.END_EVENT_ID FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=".(int)$fencer->thread_id);
  if(count($transactions)!==1){throw new RuntimeException('recovery_grant_mdl_transaction_unknown');}
  $tx=$transactions[0];$holder=null;
  foreach($tickets as$row){if($row['PROCESSLIST_ID']===(string)$fencer->thread_id&&$row['LOCK_STATUS']==='GRANTED'&&$row['LOCK_TYPE']==='SHARED_WRITE'){
    if($holder!==null){throw new RuntimeException('recovery_grant_mdl_holder_unknown');}$holder=$row;}}
  if($holder===null||!ctype_digit($holder['OWNER_THREAD_ID']??'')||!ctype_digit($holder['OWNER_EVENT_ID']??'')){
    throw new RuntimeException('recovery_grant_mdl_holder_unknown');}
  $where=' WHERE THREAD_ID='.$holder['OWNER_THREAD_ID'].' AND EVENT_ID='.$holder['OWNER_EVENT_ID'];
  $columns='CAST(THREAD_ID AS CHAR) THREAD_ID,CAST(EVENT_ID AS CHAR) EVENT_ID,CAST(NESTING_EVENT_ID AS CHAR) NESTING_EVENT_ID,NESTING_EVENT_TYPE';
  $statements=governance_recovery_grant_mdl_rows($control,'SELECT '.$columns.' FROM performance_schema.events_statements_current'.$where.' UNION SELECT '.$columns.' FROM performance_schema.events_statements_history'.$where);
  if(count($statements)!==1){throw new RuntimeException('recovery_grant_mdl_statement_unknown');}
  $proof=governance_recovery_grant_mdl_proof($tickets,$tx,$statements[0],(string)$fencer->thread_id,(string)$revoker->thread_id);
  $requests=governance_recovery_grant_mdl_rows($control,'SELECT CAST(s.THREAD_ID AS CHAR) tid,CAST(s.EVENT_ID AS CHAR) eid,s.EVENT_NAME FROM performance_schema.events_statements_current s WHERE s.THREAD_ID='.$proof['request']['OWNER_THREAD_ID'].' AND s.EVENT_ID='.$proof['request']['OWNER_EVENT_ID']);
  if(count($requests)!==1||$requests[0]['EVENT_NAME']!=='statement/sql/revoke'){
    throw new RuntimeException('recovery_grant_mdl_request_event_unknown');}
  $read=array($revoker);$errors=$reject=array();$poll=mysqli_poll($read,$errors,$reject,0,0);
  if($poll!==0||$read||$errors||$reject){throw new RuntimeException('recovery_grant_mdl_revoke_not_pending');}
  return$proof;
}

function governance_recovery_grant_mdl_released(mysqli $control,mysqli $fencer,array $proof) {
  $tickets=governance_recovery_grant_mdl_tickets($control);
  if(count($tickets)>64){throw new RuntimeException('recovery_grant_mdl_release_unknown');}
  foreach($tickets as$row){if($row['OBJECT_INSTANCE_BEGIN']===$proof['holder']['OBJECT_INSTANCE_BEGIN']||
    $row['PROCESSLIST_ID']===(string)$fencer->thread_id){return false;}}
  $tx=governance_recovery_grant_mdl_rows($control,'SELECT STATE FROM performance_schema.events_transactions_current WHERE THREAD_ID='.$proof['transaction']['THREAD_ID'].' AND EVENT_ID='.$proof['transaction']['EVENT_ID']);
  return count($tx)===0||(count($tx)===1&&in_array($tx[0]['STATE'],array('COMMITTED','ROLLED BACK'),true));
}

/** Failure-only reason enum. Never return native exception text. */
function governance_recovery_grant_mdl_rejection(Throwable $error) {
  $known=array('recovery_grant_mdl_identity_unknown'=>'identity-unknown',
    'recovery_grant_mdl_ticket_unknown'=>'ticket-unknown',
    'recovery_grant_mdl_unique_blocker_unproved'=>'unique-blocker-unproved',
    'recovery_grant_mdl_observation_failed'=>'observation-failed',
    'recovery_grant_mdl_instrumentation_unknown'=>'instrumentation-unknown',
    'recovery_grant_mdl_transaction_unknown'=>'transaction-unknown',
    'recovery_grant_mdl_holder_unknown'=>'holder-unknown',
    'recovery_grant_mdl_statement_unknown'=>'statement-unknown',
    'recovery_grant_mdl_request_event_unknown'=>'request-event-unknown',
    'recovery_grant_mdl_revoke_not_pending'=>'poll-not-pending');
  return get_class($error)===RuntimeException::class?($known[$error->getMessage()]??'unknown'):'unknown';
}
