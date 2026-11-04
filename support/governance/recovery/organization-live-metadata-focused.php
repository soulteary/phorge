<?php

/** Fresh scoped actual metadata collector probes, never a recovery endpoint. */
function governance_recovery_org_metadata_focused($fixture,callable $check) {
  $fixture->assertOwned();$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
    throw new RuntimeException('recovery_metadata_source_revision');}
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once __DIR__.'/account-cases.php';
  require_once __DIR__.'/organization-live-metadata-cases.php';
  $cookies=$_COOKIE;$server=$_SERVER;$flags=null;$environment=null;
  try{
    $actor=(new PhabricatorUser())->setUsername('orgmetadata'.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Metadata Administrator')->setIsAdmin(1)->setIsApproved(1)->save();
    $admin=governance_native_request($actor,new PhabricatorAuthSessionEngine());
    // Explicit bootstrap issuer initialization is committed before capture;
    // the metadata collector itself never initializes or changes an issuer.
    $writer=(new PhabricatorUser())->establishConnection('w');$writer->openTransaction();
    try{PhorgeGovernanceNativeIdentitySource::lockCurrent($admin['context'],$writer);$writer->saveTransaction();}
    catch(Throwable$error){if($writer->isInsideTransaction()){$writer->killTransaction();}throw$error;}
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    governance_recovery_org_live_metadata_cases($fixture,$check,array('admin'=>$admin));
    return array('profile'=>'isolated-live-organization-metadata-observation-v1','source_revision'=>$revision,
      'actual_global_control_and_limited_writer'=>true,'actual_role_none_proxy_null'=>true,
      'actual_trigger_incoming_outgoing_fk_negatives'=>true,'metadata_SQL_obligations'=>6,
      'metadata_control_connections_closed'=>2,'new_accounts_or_grants'=>false,
      'active_role_negative_actual'=>false,'proxied_authentication_negative_actual'=>false,
      'grant_revoke_race_proved'=>false,'concurrent_DDL_absence_lease_proved'=>false,
      'organization_lifecycle_readset_complete'=>false,'all_thirteen_families_complete'=>false,
      'restoration_authorized'=>false,'production_metadata_credentials_provisioned'=>false,
      'HTTP_controller_evidence'=>false);
  }finally{
    try{if($environment){governance_recovery_account_restore_fixture_environment($environment);}}
    finally{unset($flags);$_COOKIE=$cookies;$_SERVER=$server;}
  }
}
