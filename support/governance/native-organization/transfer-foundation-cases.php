<?php

/** Real native storage/rollback only. Fixture rows are not approval authority. */
function governance_native_transfer_foundation_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('transfer_fixture_clean_writer_required');}
  $subject=$state['users']['member']->getPHID();$actor=$state['admin']['viewer']->getPHID();
  $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
  $plan=PhorgeOrganizationCalendar::preview($now);
  $before_primary=queryfx_all($conn,'SELECT * FROM %R ORDER BY id',new PhorgeOrganizationPrimaryDepartment());
  $before_members=queryfx_all($conn,'SELECT * FROM %R ORDER BY id',new PhorgeOrganizationMember());
  $transfer='transfer-fixture-'.substr($fixture->getNamespace(),-12);
  $handover='handover-fixture-'.substr($fixture->getNamespace(),-12);
  queryfx($conn,'INSERT INTO %R (transferID,operationID,subjectPHID,responsiblePHID,requesterPHID,executorPHID,action,state,requestDigest,bindingJSON,expiresAt,version,createdAt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,1,%d)',
    new PhorgeOrganizationTransfer(),$transfer,'transfer-fixture-propose',$subject,$actor,$actor,$actor,
    'transfer','submitted',hash('sha256','synthetic-transfer-fixture'),'{"fixture_only":true,"approval_complete":false}',$now+300,$now);
  queryfx($conn,'INSERT INTO %R (handoverID,transferID,subjectPHID,fromDepartmentID,toDepartmentID,responsiblePHID,state,startAt,deadlineAt,timezone,startLocal,deadlineLocal,startOffset,deadlineOffset,cumulativeDays,version) VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s,%d,%d,14,1)',
    new PhorgeOrganizationHandover(),$handover,$transfer,$subject,'fixture-old','fixture-new',$actor,'active',
    $now,$plan['deadline_at'],$plan['timezone'],$plan['start_local'],$plan['deadline_local'],$plan['start_offset'],$plan['deadline_offset']);
  $event='handover-created:'.$handover;
  queryfx($conn,'INSERT INTO %R (eventID,eventType,transferID,handoverID,sourceVersion,state,attempts,createdAt) VALUES (%s,%s,%s,%s,1,%s,0,%d)',
    new PhorgeOrganizationOutbox(),$event,'handover.created',$transfer,$handover,'pending',$now);
  $record=(new PhorgeOrganizationTransfer())->loadOneWhere('transferID=%s',$transfer);
  $stored=(new PhorgeOrganizationHandover())->loadOneWhere('handoverID=%s',$handover);
  $check($record && $stored && $record->getState()==='submitted' && (int)$record->getVersion()===1 &&
    (int)$stored->getDeadlineAt()===$plan['deadline_at'] && $stored->getTimezone()==='UTC',
    'native-transfer-foundation-real-people-dao-roundtrip-calendar-and-version');
  $check(!(new PhorgeOrganizationTransfer())->loadOneWhere('transferID=%s',strtoupper($transfer)) &&
    !(new PhorgeOrganizationTransfer())->loadOneWhere('transferID=%s',$transfer.' '),
    'native-transfer-foundation-physical-binary-identity-rejects-case-and-trailing-pad-aliases');
  queryfx($conn,'UPDATE %R SET version=version+1 WHERE handoverID=%s AND version=1',new PhorgeOrganizationHandover(),$handover);
  $changed=$conn->getAffectedRows();
  queryfx($conn,'UPDATE %R SET version=version+1 WHERE handoverID=%s AND version=1',new PhorgeOrganizationHandover(),$handover);
  $check($changed===1 && $conn->getAffectedRows()===0,
    'native-transfer-foundation-real-version-cas-refuses-stale-update');
  $fingerprint=function()use($conn){$rows=array();foreach(array(new PhorgeOrganizationTransfer(),new PhorgeOrganizationHandover(),
      new PhorgeOrganizationProjectDisposition(),new PhorgeOrganizationOutbox())as$table){$rows[]=queryfx_all($conn,'SELECT * FROM %R ORDER BY id',$table);}return hash('sha256',phutil_json_encode($rows));};
  $before=$fingerprint();$duplicate=false;$conn->openTransaction();
  try{
    queryfx($conn,'UPDATE %R SET version=version+1 WHERE handoverID=%s AND version=2',new PhorgeOrganizationHandover(),$handover);
    if($conn->getAffectedRows()!==1){throw new RuntimeException('transfer_fixture_expected_update_missing');}
    queryfx($conn,'INSERT INTO %R (eventID,eventType,sourceVersion,state,attempts,createdAt) VALUES (%s,%s,2,%s,0,%d)',
      new PhorgeOrganizationOutbox(),$event,'handover.extended','pending',$now);
  }catch(AphrontDuplicateKeyQueryException$error){
    // Native MySQL maps errno1062 to this final class but omits exception code.
    // The only fault here is the already-existing owned eventID, not a generic
    // Throwable or an arbitrary precondition failure.
    if(!str_starts_with($error->getMessage(),'#1062:') || strpos($error->getMessage(),$event)===false){throw$error;}
    $duplicate=true;
  }
  finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
  $check($duplicate && $before===$fingerprint() && !$conn->isInsideTransaction(),
    'native-transfer-foundation-exact-duplicate-event-fault-rolls-back-version-and-all-workflow-tables');
  $event_row=queryfx_one($conn,'SELECT * FROM %R WHERE eventID=%s',new PhorgeOrganizationOutbox(),$event);
  $check($event_row['state']==='pending' && (int)$event_row['attempts']===0 && $event_row['dispatchedAt']===null &&
    !array_intersect(array_keys($event_row),array('body','payloadJSON','email','attachment','credential')),
    'native-transfer-foundation-outbox-holds-only-protected-references-and-never-dispatches');
  $check($before_primary===queryfx_all($conn,'SELECT * FROM %R ORDER BY id',new PhorgeOrganizationPrimaryDepartment()) &&
    $before_members===queryfx_all($conn,'SELECT * FROM %R ORDER BY id',new PhorgeOrganizationMember()),
    'native-transfer-foundation-fixture-storage-does-not-switch-primary-or-grant-membership');
  return array('storage_only'=>true,'version_cas_verified'=>true,'storage_rollback_verified'=>true,
    'independent_approval_implemented'=>false,'native_primary_switch_implemented'=>false,
    'outbox_dispatch_registered'=>false,'production_activation'=>false);
}
