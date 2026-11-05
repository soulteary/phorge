<?php
/** Fresh isolated profile only; no route, grant provisioning or production use. */
function governance_recovery_task_owner_focused($fixture,mysqli$control,callable$check){
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class||(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'control'))->getValue($fixture)!==$control){throw new RuntimeException('task_owner_control_owner_unknown');}
  $fixture->assertOwned();require_once __DIR__.'/task-owner-source-cases.php';require_once __DIR__.'/project-source-focused.php';require_once __DIR__.'/organization-live-metadata-cases.php';require_once dirname(__DIR__).'/native-organization/workers.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('task_owner_revision_unknown');}
  $stack=PhabricatorEnv::getConfigSourceStack();if(get_class($stack)!==PhabricatorConfigStackSource::class||count($stack->getStack())!==2||get_class($stack->getStack()[0])!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('task_owner_config_scope_unknown');}
  $source=$stack->getStack()[0];$values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);$cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();$observer=null;$observerID=null;$primary=null;$observerClosed=false;
  try{
    $users=governance_task_owner_prepare($fixture);
    $source->setKeys(array('governance.account-recovery.enabled'=>true,'governance.account-recovery.owned-rr-scope.enabled'=>true,'governance.account-recovery.incarnation'=>bin2hex(random_bytes(16))));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    // Existing isolated bootstrap control, not a new metadata principal/grant API.
    $observer=governance_recovery_org_metadata_control($fixture);$observerID=queryfx_one($observer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];
    if(!is_string($observerID)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$observerID)){throw new RuntimeException('task_owner_observer_identity_unknown');}
    $state=governance_task_owner_native_cases($fixture,$observer,$users,$check);
    governance_task_owner_empty_range_cases($fixture,$observer,$state,$check);
    governance_task_owner_raw_insert_delete_cases($fixture,$observer,$state,$check);
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    $errors=array();if($primary){$errors['primary']=$primary;}
    try{if($observer!==null){$observer->close();if($observerID===null){throw new RuntimeException('task_owner_observer_cleanup_identity_unknown');}$deadline=microtime(true)+2;$absent=false;do{$row=$control->query('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE ID='.$observerID)->fetch_assoc();if($row&&in_array($row['n'],array('0',0),true)){$absent=true;break;}usleep(100000);}while(microtime(true)<$deadline);if(!$absent){throw new RuntimeException('task_owner_observer_session_remaining');}}$observerClosed=true;}catch(Throwable$error){$errors['observer']=$error;}
    try{governance_recovery_project_fixture_cleanup(null,$source,$values,$cache,$primary);}catch(Throwable$error){$errors['configuration']=$error;}
    if(count($errors)>($primary?1:0)){throw new PhutilAggregateException('task_owner_focused_cleanup_failed',$errors);}
  }
  return array('profile'=>'fixed-native-Task-owner-under-owned-RR-v1','source_revision'=>$revision,'native_Editor_assign_unassign_and_history_rollback'=>true,'disabled_assignee_native_Editor_not_account_admission'=>true,'owned_native_User_RR_source_observed'=>true,'raw_update_insert_delete_waits_and_reaps_observed'=>true,'observer_closed_and_absent'=>$observerClosed,'minimum_production_grants_proved'=>false,'DDL_and_fault_matrix_proved'=>false,'native_owner_function_is_full_policy'=>false,'all_automatic_object_classes_supported'=>false,'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);
}
