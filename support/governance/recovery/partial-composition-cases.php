<?php

/** Isolated real-source durable-review cases. UNRUN until Root schedules SQL. */
function governance_recovery_partial_composition_cases($fixture,array $requester,
  array $approver,$id,PhabricatorProject $project,
  PhabricatorSpacesNamespace $space,callable $refuse,callable $check) {
  $fixture->assertOwned();
  $conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('recovery_partial_fixture_outer_transaction');}
  $source=(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->getValue();
  if(get_class($source)!==PhabricatorConfigStackSource::class){throw new RuntimeException('recovery_partial_fixture_config_unknown');}
  $sources=$source->getStack();$dictionary=head($sources);
  if(get_class($dictionary)!==PhabricatorConfigDictionarySource::class){throw new RuntimeException('recovery_partial_fixture_config_unknown');}
  $property=new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary');
  $values=$property->getValue($dictionary);$policy=null;
  $namespace=$fixture->getNamespace();
  $project_before=queryfx_one($conn,'SELECT status FROM %T.project WHERE id=%d',$namespace.'_project',$project->getID());
  $space_before=queryfx_one($conn,'SELECT description FROM %T.spaces_namespace WHERE id=%d',$namespace.'_spaces',$space->getID());
  if(!$project_before||!$space_before){throw new RuntimeException('recovery_partial_fixture_source_missing');}
  try {
    // This changes the actual source dictionary, not the separate Env test cache.
    $dictionary->setKeys(array('account.minimum-password-length'=>($values['account.minimum-password-length']??null)===123?124:123));
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');},
      'recovery_restore_sources_stale','recovery-partial-real-config-source-change-invalidates-durable-preview');
    $property->setValue($dictionary,$values);
    $check(PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1')['permit_state']==='blocked',
      'recovery-partial-restored-config-remains-blocked');
    queryfx($conn,'UPDATE %T.project SET status=%d WHERE id=%d',$namespace.'_project',
      PhabricatorProjectStatus::STATUS_ARCHIVED,$project->getID());
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');},
      'recovery_restore_sources_stale','recovery-partial-real-project-row-change-invalidates-durable-preview');
    queryfx($conn,'UPDATE %T.project SET status=%s WHERE id=%d',$namespace.'_project',$project_before['status'],$project->getID());
    $space_failure=governance_recovery_partial_space_mutation_failure($space);
    queryfx($conn,'UPDATE %T.spaces_namespace SET description=%s WHERE id=%d',$namespace.'_spaces',
      'Synthetic partial changed description',$space->getID());
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');},
      $space_failure,'recovery-partial-real-space-row-change-invalidates-durable-preview');
    queryfx($conn,'UPDATE %T.spaces_namespace SET description=%s WHERE id=%d',$namespace.'_spaces',$space_before['description'],$space->getID());
    // Real native DAO insertion changes a previously captured row set, including
    // an originally empty Policy range. It never supplies a fake adapter result.
    $policy=(new PhabricatorPolicy())->setRules(array())
      ->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->save();
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::review($approver['context'],$id,'1','partial-policy-change');},
      'recovery_restore_sources_stale','recovery-partial-real-policy-row-addition-invalidates-durable-review');
    // Check real adapter budget refusal before mutation can persist a review.
    $policy->setRules(array(array('rule'=>str_repeat('x',20000))))->save();
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');},
      'recovery_policy_byte_budget','recovery-partial-real-policy-budget-fails-closed-without-review-write');
    $policy->delete();$policy=null;
    $check(PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1')['permit_state']==='blocked',
      'recovery-partial-restored-policy-rows-remain-blocked');
  } finally {
    if($conn->isInsideTransaction()){$conn->killTransaction();}
    try{if($policy){$policy->delete();}}
    finally{
      try{queryfx($conn,'UPDATE %T.project SET status=%s WHERE id=%d',$namespace.'_project',$project_before['status'],$project->getID());}
      finally{
        try{queryfx($conn,'UPDATE %T.spaces_namespace SET description=%s WHERE id=%d',$namespace.'_spaces',$space_before['description'],$space->getID());}
        finally{$property->setValue($dictionary,$values);}
      }
    }
  }
}

/** Predict only the exact earlier cache error for this selected row mutation.
 * The preceding real preview has already validated this native cache shape.
 * Read it without creating, clearing or rewriting the caller's request cache.
 */
function governance_recovery_partial_space_mutation_failure(PhabricatorSpacesNamespace $space) {
  $cache=(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->getValue();
  if($cache===null){return 'recovery_restore_sources_stale';}
  if(!is_object($cache)||get_class($cache)!==PhutilInRequestKeyValueCache::class){
    throw new RuntimeException('recovery_partial_fixture_cache_unknown');}
  $values=(new ReflectionProperty(PhutilInRequestKeyValueCache::class,'cache'))->getValue($cache);
  if(!is_array($values)){throw new RuntimeException('recovery_partial_fixture_cache_unknown');}
  foreach($values as$key=>$value){
    if(!is_string($key)||!str_starts_with($key,'spaces.')){continue;}
    if($key===PhabricatorSpacesNamespaceQuery::KEY_DEFAULT){
      if(is_object($value)&&get_class($value)===PhabricatorSpacesNamespace::class&&$value->getPHID()===$space->getPHID()){
        return 'recovery_spaces_cache_default_changed';}
    }elseif(is_array($value)&&array_key_exists($space->getPHID(),$value)){
      return 'recovery_spaces_cache_rows_changed';}
  }
  return 'recovery_restore_sources_stale';
}
