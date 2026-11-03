<?php

/** Real native configured-TOTP one-use editor tokens, not a test MFA bypass. */
function governance_project_mfa_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$approver=$state['approver'];
  $factor=new PhabricatorTOTPAuthFactor();
  $provider=PhabricatorAuthFactorProvider::initializeNewProvider($factor)->setName('Synthetic Native TOTP')->save();
  $sessions=new PhabricatorAuthSessionEngine();
  $create=function($label,$admin=false) use($factor,$provider,$sessions){
    $user=(new PhabricatorUser())->setUsername('mfa'.$label.bin2hex(random_bytes(5)))
      ->setRealName('Synthetic Native MFA')->setIsApproved(1)->setIsAdmin($admin?1:0)->save();
    $secret=PhabricatorTOTPAuthFactor::generateNewTOTPKey();
    $config=(new PhabricatorAuthFactorConfig())->setUserPHID($user->getPHID())
      ->setFactorProviderPHID($provider->getPHID())->setFactorName('Synthetic Native TOTP')
      ->setFactorSecret($secret)->save();
    $user->updateMultiFactorEnrollment();
    $web=governance_native_request($user,$sessions);
    return array('user'=>$user,'factor'=>$config,'secret'=>$secret,'web'=>$web);
  };
  $target=function($identity,$name,$admin_policy=false) use($approver){
    $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
    try{$project=PhabricatorProject::initializeNewProject($identity['web']['viewer'])->setName($name)
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy($admin_policy?PhabricatorPolicies::POLICY_ADMIN:$identity['user']->getPHID())
      ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($off);}
    $ticket=PhorgeProjectPolicyApprovalService::call($identity['web']['context'],'submit',array(
      'target_phid'=>$project->getPHID(),'new_policy'=>PhabricatorPolicies::POLICY_USER,
      'reason'=>'Synthetic native configured factor','ttl_seconds'=>240));
    PhorgeProjectPolicyApprovalService::call($approver['context'],'approve',array('approval_id'=>$ticket['approval_id']));
    return array('project'=>$project,'ticket'=>$ticket['approval_id'],'new_policy'=>PhabricatorPolicies::POLICY_USER);
  };
  $request=function($identity,array $data=array()){
    $web=$identity['web'];$_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$web['token'];
    $web['request']->setRequestData(array(AphrontRequest::getCSRFTokenName()=>$web['viewer']->getCSRFToken())+$data);
    $web['request']->validateCSRF();return $web['request'];
  };
  $execute=function($identity,$work,array $data=array()) use($request){
    $r=$request($identity,$data);
    return (new PhabricatorProjectTransactionEditor())->setActor($identity['web']['viewer'])
      ->setRequest($r)->setCancelURI('/project/')->setShouldRequireMFA(true)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->setProjectApprovalID($work['ticket'])->setContinueOnMissingFields(true)
      ->applyTransactions($work['project'],array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue($work['new_policy']??PhabricatorPolicies::POLICY_USER)));
  };
  $snapshot=function($work){
    $conn=$work['project']->establishConnection('w');
    return array('project'=>queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$work['project']->getID()),
      'ticket'=>queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$work['ticket']),
      'native'=>(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorProjectTransaction(),$work['project']->getPHID())['n'],
      'audit'=>(int)queryfx_one($conn,'SELECT COUNT(*) n FROM governance_executionaudit WHERE approvalID=%s AND action=%s',$work['ticket'],'executed')['n'],
      'publishing'=>(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorWorkerActiveTask(),$work['project']->getPHID())['n']);
  };
  $prompt=function($identity,$work,array $data,$name,$wait=false) use($execute,$snapshot,$check){
    $before=$snapshot($work);$error=null;
    try{$execute($identity,$work,$data);}catch(PhabricatorAuthHighSecurityRequiredException $ex){$error=$ex;}
    $actual_wait=false;
    if($error!==null){foreach($error->getFactorValidationResults() as $result){$actual_wait=$actual_wait||$result->getIsWait();}}
    $check($error!==null && $error->getIsSessionUpgrade()===false && count($error->getFactors())===1 &&
      (!$wait || $actual_wait) && $snapshot($work)===$before &&
      !$work['project']->establishConnection('w')->isInsideTransaction(),$name);
    return $error;
  };
  $answer=function($identity) use($factor){
    return array(AphrontRequest::TYPE_HISEC=>1,
      $factor->getParameterName($identity['factor'],'mfa.response')=>PhabricatorTOTPAuthFactor::getTOTPCode(
        new PhutilOpaqueEnvelope($identity['secret']),(int)(PhabricatorTime::getNow()/30)));
  };
  $identity=$create('positive');$work=$target($identity,'Synthetic Native MFA Positive');
  $prompt($identity,$work,array(),'project-mfa-configured-native-factor-issues-real-challenge-before-consume');
  $auth=(new PhabricatorAuthChallenge())->establishConnection('w');
  $challenge=queryfx_one($auth,'SELECT sessionPHID,workflowKey,isCompleted FROM %R WHERE userPHID=%s',new PhabricatorAuthChallenge(),$identity['user']->getPHID());
  $check($challenge!==null && $challenge['sessionPHID']===$identity['web']['viewer']->getSession()->getPHID() &&
    $challenge['workflowKey']==='editor(PhabricatorProjectTransactionEditor).phid('.$work['project']->getPHID().')' &&
    (string)$challenge['isCompleted']==='0','project-mfa-native-challenge-binds-actual-session-and-editor-target');
  $wrong=array(AphrontRequest::TYPE_HISEC=>1,$factor->getParameterName($identity['factor'],'mfa.response')=>'invalid-synthetic-code');
  $prompt($identity,$work,$wrong,'project-mfa-invalid-native-factor-response-refuses-before-consume');
  $other=$target($identity,'Synthetic Native MFA Other Workflow');
  $prompt($identity,$other,$answer($identity),'project-mfa-valid-code-cannot-cross-native-editor-workflow',true);
  $other_session=$identity;$other_session['web']=governance_native_request($identity['user'],$sessions);
  $prompt($other_session,$work,$answer($identity),'project-mfa-valid-code-cannot-cross-native-session',true);
  $applied=$execute($identity,$work,$answer($identity));$after=$snapshot($work);
  $record=(new PhabricatorProjectTransaction())->loadOneWhere('phid=%s',$applied[0]->getPHID());
  $session_row=queryfx_one((new PhabricatorUser())->establishConnection('r'),
    'SELECT highSecurityUntil FROM %R WHERE phid=%s',new PhabricatorAuthSession(),$identity['web']['viewer']->getSession()->getPHID());
  $complete=queryfx_one($auth,'SELECT isCompleted FROM %R WHERE userPHID=%s',new PhabricatorAuthChallenge(),$identity['user']->getPHID());
  $check(count($applied)===1 && $after['ticket']['state']==='consumed' && $after['native']===1 && $after['audit']===1 &&
    $record->getIsMFATransaction() && (string)$complete['isCompleted']==='1' &&
    !$session_row['highSecurityUntil'],'project-mfa-real-challenged-one-use-token-commits-native-mutation-without-session-upgrade');
  $replay=PhorgeProjectPolicyApprovalService::call($identity['web']['context'],'submit',array(
    'target_phid'=>$work['project']->getPHID(),'new_policy'=>$identity['user']->getPHID(),
    'reason'=>'Synthetic consumed MFA response replay','ttl_seconds'=>240));
  PhorgeProjectPolicyApprovalService::call($approver['context'],'approve',array('approval_id'=>$replay['approval_id']));
  $replay_work=array('project'=>$work['project'],'ticket'=>$replay['approval_id'],'new_policy'=>$identity['user']->getPHID());
  $prompt($identity,$replay_work,$answer($identity),'project-mfa-consumed-native-response-not-reusable-for-second-operation',true);

  $rollback_identity=$create('rollback');$rollback=$target($rollback_identity,'Synthetic Native MFA Rollback');
  $prompt($rollback_identity,$rollback,array(),'project-mfa-rollback-case-requires-real-native-challenge');
  $baseline=$snapshot($rollback);$mode=(new mysqli_driver())->report_mode;$installed=false;
  $trigger='`'.$fixture->getNamespace().'_project`.`governance_mfa_native_fault`';
  try{
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $fixture->getAdmin()->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('project',(new PhabricatorProjectTransaction())->getTableName()).
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated-native-mfa-failure'");$installed=true;
    $exists=$fixture->getAdmin()->query("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
      $fixture->getNamespace()."_project' AND TRIGGER_NAME='governance_mfa_native_fault'")->fetch_assoc();
    $check((int)$exists['n']===1,'project-mfa-actual-native-record-fault-installed');
    $failed=false;try{$execute($rollback_identity,$rollback,$answer($rollback_identity));}catch(Throwable $ex){
      if(!in_array(get_class($ex),array(AphrontQueryException::class,mysqli_sql_exception::class),true) ||
        (int)$ex->getCode()!==1644 || !preg_match('/\A(?:#1644:\s*)?isolated-native-mfa-failure\z/',$ex->getMessage())){throw $ex;}$failed=true;
    }
    $complete=queryfx_one($auth,'SELECT isCompleted FROM %R WHERE userPHID=%s',new PhabricatorAuthChallenge(),$rollback_identity['user']->getPHID());
    $check($failed && $snapshot($rollback)===$baseline && (string)$complete['isCompleted']==='1' &&
      !$rollback['project']->establishConnection('w')->isInsideTransaction(),
      'project-mfa-native-mutation-consume-record-audit-rollback-does-not-revive-used-security-challenge');
  }finally{
    try{mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);if($installed){$fixture->getAdmin()->query('DROP TRIGGER '.$trigger);}}
    finally{mysqli_report($mode);}
  }
  $prompt($rollback_identity,$rollback,$answer($rollback_identity),'project-mfa-rolled-back-action-cannot-reuse-consumed-native-token',true);
  $revoked=$create('role',true);$role_work=$target($revoked,'Synthetic Native MFA Current Role',true);
  $prompt($revoked,$role_work,array(),'project-mfa-current-role-case-issues-actual-challenge');
  $role_before=$snapshot($role_work);
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$approver['token'];
  (new PhabricatorUserTransactionEditor())->setActor($approver['viewer'])
    ->setContentSourceFromRequest($approver['request'])->setCancelURI('/people/')
    ->setContinueOnMissingFields(true)->applyTransactions($revoked['user'],array((new PhabricatorUserTransaction())
      ->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(false)));
  $current=queryfx_one((new PhabricatorUser())->establishConnection('r'),'SELECT isAdmin FROM %R WHERE phid=%s',
    new PhabricatorUser(),$revoked['user']->getPHID());
  $refused=false;try{$execute($revoked,$role_work,$answer($revoked));}catch(RuntimeException $ex){
    if(get_class($ex)!==RuntimeException::class || $ex->getMessage()!=='project_execution_native_edit_required'){throw $ex;}$refused=true;
  }
  $check($refused && (string)$current['isAdmin']==='0' && $revoked['web']['viewer']->getIsAdmin() &&
    $snapshot($role_work)===$role_before && !$role_work['project']->establishConnection('w')->isInsideTransaction(),
    'project-mfa-valid-factor-response-does-not-restore-revoked-native-role-or-trust-cached-admin');
  return array('actual_native_totp_provider_and_secret'=>true,'actual_editor_challenge_response'=>true,
    'native_workflow_session_and_replay_enforced'=>true,'one_time_token_not_session_upgrade'=>true,
    'native_security_challenge_completion_survives_mutation_rollback'=>true,
    'actual_current_native_role_revocation_enforced'=>true,
    'configured_factor_browser_login_tested'=>false,'all_source_factor_lifecycle_closed'=>false);
}
