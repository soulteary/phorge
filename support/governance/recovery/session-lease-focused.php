<?php
function governance_recovery_session_lease_obligations(){
  $labels=array();foreach(array('commit','rollback')as$ending){foreach(array('session-lease-real-INSERT-waits-through-protected-capture-','session-lease-real-INSERT-resumes-after-observed-','session-lease-new-native-producer-transition-invalidates-selected-binding-')as$prefix){$labels[]=$prefix.$ending;}}sort($labels,SORT_STRING);return$labels;
}
/** Native worker lock order is actor before either subject, not creation order. */
function governance_recovery_session_lease_prepare_actor_order(array$users){
  if(PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')!==false||
    PhabricatorEnv::getEnvConfig('governance.account-recovery.session-lease.enabled')!==false){
    throw new RuntimeException('session_lease_actor_preparation_after_enable');}
  $keys=array_keys($users);sort($keys,SORT_STRING);
  if($keys!==array('actor','disabled','other','subject')){throw new RuntimeException('session_lease_prepared_users_unknown');}
  $eligible=array();$phids=array();$ids=array();
  foreach(array('actor','subject','other','disabled')as$role){
    $user=$users[$role];
    if(!is_object($user)||get_class($user)!==PhabricatorUser::class||!is_int($user->getID())||$user->getID()<=0||
      !is_string($user->getPHID())||preg_match('/\APHID-USER-[a-z0-9]{20}\z/D',$user->getPHID())!==1||
      isset($phids[$user->getPHID()])||isset($ids[$user->getID()])||$user->getIsApproved()!==true||
      $user->getIsSystemAgent()||$user->getIsMailingList()||$user->getIsDisabled()!==($role==='disabled')){
      throw new RuntimeException('session_lease_prepared_users_unknown');}
    $phids[$user->getPHID()]=true;$ids[$user->getID()]=true;
    if($role!=='disabled'){$eligible[]=$user;}
    elseif($user->getIsAdmin()){throw new RuntimeException('session_lease_disabled_admin_unknown');}
  }
  usort($eligible,static fn(PhabricatorUser$a,PhabricatorUser$b)=>strcmp($a->getPHID(),$b->getPHID()));
  // Recovery is still OFF. Persist real native flags, without regenerating
  // names, search tasks or already-owned native avatar files. Clear the old
  // creation-role admin before granting the lexically first prepared user.
  foreach($eligible as$user){$user->setIsAdmin(0)->saveWithoutIndex();}
  $eligible[0]->setIsAdmin(1)->saveWithoutIndex();
  return array('actor'=>$eligible[0],'subject'=>$eligible[1],'other'=>$eligible[2],'disabled'=>$users['disabled']);
}
function governance_recovery_session_lease_focused($fixture,mysqli $control,callable $check){
  $GLOBALS['governance_session_lease_cleanup']=array('cleanup_proved'=>false);
  $fixture->assertOwned();require_once __DIR__.'/session-lease-cases.php';require_once __DIR__.'/session-lease-observer.php';require_once __DIR__.'/task-owner-source-cases.php';require_once __DIR__.'/account-cases.php';require_once __DIR__.'/project-source-focused.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('session_lease_revision_unknown');}
  if(isset($GLOBALS['governance_session_lease_observer_owner'])){throw new RuntimeException('session_observer_owner_reentry');}
  $stack=PhabricatorEnv::getConfigSourceStack();$sources=$stack->getStack();$source=$sources[0];
  if(get_class($stack)!==PhabricatorConfigStackSource::class||count($sources)!==2||get_class($source)!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('session_lease_config_scope_unknown');}
  $values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);$cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();
  $cookies=$_COOKIE;$server=$_SERVER;$flags=null;$environment=null;$primary=null;$owner=null;$executed=array();$cleanup=null;
  $record=static function($condition,$label)use($check,&$executed){if(!is_bool($condition)||!is_string($label)||isset($executed[$label])||!in_array($label,governance_recovery_session_lease_obligations(),true)){throw new RuntimeException('session_lease_case_result_unknown');}$check($condition,$label);if($condition!==true){throw new RuntimeException('session_lease_case_failed');}$executed[$label]=true;};
  try{
    $source->setKeys(array('gorge.image.builtin-mode'=>'legacy'));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    // This actual helper prepares native Users and real stored avatars only;
    // it never enters a Task/File RR source scope or creates a Task here.
    $users=governance_recovery_session_lease_prepare_actor_order(governance_task_owner_prepare($fixture));
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.session-lease.enabled',true)->overrideEnvConfig('governance.account-recovery.enabled',true)->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    $owner=GovernanceSessionLeaseObserverOwner::prepare($fixture,$control,$revision);
    governance_recovery_session_lease_owned_entry($fixture,array('users'=>array('admin'=>$users['actor']),'sessions'=>new PhabricatorAuthSessionEngine(),'subjects'=>array('commit'=>$users['subject'],'rollback'=>$users['other'])),$owner->configuration(),$record);
    $labels=array_keys($executed);sort($labels,SORT_STRING);if($labels!==governance_recovery_session_lease_obligations()||($GLOBALS['governance_session_lease_observer_close']??null)!==array('attempted'=>true,'returned'=>true)){
      throw new RuntimeException('session_lease_complete_result_required');}
  }catch(Throwable $error){$primary=$error;throw$error;}
  finally{
    $errors=$primary===null?array():array('primary'=>$primary);
    try{$owner=$owner??($GLOBALS['governance_session_lease_observer_owner']??null);if($owner!==null){if(get_class($owner)!==GovernanceSessionLeaseObserverOwner::class){throw new RuntimeException('session_observer_owner_unknown');}$cleanup=$owner->cleanup();}else{$cleanup=array('cleanup_proved'=>true);}
      if(($GLOBALS['governance_session_lease_physical_cleanup_proved']??true)!==true){throw new RuntimeException('session_lease_physical_cleanup_unproved');}
      $GLOBALS['governance_session_lease_cleanup']=$cleanup;
    }catch(Throwable $error){$errors['observer-cleanup']=$error;}
    try{if($environment!==null){governance_recovery_account_restore_fixture_environment($environment);}}catch(Throwable $error){$errors['environment']=$error;}
    unset($flags);$_COOKIE=$cookies;$_SERVER=$server;
    try{governance_recovery_project_fixture_cleanup(null,$source,$values,$cache,null);}catch(Throwable $error){$errors['configuration']=$error;}
    if(count($errors)>($primary===null?0:1)){throw new PhutilAggregateException('session_lease_focused_cleanup_failed',$errors);}
  }
  return array('profile'=>'native-AuthSession-selected-producer-focused-v1','source_revision'=>$revision,'executed_case_obligations'=>governance_recovery_session_lease_obligations(),
    'actual_User_writer_RC_and_independent_observer'=>true,'actual_native_SessionEngine_workers'=>2,'exact_PRIMARY_wait_and_terminal_events_observed'=>true,
    'worker_SQL_sessions_absent'=>true,'observer_public_close_returned'=>true,'observer_cleanup'=>$cleanup,
    'all_session_producers_closed'=>false,'fault_matrix_proved'=>false,'grant_DDL_lease_proved'=>false,'Task_File_RR_combined'=>false,
    'durable_review_compatible'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false,'execution_blocked'=>true);
}
