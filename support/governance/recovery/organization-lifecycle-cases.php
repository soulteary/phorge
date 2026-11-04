<?php

/** Prepared isolated native probes. Not hooked into a passed component profile. */
function governance_recovery_org_lifecycle_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('recovery_org_lifecycle_outer_transaction');}
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;$environment=null;$flags=null;$primary=null;
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
  }catch(Throwable$error){$primary=$error;throw$error;}finally{
    $errors=array();
    try{if($trigger){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger_name);}}
    catch(Throwable$error){$errors['trigger']=$error;}
    try{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    catch(Throwable$error){$errors['transaction']=$error;}
    try{if($environment!==null){governance_recovery_account_restore_fixture_environment($environment);}}
    catch(Throwable$error){$errors['environment']=$error;}
    try{if($flags!==null){$flags->endScopedEnv();}}
    catch(Throwable$error){$errors['flags']=$error;}
    $_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}
    if($errors){if($primary!==null){$errors=array('original'=>$primary)+$errors;}throw new PhutilAggregateException('recovery_org_lifecycle_cleanup_failed',$errors);}
  }
}
