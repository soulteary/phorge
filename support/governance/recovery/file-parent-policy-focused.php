<?php
/** Dedicated fresh fixture only; ordinary application writers stay native RC. */
function governance_recovery_file_parent_policy_focused($fixture,mysqli$control,callable$check){
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class||(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'control'))->getValue($fixture)!==$control){throw new RuntimeException('file_source_control_owner_unknown');}
  $fixture->assertOwned();require_once __DIR__.'/file-parent-policy-cases.php';require_once __DIR__.'/file-source-cases.php';require_once __DIR__.'/spaces-source-focused.php';require_once __DIR__.'/project-source-focused.php';require_once __DIR__.'/organization-live-metadata-cases.php';require_once dirname(__DIR__).'/native-organization/workers.php';
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('file_source_revision_unknown');}
  $stack=PhabricatorEnv::getConfigSourceStack();if(get_class($stack)!==PhabricatorConfigStackSource::class||count($stack->getStack())!==2||get_class($stack->getStack()[0])!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('file_source_config_scope_unknown');}
  $source=$stack->getStack()[0];$values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);$cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();$observer=null;$observerID=null;$primary=null;$observerClosed=false;
  try{
    $state=governance_recovery_file_source_prepare($fixture);
    $source->setKeys(array('governance.account-recovery.enabled'=>true,'governance.account-recovery.owned-rr-scope.enabled'=>true,'governance.account-recovery.incarnation'=>bin2hex(random_bytes(16))));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    // Existing isolated bootstrap control only; no new principal/grant API.
    $observer=governance_recovery_org_metadata_control($fixture);$observerID=queryfx_one($observer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];if(!is_string($observerID)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$observerID)){throw new RuntimeException('file_source_observer_identity_unknown');}
    $executed=array();$expected=governance_recovery_file_parent_policy_obligations();
    $record=static function($condition,$name)use($check,&$executed,$expected) {
      if($condition!==true||!is_string($name)||!in_array($name,$expected,true)||isset($executed[$name])) {
        throw new RuntimeException('file_parent_policy_case_result_refused');
      }
      $executed[$name]=true;$check($condition,$name);
    };
    $result=governance_recovery_file_parent_policy_cases($fixture,$observer,$state,$record);
    $labels=governance_recovery_file_parent_policy_validate_cases($executed,$result);
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    $errors=array();if($primary){$errors['primary']=$primary;}
    try{if($observer!==null){$observer->close();if($observerID===null){throw new RuntimeException('file_source_observer_cleanup_identity_unknown');}$deadline=microtime(true)+2;$absent=false;do{$row=$control->query('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE ID='.$observerID)->fetch_assoc();if($row&&in_array($row['n'],array('0',0),true)){$absent=true;break;}usleep(100000);}while(microtime(true)<$deadline);if(!$absent){throw new RuntimeException('file_source_observer_session_remaining');}}$observerClosed=true;}catch(Throwable$error){$errors['observer']=$error;}
    try{governance_recovery_project_fixture_cleanup(null,$source,$values,$cache,$primary);}catch(Throwable$error){$errors['configuration']=$error;}
    if(count($errors)>($primary?1:0)){throw new PhutilAggregateException('file_source_focused_cleanup_failed',$errors);}
  }
  $source_hashes=array();foreach(governance_recovery_file_parent_policy_source_inputs()as$path) {
    $digest=hash_file('sha256',dirname(__DIR__,3).'/'.$path);
    if(!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)) {
      throw new RuntimeException('file_parent_policy_source_input_unknown');
    }
    $source_hashes[$path]=$digest;
  }
  return array('profile'=>'native-File-parent-Task-policy-endpoints-v2-current-account-flags','source_revision'=>$revision,
    'executed_case_obligations'=>$labels,'source_inputs_sha256'=>$source_hashes,
    'native_reader_relationships'=>$result['native_reader_relationships'],
    'observer_closed_and_absent'=>$observerClosed,'native_File_and_Task_queries_executed'=>true,
    'actual_current_account_admission_checked'=>true,
    'current_account_flags_bound'=>array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList'),
    'parent_policy_interpreter_complete'=>false,
    'durable_review_compatible'=>false,'dependency_budget_proved'=>false,
    'all_object_classes_covered'=>false,'all_sources_complete'=>false,'complete'=>false,
    'restoration_authorized'=>false,'execution_blocked'=>true);
}
function governance_recovery_file_parent_policy_obligations() {
  return array('file-parent-policy-unattached-author-root-is-unsupported',
    'file-parent-policy-native-Task-attachment-changes-endpoint-binding',
    'file-parent-policy-native-endpoints-never-become-restoration-permit',
    'file-parent-policy-native-invisible-parent-policy-changes-binding',
    'file-parent-policy-current-account-flags-admission-bound',
    'file-parent-policy-anonymous-refused-before-native-author-grant');
}
function governance_recovery_file_parent_policy_source_inputs() {
  return array('src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php',
    'src/applications/people/storage/PhabricatorUser.php',
    'src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php',
    'src/applications/files/query/PhabricatorFileQuery.php',
    'src/applications/maniphest/query/ManiphestTaskQuery.php',
    'src/applications/policy/rule/PhorgeGlobalPolicyRule.php',
    'support/governance/recovery/file-parent-policy-cases.php',
    'support/governance/recovery/file-parent-policy-focused.php',
    'support/governance/recovery/file-source-cases.php',
    'support/governance/recovery/task-owner-source-cases.php',
    'support/governance/completion/pipeline-gorge.php',
    'support/governance/native-organization/test-native-organization-mysql.php');
}
function governance_recovery_file_parent_policy_validate_cases(array $executed,$result) {
  $labels=array_keys($executed);$expected=governance_recovery_file_parent_policy_obligations();
  sort($labels,SORT_STRING);sort($expected,SORT_STRING);
  $relationships=array('owned_User_RR_scope_observed'=>true,'same_server_observed'=>true,
    'File_reader_is_User_writer'=>false,'Task_reader_is_User_writer'=>false,
    'File_Task_same_physical_reader'=>false,'same_snapshot_proved'=>false);
  if($labels!==$expected||in_array(false,$executed,true)||
      !is_array($result)||array_keys($result)!==array('native_reader_relationships')||
      $result['native_reader_relationships']!==$relationships) {
    throw new RuntimeException('file_parent_policy_case_result_refused');
  }
  foreach($executed as$value){if($value!==true){throw new RuntimeException('file_parent_policy_case_result_refused');}}
  return $labels;
}
