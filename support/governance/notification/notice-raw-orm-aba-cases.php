<?php
/** Diagnose the real native DAO entry, not the transaction-editor profile. */
function governance_notice_raw_orm_projection(array $facts){
  $bools=array('notifications_enabled','recovery_enabled','before_allowed','after_allowed','disabled_commit_observed',
    'enabled_commit_observed','user_transaction_source_changed','legacy_epoch_source_changed','binding_account_hash_unchanged');
  $keys=array_keys($facts);sort($keys);$expected=array_merge($bools,array('actual_native_DAO_saves','intermediate_policy_calls'));sort($expected);
  if($keys!==$expected){throw new RuntimeException('notice_raw_orm_projection_refused');}
  foreach($bools as$key){if(!is_bool($facts[$key])){throw new RuntimeException('notice_raw_orm_projection_refused');}}
  if($facts['actual_native_DAO_saves']!==2||$facts['intermediate_policy_calls']!==0){throw new RuntimeException('notice_raw_orm_projection_refused');}
  return$facts;
}
function governance_notice_raw_orm_aba_cases($fixture,callable $check){
  $fixture->assertOwned();require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
  require_once dirname(__DIR__).'/native-organization/native-cases.php';
  $queue=null;$scope=null;$user=null;$failure=null;$result=null;$cleanup_failed=false;
  try{
    if((new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){throw new RuntimeException('notice_raw_orm_background_execution_refused');}
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled',true)
      ->overrideEnvConfig('governance.private-mail.enabled',true)->overrideEnvConfig('governance.organization.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.enabled',false)->overrideEnvConfig('governance.recovery-stop-ledger.enabled',false)
      ->overrideEnvConfig('metamta.gorge-delivery-mode','native')->overrideEnvConfig('gorge.taskqueue.owner','gorge')
      ->overrideEnvConfig('gorge.taskqueue.uri',$queue['uri'])->overrideEnvConfig('gorge.taskqueue.token',$queue['token'])
      ->overrideEnvConfig('gorge.service-policies',array('file'=>'required','taskqueue'=>'required'));
    $check(PhabricatorEnv::getEnvConfig('governance.notifications.enabled')===true&&
      PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')===false,
      'notice-raw-orm-profile-notifications-on-restoration-guard-off');
    $user=(new PhabricatorUser())->setUsername('gnrawaba'.substr($fixture->getNamespace(),-12))
      ->setRealName('Synthetic raw DAO notice recipient')->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $auth=governance_native_request($user,new PhabricatorAuthSessionEngine());
    $service=PhorgeOrganizationService::newForActor($auth['context']);
    $service->createUnit(array('operation_id'=>'notice-raw-orm-aba','unit_id'=>'notice-raw-orm-aba','kind'=>'team',
      'name'=>'SYNTHETIC_PRIVATE_RAW_DAO_NOTICE','parent_id'=>null));
    $meta=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
    $event=queryfx_one($meta,'SELECT eventID FROM governance_notice_event WHERE operationDigest=%s',hash('sha256','notice-raw-orm-aba'));
    if(!$event){throw new RuntimeException('notice_raw_orm_event_missing');}
    (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$event['eventID'])))->executeTask();
    $mapping=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',$event['eventID'],$user->getPHID());
    if(!$mapping){throw new RuntimeException('notice_raw_orm_mapping_missing');}
    $mail=(new PhabricatorMetaMTAMail())->loadOneWhere('BINARY phid=BINARY %s',$mapping['mailPHID']);
    if(!$mail||$mail->getPHID()!==$mapping['mailPHID']){throw new RuntimeException('notice_raw_orm_mail_missing');}
    $before_allowed=PhorgePrivateMailPolicy::authorize($mail);
    $check($before_allowed,'notice-raw-orm-current-real-producer-binding-authorized-before-ABA');
    $conn=$user->establishConnection('w');
    if($conn->isInsideTransaction()||$conn->isReadLocking()||$conn->isWriteLocking()){
      throw new RuntimeException('notice_raw_orm_inherited_transaction_refused');}
    $sources=static function()use($conn,$user){
      $transaction=queryfx_one($conn,'SELECT COALESCE(MAX(id),0) n FROM %R WHERE objectPHID=%s',new PhabricatorUserTransaction(),$user->getPHID());
      $legacy=queryfx_one($conn,'SELECT epoch FROM %R WHERE BINARY userPHID=BINARY %s',new PhorgeGovernanceIdentityEpoch(),$user->getPHID());
      return array((string)$transaction['n'],$legacy?(string)$legacy['epoch']:null);
    };
    $before_sources=$sources();$binding=$mail->getPrivateMailPolicyValue('governance.notice-binding');
    // These are the actual native ORM production entry points. No editor,
    // synthetic user transaction, accountSecret rotation or epoch increment.
    $disabled=(new PhabricatorUser())->load($user->getID());$disabled->setIsDisabled(1)->save();
    $committed=queryfx_one($conn,'SELECT isDisabled FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$user->getPHID());
    $disabled_observed=$committed!==null&&(string)$committed['isDisabled']==='1'&&!$conn->isInsideTransaction();
    $check($disabled_observed,'notice-raw-orm-native-DAO-disable-really-committed-without-policy-probe');
    $enabled=(new PhabricatorUser())->load($user->getID());$enabled->setIsDisabled(0)->save();
    $committed=queryfx_one($conn,'SELECT isDisabled FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$user->getPHID());
    $enabled_observed=$committed!==null&&(string)$committed['isDisabled']==='0'&&!$conn->isInsideTransaction();
    $check($enabled_observed,'notice-raw-orm-native-DAO-enable-really-committed-before-new-policy-probe');
    $after_sources=$sources();
    $fresh=(new PhabricatorMetaMTAMail())->load($mail->getID());
    if(!$fresh||$fresh->getPHID()!==$mail->getPHID()){throw new RuntimeException('notice_raw_orm_mail_reload_refused');}
    // authorize() owns a new native request-cache scope and current SQL reads.
    $after_allowed=PhorgePrivateMailPolicy::authorize($fresh);
    $method=new ReflectionMethod(PhorgeGovernanceNoticeMailPolicy::class,'accountDigest');
    $current_hash=$method->invoke(null,$user->getPHID());
    $facts=governance_notice_raw_orm_projection(array('notifications_enabled'=>PhabricatorEnv::getEnvConfig('governance.notifications.enabled'),
      'recovery_enabled'=>PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled'),
      'before_allowed'=>$before_allowed,'after_allowed'=>$after_allowed,'disabled_commit_observed'=>$disabled_observed,
      'enabled_commit_observed'=>$enabled_observed,'user_transaction_source_changed'=>$before_sources[0]!==$after_sources[0],
      'legacy_epoch_source_changed'=>$before_sources[1]!==$after_sources[1],
      'binding_account_hash_unchanged'=>hash_equals($binding['account_hash'],$current_hash),
      'actual_native_DAO_saves'=>2,'intermediate_policy_calls'=>0));
    $GLOBALS['governance_notice_raw_orm_facts']=$facts;
    // Required safety expectation: an old binding must NOT revive. If the
    // current source has the suspected gap this case intentionally fails.
    $check($after_allowed===false,'notice-raw-orm-disable-enable-cannot-revive-old-binding-with-notifications-only');
    $result=array('native_raw_DAO_profile_proved'=>true,'old_binding_revival_refused'=>true,'facts'=>$facts,
      'UserEditor_substitute_used'=>false,'SMTP_proved'=>false,'global_account_sources_complete'=>false);
  }catch(Throwable$error){$failure=$error;}
  finally{
    unset($scope);
    if($queue!==null){try{$stopped=governance_completion_stop_gorge($queue);
      if(!$stopped['process_stopped']||!$stopped['private_storage_removed']){$cleanup_failed=true;}}
      catch(Throwable$error){$cleanup_failed=true;}}
  }
  $GLOBALS['governance_notice_raw_orm_queue_cleanup_proved']=!$cleanup_failed;
  if($failure!==null&&$cleanup_failed){throw new PhutilAggregateException('notice_raw_orm_primary_and_cleanup_failed',array($failure,new RuntimeException('notice_raw_orm_queue_cleanup_failed')));}
  if($failure!==null){throw$failure;}if($cleanup_failed){throw new RuntimeException('notice_raw_orm_queue_cleanup_failed');}
  return$result;
}
