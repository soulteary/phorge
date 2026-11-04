<?php

/** Actual native ORM paths in a disposable profile; no complete permit exists. */
function governance_recovery_guard_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$suffix=substr($fixture->getNamespace(),-10);
  $conn=(new PhabricatorUser())->establishConnection('w');$flags=null;$environment=null;$renamed=false;
  $namespace=$fixture->getNamespace();
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){throw new RuntimeException('recovery_guard_namespace_unknown');}
  $request_table=sprintf('%c%s_user%c.%crecovery_restore_request%c',96,$namespace,96,96,96);
  $backup_table=sprintf('%c%s_user%c.%crecovery_restore_request_guard_saved%c',96,$namespace,96,96,96);
  try {
    $disabled=(new PhabricatorUser())->setUsername('guarddisabled'.$suffix)
      ->setRealName('Synthetic Disabled Guard')->setIsApproved(1)->setIsDisabled(1)->save();
    $active=(new PhabricatorUser())->setUsername('guardactive'.$suffix)
      ->setRealName('Synthetic Active Guard')->setIsApproved(1)->save();
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    $fingerprint=static function()use($conn){
      return hash('sha256',phutil_json_encode(array(
        queryfx_all($conn,'SELECT id,phid,isApproved,isDisabled,isAdmin,isSystemAgent,isMailingList,realName FROM %R ORDER BY id',new PhabricatorUser()),
        queryfx_all($conn,'SELECT id,objectPHID,transactionType,oldValue,newValue FROM %R ORDER BY id',new PhabricatorUserTransaction()),
        queryfx_all($conn,'SELECT * FROM recovery_restore_request ORDER BY requestID'),
        queryfx_all($conn,'SELECT * FROM recovery_restore_operation ORDER BY operationID'),
        queryfx_all($conn,'SELECT * FROM recovery_restore_audit ORDER BY id'))));
    };
    $refuse=static function($call,$reason,$label)use($check,$fingerprint,$conn){
      $before=$fingerprint();$failed=false;
      try{$call();}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!==$reason){throw$error;}$failed=true;}
      $check($failed&&!$conn->isInsideTransaction()&&$before===$fingerprint(),$label);
    };
    $refuse(static function()use($disabled){
      (new PhabricatorUser())->load($disabled->getID())->setIsDisabled(0)->saveWithoutIndex();},
      'recovery_all_sources_unavailable','recovery-guard-real-native-dao-enable-cannot-bypass-source-closure');
    $mode=queryfx_one($conn,'SELECT @@SESSION.sql_mode mode')['mode'];
    if(!is_string($mode)||$conn->isInsideTransaction()){throw new RuntimeException('recovery_guard_sql_mode_unknown');}
    try {
      queryfx($conn,'SET SESSION sql_mode=%s','');
      $probe_before=$fingerprint();$coercion_control=true;
      foreach(array('0.0','00','false')as$raw){
        $conn->openTransaction();
        try {
          // Fixture-only rawSQL control, always rolled back. It proves the
          // actual server coercion without ever committing an enabled user.
          queryfx($conn,'UPDATE %R SET isDisabled=%s WHERE id=%d',new PhabricatorUser(),$raw,$disabled->getID());
          $coerced=queryfx_one($conn,'SELECT isDisabled FROM %R WHERE id=%d',new PhabricatorUser(),$disabled->getID());
          $coercion_control=$coercion_control&&(string)$coerced['isDisabled']==='0';
        }finally{$conn->killTransaction();}
      }
      $check(queryfx_one($conn,'SELECT @@SESSION.sql_mode mode')['mode']===''&&
        $coercion_control&&$probe_before===$fingerprint()&&!$conn->isInsideTransaction(),
        'recovery-guard-real-permissive-session-sql-mode-control');
      foreach(array(
        'recovery-guard-permissive-raw-zero-decimal-refused'=>'0.0',
        'recovery-guard-permissive-raw-double-zero-refused'=>'00',
        'recovery-guard-permissive-raw-false-word-refused'=>'false')as$label=>$value){
        $before=$fingerprint();$failed=false;
        try{(new PhabricatorUser())->load($disabled->getID())->setIsDisabled($value)->saveWithoutIndex();}
        catch(InvalidArgumentException$error){
          if(get_class($error)!==InvalidArgumentException::class||$error->getMessage()!=='recovery_native_flag_refused'){throw$error;}
          $failed=true;
        }
        $current=queryfx_one($conn,'SELECT isDisabled FROM %R WHERE id=%d',new PhabricatorUser(),$disabled->getID());
        $check($failed&&!$conn->isInsideTransaction()&&(string)$current['isDisabled']==='1'&&
          $before===$fingerprint(),$label);
      }
    }finally{
      queryfx($conn,'SET SESSION sql_mode=%s',$mode);
      if(queryfx_one($conn,'SELECT @@SESSION.sql_mode mode')['mode']!==$mode){
        throw new RuntimeException('recovery_guard_sql_mode_restore_failed');}
    }
    $refuse(static function()use($disabled,$state){
      $fresh=(new PhabricatorUser())->load($disabled->getID());
      (new PhabricatorUserTransactionEditor())->setActor($state['admin']['viewer'])
        ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
        ->applyTransactions($fresh,array((new PhabricatorUserTransaction())
          ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(false)));},
      'recovery_all_sources_unavailable','recovery-guard-real-native-user-editor-enable-rolls-back-without-permit');
    $pending=(new PhabricatorUser())->setUsername('guardpending'.$suffix)
      ->setRealName('Synthetic Pending Admission')->save();
    $persisted=(new PhabricatorUser())->load($pending->getID());
    $check(is_string($persisted->getPHID())&&preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$persisted->getPHID())&&
      !$persisted->getIsApproved()&&!$persisted->getIsAdmin(),
      'recovery-guard-native-aux-phid-allocation-preserves-only-pending-new-registration');
    $refuse(static function()use($pending){(new PhabricatorUser())->load($pending->getID())->setIsApproved(1)->saveWithoutIndex();},
      'recovery_all_sources_unavailable','recovery-guard-first-approval-remains-explicit-unreviewed-admission');
    $refuse(static function()use($pending){(new PhabricatorUser())->load($pending->getID())->replace();},
      'recovery_existing_identity_insert_refused','recovery-guard-replace-cannot-reclassify-existing-native-identity-as-new');
    // New review storage must not become a dependency of emergency disable.
    governance_native_setup_query($fixture,'RENAME TABLE '.$request_table.' TO '.$backup_table);$renamed=true;
    try {
      $fresh=(new PhabricatorUser())->load($active->getID());
      (new PhabricatorUserTransactionEditor())->setActor($state['admin']['viewer'])
        ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
        ->applyTransactions($fresh,array((new PhabricatorUserTransaction())
          ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true)));
      $current=(new PhabricatorUser())->load($active->getID());
      $check((string)$current->getIsDisabled()==='1'&&!$conn->isInsideTransaction(),
        'recovery-guard-real-emergency-disable-does-not-depend-on-review-storage');
    }finally{governance_native_setup_query($fixture,'RENAME TABLE '.$backup_table.' TO '.$request_table);$renamed=false;}
    // A raw caller transaction must survive the exact server1568 refusal.
    queryfx($conn,'START TRANSACTION');
    try {
      queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),'Synthetic raw guard write',$pending->getID());
      $refused=false;
      try{(new PhabricatorUser())->load($pending->getID())->setRealName('Attempted nested guard')->saveWithoutIndex();}
      catch(AphrontQueryException$error){if($error->getCode()!==1568){throw$error;}$refused=true;}
      $current=queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$pending->getID());
      $check($refused&&!$conn->isInsideTransaction()&&$current['realName']==='Synthetic raw guard write',
        'recovery-guard-untracked-native-raw-transaction-refused-without-implicit-commit');
    }finally{queryfx($conn,'ROLLBACK');}
    return array('actual_native_orm_entry_profile'=>true,'initial_approval_supported'=>false,
      'complete_restoration_permit'=>false,'global_sources_complete'=>false,'production_activation'=>false);
  }finally{
    try{if($renamed){governance_native_setup_query($fixture,'RENAME TABLE '.$backup_table.' TO '.$request_table);}}
    finally{try{if($environment!==null){governance_recovery_account_restore_fixture_environment($environment);}}
      finally{unset($flags);}}
  }
}
