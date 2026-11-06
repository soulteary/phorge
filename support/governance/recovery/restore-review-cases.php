<?php

/** Native Project membership producer, including materialized membership. */
function governance_recovery_restore_partial_project($actor,$subject,$suffix) {
  $project=PhabricatorProject::initializeNewProject($actor);
  // Match ProjectMembersAddController: raw EdgeEditor writes direct/inverse
  // edges but does not run the Project editor's membership final effects.
  (new PhabricatorProjectTransactionEditor())->setActor($actor)
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->applyTransactions($project,array(
      (new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
        ->setNewValue('Synthetic partial review '.$suffix),
      (new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue('edge:type',PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
        ->setNewValue(array('+'=>array_fuse(array($subject->getPHID()))))));
  return $project;
}

/** Unrun native integration draft. Does not claim any successful restoration. */
function governance_recovery_restore_review_cases($fixture,callable $check) {
  $fixture->assertOwned();$cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  $environment=null;$flags=null;$trigger=false;$trigger_name=null;
  try {
    $suffix=substr($fixture->getNamespace(),-10);
    $actor=(new PhabricatorUser())->setUsername('restoreactor'.$suffix)
      ->setRealName('Synthetic Restore Requester')->setIsAdmin(1)->setIsApproved(1)->save();
    $reviewer=(new PhabricatorUser())->setUsername('restorereviewer'.$suffix)
      ->setRealName('Synthetic Restore Reviewer')->setIsAdmin(1)->setIsApproved(1)->save();
    $subject=(new PhabricatorUser())->setUsername('restoresubject'.$suffix)
      ->setRealName('Synthetic Restore Subject')->setIsApproved(1)->save();
    $ordinary=(new PhabricatorUser())->setUsername('restoreordinary'.$suffix)
      ->setRealName('Synthetic Restore Ordinary')->setIsApproved(1)->save();
    $sessions=new PhabricatorAuthSessionEngine();
    $requester=governance_native_request($actor,$sessions);
    $approver=governance_native_request($reviewer,$sessions);
    $member=governance_native_request($ordinary,$sessions);
    $conn=(new PhabricatorUser())->establishConnection('w');
    (new PhabricatorUserTransactionEditor())->setActor($actor)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->applyTransactions($subject,array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true)));
    if ($conn->isInsideTransaction()) {throw new RuntimeException('recovery_restore_fixture_commit_required');}
    $event=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s '.
      'AND transactionType=%s ORDER BY id DESC LIMIT 1',new PhabricatorUserTransaction(),
      $subject->getPHID(),PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
    if (!$event) {throw new RuntimeException('recovery_restore_fixture_event_required');}
    $flags=PhabricatorEnv::beginScopedEnv()
      ->overrideEnvConfig('governance.recovery-stop-ledger.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    // Reuse the exact fixture-owned config subcontext, not a production guard
    // relaxation or arbitrary external configuration supplied to the service.
    $environment=governance_recovery_account_fixture_environment($fixture);
    PhorgeRecoveryStopService::recordAlreadyStopped($requester['context'],$subject->getPHID(),
      (string)$event['id'],'technical-pause','Synthetic review stop','restore-stop-'.$suffix);
    $before_name=queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName'];
    $conn->openTransaction();$caller_preserved=false;
    try {
      queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),'Synthetic caller-owned source read',$subject->getID());
      $facts=PhorgeRecoveryNativeCaptureScope::captureForRestoration($requester['context'],$conn,$subject->getPHID());
      $caller_preserved=$conn->isInsideTransaction()&&$facts['account_facts']['flags']['isDisabled']===true&&
        $facts['complete']===false&&strlen($facts['web_storage_digest'])===64&&strlen($facts['api_storage_digest'])===64&&
        queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName']==='Synthetic caller-owned source read';
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $check($caller_preserved&&!$conn->isInsideTransaction()&&
      queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName']===$before_name,
      'recovery-restore-direct-locked-account-web-api-readers-preserve-caller-transaction');
    // Real native producers establish bounded selected sources before submission.
    $partial_project=governance_recovery_restore_partial_project($actor,$subject,$suffix);
    $partial_space=PhabricatorSpacesNamespace::initializeNewNamespace($actor);
    (new PhabricatorSpacesNamespaceEditor())->setActor($actor)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->applyTransactions($partial_space,array((new PhabricatorSpacesNamespaceTransaction())
        ->setTransactionType(PhabricatorSpacesNamespaceNameTransaction::TRANSACTIONTYPE)
        ->setNewValue('Synthetic partial review '.$suffix)));
    // Fresh request-cache state is explicit test setup, never an absence lease.
    (new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,null);
    $input=array('native_stop_transaction'=>(string)$event['id'],'operation_id'=>'restore-submit-'.$suffix,
      'reason'=>'Synthetic independent review','subject'=>$subject->getPHID(),'ttl_seconds'=>600);
    $fingerprint=static function()use($conn){
      $rows=array();foreach(array('recovery_restore_request','recovery_restore_operation',
        'recovery_restore_audit','organization_member','organization_appointment',
        'organization_primarydepartment')as$table){
        $rows[$table]=queryfx_all($conn,'SELECT * FROM %T ORDER BY 1',$table);
      }
      $rows['users']=queryfx_all($conn,'SELECT phid,isDisabled,isApproved,isAdmin FROM %R ORDER BY id',new PhabricatorUser());
      return hash('sha256',phutil_json_encode($rows));
    };
    $refuse=static function($call,$reason,$label)use($check,$conn,$fingerprint){
      $before=$fingerprint();$denied=false;
      try {$call();}catch(RuntimeException $error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!==$reason){throw$error;}
        $denied=true;
      }
      $check($denied&&!$conn->isInsideTransaction()&&$before===$fingerprint(),$label);
    };
    // Initialize ordinary credentials independently; subsequent denial must not
    // be satisfied by failure to recognize its session or an uninitialized epoch.
    $conn->openTransaction();
    try {PhorgeGovernanceNativeIdentitySource::lockCurrent($member['context'],$conn);$conn->saveTransaction();}
    catch(Throwable $error){$conn->killTransaction();throw$error;}
    $refuse(static function()use($member,$input){PhorgeRecoveryRestoreService::submit($member['context'],$input);},
      'recovery_restore_admin_required','recovery-restore-ordinary-current-session-cannot-submit');
    $receipt=PhorgeRecoveryRestoreService::submit($requester['context'],$input);
    $check($receipt['review_state']==='submitted-blocked'&&$receipt['permit_state']==='blocked'&&
      $receipt['restoration_authorized']===false,'recovery-restore-native-submission-persists-blocked-review');
    $before=$fingerprint();$replay=PhorgeRecoveryRestoreService::submit($requester['context'],$input);
    $check($replay['replayed']===true&&$before===$fingerprint(),'recovery-restore-submit-ledger-replay-no-second-audit');
    $id=$receipt['request_id'];
    $before=$fingerprint();$preview=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');
    $preview_keys=array_keys($preview);sort($preview_keys,SORT_STRING);
    $check($before===$fingerprint()&&$preview['source_schema_version']===3&&
      strlen($preview['source_digest'])===64&&count($preview['incomplete_families'])===13&&
      $preview['preview_is_authorization']===false&&$preview['restoration_authorized']===false&&
      $preview_keys===array('category','expires_at','families','incomplete_families','permit_state',
        'preview_is_authorization','request_id','restoration_authorized','review_state',
        'source_digest','source_schema_version','version'),
      'recovery-restore-live-independent-admin-preview-exposes-blockers-not-credentials-or-permit');
    require_once __DIR__.'/partial-composition-cases.php';
    governance_recovery_partial_composition_cases($fixture,$requester,$approver,$id,$partial_project,$partial_space,$refuse,$check,$fingerprint);
    $refuse(static function()use($requester,$id,$suffix){
      PhorgeRecoveryRestoreService::review($requester['context'],$id,'1','restore-self-'.$suffix);},
      'recovery_restore_independent_admin_required','recovery-restore-requester-cannot-self-review');
    $namespace=$fixture->getNamespace();
    if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
      throw new RuntimeException('recovery_restore_fixture_namespace_required');}
    $trigger_name=sprintf('%c%s_user%c.%crecovery_restore_audit_fault%c',96,$namespace,96,96,96);
    $audit_table=sprintf('%c%s_user%c.%crecovery_restore_audit%c',96,$namespace,96,96,96);
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger_name.
      ' BEFORE INSERT ON '.$audit_table.
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic restoration audit failure'");
    $trigger=true;
    $installed=governance_native_setup_query($fixture,'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '.
      "WHERE TRIGGER_SCHEMA='".$namespace."_user' AND TRIGGER_NAME='recovery_restore_audit_fault'")->fetch_all(MYSQLI_ASSOC);
    $check(count($installed)===1&&$installed[0]['TRIGGER_NAME']==='recovery_restore_audit_fault',
      'recovery-restore-owned-setup-proves-real-audit-trigger-installed');
    // Initialize this real reviewer independently before the domain fingerprint.
    $conn->openTransaction();
    try{PhorgeGovernanceNativeIdentitySource::lockCurrent($approver['context'],$conn);$conn->saveTransaction();}
    catch(Throwable $error){$conn->killTransaction();throw$error;}
    $before=$fingerprint();$failed=false;
    try{PhorgeRecoveryRestoreService::review($approver['context'],$id,'1','restore-fault-'.$suffix);}
    catch(AphrontQueryException $error){if($error->getCode()!==1644){throw$error;}$failed=true;}
    finally{governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger_name);$trigger=false;}
    $check($failed&&!$conn->isInsideTransaction()&&$before===$fingerprint(),
      'recovery-restore-real-review-audit-fault-rolls-ticket-operation-and-audit-back');
    $reviewed=PhorgeRecoveryRestoreService::review($approver['context'],$id,'1','restore-review-'.$suffix);
    $check($reviewed['version']==='2'&&$reviewed['review_state']==='reviewed-blocked'&&
      $reviewed['restoration_authorized']===false,'recovery-restore-independent-review-is-not-a-permit');
    $refuse(static function()use($requester,$id){PhorgeRecoveryRestoreService::execute($requester['context'],$id,'2');},
      'recovery_restore_sources_incomplete','recovery-restore-real-review-cannot-bypass-incomplete-families');
    // Keep the durable database review unchanged while only separately
    // supplied process configuration rotates. This is not a physical restore
    // rehearsal and cannot certify the whole restore-lineage family.
    $rotated=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig(
      'governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $rotated_environment=governance_recovery_account_fixture_environment($fixture);
    try {
      $refuse(static function()use($requester,$id){PhorgeRecoveryRestoreService::execute($requester['context'],$id,'2');},
        'recovery_restore_sources_stale','recovery-restore-retained-database-review-refused-after-external-incarnation-change');
    }finally{try{governance_recovery_account_restore_fixture_environment($rotated_environment);}finally{unset($rotated);}}
    $invalid_incarnation=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.incarnation',null);
    $invalid_environment=governance_recovery_account_fixture_environment($fixture);
    try {
      $refuse(static function()use($requester,$input){PhorgeRecoveryRestoreService::submit($requester['context'],$input);},
        'recovery_restore_incarnation_unknown','recovery-restore-missing-external-incarnation-refuses-without-domain-write');
    }finally{try{governance_recovery_account_restore_fixture_environment($invalid_environment);}finally{unset($invalid_incarnation);}}
    $history=PhorgeRecoveryRestoreService::history($requester['context'],$subject->getPHID());
    $keys=array_keys(head($history['rows']));sort($keys,SORT_STRING);
    $check(count($history['rows'])===1&&$keys===array('createdAt','expiresAt','nativeTransactionID',
      'permitState','requestID','reviewState','version'),'recovery-restore-history-fixed-metadata-without-binding-or-reason');
    $sessions->logoutSession($actor,$requester['viewer']->getSession());
    $refuse(static function()use($requester,$subject){PhorgeRecoveryRestoreService::history(
      $requester['context'],$subject->getPHID());},'governance_native_credential_ineligible',
      'recovery-restore-history-rechecks-actual-logged-out-session');
    return array('review_ledger_candidate'=>true,'source_family_complete'=>false,
      'restoration_executed'=>false,'all_sources_complete'=>false,'production_activation'=>false);
  } finally {
    try {
      if($trigger){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger_name);}
    } finally {
      try {
        if($environment!==null){governance_recovery_account_restore_fixture_environment($environment);}
      } finally {
        unset($flags);$_COOKIE=$cookies;
        if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}
      }
    }
  }
}
