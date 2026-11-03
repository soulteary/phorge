<?php

/** Draft real-native fixture. Not executed until parent assigns the isolated pod. */
function governance_native_handover_lifecycle_cases($fixture,callable $check,array $state){
  $fixture->assertOwned();$service=$state['service'];$conn=(new PhabricatorUser())->establishConnection('w');
  $cookies=$_COOKIE;$server=$_SERVER;$trigger=null;$installed=false;
  $make=function($suffix,$admin)use($fixture){$u=(new PhabricatorUser())->setUsername('h4'.$suffix.substr($fixture->getNamespace(),-9))
    ->setRealName('Synthetic Handover '.$suffix)->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin($admin?1:0)->save();
    (new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();return$u;};
  $reject=function(callable $call,$reason){try{$call();}catch(RuntimeException$error){if($error->getMessage()!==$reason){throw$error;}return true;}return false;};
  $post=function($session,$action,$payload){return governance_native_route_response($session,'POST',array('json'=>'true','action'=>$action,
    'payload'=>phutil_json_encode($payload),AphrontRequest::getCSRFTokenName()=>$session['viewer']->getCSRFToken()));};
  $fingerprint=function()use($conn){$rows=array();foreach(array('transfer','handover','outbox','operation','audit','member','appointment')as$t){
    $rows[$t]=queryfx_all($conn,'SELECT * FROM %T ORDER BY %C','organization_'.$t,$t==='operation'?'operationID':'id');}
    $rows['primary']=queryfx_all($conn,'SELECT * FROM organization_primarydepartment ORDER BY userPHID');return hash('sha256',phutil_json_encode($rows));};
  try{
    $subject=$make('subject',false);$old=$make('oldowner',false);$new=$make('newowner',false);
    $reviewer=governance_native_request($make('reviewer',true),$state['sessions']);$review=PhorgeOrganizationService::newForActor($reviewer['context']);
    $ordinary=governance_native_request($subject,$state['sessions']);$ordinary_service=PhorgeOrganizationService::newForActor($ordinary['context']);
    // Known T3 precondition only. First-issuer normalization remains a separate source regression gate.
    $ordinary_service->readActorCapabilities();
    foreach(array('handover-four-from','handover-four-to')as$id){$service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,'kind'=>'department','name'=>$id,'parent_id'=>null));}
    $service->assignPrimaryDepartment(array('operation_id'=>'handover-four-primary','user_phid'=>$subject->getPHID(),'unit_id'=>'handover-four-from','expected_version'=>0));
    $initial=array('operation_id'=>'handover-four-initial-op','transfer_id'=>'handover-four-initial','subject_phid'=>$subject->getPHID(),
      'responsible_phid'=>$old->getPHID(),'from_department'=>'handover-four-from','to_department'=>'handover-four-to',
      'primary_version'=>1,'from_version'=>1,'to_version'=>1,'reason'=>'Initial actual native transfer','acknowledge_no_project_permission_change'=>true);
    $service->proposeTransfer($initial,300);$ticket=$service->readTransfer($initial['transfer_id']);
    $review->approveTransfer($initial['transfer_id'],$ticket['request_digest']);$service->executeTransfer($initial['transfer_id'],true);
    $h=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$initial['transfer_id']);
    $intent=array('operation_id'=>'handover-four-extend-op','transfer_id'=>'handover-four-extend','handover_id'=>$h['handoverID'],
      'expected_version'=>1,'handover_days'=>14,'reason'=>'Continue handover','acknowledge_no_project_permission_change'=>true);
    $preview=$service->previewHandover('extend',$intent);
    $check($preview['calendar']['cumulative_days']===28&&$preview['approval_required']===true&&$preview['automatic_dispatch']===false,
      'native-handover-extension-preview-derives-total-from-stored-original-calendar');
    $request=array('request'=>$intent,'ttl_seconds'=>300);
    list($bad,$unused)=governance_native_route_response($state['admin'],'POST',array('json'=>'true','action'=>'handover.extend.propose','payload'=>phutil_json_encode($request)));
    $check($bad->getHTTPResponseCode()===403&&!queryfx_one($conn,'SELECT id FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id']),
      'native-handover-extension-real-post-without-csrf-cannot-propose');
    list($ok,$body)=$post($state['admin'],'handover.extend.propose',$request);
    $check($ok->getHTTPResponseCode()===200&&$body['result']['state']==='submitted',
      'native-handover-extension-real-csrf-route-persists-bound-pending-ticket');
    $detail=$service->readHandoverApproval($intent['transfer_id']);
    $check($reject(function()use($service,$intent,$detail){$service->approveHandover($intent['transfer_id'],$detail['request_digest']);},'organization_handover_independent_approval_required'),
      'native-handover-requester-cannot-self-approve-extension');
    $check($reject(function()use($ordinary_service,$intent){$ordinary_service->readHandoverApproval($intent['transfer_id']);},'organization_handover_current_admin_required'),
      'native-handover-ordinary-subject-cannot-read-approval-or-receive-self-exception');
    list($ok,$body)=$post($reviewer,'handover.approve',array('transfer_id'=>$intent['transfer_id'],'request_digest'=>$detail['request_digest']));
    $check($ok->getHTTPResponseCode()===200&&$body['result']['state']==='approved',
      'native-handover-independent-current-admin-approval-uses-actual-credential-source');
    $check($reject(function()use($review,$intent){$review->executeHandover($intent['transfer_id'],true);},'organization_handover_bound_executor_required'),
      'native-handover-approver-cannot-become-executor');
    list($ok,$body)=$post($state['admin'],'handover.execute',array('transfer_id'=>$intent['transfer_id'],'acknowledge_no_project_permission_change'=>true));
    $changed=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$h['handoverID']);
    $check($ok->getHTTPResponseCode()===200&&$body['result']['replayed']===false&&(int)$changed['cumulativeDays']===28&&(int)$changed['version']===2&&
      $changed['startAt']===$h['startAt']&&$changed['timezone']===$h['timezone'],
      'native-handover-actual-execution-consumes-and-extends-one-current-row-with-original-start');
    $before=$fingerprint();$again=$service->executeHandover($intent['transfer_id'],true);
    $check($again['replayed']===true&&$fingerprint()===$before,'native-handover-committed-replay-does-not-consume-extend-or-audit-again');
    $above=$intent;$above['operation_id']='handover-above-op';$above['transfer_id']='handover-above-ticket';$above['expected_version']=2;$above['handover_days']=3;
    $exception=false;try{$service->previewHandover('extend',$above);}catch(InvalidArgumentException$error){$exception=$error->getMessage()==='organization_handover_cumulative_exception_required';}
    $check($exception,'native-handover-above-thirty-requires-distinct-exception-reason');
    $above['exception_reason']='Approved cumulative exception';$p=$service->previewHandover('extend',$above);
    $check($p['calendar']['cumulative_days']===31,'native-handover-above-thirty-preview-preserves-server-derived-total-and-explicit-exception');
    // A real SQL fault after ticket consumption must roll the entire workflow back.
    $service->proposeHandover('extend',$above,300);$d=$service->readHandoverApproval($above['transfer_id']);$review->approveHandover($above['transfer_id'],$d['request_digest']);
    if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$fixture->getNamespace())){throw new RuntimeException('handover_fixture_namespace_refused');}
    $trigger='`'.$fixture->getNamespace().'_user`.`handover_four_audit_fault`';
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('user','organization_audit')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-handover-four-fault'");$installed=true;
    $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$fixture->getNamespace()."_user' AND TRIGGER_NAME='handover_four_audit_fault'")->fetch_assoc();
    $check((int)$proof['n']===1,'native-handover-owned-audit-fault-trigger-actually-installed');
    $before=$fingerprint();$fault=false;try{$service->executeHandover($above['transfer_id'],true);}catch(AphrontQueryException$error){
      if($error->getCode()!==1644||strpos($error->getMessage(),'owned-handover-four-fault')===false){throw$error;}$fault=true;}
    $check($fault&&$fingerprint()===$before,'native-handover-real-audit-fault-rolls-consumption-deadline-outbox-and-ledger-back');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$installed=false;
    $service->executeHandover($above['transfer_id'],true);
    $disabled=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true);
    (new PhabricatorUserTransactionEditor())->setActor($state['users']['admin'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->applyTransactions($old,array($disabled));
    $check((string)(new PhabricatorUser())->loadOneWhere('phid=%s',$old->getPHID())->getIsDisabled()==='1',
      'native-handover-native-account-disable-is-not-blocked-by-responsibility');
    $replacement=array('operation_id'=>'handover-replace-op','transfer_id'=>'handover-replace-ticket','handover_id'=>$h['handoverID'],
      'expected_version'=>3,'responsible_phid'=>$new->getPHID(),'reason'=>'New designated responsible','acknowledge_no_project_permission_change'=>true);
    $service->proposeHandover('responsible',$replacement,300);$d=$service->readHandoverApproval($replacement['transfer_id']);$review->approveHandover($replacement['transfer_id'],$d['request_digest']);
    $service->executeHandover($replacement['transfer_id'],true);
    $now_h=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$h['handoverID']);
    $check($now_h['responsiblePHID']===$new->getPHID()&&(int)$now_h['version']===4&&
      !queryfx_one($conn,'SELECT id FROM organization_member WHERE BINARY userPHID=BINARY %s',$new->getPHID())&&
      !queryfx_one($conn,'SELECT id FROM organization_appointment WHERE BINARY userPHID=BINARY %s',$new->getPHID()),
      'native-handover-responsible-replacement-is-descriptive-with-no-team-membership-or-admin-grant');
    global $control;if(!($control instanceof mysqli)){throw new RuntimeException('native_concurrency_observer_required');}
    $race=$intent;$race['operation_id']='handover-race-op';$race['transfer_id']='handover-race-ticket';$race['expected_version']=4;
    $race['handover_days']=1;$race['exception_reason']='Explicit cumulative exception after reassignment';
    $service->proposeHandover('extend',$race,300);$d=$service->readHandoverApproval($race['transfer_id']);$review->approveHandover($race['transfer_id'],$d['request_digest']);
    $setup=$fixture->getAdmin();$setup->begin_transaction();$locked=true;$workers=array();
    try{
      governance_native_setup_query($fixture,'SELECT id FROM '.$fixture->table('user','organization_guard').' WHERE id=1 FOR UPDATE');
      foreach(array(0,1)as$unused){$workers[]=governance_native_start_worker($fixture,array('action'=>'org.handover.execute','token'=>$state['admin']['token'],
        'payload'=>array('transfer_id'=>$race['transfer_id'],'acknowledge_no_project_permission_change'=>true)));}
      foreach($workers as$worker){governance_native_concurrency_wait($fixture,$control,$worker,'organization_guard');}
      $check(true,'native-handover-real-parallel-executors-wait-on-existing-organization-lock-order');
      $setup->commit();$locked=false;$results=array();foreach($workers as&$worker){$results[]=governance_native_finish_worker($worker);}unset($worker);
      $fresh=0;$replays=0;foreach($results as$result){if($result['status']!=='committed'){throw new RuntimeException('handover_fixture_race_refused');}
        $result['replayed']?$replays++:$fresh++;}
      $current=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$h['handoverID']);
      $check($fresh===1&&$replays===1&&(int)$current['version']===5&&(int)$current['cumulativeDays']===32&&
        count(queryfx_all($conn,'SELECT id FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationOutbox(),$race['transfer_id']))===1,
        'native-handover-parallel-approved-execution-extends-once-and-replays-once');
    }finally{if($locked){$setup->rollback();}}
    $expiry=$race;$expiry['operation_id']='handover-expiry-op';$expiry['transfer_id']='handover-expiry-ticket';$expiry['expected_version']=5;
    $service->proposeHandover('extend',$expiry,8);$d=$service->readHandoverApproval($expiry['transfer_id']);$review->approveHandover($expiry['transfer_id'],$d['request_digest']);
    $expires=(int)queryfx_one($conn,'SELECT expiresAt FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$expiry['transfer_id'])['expiresAt'];
    $setup->begin_transaction();$locked=true;
    try{
      governance_native_concurrency_sql($fixture,'SELECT id FROM '.$fixture->table('user','organization_transfer').' WHERE transferID=? FOR UPDATE',array($expiry['transfer_id']));
      $worker=governance_native_start_worker($fixture,array('action'=>'org.handover.execute','token'=>$state['admin']['token'],
        'payload'=>array('transfer_id'=>$expiry['transfer_id'],'acknowledge_no_project_permission_change'=>true)));
      governance_native_concurrency_wait($fixture,$control,$worker,'organization_transfer');
      $check(true,'native-handover-expiry-worker-reaches-real-approval-row-wait-after-source-locks');
      $timeout=microtime(true)+12;while(governance_native_concurrency_now($fixture)<$expires){
        if(microtime(true)>$timeout){throw new RuntimeException('handover_fixture_expiry_wait_refused');}usleep(20000);}
      $setup->commit();$locked=false;$result=governance_native_finish_worker($worker);
      $check($result['status']==='rejected'&&$result['reason']==='organization_handover_approval_expired'&&
        (int)queryfx_one($conn,'SELECT version FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$h['handoverID'])['version']===5&&
        queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$expiry['transfer_id'])['state']==='approved'&&
        !queryfx_one($conn,'SELECT operationID FROM organization_operation WHERE BINARY operationID=BINARY %s',$expiry['operation_id']),
        'native-handover-approval-expiry-after-real-wait-cannot-consume-or-extend');
    }finally{if($locked){$setup->rollback();}}
    // The public native query must be re-run for every visible output.
    $visible=PhabricatorProject::initializeNewProject($state['admin']['viewer'])->setName('Visible handover project')->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
    $hidden=PhabricatorProject::initializeNewProject($state['admin']['viewer'])->setName('Private handover project')->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $receipt=array('operation_id'=>'handover-project-receipt','handover_id'=>$h['handoverID'],'handover_version'=>5,'project_phid'=>$visible->getPHID(),
      'decision'=>'remove','manual_status'=>'pending','expected_version'=>0,'note'=>'Manual follow-up only');
    $service->recordHandoverProject($receipt);$rows=$service->readHandoverProjects($h['handoverID']);
    $check(count($rows['rows'])===1&&$rows['rows'][0]['project_phid']===$visible->getPHID()&&$rows['exhaustive']===false&&
      $rows['native_project_permissions_changed']===false,'native-handover-manual-project-receipt-is-not-a-project-policy-mutation-or-exhaustive-list');
    $invisible=$receipt;$invisible['operation_id']='handover-hidden-receipt';$invisible['project_phid']=$hidden->getPHID();
    $check($reject(function()use($service,$invisible){$service->recordHandoverProject($invisible);},'organization_handover_project_unavailable'),
      'native-handover-current-native-viewer-query-refuses-hidden-project-receipts');
    $visible->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $rows=$service->readHandoverProjects($h['handoverID']);
    $check($rows['rows']===array()&&!isset($rows['total'])&&!isset($rows['hidden_count']),
      'native-handover-project-receipt-output-hides-now-invisible-reference-and-count');
    $check($reject(function()use($service,$receipt){$service->recordHandoverProject($receipt);},'organization_handover_project_unavailable'),
      'native-handover-committed-manual-replay-still-requires-current-project-visibility');
    $due=$service->readDueHandovers(100,null);$found=false;foreach($due['rows']as$row){if($row['handover_id']===$h['handoverID']){$found=true;}}
    $check(!$found&&$due['automatic_dispatch']===false,'native-handover-due-page-does-not-classify-future-deadline-as-due-or-dispatch');
    $termination=array('operation_id'=>'handover-end-op','transfer_id'=>'handover-end-ticket','handover_id'=>$h['handoverID'],
      'expected_version'=>5,'reason'=>'Explicit handover completion','acknowledge_no_project_permission_change'=>true);
    $service->proposeHandover('terminate',$termination,300);$d=$service->readHandoverApproval($termination['transfer_id']);$review->approveHandover($termination['transfer_id'],$d['request_digest']);
    $service->executeHandover($termination['transfer_id'],true);$ended=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$h['handoverID']);
    $check($ended['state']==='terminated'&&(int)$ended['version']===6&&
      queryfx_one($conn,'SELECT unitID FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$subject->getPHID())['unitID']==='handover-four-to',
      'native-handover-independent-termination-preserves-current-primary-department');
    // Explicit fixture setup models persisted historical state; it does not
    // claim an accelerated clock, a production migration, or elapsed-day proof.
    $historical=function($suffix,$ago)use($fixture,$make,$service,$review,$initial,$conn,$new){
      $u=$make($suffix,false);$service->assignPrimaryDepartment(array('operation_id'=>'historical-primary-'.$suffix,
        'user_phid'=>$u->getPHID(),'unit_id'=>'handover-four-from','expected_version'=>0));
      $i=$initial;$i['operation_id']='historical-transfer-'.$suffix;$i['transfer_id']='historical-ticket-'.$suffix;$i['subject_phid']=$u->getPHID();$i['responsible_phid']=$new->getPHID();
      $service->proposeTransfer($i,300);$d=$service->readTransfer($i['transfer_id']);$review->approveTransfer($i['transfer_id'],$d['request_digest']);
      $service->executeTransfer($i['transfer_id'],true);
      $h=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationHandover(),$i['transfer_id']);
      $now=governance_native_concurrency_now($fixture);$calendar=PhorgeOrganizationCalendar::preview($now-($ago+14)*86400,14,'UTC');
      governance_native_concurrency_sql($fixture,'UPDATE '.$fixture->table('user','organization_handover').
        ' SET startAt=?,deadlineAt=?,timezone=?,startLocal=?,deadlineLocal=?,startOffset=?,deadlineOffset=? WHERE handoverID=?',
        array($calendar['start_at'],$calendar['deadline_at'],$calendar['timezone'],$calendar['start_local'],$calendar['deadline_local'],
          $calendar['start_offset'],$calendar['deadline_offset'],$h['handoverID']));
      return queryfx_one($conn,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s',new PhorgeOrganizationHandover(),$h['handoverID']);
    };
    $historical_a=$historical('duea',2);$historical_b=$historical('dueb',1);
    $page=$service->readDueHandovers(1,null);$next=$service->readDueHandovers(1,$page['next_cursor']);
    $check(count($page['rows'])===1&&count($next['rows'])===1&&$page['rows'][0]['handover_id']===$historical_a['handoverID']&&
      $next['rows'][0]['handover_id']===$historical_b['handoverID'],
      'native-handover-historical-state-due-keyset-pages-progress-by-stored-deadline-and-id');
    $due_request=array('operation_id'=>'historical-due-record','handover_id'=>$historical_a['handoverID'],'expected_version'=>1);
    $before=$fingerprint();list($bad,$unused)=governance_native_route_response($state['admin'],'POST',array('json'=>'true','action'=>'handover.due.record','payload'=>phutil_json_encode($due_request)));
    $check($bad->getHTTPResponseCode()===403&&$fingerprint()===$before,'native-handover-due-event-real-post-without-csrf-has-no-ledger-or-outbox-effects');
    list($ok,$body)=$post($state['admin'],'handover.due.record',$due_request);
    $event_rows=queryfx_all($conn,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s AND eventType=%s',new PhorgeOrganizationOutbox(),$historical_a['handoverID'],'handover.due');
    $check($ok->getHTTPResponseCode()===200&&count($event_rows)===1&&$event_rows[0]['state']==='pending'&&(int)$event_rows[0]['attempts']===0&&$event_rows[0]['dispatchedAt']===null,
      'native-handover-explicit-due-post-persists-one-undispatched-reference-event');
    $before=$fingerprint();$r=$service->recordHandoverDue($due_request['operation_id'],$historical_a['handoverID'],1);
    $check($r['replayed']===true&&$fingerprint()===$before,'native-handover-due-operation-replay-does-not-multiply-events-or-audit');
    $service->recordHandoverDue('historical-due-another-operation',$historical_a['handoverID'],1);
    $event_rows=queryfx_all($conn,'SELECT * FROM %R WHERE BINARY handoverID=BINARY %s AND eventType=%s',new PhorgeOrganizationOutbox(),$historical_a['handoverID'],'handover.due');
    $check(count($event_rows)===1,'native-handover-different-operation-ids-cannot-amplify-one-deadline-event');
    $check($reject(function()use($service,$due_request,$historical_b){$service->recordHandoverDue($due_request['operation_id'],$historical_b['handoverID'],1);},'organization_operation_conflict'),
      'native-handover-due-operation-id-cannot-bind-another-handover');
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('user','organization_audit')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-handover-four-fault'");$installed=true;
    $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$fixture->getNamespace()."_user' AND TRIGGER_NAME='handover_four_audit_fault'")->fetch_assoc();
    $check((int)$proof['n']===1,'native-handover-due-fault-trigger-is-owned-and-actually-installed');
    $before=$fingerprint();$fault=false;try{$service->recordHandoverDue('historical-due-fault',$historical_b['handoverID'],1);}
    catch(AphrontQueryException$error){if($error->getCode()!==1644||strpos($error->getMessage(),'owned-handover-four-fault')===false){throw$error;}$fault=true;}
    $check($fault&&$fingerprint()===$before,'native-handover-due-audit-fault-rolls-new-event-and-operation-ledger-back');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$installed=false;
    return array('native_extension_termination_responsible_backend'=>true,'independent_native_approval'=>true,'cumulative_server_derived'=>true,
      'manual_project_query_filtered'=>true,'due_future_negative_exercised'=>true,'positive_historical_due_fixture_exercised'=>true,
      'new_concurrency_exercised'=>true,'disabled_responsible_replacement_exercised'=>true,'native_ui_forms_exercised'=>false,
      'dispatcher_registered'=>false,'production_activation'=>false);
  }finally{if($installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}$_COOKIE=$cookies;$_SERVER=$server;}
}
