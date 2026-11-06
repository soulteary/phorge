<?php
require_once __DIR__.'/partial-source-diagnostic.php';

/** Actual adapter probe owns only its short reader transaction. */
function governance_recovery_partial_policy_reader_probe($conn) {
  if($conn->isInsideTransaction()) {
    throw new RuntimeException('recovery_partial_policy_probe_outer_transaction');
  }
  $conn->openTransaction();
  try {return PhorgeRecoveryRestorePolicySource::capture($conn);}
  finally {if($conn->isInsideTransaction()){$conn->killTransaction();}}
}

/** Explicit raw fault injection, not native producer/serializer coverage. */
function governance_recovery_partial_policy_budget_fault($fixture,$conn,PhabricatorPolicy $policy) {
  $fixture->assertOwned();$namespace=$fixture->getNamespace();
  if(!is_string($namespace)||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)||
      $conn!==(new PhabricatorUser())->establishConnection('w')||$conn->isInsideTransaction()) {
    throw new RuntimeException('recovery_partial_policy_fault_route_unproved');
  }
  $id=PhorgeRecoveryStopRequest::transactionID((string)$policy->getID());$phid=$policy->getPHID();
  if(!is_string($phid)||strlen($phid)>64||!$phid) {
    throw new RuntimeException('recovery_partial_policy_fault_identity_unproved');
  }
  // Prove this exact native DAO row was selected and consumed by the actual
  // adapter's PRIMARY size/digest queries before introducing the one fault.
  $before=governance_recovery_partial_policy_reader_probe($conn);$selected=false;
  foreach($before['selected_source_rows']['policy']as$row) {
    if($row['identity_digest']===hash('sha256',$id)&&
        $row['field_digests']['phid']===hash('sha256','V'.strlen($phid).':'.$phid)) {
      if($selected){throw new RuntimeException('recovery_partial_policy_fault_identity_unproved');}
      $selected=true;
    }
  }
  if(!$selected){throw new RuntimeException('recovery_partial_policy_fault_not_selected');}
  $rules=phutil_json_encode(array(array('rule'=>str_repeat('x',
    PhorgeRecoveryRestorePolicySource::MAX_ROW_BYTES+1))));
  queryfx($conn,'UPDATE %T.policy SET rules=%s WHERE id=%s AND phid=%s',
    $namespace.'_policy',$rules,$id,$phid);
  $stored=queryfx_one($conn,'SELECT id,phid,OCTET_LENGTH(rules) rule_bytes,'.
    'SHA2(CAST(rules AS BINARY),256) rules_sha FROM %T.policy WHERE id=%s AND phid=%s',
    $namespace.'_policy',$id,$phid);
  if(!$stored||(string)$stored['id']!==$id||$stored['phid']!==$phid||
      (string)$stored['rule_bytes']!==(string)strlen($rules)||
      $stored['rules_sha']!==hash('sha256',$rules)) {
    throw new RuntimeException('recovery_partial_policy_fault_bytes_unproved');
  }
  $refused=false;
  try {governance_recovery_partial_policy_reader_probe($conn);}
  catch(RuntimeException $error) {
    if(get_class($error)!==RuntimeException::class||
        $error->getMessage()!=='recovery_policy_byte_budget'){throw $error;}
    $refused=true;
  }
  if(!$refused){throw new RuntimeException('recovery_partial_policy_fault_budget_unproved');}
}

/** Isolated real-source durable-review cases. UNRUN until Root schedules SQL. */
function governance_recovery_partial_composition_cases($fixture,array $requester,
  array $approver,$id,PhabricatorProject $project,
  PhabricatorSpacesNamespace $space,callable $refuse,callable $check,callable $fingerprint) {
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
    governance_recovery_partial_attempt('partial-config-mutation');
    $dictionary->setKeys(array('account.minimum-password-length'=>($values['account.minimum-password-length']??null)===123?124:123));
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');},
      'recovery_restore_sources_stale','recovery-partial-real-config-source-change-invalidates-durable-preview');
    $property->setValue($dictionary,$values);
    governance_recovery_partial_attempt('partial-restored-config-preview');
    $check(PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1')['permit_state']==='blocked',
      'recovery-partial-restored-config-remains-blocked');
    governance_recovery_partial_attempt('partial-project-mutation');
    queryfx($conn,'UPDATE %T.project SET status=%d WHERE id=%d',$namespace.'_project',
      PhabricatorProjectStatus::STATUS_ARCHIVED,$project->getID());
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');},
      'recovery_restore_sources_stale','recovery-partial-real-project-row-change-invalidates-durable-preview');
    queryfx($conn,'UPDATE %T.project SET status=%s WHERE id=%d',$namespace.'_project',$project_before['status'],$project->getID());
    governance_recovery_partial_attempt('partial-space-mutation');
    $space_failure=governance_recovery_partial_space_mutation_failure($space);
    queryfx($conn,'UPDATE %T.spaces_namespace SET description=%s WHERE id=%d',$namespace.'_spaces',
      'Synthetic partial changed description',$space->getID());
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');},
      $space_failure,'recovery-partial-real-space-row-change-invalidates-durable-preview');
    queryfx($conn,'UPDATE %T.spaces_namespace SET description=%s WHERE id=%d',$namespace.'_spaces',$space_before['description'],$space->getID());
    // Real native DAO insertion changes a previously captured row set, including
    // an originally empty Policy range. It never supplies a fake adapter result.
    governance_recovery_partial_attempt('partial-policy-addition');
    $policy=(new PhabricatorPolicy())->setRules(array())
      ->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->save();
    $refuse(static function()use($approver,$id){PhorgeRecoveryRestoreService::review($approver['context'],$id,'1','partial-policy-change');},
      'recovery_restore_sources_stale','recovery-partial-real-policy-row-addition-invalidates-durable-review');
    // Raw isolated fault injection is independently proved against the same
    // current writer and actual adapter before expecting the service refusal.
    governance_recovery_partial_attempt('partial-policy-budget');
    $refuse(static function()use($fixture,$conn,$policy,$approver,$id){
      governance_recovery_partial_policy_budget_fault($fixture,$conn,$policy);
      PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');
    },
      'recovery_policy_byte_budget','recovery-partial-real-policy-budget-fails-closed-without-review-write');
    $before_restored_policy=$fingerprint();
    governance_recovery_partial_attempt('partial-policy-delete');
    $deleted=array('id'=>(string)$policy->getID(),'phid'=>$policy->getPHID());
    $policy->delete();$policy=null;
    governance_recovery_partial_attempt('partial-restored-policy-preview');
    try {$restored=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');
      $restored_again=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');}
    catch(Throwable $original) {
      if(get_class($original)===RuntimeException::class&&
          $original->getMessage()==='recovery_restore_sources_stale') {
        try {$GLOBALS['governance_recovery_partial_source_difference']=
          governance_recovery_partial_restored_source_diagnostic($fixture,$approver['context'],$id,$deleted);}
        catch(Throwable $diagnostic_error) {$GLOBALS['governance_recovery_partial_source_difference']=
          array('schema'=>'native-partial-source-difference-v1','reader_succeeded'=>false);}
      }
      $unchanged=false;
      try {$unchanged=$before_restored_policy===$fingerprint();}catch(Throwable $fingerprint_error) {}
      if(!$unchanged) {$GLOBALS['governance_recovery_partial_source_difference']=
        array('schema'=>'native-partial-source-difference-v1','reader_succeeded'=>false);}
      else if(isset($GLOBALS['governance_recovery_partial_source_difference'])) {
        $GLOBALS['governance_recovery_partial_source_difference']['approval_fingerprint_unchanged']=true;
      }
      throw $original;
    }
    $check($before_restored_policy===$fingerprint()&&$restored['permit_state']==='blocked'&&$restored_again===$restored,
      'recovery-partial-restored-policy-rows-remain-blocked');
    governance_recovery_partial_attempt();
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
