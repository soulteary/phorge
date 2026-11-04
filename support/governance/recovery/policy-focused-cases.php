<?php

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
    governance_recovery_policy_range_cases($fixture,$check,array('admin'=>$admin));
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    governance_recovery_policy_source_cases($fixture,$check);
    return array('profile'=>'isolated-native-policy-range-component-v1','source_revision'=>$revision,
      'actual_native_policy_dao'=>true,'service_owned_rr_transaction'=>true,
      'actual_empty_and_populated_primary_insert_waits'=>true,
      'registered_policy_workers'=>2,'policy_source_SQL_obligations'=>9,
      'all_mode_native_evidence'=>false,'full_storage_upgrade_acceptance'=>false,
      'all_thirteen_families_complete'=>false,'restoration_authorized'=>false,
      'production_scale_ready'=>false,'HTTP_controller_evidence'=>false);
  }finally{
    try{if($environment){governance_recovery_account_restore_fixture_environment($environment);}}
    finally{unset($flags);$_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}}
  }
}
