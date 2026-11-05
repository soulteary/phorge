<?php
/** Test-only MDL verifier using captured real statement counter intervals. */
function governance_grant_mdl_v3_statement(array$rows,$thread,$point,$eventName,?array$transaction=null){
  if(!governance_grant_cutpoint_uint($thread)||$thread==='0'||!governance_grant_cutpoint_uint($point)||$point==='0'||count($rows)>32||!in_array($eventName,array('statement/sql/select','statement/sql/revoke'),true)){
    throw new RuntimeException('recovery_grant_mdl_v3_statement_unknown');}
  if($eventName==='statement/sql/select'&&($transaction===null||($transaction['STATE']??null)!=='ACTIVE'||($transaction['END_EVENT_ID']??null)!==null)){throw new RuntimeException('recovery_grant_mdl_v3_statement_parent_unknown');}
  $families=array('statement'=>$rows,'stage'=>array(),'wait'=>array());
  // Also validates all exact row shapes, uint64 identifiers and thread identity.
  $summary=governance_grant_cutpoint_summary($families,$point,$transaction===null?array():array($transaction),$thread);
  if($summary['duplicate_event_versions']||$summary['statement_extended_interval_count']!==1||$summary['statement_boundary_overlap_with_next_start']||$summary['statement_extended_interval_ambiguous']){
    throw new RuntimeException('recovery_grant_mdl_v3_statement_ambiguous');}
  $seen=array();$candidates=array();
  foreach($rows as$row){
    if(isset($seen[$row['EVENT_ID']])){continue;}$seen[$row['EVENT_ID']]=true;
    if(governance_grant_cutpoint_compare($row['EVENT_ID'],$point)>=0){continue;}
    $end=$row['END_EVENT_ID'];$contains=$end===null||governance_grant_cutpoint_compare($point,$end)<=0||governance_grant_cutpoint_increment($end)===$point;
    if($contains){$candidates[]=$row;}
  }
  if(count($candidates)!==1){throw new RuntimeException('recovery_grant_mdl_v3_statement_unknown');}$row=$candidates[0];
  if($row['EVENT_NAME']!==$eventName||($eventName==='statement/sql/select'&&($row['END_EVENT_ID']===null||$transaction===null||$row['NESTING_EVENT_TYPE']!=='TRANSACTION'||$row['NESTING_EVENT_ID']!==$transaction['EVENT_ID']))||($eventName==='statement/sql/revoke'&&$row['END_EVENT_ID']!==null)){
    throw new RuntimeException('recovery_grant_mdl_v3_statement_parent_unknown');}
  return$row;
}
function governance_grant_mdl_v3_capture(array$capture,array$late,$process){
  $keys=array_keys($capture);sort($keys);
  if($keys!==array('private_after_identity','private_identity','private_statement_counts','private_statement_rows','public')){throw new RuntimeException('recovery_grant_mdl_v3_capture_unknown');}
  foreach(array('private_identity','private_after_identity')as$key){
    $value=$capture[$key];
    if(!is_array($value)||array_keys($value)!==array('ticket','transaction','physical')||governance_grant_acquisition_identity(array($value['ticket']),array($value['transaction']),array($value['physical']),$process)!==$value){throw new RuntimeException('recovery_grant_mdl_v3_capture_unknown');}
  }
  $identity=$capture['private_identity'];
  if($identity!==$capture['private_after_identity']||$identity!==$late){throw new RuntimeException('recovery_grant_mdl_v3_capture_changed');}
  $counts=$capture['private_statement_counts'];
  if(!is_array($counts)||array_keys($counts)!==array('current','history')||!is_int($counts['current'])||!is_int($counts['history'])||$counts['current']<0||$counts['history']<0||$counts['current']>16||$counts['history']>16||!is_array($capture['private_statement_rows'])||count($capture['private_statement_rows'])!==$counts['current']+$counts['history']){
    throw new RuntimeException('recovery_grant_mdl_v3_history_unproved');}
  return governance_grant_mdl_v3_statement($capture['private_statement_rows'],$identity['ticket']['OWNER_THREAD_ID'],$identity['ticket']['OWNER_EVENT_ID'],'statement/sql/select',$identity['transaction']);
}
function governance_grant_mdl_v3_proof(array$tickets,array$capture,array$before,array$after,array$requests,$fencerID,$revokerID){
  if(!governance_grant_cutpoint_uint($fencerID)||$fencerID==='0'||!governance_grant_cutpoint_uint($revokerID)||$revokerID==='0'||$fencerID===$revokerID||count($tickets)>64){throw new RuntimeException('recovery_grant_mdl_v3_identity_unknown');}
  $statement=governance_grant_mdl_v3_capture($capture,$before,$fencerID);
  if($before!==$after){throw new RuntimeException('recovery_grant_mdl_v3_capture_changed');}
  $compatible=array('SHARED','SHARED_HIGH_PRIO','SHARED_READ','SHARED_UPGRADABLE','SHARED_READ_ONLY','SHARED_NO_WRITE');
  $incompatible=array('SHARED_WRITE','SHARED_WRITE_LOW_PRIO','SHARED_NO_READ_WRITE','EXCLUSIVE');
  $pending=array();$blockers=array();$seen=array();
  foreach($tickets as$row){
    if(array_keys($row)!==array('OBJECT_INSTANCE_BEGIN','LOCK_TYPE','LOCK_DURATION','LOCK_STATUS','OWNER_THREAD_ID','OWNER_EVENT_ID','PROCESSLIST_ID')||!in_array($row['LOCK_TYPE'],array_merge($compatible,$incompatible),true)){
      throw new RuntimeException('recovery_grant_mdl_v3_ticket_unknown');}
    foreach(array('OBJECT_INSTANCE_BEGIN','OWNER_THREAD_ID','OWNER_EVENT_ID','PROCESSLIST_ID')as$key){if(!governance_grant_cutpoint_uint($row[$key])||$row[$key]==='0'){throw new RuntimeException('recovery_grant_mdl_v3_ticket_unknown');}}
    if(isset($seen[$row['OBJECT_INSTANCE_BEGIN']])){throw new RuntimeException('recovery_grant_mdl_v3_ticket_unknown');}$seen[$row['OBJECT_INSTANCE_BEGIN']]=true;
    if($row['LOCK_STATUS']==='PENDING'){$pending[]=$row;}
    elseif($row['LOCK_STATUS']==='GRANTED'){if(in_array($row['LOCK_TYPE'],$incompatible,true)){$blockers[]=$row;}}
    else{throw new RuntimeException('recovery_grant_mdl_v3_ticket_unknown');}
  }
  if(count($pending)!==1||count($blockers)!==1||$blockers[0]!==$before['ticket']||$blockers[0]['PROCESSLIST_ID']!==$fencerID||$blockers[0]['LOCK_TYPE']!=='SHARED_WRITE'||$blockers[0]['LOCK_DURATION']!=='TRANSACTION'||$pending[0]['PROCESSLIST_ID']!==$revokerID||$pending[0]['LOCK_TYPE']!=='SHARED_READ_ONLY'){
    throw new RuntimeException('recovery_grant_mdl_v3_unique_blocker_unproved');}
  $request=governance_grant_mdl_v3_statement($requests,$pending[0]['OWNER_THREAD_ID'],$pending[0]['OWNER_EVENT_ID'],'statement/sql/revoke');
  return array('holder'=>$blockers[0],'request'=>$pending[0],'transaction'=>$before['transaction'],'statement'=>$statement,'physical'=>$before['physical'],'request_statement'=>$request);
}
function governance_grant_mdl_v3_observe(mysqli$control,mysqli$fencer,mysqli$revoker,array$capture){
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||getenv('PHORGE_GOVERNANCE_GRANT_MDL_V3')!=='1'||$fencer->thread_id===$revoker->thread_id||$control->thread_id===$fencer->thread_id||$control->thread_id===$revoker->thread_id){throw new RuntimeException('recovery_grant_mdl_v3_profile_required');}
  $enabled=governance_recovery_grant_mdl_rows($control,"SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl'");
  if(count($enabled)!==1||$enabled[0]['ENABLED']!=='YES'){throw new RuntimeException('recovery_grant_mdl_v3_instrumentation_unknown');}
  $before=governance_grant_acquisition_read($control,$fencer);$tickets=governance_recovery_grant_mdl_tickets($control);$pending=array();
  foreach($tickets as$row){if($row['PROCESSLIST_ID']===(string)$revoker->thread_id&&$row['LOCK_STATUS']==='PENDING'&&$row['LOCK_TYPE']==='SHARED_READ_ONLY'){$pending[]=$row;}}
  if(count($pending)!==1||!governance_grant_cutpoint_uint($pending[0]['OWNER_THREAD_ID'])){throw new RuntimeException('recovery_grant_mdl_v3_request_unknown');}
  $requests=governance_recovery_grant_mdl_rows($control,'SELECT CAST(THREAD_ID AS CHAR) THREAD_ID,CAST(EVENT_ID AS CHAR) EVENT_ID,CAST(END_EVENT_ID AS CHAR) END_EVENT_ID,EVENT_NAME,CAST(NESTING_EVENT_ID AS CHAR) NESTING_EVENT_ID,NESTING_EVENT_TYPE FROM performance_schema.events_statements_current WHERE THREAD_ID='.$pending[0]['OWNER_THREAD_ID'].' LIMIT 3');
  $read=array($revoker);$errors=$reject=array();$poll=mysqli_poll($read,$errors,$reject,0,0);
  if($poll!==0||$read||$errors||$reject){throw new RuntimeException('recovery_grant_mdl_v3_revoke_not_pending');}
  $after=governance_grant_acquisition_read($control,$fencer);
  return governance_grant_mdl_v3_proof($tickets,$capture,$before,$after,$requests,(string)$fencer->thread_id,(string)$revoker->thread_id);
}
function governance_grant_mdl_v3_rejection(Throwable$error){
  $known=array('statement_unknown','statement_ambiguous','statement_parent_unknown','capture_unknown','capture_changed','history_unproved','identity_unknown','ticket_unknown','unique_blocker_unproved','profile_required','instrumentation_unknown','request_unknown','revoke_not_pending');
  foreach($known as$suffix){if(get_class($error)===RuntimeException::class&&$error->getMessage()==='recovery_grant_mdl_v3_'.$suffix){return 'v3-'.str_replace('_','-',$suffix);}}
  return 'v3-observation-unknown';
}
