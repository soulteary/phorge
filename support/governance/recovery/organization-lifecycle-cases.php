<?php

/** Prepared isolated native probes. Not hooked into a passed component profile. */
function governance_recovery_org_lifecycle_cases($fixture,callable $check,array $state) {
  global $control;
  $fixture->assertOwned();$conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('recovery_org_lifecycle_outer_transaction');}
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;$environment=null;$flags=null;$primary=null;$handles=array();
  $trigger=false;$trigger_name='`'.$fixture->getNamespace().'_user`.`recovery_lifecycle_audit_fault`';
  $read=static function()use($conn){return PhorgeRecoveryStopRequest::transactionID(
    queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version']);};
  $switch=static function(array $request){$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$request['token'];
    $_SERVER['REQUEST_METHOD']='POST';};
  $source=static function($subject,$context)use($conn){
    if($conn->isInsideTransaction()){throw new RuntimeException('recovery_org_lifecycle_capture_outer');}
    $conn->openTransaction();
    try{
      $phids=array_unique(array($subject,$context->getUserPHID()));sort($phids,SORT_STRING);
      foreach($phids as$phid){PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($phid,$conn);}
      $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      if(!$actor->isSystemAdmin()){throw new RuntimeException('recovery_org_lifecycle_admin');}
      $facts=PhorgeRecoveryRestoreOrganizationSource::capture($conn,$subject);
      PhorgeGovernanceNativeIdentitySource::revalidate($actor,$conn);return$facts;
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
  };
  try{
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    $first=governance_native_request($state['users']['admin'],$state['sessions']);
    $second_user=(new PhabricatorUser())->setUsername('rl'.substr($fixture->getNamespace(),-12))
      ->setRealName('Synthetic lifecycle reviewer')->setIsApproved(1)->setIsAdmin(1)->setIsEmailVerified(1)->save();
    $second=governance_native_request($second_user,$state['sessions']);
    // Initialize the reviewer outside the observed production transactions.
    $conn->openTransaction();try{PhorgeGovernanceNativeIdentitySource::lockCurrent($second['context'],$conn);
      $conn->saveTransaction();}finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $switch($first);$service=PhorgeOrganizationService::newForActor($first['context']);
    $reviewer=PhorgeOrganizationService::newForActor($second['context']);
    $subject=$state['users']['member']->getPHID();$team='recovery-lifecycle-team';
    $unit=array('operation_id'=>'recovery-lifecycle-create','unit_id'=>$team,'kind'=>'team',
      'name'=>'Synthetic lifecycle team','parent_id'=>null);
    $before=$read();$service->createUnit($unit);
    $check($read()===PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($before),
      'recovery-org-service-audit-record-advances-one-generation-not-two');
    $before=$read();$service->createUnit($unit);
    $check($read()===$before,'recovery-org-service-operation-replay-does-not-advance-generation');
    $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
    // Native appointment approval requires real active team membership.
    $service->execute(array('operation_id'=>'recovery-lifecycle-member','mutations'=>array(array(
      'action'=>'member.add','team'=>$team,'target_user'=>$subject,'valid_from'=>$now-60,'valid_until'=>null,
      'approval_id'=>null,'expected_team_version'=>1,'expected_member_version'=>0,'expected_appointment_version'=>0))));
    $proposal=array('approval_id'=>'recovery-lifecycle-approval','operation_id'=>'recovery-lifecycle-grant',
      'team'=>$team,'action'=>'appointment.grant','target_user'=>$subject,
      'valid_from'=>$now,'valid_until'=>null,'ttl_seconds'=>120);
    $before=$read();$receipt=$service->proposeApproval($proposal);
    $row=queryfx_one($conn,'SELECT * FROM organization_approval WHERE approvalID=%s',$proposal['approval_id']);
    $check($receipt['state']==='submitted'&&$read()===PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($before)&&
      (string)$row['sourceParticipantVersion']==='1'&&$row['sourceSubjectPHID']===$subject&&
      $row['sourceRequesterPHID']===$first['viewer']->getPHID()&&$row['sourceExecutorPHID']===$first['viewer']->getPHID(),
      'recovery-org-real-proposal-persists-exact-derived-participants-and-one-generation');
    $before=$read();$receipt=$service->proposeApproval($proposal);
    $check($receipt['replayed']===true&&$read()===$before,
      'recovery-org-real-proposal-replay-preserves-generation');
    $switch($second);$before=$read();$approved=$reviewer->approveApproval($proposal['approval_id']);
    $check($approved['state']==='approved'&&$read()===PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($before),
      'recovery-org-real-independent-approval-advances-one-generation');
    $before=$read();$approved=$reviewer->approveApproval($proposal['approval_id']);
    $check($approved['replayed']===true&&$read()===$before,
      'recovery-org-real-approval-replay-preserves-generation');
    $switch($first);$facts=$source($subject,$first['context']);
    $check($facts['provider_schema']===2&&count($facts['lifecycle_row_digests']['organization_approval'])===1&&
      $facts['complete']===false&&$facts['restoration_authorized']===false,
      'recovery-org-current-lifecycle-source-captures-real-approval-without-claiming-all-sources');
    // Fail after generation advancement but before the audit can persist.
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger_name.' BEFORE INSERT ON '.
      $fixture->table('user','organization_audit')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recovery_lifecycle_fixture_fault'");$trigger=true;
    $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
      $fixture->getNamespace()."_user' AND TRIGGER_NAME='recovery_lifecycle_audit_fault'");
    $installed=(int)$proof->fetch_assoc()['n']===1;$proof->free();
    if(!$installed){throw new RuntimeException('recovery_org_lifecycle_trigger_missing');}
    $before=$read();$failed=$proposal;$failed['approval_id']='recovery-lifecycle-rollback';$failed['operation_id']='recovery-lifecycle-rollback';
    $refused=false;
    try{$service->proposeApproval($failed);}catch(AphrontQueryException$error){
      if(get_class($error)!==AphrontQueryException::class||$error->getCode()!==1644){throw$error;}$refused=true;
    }
    $check($refused&&$read()===$before&&!$conn->isInsideTransaction()&&
      (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_approval WHERE approvalID=%s',$failed['approval_id'])['n']===0,
      'recovery-org-proposal-audit-fault-rolls-back-ticket-and-generation');
    // Approval rollback uses the same real trigger, not a fabricated failure.
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger_name);$trigger=false;
    $service->proposeApproval($failed);$switch($second);
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger_name.' BEFORE INSERT ON '.
      $fixture->table('user','organization_audit')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recovery_lifecycle_fixture_fault'");$trigger=true;
    $before=$read();$refused=false;
    try{$reviewer->approveApproval($failed['approval_id']);}catch(AphrontQueryException$error){
      if(get_class($error)!==AphrontQueryException::class||$error->getCode()!==1644){throw$error;}$refused=true;
    }
    $ticket=queryfx_one($conn,'SELECT state,approverPHID FROM organization_approval WHERE approvalID=%s',$failed['approval_id']);
    $check($refused&&$read()===$before&&!$conn->isInsideTransaction()&&$ticket['state']==='submitted'&&$ticket['approverPHID']===null,
      'recovery-org-approval-audit-fault-rolls-back-review-and-generation');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger_name);$trigger=false;
    $switch($first);
    $make=static function($suffix)use($fixture){
      $user=(new PhabricatorUser())->setUsername('rl'.$suffix.substr($fixture->getNamespace(),-9))
        ->setRealName('Synthetic lifecycle participant')->setIsApproved(1)->setIsAdmin(0)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
        ->setIsPrimary(1)->setIsVerified(1)->save();return$user;
    };
    $moving=$make('moving');$owner=$make('owner');
    foreach(array('recovery-lifecycle-from','recovery-lifecycle-to')as$department){
      $service->createUnit(array('operation_id'=>'create-'.$department,'unit_id'=>$department,
        'kind'=>'department','name'=>'Synthetic lifecycle department','parent_id'=>null));
    }
    $service->assignPrimaryDepartment(array('operation_id'=>'recovery-lifecycle-primary','user_phid'=>$moving->getPHID(),
      'unit_id'=>'recovery-lifecycle-from','expected_version'=>0));
    $intent=array('operation_id'=>'recovery-lifecycle-transfer-op','transfer_id'=>'recovery-lifecycle-transfer',
      'subject_phid'=>$moving->getPHID(),'responsible_phid'=>$owner->getPHID(),
      'from_department'=>'recovery-lifecycle-from','to_department'=>'recovery-lifecycle-to',
      'primary_version'=>1,'from_version'=>1,'to_version'=>1,'reason'=>'Synthetic generation probe',
      'acknowledge_no_project_permission_change'=>true);
    $observe=static function(callable$call,$replay,$id)use($read,$check){
      $before=$read();$result=$call();$expected=$replay?$before:PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($before);
      $check($read()===$expected&&$result['replayed']===$replay,$id);return$result;
    };
    $fault=static function(callable$call,$id)use($read,$check,$fixture,$trigger_name,&$trigger,$conn){
      if($trigger){throw new RuntimeException('recovery_org_lifecycle_fault_already_installed');}
      governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger_name.' BEFORE INSERT ON '.
        $fixture->table('user','organization_audit')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recovery_lifecycle_fixture_fault'");$trigger=true;
      $before=$read();$fingerprint=static function()use($conn){$rows=array();
        foreach(array('guard','unit','member','appointment','primarydepartment','transfer','handover','outbox','projectdisposition','audit','operation')as$table){
          $key=$table==='operation'?'operationID':'id';
          $rows[$table]=queryfx_all($conn,'SELECT * FROM %T ORDER BY %C','organization_'.$table,$key);
        }
        return PhorgeRecoveryRestoreRequest::digest($rows);
      };
      $original=$fingerprint();$denied=false;
      try{$call();}catch(AphrontQueryException$error){
        if(get_class($error)!==AphrontQueryException::class||$error->getCode()!==1644){throw$error;}$denied=true;
      }finally{
        governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger_name);$trigger=false;
      }
      $check($denied&&$read()===$before&&!$conn->isInsideTransaction()&&$fingerprint()===$original,$id);
    };
    $fault(static fn()=>$service->proposeTransfer($intent,300),
      'recovery-org-transfer-proposal-audit-fault-rolls-back-domain-and-generation');
    $observe(static fn()=>$service->proposeTransfer($intent,300),false,
      'recovery-org-transfer-proposal-advances-one-generation');
    $observe(static fn()=>$service->proposeTransfer($intent,300),true,
      'recovery-org-transfer-proposal-replay-preserves-generation');
    $detail=$service->readTransfer($intent['transfer_id']);$switch($second);
    $fault(static fn()=>$reviewer->approveTransfer($intent['transfer_id'],$detail['request_digest']),
      'recovery-org-transfer-approval-audit-fault-rolls-back-domain-and-generation');
    $observe(static fn()=>$reviewer->approveTransfer($intent['transfer_id'],$detail['request_digest']),false,
      'recovery-org-transfer-approval-advances-one-generation');
    $observe(static fn()=>$reviewer->approveTransfer($intent['transfer_id'],$detail['request_digest']),true,
      'recovery-org-transfer-approval-replay-preserves-generation');
    $switch($first);
    $fault(static fn()=>$service->executeTransfer($intent['transfer_id'],true),
      'recovery-org-transfer-execution-audit-fault-rolls-back-domain-and-generation');
    $observe(static fn()=>$service->executeTransfer($intent['transfer_id'],true),false,
      'recovery-org-transfer-execution-audit-record-advances-once');
    $observe(static fn()=>$service->executeTransfer($intent['transfer_id'],true),true,
      'recovery-org-transfer-execution-replay-preserves-generation');
    $h=queryfx_one($conn,'SELECT * FROM organization_handover WHERE transferID=%s',$intent['transfer_id']);
    if(!$h){throw new RuntimeException('recovery_org_lifecycle_handover_missing');}
    $extension=array('operation_id'=>'recovery-lifecycle-extension-op','transfer_id'=>'recovery-lifecycle-extension',
      'handover_id'=>$h['handoverID'],'expected_version'=>1,'handover_days'=>14,
      'reason'=>'Synthetic actual extension','acknowledge_no_project_permission_change'=>true);
    $fault(static fn()=>$service->proposeHandover('extend',$extension,300),
      'recovery-org-handover-proposal-audit-fault-rolls-back-domain-and-generation');
    $observe(static fn()=>$service->proposeHandover('extend',$extension,300),false,
      'recovery-org-handover-proposal-advances-one-generation');
    $observe(static fn()=>$service->proposeHandover('extend',$extension,300),true,
      'recovery-org-handover-proposal-replay-preserves-generation');
    $detail=$service->readHandoverApproval($extension['transfer_id']);$switch($second);
    $fault(static fn()=>$reviewer->approveHandover($extension['transfer_id'],$detail['request_digest']),
      'recovery-org-handover-approval-audit-fault-rolls-back-domain-and-generation');
    $observe(static fn()=>$reviewer->approveHandover($extension['transfer_id'],$detail['request_digest']),false,
      'recovery-org-handover-approval-advances-one-generation');
    $observe(static fn()=>$reviewer->approveHandover($extension['transfer_id'],$detail['request_digest']),true,
      'recovery-org-handover-approval-replay-preserves-generation');
    $switch($first);
    $fault(static fn()=>$service->executeHandover($extension['transfer_id'],true),
      'recovery-org-handover-execution-audit-fault-rolls-back-domain-and-generation');
    $observe(static fn()=>$service->executeHandover($extension['transfer_id'],true),false,
      'recovery-org-handover-execution-audit-record-advances-once');
    $observe(static fn()=>$service->executeHandover($extension['transfer_id'],true),true,
      'recovery-org-handover-execution-replay-preserves-generation');
    // Stored discovery metadata is never allowed to disagree with the binding.
    $original=queryfx_one($conn,'SELECT * FROM organization_approval WHERE approvalID=%s',$proposal['approval_id']);
    $restore_participants=static function()use($conn,$proposal,$original){
      queryfx($conn,'UPDATE organization_approval SET sourceParticipantVersion=%d,sourceSubjectPHID=%s,'.
        'sourceRequesterPHID=%s,sourceExecutorPHID=%s,bindingJSON=%s WHERE approvalID=%s',
        (int)$original['sourceParticipantVersion'],$original['sourceSubjectPHID'],$original['sourceRequesterPHID'],
        $original['sourceExecutorPHID'],$original['bindingJSON'],$proposal['approval_id']);
    };
    $exact_refusal=static function(callable$call,$reason){
      try{$call();}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!==$reason){throw$error;}return true;
      }return false;
    };
    try{
      queryfx($conn,'UPDATE organization_approval SET sourceRequesterPHID=%s WHERE approvalID=%s',$subject,$proposal['approval_id']);
      $before=$read();
      $check($exact_refusal(static fn()=>$service->approveApproval($proposal['approval_id']),
          'organization_approval_participants_refused')&&
        $exact_refusal(static fn()=>$source($subject,$first['context']),'organization_approval_participants_refused')&&
        $read()===$before&&!$conn->isInsideTransaction(),
        'recovery-org-tampered-derived-role-refused-by-native-load-and-current-source');
    }finally{$restore_participants();}
    try{
      queryfx($conn,'UPDATE organization_approval SET sourceParticipantVersion=0,sourceSubjectPHID=NULL,'.
        'sourceRequesterPHID=NULL,sourceExecutorPHID=NULL WHERE approvalID=%s',$proposal['approval_id']);
      $before=$read();
      $check($exact_refusal(static fn()=>$source($subject,$first['context']),
          'organization_approval_participants_unindexed')&&$read()===$before&&!$conn->isInsideTransaction(),
        'recovery-org-real-legacy-unindexed-row-hard-blocks-current-absence');
      // Only this known synthetic row is backfilled from its existing binding.
      $data=json_decode($original['bindingJSON'],true,16,JSON_THROW_ON_ERROR);
      $derived=PhorgeGovernanceNativeApprovalAdapter::participantMetadata($data);
      queryfx($conn,'UPDATE organization_approval SET sourceParticipantVersion=1,sourceSubjectPHID=%s,'.
        'sourceRequesterPHID=%s,sourceExecutorPHID=%s WHERE approvalID=%s',
        $derived['sourceSubjectPHID'],$derived['sourceRequesterPHID'],$derived['sourceExecutorPHID'],$proposal['approval_id']);
      $after=$source($subject,$first['context']);
      $stored=queryfx_one($conn,'SELECT * FROM organization_approval WHERE approvalID=%s',$proposal['approval_id']);
      $check($stored===$original&&$after['complete']===false&&$after['restoration_authorized']===false,
        'recovery-org-isolated-known-binding-backfill-preserves-every-ticket-byte-and-no-authority');
    }finally{$restore_participants();}
    try{
      queryfx($conn,'UPDATE organization_approval SET bindingJSON=%s WHERE approvalID=%s',str_repeat('x',32769),$proposal['approval_id']);
      $check($exact_refusal(static fn()=>$source($subject,$first['context']),'recovery_restore_organization_budget')&&
        !$conn->isInsideTransaction(),
        'recovery-org-real-oversized-binding-refused-before-payload-decode');
    }finally{$restore_participants();}
    $guard_before=$read();
    try{
      queryfx($conn,'UPDATE organization_guard SET version=18446744073709551615 WHERE id=1');
      $overflow=$proposal;$overflow['approval_id']='recovery-lifecycle-overflow';$overflow['operation_id']='recovery-lifecycle-overflow';
      $check($exact_refusal(static fn()=>$service->proposeApproval($overflow),
          'recovery_org_producer_generation_exhausted')&&$read()==='18446744073709551615'&&
        (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_approval WHERE approvalID=%s',$overflow['approval_id'])['n']===0&&
        !$conn->isInsideTransaction(),
        'recovery-org-real-uint64-exhaustion-rolls-back-proposal-without-wrap');
    }finally{queryfx($conn,'UPDATE organization_guard SET version=CAST(%s AS UNSIGNED) WHERE id=1',$guard_before);}
    if(!($control instanceof mysqli)){throw new RuntimeException('recovery_org_lifecycle_observer_required');}
    foreach(array(false,true)as$rollback){
      $handle=governance_native_start_worker($fixture,array('action'=>'recovery.org.approval.propose',
        'token'=>$second['token'],'subject'=>$subject));$handles[]=$handle;
      governance_native_wait_lifecycle_producer_ready($handle);
      $race_id='recovery-lifecycle-race-'.substr($handle['nonce'],0,16);
      $before=$read();$conn->openTransaction();
      try{
        $phids=array_unique(array($subject,$first['context']->getUserPHID()));sort($phids,SORT_STRING);
        foreach($phids as$phid){PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($phid,$conn);}
        $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($first['context'],$conn);
        if(!$actor->isSystemAdmin()){throw new RuntimeException('recovery_org_lifecycle_admin');}
        $held=PhorgeRecoveryRestoreOrganizationSource::capture($conn,$subject);
        $parent_thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
        governance_native_continue_lifecycle_producer($handle);
        $waiting=false;$deadline=microtime(true)+4;
        do{
          $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
            'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
            'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
            'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
            'WHERE r.PROCESSLIST_ID='.(int)$handle['mysql_thread'].' AND b.PROCESSLIST_ID='.$parent_thread.
            " AND l.OBJECT_SCHEMA='".$control->real_escape_string($fixture->getNamespace().'_user').
            "' AND l.OBJECT_NAME='organization_guard' AND l.INDEX_NAME='PRIMARY'".
            " AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='WAITING' AND l.LOCK_DATA='1'";
          $query=$control->query($sql);if(!$query){throw new RuntimeException('recovery_org_lifecycle_wait_observation');}
          $waiting=(int)$query->fetch_assoc()['n']>0;$query->free();if($waiting){break;}usleep(20000);
        }while(microtime(true)<$deadline);
        $check($waiting&&$conn->isInsideTransaction()&&$read()===$before&&
          (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_approval WHERE approvalID=%s',$race_id)['n']===0,
          $rollback?'recovery-org-real-service-proposal-waits-current-source-until-parent-rollback':
            'recovery-org-real-service-proposal-waits-current-source-until-parent-commit');
        PhorgeGovernanceNativeIdentitySource::revalidate($actor,$conn);
        if($rollback){$conn->killTransaction();}else{$conn->saveTransaction();}
      }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
      $result=governance_native_finish_worker($handle);
      $stored=queryfx_one($conn,'SELECT * FROM organization_approval WHERE approvalID=%s',$race_id);
      $after=$source($subject,$first['context']);
      $check($stored&&$result['native_approval_digest']===PhorgeRecoveryRestoreRequest::digest($stored)&&
        $read()===PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($before)&&
        PhorgeRecoveryRestoreRequest::digest($held)!==PhorgeRecoveryRestoreRequest::digest($after)&&
        $stored['sourceSubjectPHID']===$subject&&$stored['sourceRequesterPHID']===$second['viewer']->getPHID()&&
        $result['restoration_authorized']===false&&!$conn->isInsideTransaction(),
        $rollback?'recovery-org-real-service-proposal-after-parent-rollback-commits-one-new-source-generation':
          'recovery-org-real-service-proposal-after-parent-commit-commits-one-new-source-generation');
    }
  }catch(Throwable$error){$primary=$error;throw$error;}finally{
    $errors=array();
    try{if($handles){governance_native_stop_fixture_handles($handles);}}
    catch(Throwable$error){$errors['workers']=$error;}
    try{if($trigger){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger_name);}}
    catch(Throwable$error){$errors['trigger']=$error;}
    try{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    catch(Throwable$error){$errors['transaction']=$error;}
    try{if($environment!==null){governance_recovery_account_restore_fixture_environment($environment);}}
    catch(Throwable$error){$errors['environment']=$error;}
    try{unset($flags);}
    catch(Throwable$error){$errors['flags']=$error;}
    $_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}
    if($errors){if($primary!==null){$errors=array('original'=>$primary)+$errors;}throw new PhutilAggregateException('recovery_org_lifecycle_cleanup_failed',$errors);}
  }
}
