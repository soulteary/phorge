<?php

/** Remove only the exact owned baseline row; COMMIT before any range lease. */
function governance_recovery_policy_remove_baseline_seed($fixture) {
  $fixture->assertOwned();$conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('recovery_policy_seed_outer_transaction');}
  $ids=$fixture->getBaselineIDs();
  $expected=array('id'=>'1','phid'=>$ids['policy'],'rules'=>json_encode(array(array(
    'action'=>'allow','rule'=>'PhabricatorProjectsPolicyRule','value'=>array($ids['project'])))),
    'defaultAction'=>'deny','dateCreated'=>'0','dateModified'=>'0');
  // Reject a raw physical transaction before taking ownership. This fixture
  // setup has no caller transaction to commit or roll back.
  queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
  $conn->openTransaction();
  try {
    $rows=queryfx_all($conn,'SELECT id,phid,rules,defaultAction,dateCreated,dateModified FROM %R ORDER BY id FOR UPDATE',new PhabricatorPolicy());
    $actual=count($rows)===1?$rows[0]:array();
    foreach($actual as$key=>$value){$actual[$key]=(string)$value;}
    if($actual!==$expected){throw new RuntimeException('recovery_policy_baseline_seed_unrecognized');}
    queryfx($conn,'DELETE FROM %R WHERE id=1 AND BINARY phid=BINARY %s',new PhabricatorPolicy(),$expected['phid']);
    if($conn->getAffectedRows()!==1){throw new RuntimeException('recovery_policy_baseline_seed_delete_failed');}
    $conn->saveTransaction();
  }catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
  $observed=governance_native_setup_query($fixture,'SELECT COUNT(*) n FROM '.$fixture->table('policy','policy'))->fetch_assoc();
  if($conn->isInsideTransaction()||(string)$observed['n']!=='0'){
    throw new RuntimeException('recovery_policy_empty_committed_seed_profile_required');}
  return $expected;
}

function governance_recovery_policy_restore_baseline_seed($fixture,array $seed) {
  $fixture->assertOwned();$conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('recovery_policy_seed_outer_transaction');}
  queryfx($conn,'INSERT INTO %R (id,phid,rules,defaultAction,dateCreated,dateModified) VALUES (1,%s,%s,%s,0,0)',
    new PhabricatorPolicy(),$seed['phid'],$seed['rules'],$seed['defaultAction']);
  $rows=governance_native_setup_query($fixture,'SELECT id,phid,rules,defaultAction,dateCreated,dateModified FROM '.
    $fixture->table('policy','policy').' WHERE id=1')->fetch_all(MYSQLI_ASSOC);
  $actual=count($rows)===1?$rows[0]:array();foreach($actual as$key=>$value){$actual[$key]=(string)$value;}
  if($actual!==$seed){throw new RuntimeException('recovery_policy_baseline_seed_restore_failed');}
}

/** Isolated component driver. Never substitutes for all-mode native evidence. */
function governance_recovery_policy_focused_cases($fixture,callable $check) {
  $fixture->assertOwned();$namespace=$fixture->getNamespace();
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
    throw new RuntimeException('recovery_policy_source_revision_required');}
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    throw new RuntimeException('recovery_policy_namespace_unknown');}
  $conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('recovery_policy_fixture_outer_transaction');}
  // The exact development migration is now part of the native patch loader
  // and fixture-reviewed patch allowlist. No hand-made table shape substitutes
  // for native applyPatchSQL or the service's exact three-table verification.
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once __DIR__.'/account-cases.php';
  require_once __DIR__.'/policy-range-cases.php';
  require_once __DIR__.'/policy-source-cases.php';
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;$flags=null;$environment=null;
  try {
    $actor=(new PhabricatorUser())->setUsername('policyactor'.substr($namespace,-10))
      ->setRealName('Synthetic Policy Range Administrator')->setIsAdmin(1)->setIsApproved(1)->save();
    $sessions=new PhabricatorAuthSessionEngine();$admin=governance_native_request($actor,$sessions);
    $seed=governance_recovery_policy_remove_baseline_seed($fixture);
    $range_error=null;
    try{governance_recovery_policy_range_cases($fixture,$check,array('admin'=>$admin));}
    catch(Throwable$error){$range_error=$error;throw$error;}
    finally{
      try{governance_recovery_policy_restore_baseline_seed($fixture,$seed);}
      catch(Throwable$seed_error){
        // Preserve the original case failure and separately retain cleanup
        // failure; never convert either into component acceptance.
        if($range_error===null){throw$seed_error;}
        global $cleanup_failures;
        if(!is_array($cleanup_failures)){throw$seed_error;}
        $cleanup_failures[]=array('stage'=>'owned-policy-baseline-seed-restore','error_type'=>get_class($seed_error));
      }
    }
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    governance_recovery_policy_source_cases($fixture,$check);
    return array('profile'=>'isolated-native-policy-range-component-v1','source_revision'=>$revision,
      'actual_native_policy_dao'=>true,'service_owned_rr_transaction'=>true,
      'actual_empty_and_populated_primary_insert_waits'=>true,
      'registered_policy_workers'=>2,'policy_source_SQL_obligations'=>9,
      'identified_fixture_seed_restored'=>true,
      'all_mode_native_evidence'=>false,'full_storage_upgrade_acceptance'=>false,
      'all_thirteen_families_complete'=>false,'restoration_authorized'=>false,
      'production_scale_ready'=>false,'HTTP_controller_evidence'=>false);
  }finally{
    try{if($environment){governance_recovery_account_restore_fixture_environment($environment);}}
    finally{unset($flags);$_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}}
  }
}
