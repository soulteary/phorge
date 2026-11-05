<?php
/** Dedicated fresh fixture only; ordinary application writers stay native RC. */
function governance_recovery_file_source_focused($fixture,mysqli$control,callable$check){
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class||(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'control'))->getValue($fixture)!==$control){throw new RuntimeException('file_source_control_owner_unknown');}
  $fixture->assertOwned();require_once __DIR__.'/file-source-cases.php';require_once __DIR__.'/spaces-source-focused.php';require_once __DIR__.'/project-source-focused.php';require_once __DIR__.'/organization-live-metadata-cases.php';require_once dirname(__DIR__).'/native-organization/workers.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('file_source_revision_unknown');}
  $stack=PhabricatorEnv::getConfigSourceStack();if(get_class($stack)!==PhabricatorConfigStackSource::class||count($stack->getStack())!==2||get_class($stack->getStack()[0])!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('file_source_config_scope_unknown');}
  $source=$stack->getStack()[0];$values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);$cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();$observer=null;$observerID=null;$primary=null;$observerClosed=false;
  try{
    $state=governance_recovery_file_source_prepare($fixture);
    $source->setKeys(array('governance.account-recovery.enabled'=>true,'governance.account-recovery.owned-rr-scope.enabled'=>true,'governance.account-recovery.incarnation'=>bin2hex(random_bytes(16))));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    // Existing isolated bootstrap control only; no new principal/grant API.
    $observer=governance_recovery_org_metadata_control($fixture);$observerID=queryfx_one($observer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];if(!is_string($observerID)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$observerID)){throw new RuntimeException('file_source_observer_identity_unknown');}
    $state=governance_recovery_file_source_native_cases($fixture,$observer,$state,$check);
    governance_recovery_file_source_raw_cases($fixture,$observer,$state,$check);
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    $errors=array();if($primary){$errors['primary']=$primary;}
    try{if($observer!==null){$observer->close();if($observerID===null){throw new RuntimeException('file_source_observer_cleanup_identity_unknown');}$deadline=microtime(true)+2;$absent=false;do{$row=$control->query('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE ID='.$observerID)->fetch_assoc();if($row&&in_array($row['n'],array('0',0),true)){$absent=true;break;}usleep(100000);}while(microtime(true)<$deadline);if(!$absent){throw new RuntimeException('file_source_observer_session_remaining');}}$observerClosed=true;}catch(Throwable$error){$errors['observer']=$error;}
    try{governance_recovery_project_fixture_cleanup(null,$source,$values,$cache,$primary);}catch(Throwable$error){$errors['configuration']=$error;}
    if(count($errors)>($primary?1:0)){throw new PhutilAggregateException('file_source_focused_cleanup_failed',$errors);}
  }
  return array('profile'=>'fixed-author-rooted-File-Task-attachment-DAO-topology-under-owned-RR-v1','source_revision'=>$revision,'native_File_and_Task_ordinary_author_producers'=>true,'actual_ordinary_FileQuery_allow_and_deny'=>true,'native_reference_attach_detach_distinguished'=>true,'native_File_relation_DAO_rollback_observed'=>true,'raw_author_attachment_transform_waits_and_reaps_observed'=>true,'observer_closed_and_absent'=>$observerClosed,'native_image_transform_generator_proved'=>false,'native_Editor_cross_application_atomicity_proved'=>false,'all_File_visibility_and_secret_capabilities_covered'=>false,'minimum_production_grants_proved'=>false,'DDL_and_fault_matrix_proved'=>false,'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);
}
