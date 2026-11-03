<?php

/** Initial transfer profile through actual native accounts and one People writer. */
function governance_native_transfer_execution_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$service=$state['service'];$conn=(new PhabricatorUser())->establishConnection('w');
  $cookies=$_COOKIE;$server=$_SERVER;$trigger_owned=false;
  $new_user=function($suffix,$admin)use($fixture){$user=(new PhabricatorUser())
    ->setUsername('tx'.$suffix.substr($fixture->getNamespace(),-9))->setRealName('Synthetic Transfer '.$suffix)
    ->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin($admin?1:0)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();return$user;};
  $refuse=function(callable $call,$reason)use($check){$seen=false;
    try{$call();}catch(RuntimeException$error){if($error->getMessage()!==$reason){throw$error;}$seen=true;}
    return$seen;};
  try{
    $subject=$new_user('subject',false);$owner=$new_user('owner',false);$reviewer_user=$new_user('reviewer',true);
    $reviewer=governance_native_request($reviewer_user,$state['sessions']);
    $reviewer_service=PhorgeOrganizationService::newForActor($reviewer['context']);
    $ordinary=governance_native_request($subject,$state['sessions']);$ordinary_service=PhorgeOrganizationService::newForActor($ordinary['context']);
    // Establish the subject's actual first-issuer epoch before binding sources.
    // A later first authentication is a real source change, not an allow case.
    if($ordinary_service->readActorCapabilities()['manage_units']!==false){throw new RuntimeException('transfer_fixture_ordinary_source_required');}
    foreach(array('transfer-from','transfer-to')as$id){$service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,
      'kind'=>'department','name'=>'Synthetic '.$id,'parent_id'=>null));}
    $service->assignPrimaryDepartment(array('operation_id'=>'transfer-primary-initial','user_phid'=>$subject->getPHID(),
      'unit_id'=>'transfer-from','expected_version'=>0));
    $intent=array('operation_id'=>'transfer-real-execute','transfer_id'=>'transfer-real-ticket',
      'subject_phid'=>$subject->getPHID(),'responsible_phid'=>$owner->getPHID(),'from_department'=>'transfer-from','to_department'=>'transfer-to',
      'primary_version'=>1,'from_version'=>1,'to_version'=>1,'reason'=>'Explicit synthetic handover',
      'acknowledge_no_project_permission_change'=>true);
    $relationships=function()use($conn){return hash('sha256',phutil_json_encode(array(
      queryfx_all($conn,'SELECT * FROM organization_member ORDER BY id'),
      queryfx_all($conn,'SELECT * FROM organization_appointment ORDER BY id'))));};
    $native_projects=function()use($fixture){$tables=array();
      $tables[]=governance_native_setup_query($fixture,'SELECT * FROM '.$fixture->table('project','project').' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
      $tables[]=governance_native_setup_query($fixture,'SELECT * FROM '.$fixture->table('project','edge').' ORDER BY src,type,dst')->fetch_all(MYSQLI_ASSOC);
      return hash('sha256',phutil_json_encode($tables));};
    $before_projects=$native_projects();
    $before_relationships=$relationships();$preview=$service->previewTransfer($intent);
    $check($preview['calendar']['days']===14 && $preview['approval_required']===true &&
      $preview['native_project_permissions_changed']===false && $preview['exhaustive_project_review']===false,
      'native-transfer-real-preview-is-explicit-14-day-plan-not-permission-proof');
    $transport=array('json'=>'true','action'=>'transfer.propose','payload'=>phutil_json_encode(array('request'=>$intent,'ttl_seconds'=>300)));
    list($denied,$unused)=governance_native_route_response($state['admin'],'POST',$transport);
    $check($denied->getHTTPResponseCode()===403 && !queryfx_one($conn,'SELECT id FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id']),
      'native-transfer-actual-post-without-csrf-cannot-propose-or-write');
    $transport[AphrontRequest::getCSRFTokenName()]=$state['admin']['viewer']->getCSRFToken();
    list($response,$body)=governance_native_route_response($state['admin'],'POST',$transport);
    $check($response->getHTTPResponseCode()===200 && isset($body['result']),
      'native-transfer-actual-csrf-post-dispatches-fixed-native-service-proposal');
    $request=$body['result'];$detail=$service->readTransfer($intent['transfer_id']);
    list($read_response,$read_body)=governance_native_route_response($state['admin'],'GET',array('json'=>'true','mode'=>'transfer','unit_id'=>$intent['transfer_id']));
    list($hidden_response,$hidden_body)=governance_native_route_response($ordinary,'GET',array('json'=>'true','mode'=>'transfer','unit_id'=>$intent['transfer_id']));
    $check($read_response->getHTTPResponseCode()===200 && isset($read_body['result']) && $hidden_response->getHTTPResponseCode()===403 &&
      !isset($hidden_body['result']) && governance_native_route_guard_depth()===0,
      'native-transfer-actual-get-revalidates-current-admin-and-restores-read-guard');
    $encoded=phutil_json_encode($detail);
    $check($request['state']==='submitted' && $request['version']===1 &&
      strpos($encoded,'credentialDigest')===false && strpos($encoded,'identityDigest')===false && strpos($encoded,'bindingJSON')===false,
      'native-transfer-real-proposal-projects-no-native-credentials-or-private-source-snapshots');
    $check($refuse(function()use($service,$intent,$detail){$service->approveTransfer($intent['transfer_id'],$detail['request_digest']);},
      'organization_transfer_independent_approval_refused'),
      'native-transfer-requester-cannot-self-approve');
    $check($refuse(function()use($ordinary_service,$intent){$ordinary_service->readTransfer($intent['transfer_id']);},
      'organization_transfer_current_admin_required'),
      'native-transfer-ordinary-subject-has-no-request-history-or-self-directory-exception');
    list($review_response,$review_body)=governance_native_route_response($reviewer,'POST',array('json'=>'true',
      'action'=>'transfer.approve','payload'=>phutil_json_encode(array('transfer_id'=>$intent['transfer_id'],'request_digest'=>$detail['request_digest'])),
      AphrontRequest::getCSRFTokenName()=>$reviewer['viewer']->getCSRFToken()));
    if($review_response->getHTTPResponseCode()!==200||!isset($review_body['result'])){throw new RuntimeException('transfer_fixture_actual_approval_route_failed');}
    $approved=$review_body['result'];
    $check($approved['state']==='approved' && $approved['version']===2 &&
      $refuse(function()use($reviewer_service,$intent){$reviewer_service->executeTransfer($intent['transfer_id'],true);},
        'organization_transfer_bound_executor_required'),
      'native-transfer-independent-current-admin-approves-without-taking-executor-role');
    $check($refuse(function()use($service,$intent){$service->executeTransfer($intent['transfer_id'],false);},
      'organization_transfer_acknowledgement_required'),
      'native-transfer-execution-requires-explicit-no-native-permission-change-acknowledgement');
    $execute_transport=array('json'=>'true','action'=>'transfer.execute','payload'=>phutil_json_encode(array(
      'transfer_id'=>$intent['transfer_id'],'acknowledge_no_project_permission_change'=>true)));
    list($denied,$unused)=governance_native_route_response($state['admin'],'POST',$execute_transport);
    $check($denied->getHTTPResponseCode()===403 && queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id'])['state']==='approved',
      'native-transfer-actual-execute-without-csrf-never-consumes-approved-request');
    $execute_transport[AphrontRequest::getCSRFTokenName()]=$state['admin']['viewer']->getCSRFToken();
    list($execute_response,$execute_body)=governance_native_route_response($state['admin'],'POST',$execute_transport);
    if($execute_response->getHTTPResponseCode()!==200||!isset($execute_body['result'])){throw new RuntimeException('transfer_fixture_actual_execute_route_failed');}
    $result=$execute_body['result'];
    $primary=queryfx_one($conn,'SELECT * FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$subject->getPHID());
    $handover=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$intent['transfer_id']);
    $ticket=queryfx_one($conn,'SELECT state,version,consumedAt FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id']);
    $check($result['status']==='committed' && $result['replayed']===false &&
      $primary['unitID']==='transfer-to' && (int)$primary['version']===2 &&
      $handover['state']==='active' && (int)$handover['cumulativeDays']===14 &&
      $ticket['state']==='executed' && (int)$ticket['version']===3 && $ticket['consumedAt']!==null &&
      $before_relationships===$relationships(),
      'native-transfer-approved-consumption-primary-handover-and-ledger-commit-without-member-or-admin-grants');
    $count=function()use($conn){return array(
      queryfx_one($conn,'SELECT COUNT(*) n FROM organization_audit')['n'],
      queryfx_one($conn,'SELECT COUNT(*) n FROM organization_operation')['n'],
      queryfx_one($conn,'SELECT COUNT(*) n FROM %R',new PhorgeOrganizationHandover())['n'],
      queryfx_one($conn,'SELECT COUNT(*) n FROM %R',new PhorgeOrganizationOutbox())['n']);};
    $before=$count();$retry=$service->executeTransfer($intent['transfer_id'],true);
    $check($retry['replayed']===true && $before===$count(),
      'native-transfer-consumed-request-retries-durable-ledger-without-second-primary-switch-or-outbox');
    $events=queryfx_all($conn,'SELECT * FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationOutbox(),$intent['transfer_id']);
    $check(count($events)===1 && $events[0]['state']==='pending' && (int)$events[0]['attempts']===0 && $events[0]['dispatchedAt']===null,
      'native-transfer-persisted-outbox-reference-remains-undispatched');
    $instant=$new_user('instant',false);$service->assignPrimaryDepartment(array('operation_id'=>'instant-primary','user_phid'=>$instant->getPHID(),
      'unit_id'=>'transfer-from','expected_version'=>0));
    $zero=$intent;$zero['subject_phid']=$instant->getPHID();$zero['transfer_id']='transfer-instant';$zero['operation_id']='transfer-instant-execute';$zero['handover_days']=0;
    $service->proposeTransfer($zero,300);$plan=$service->readTransfer($zero['transfer_id']);
    $reviewer_service->approveTransfer($zero['transfer_id'],$plan['request_digest']);$service->executeTransfer($zero['transfer_id'],true);
    $check(!queryfx_one($conn,'SELECT id FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$zero['transfer_id']) &&
      queryfx_one($conn,'SELECT unitID FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$instant->getPHID())['unitID']==='transfer-to',
      'native-transfer-immediate-zero-day-switch-creates-no-active-handover');
    // A different approved request cannot reuse the first durable operationID.
    $conflict=$new_user('conflict',false);$service->assignPrimaryDepartment(array('operation_id'=>'conflict-primary','user_phid'=>$conflict->getPHID(),
      'unit_id'=>'transfer-from','expected_version'=>0));
    $other=$intent;$other['subject_phid']=$conflict->getPHID();$other['transfer_id']='transfer-conflict';
    $service->proposeTransfer($other,300);$plan=$service->readTransfer($other['transfer_id']);
    $reviewer_service->approveTransfer($other['transfer_id'],$plan['request_digest']);
    $check($refuse(function()use($service,$other){$service->executeTransfer($other['transfer_id'],true);},'organization_operation_conflict') &&
      queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$other['transfer_id'])['state']==='approved' &&
      queryfx_one($conn,'SELECT unitID FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$conflict->getPHID())['unitID']==='transfer-from',
      'native-transfer-same-operation-different-payload-refused-before-consumption');
    $fault=$new_user('fault',false);$service->assignPrimaryDepartment(array('operation_id'=>'fault-primary','user_phid'=>$fault->getPHID(),
      'unit_id'=>'transfer-from','expected_version'=>0));
    $failed=$intent;$failed['subject_phid']=$fault->getPHID();$failed['transfer_id']='transfer-audit-fault';$failed['operation_id']='transfer-audit-fault-execute';
    $service->proposeTransfer($failed,300);$plan=$service->readTransfer($failed['transfer_id']);
    $reviewer_service->approveTransfer($failed['transfer_id'],$plan['request_digest']);
    $namespace=$fixture->getNamespace();if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){throw new RuntimeException('transfer_fixture_namespace_refused');}
    $trigger='`'.$namespace.'_user`.`transfer_audit_fault`';$table=$fixture->table('user','organization_audit');
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$table.
      " FOR EACH ROW BEGIN IF NEW.action='transfer.execute' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='transfer-midwrite-audit-fault'; END IF; END");$trigger_owned=true;
    $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$namespace."_user' AND TRIGGER_NAME='transfer_audit_fault' AND EVENT_OBJECT_TABLE='organization_audit'")->fetch_assoc();
    $check((int)$proof['n']===1,'native-transfer-real-midwrite-audit-trigger-installed-by-owned-setup');
    $seen=false;try{$service->executeTransfer($failed['transfer_id'],true);}
    catch(AphrontQueryException$error){if((int)$error->getCode()!==1644||strpos($error->getMessage(),'transfer-midwrite-audit-fault')===false){throw$error;}$seen=true;}
    $check($seen && queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$failed['transfer_id'])['state']==='approved' &&
      queryfx_one($conn,'SELECT unitID FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$fault->getPHID())['unitID']==='transfer-from' &&
      !queryfx_one($conn,'SELECT id FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$failed['transfer_id']) &&
      !queryfx_one($conn,'SELECT id FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationOutbox(),$failed['transfer_id']) &&
      !queryfx_one($conn,'SELECT operationID FROM organization_operation WHERE BINARY operationID=BINARY %s',$failed['operation_id']) && !$conn->isInsideTransaction(),
      'native-transfer-real-midwrite-audit-fault-rolls-back-consume-primary-handover-outbox-and-ledger');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$trigger_owned=false;
    global $control;if(!($control instanceof mysqli)){throw new RuntimeException('native_concurrency_observer_required');}
    $race_user=$new_user('race',false);$service->assignPrimaryDepartment(array('operation_id'=>'race-primary','user_phid'=>$race_user->getPHID(),
      'unit_id'=>'transfer-from','expected_version'=>0));
    $race=$intent;$race['subject_phid']=$race_user->getPHID();$race['transfer_id']='transfer-race';$race['operation_id']='transfer-race-execute';
    $service->proposeTransfer($race,300);$plan=$service->readTransfer($race['transfer_id']);$reviewer_service->approveTransfer($race['transfer_id'],$plan['request_digest']);
    $setup=$fixture->getAdmin();$setup->begin_transaction();$locked=true;$workers=array();
    try{
      governance_native_setup_query($fixture,'SELECT id FROM '.$fixture->table('user','organization_guard').' WHERE id=1 FOR UPDATE');
      for($i=0;$i<2;$i++){$workers[]=governance_native_start_worker($fixture,array('action'=>'org.transfer.execute',
        'token'=>$state['admin']['token'],'payload'=>array('transfer_id'=>$race['transfer_id'],'acknowledge_no_project_permission_change'=>true)));}
      foreach($workers as$worker){governance_native_concurrency_wait($fixture,$control,$worker,'organization_guard');}
      $check(true,'native-transfer-two-real-native-writers-wait-in-existing-organization-lock-order');
      $setup->commit();$locked=false;$results=array();foreach($workers as&$worker){$results[]=governance_native_finish_worker($worker);}unset($worker);
      $flags=array_column($results,'replayed');sort($flags);
      $check(array_column($results,'status')===array('committed','committed') && $flags===array(false,true) &&
        (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_operation WHERE BINARY operationID=BINARY %s',$race['operation_id'])['n']===1 &&
        (int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$race['transfer_id'])['n']===1 &&
        (int)queryfx_one($conn,'SELECT version FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$race_user->getPHID())['version']===2,
        'native-transfer-concurrent-identical-execution-commits-one-primary-and-one-durable-result');
    }finally{if($locked){$setup->rollback();}}
    $ttl_user=$new_user('ttl',false);$service->assignPrimaryDepartment(array('operation_id'=>'ttl-primary','user_phid'=>$ttl_user->getPHID(),
      'unit_id'=>'transfer-from','expected_version'=>0));
    $short=$intent;$short['subject_phid']=$ttl_user->getPHID();$short['transfer_id']='transfer-expiry';$short['operation_id']='transfer-expiry-execute';
    $service->proposeTransfer($short,8);$plan=$service->readTransfer($short['transfer_id']);$reviewer_service->approveTransfer($short['transfer_id'],$plan['request_digest']);
    $expires=(int)queryfx_one($conn,'SELECT expiresAt FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$short['transfer_id'])['expiresAt'];
    $setup->begin_transaction();$locked=true;
    try{
      governance_native_concurrency_sql($fixture,'SELECT id FROM '.$fixture->table('user','organization_transfer').' WHERE transferID=? FOR UPDATE',array($short['transfer_id']));
      $worker=governance_native_start_worker($fixture,array('action'=>'org.transfer.execute','token'=>$state['admin']['token'],
        'payload'=>array('transfer_id'=>$short['transfer_id'],'acknowledge_no_project_permission_change'=>true)));
      governance_native_concurrency_wait($fixture,$control,$worker,'organization_transfer');
      $check(true,'native-transfer-actual-main-execution-waits-on-approved-row-after-native-source-locks');
      $deadline=microtime(true)+12;while(governance_native_concurrency_now($fixture)<$expires){
        if(microtime(true)>$deadline){throw new RuntimeException('transfer_fixture_expiry_wait_failed');}usleep(20000);}
      $setup->commit();$locked=false;$ended=governance_native_finish_worker($worker);
      $check($ended['status']==='rejected' && $ended['reason']==='organization_transfer_approval_expired' &&
        queryfx_one($conn,'SELECT unitID FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$ttl_user->getPHID())['unitID']==='transfer-from' &&
        queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$short['transfer_id'])['state']==='approved' &&
        !queryfx_one($conn,'SELECT operationID FROM organization_operation WHERE BINARY operationID=BINARY %s',$short['operation_id']),
        'native-transfer-expiry-during-real-row-wait-refused-before-consumption-or-primary-change');
    }finally{if($locked){$setup->rollback();}}
    $check($native_projects()===$before_projects,'native-transfer-does-not-change-native-project-policies-hierarchy-or-membership-edges');
    return array('initial_transfer_profile_implemented'=>true,'independent_current_admin_approval'=>true,
      'one_native_people_transaction'=>true,'native_csrf_transport_exercised'=>true,'conduit_transport_proved'=>false,'same_operation_replay_verified'=>true,'native_concurrency_exercised'=>true,'real_expiry_wait_exercised'=>true,
      'extension_termination_responsible_implemented'=>false,'native_project_permissions_changed'=>false,
      'dispatcher_registered'=>false,'production_activation'=>false);
  }finally{
    if($trigger_owned){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}
    $_COOKIE=$cookies;$_SERVER=$server;
  }
}
