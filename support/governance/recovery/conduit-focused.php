<?php
/** Fixed isolated phase; uses original native fixture preparation, never production observer adoption. */
require_once __DIR__.'/borrowed-session-focused.php';
function governance_recovery_conduit_cleanup_ready(){
  return($GLOBALS['governance_recovery_conduit_cleanup_proved']??null)===true&&governance_recovery_borrowed_cleanup_ready();
}
function governance_recovery_conduit_focused($fixture,mysqli $control,callable $check){
  $GLOBALS['governance_recovery_conduit_cleanup_proved']=false;
  $GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;
  $GLOBALS['governance_recovery_borrowed_fixture_cleanup']=array('cleanup_proved'=>false);
  $fixture->assertOwned();require_once __DIR__.'/conduit-cases.php';require_once __DIR__.'/task-owner-source-cases.php';
  require_once __DIR__.'/account-cases.php';require_once __DIR__.'/project-source-focused.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||preg_match('/\A[a-f0-9]{40}\z/D',$revision)!==1){throw new RuntimeException('borrowed_revision_unknown');}
  $stack=PhabricatorEnv::getConfigSourceStack();$sources=$stack->getStack();
  if(get_class($stack)!==PhabricatorConfigStackSource::class||count($sources)!==2||get_class($sources[0])!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('borrowed_config_scope_unknown');}
  $source=$sources[0];$values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);$cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();
  $flags=null;$environment=null;$primary=null;$result=null;$cookies=$_COOKIE;$server=$_SERVER;
  try{
    $source->setKeys(array('gorge.image.builtin-mode'=>'legacy'));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $users=governance_task_owner_prepare($fixture);
    $extra=(new PhabricatorUser())->setUsername('borrowedextra'.substr($fixture->getNamespace(),-12))->setRealName('Synthetic Borrowed Observer Fixture')->setIsApproved(1)->setIsAdmin(0)->save();
    $avatar=(new PhabricatorFilesComposeAvatarBuiltinFile())->updateUser($extra);
    $fresh=(new PhabricatorUser())->load($extra->getID());$stored=(new PhabricatorFile())->load($avatar->getID());
    if(!$fresh||!$stored||$stored->getStorageEngine()!=='gorge'||$fresh->getDefaultProfileImagePHID()!==$avatar->getPHID()||$fresh->getDefaultProfileImageVersion()!==PhabricatorFilesComposeAvatarBuiltinFile::VERSION){throw new RuntimeException('borrowed_avatar_unproved');}
    $users=governance_recovery_borrowed_prepare_roles(array($users['actor'],$users['subject'],$users['other'],$extra),$users['disabled']);
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);$writer=governance_recovery_borrowed_default_writer();
    $sessions=new PhabricatorAuthSessionEngine();$issuer=governance_native_request($users['issuer'],$sessions);$owner=governance_native_request($users['owner'],$sessions);
    $prewarmOwned=false;$prewarmCommitAttempted=false;
    $prewarmStateReader=new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState');
    $prewarmState=$prewarmStateReader->invoke($writer);
    if($writer->isInsideTransaction()||$prewarmState->getDepth()!==0){throw new RuntimeException('borrowed_prewarm_outer_transaction_refused');}
    try{
      $writer->openTransaction();$prewarmOwned=true;
      $issuerSnapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($issuer['context'],$writer);
      $ownerSnapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($owner['context'],$writer);
      if(!$issuerSnapshot->isSystemAdmin()||!$ownerSnapshot->isSystemAdmin()){throw new RuntimeException('borrowed_current_admin_preparation_unproved');}
      $prewarmCommitAttempted=true;$writer->saveTransaction();$prewarmOwned=false;
    }catch(Throwable$error){
      // Native commit bookkeeping decreases depth before the physical COMMIT.
      // A failed begin/commit is not authority to issue another transaction end.
      if($prewarmOwned&&!$prewarmCommitAttempted&&$writer->isInsideTransaction()&&
        $prewarmStateReader->invoke($writer)===$prewarmState&&$prewarmState->getDepth()===1){
        try{$writer->killTransaction();}
        catch(Throwable$cleanup){throw new PhutilAggregateException('borrowed_prewarm_cleanup_failed',array('primary'=>$error,'cleanup'=>$cleanup));}
      }
      throw$error;
    }
    $result=governance_recovery_borrowed_conduit_cases($fixture,$control,array('issuer_auth'=>$issuer,'owner_auth'=>$owner,'subjects'=>array('commit'=>$users['subject'],'rollback'=>$users['other'])),$check);
    if(!governance_recovery_borrowed_cleanup_ready()){throw new RuntimeException('borrowed_cleanup_unproved');}
    $result['observer_cleanup']=$GLOBALS['governance_recovery_borrowed_fixture_cleanup'];$result['both_native_identity_epochs_prewarmed']=true;
    $result['source_revision']=$revision;$result['default_native_User_writer']=true;$result['forced_connection_used']=false;
    // Return only after the actual finally cleanup below.
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    if(governance_recovery_borrowed_cleanup_ready()){
      $errors=$primary===null?array():array('primary'=>$primary);
      try{if($environment!==null){governance_recovery_account_restore_fixture_environment($environment);}}catch(Throwable$error){$errors['environment']=$error;}
      unset($flags);$_COOKIE=$cookies;$_SERVER=$server;
      try{governance_recovery_project_fixture_cleanup(null,$source,$values,$cache,null);}catch(Throwable$error){$errors['configuration']=$error;}
      if(count($errors)>($primary===null?0:1)){throw new PhutilAggregateException('borrowed_focused_cleanup_failed',$errors);}
    }else{
      // Retain until process exit; cannot prevent natural OS/mysqli close or
      // implicit rollback at PHP shutdown. Never claim terminal cleanup here.
      $GLOBALS['governance_borrowed_retained_context']=array('flags'=>$flags,'environment'=>$environment,'source'=>$source,'values'=>$values,'cache'=>$cache,'cookies'=>$cookies,'server'=>$server);
    }
  }
  if(!governance_recovery_borrowed_cleanup_ready()){throw new RuntimeException('conduit_final_cleanup_unknown');}
  $GLOBALS['governance_recovery_conduit_cleanup_proved']=true;return$result;
}
