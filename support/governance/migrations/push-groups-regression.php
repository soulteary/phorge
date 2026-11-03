<?php

/** Actual historical DDL and PHP migration, isolated synthetic rows only. */
function governance_bootstrap_push_groups_regression($api,$root) {
  foreach(array('patches/20131204.pushlog.sql','autopatches/20140325.push.1.event.sql',
      'autopatches/20140325.push.2.eventphid.sql') as $path){
    $api->applyPatchSQL($root.'/resources/sql/'.$path);
  }
  $conn=$api->getConn('repository');
  $mode=queryfx_one($conn,'SELECT @@session.sql_mode mode')['mode'];
  if(!in_array('ONLY_FULL_GROUP_BY',explode(',',$mode),true)){
    throw new RuntimeException('Actual strict group mode required.');
  }
  $existing='PHID-PSHE-syntheticexistingevent';
  queryfx($conn,'INSERT INTO repository_pushevent (phid,repositoryPHID,epoch,pusherPHID,remoteAddress,remoteProtocol,rejectCode,rejectDetails) VALUES (%s,%s,30,%s,3,%s,0,%s)',
    $existing,'PHID-REPO-syntheticrepository','PHID-USER-syntheticthird','ssh','existing');
  foreach(array(array(10,'PHID-USER-syntheticfirst','syntheticA','first',''),
      array(20,'PHID-USER-syntheticsecond','syntheticA','second',''),
      array(30,'PHID-USER-syntheticthird','syntheticB','existing',$existing)) as $row){
    queryfx($conn,'INSERT INTO repository_pushlog (epoch,repositoryPHID,pusherPHID,remoteAddress,remoteProtocol,transactionKey,refType,refNew,changeFlags,rejectCode,rejectDetails,pushEventPHID) VALUES (%d,%s,%s,1,%s,%s,%s,%s,0,0,%s,%s)',
      $row[0],'PHID-REPO-syntheticrepository',$row[1],'ssh',$row[2],'branch','synthetic-ref',$row[3],$row[4]);
  }
  $baseline_rejected=false;
  try{queryfx_all($conn,'SELECT * FROM repository_pushlog GROUP BY transactionKey ORDER BY id ASC');}
  catch(AphrontQueryException $ex){if((int)$ex->getCode()!==1055){throw $ex;}$baseline_rejected=true;}
  if(!$baseline_rejected){throw new RuntimeException('Original nondeterministic SQL unexpectedly accepted.');}
  $api->applyPatchPHP($root.'/resources/sql/autopatches/20140325.push.3.groups.php');
  $events=queryfx_all($conn,'SELECT * FROM repository_pushevent ORDER BY id');
  $logs=queryfx_all($conn,'SELECT id,transactionKey,pushEventPHID FROM repository_pushlog ORDER BY id');
  if(count($events)!==2 || count($logs)!==3 || $logs[0]['pushEventPHID']==='' ||
    $logs[0]['pushEventPHID']!==$logs[1]['pushEventPHID'] ||
    $logs[2]['pushEventPHID']!==$existing || $events[0]['phid']!==$existing ||
    $events[1]['phid']!==$logs[0]['pushEventPHID'] || (string)$events[1]['epoch']!=='10' ||
    $events[1]['pusherPHID']!=='PHID-USER-syntheticfirst' || $events[1]['rejectDetails']!=='first'){
    throw new RuntimeException('Actual deterministic migration postconditions failed.');
  }
  return array('actual_nonempty_group_regression_passed'=>true,
    'actual_original_query_errno'=>1055,'only_full_group_by_enabled'=>true,
    'synthetic_log_rows'=>3,'actual_event_rows'=>2,
    'earliest_native_id_metadata_selected'=>true,'existing_event_preserved'=>true,
    'actual_php_migration_executed'=>true);
}
