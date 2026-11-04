<?php

/** Isolated neutral organization producer profile; no restoration API. */
function governance_recovery_organization_focused_cases($fixture,callable $check) {
  $fixture->assertOwned();$namespace=$fixture->getNamespace();
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)||
    !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    throw new RuntimeException('recovery_org_component_binding_required');}
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once __DIR__.'/account-cases.php';
  require_once __DIR__.'/organization-producer-cases.php';
  require_once __DIR__.'/organization-metadata-ddl-cases.php';
  $cookies=$_COOKIE;$server=$_SERVER;
  try{
    $actor=(new PhabricatorUser())->setUsername('orgproducer'.substr($namespace,-10))
      ->setRealName('Synthetic Organization Producer Administrator')->setIsAdmin(1)->setIsApproved(1)->save();
    $admin=governance_native_request($actor,new PhabricatorAuthSessionEngine());
    governance_recovery_organization_producer_cases($fixture,$check,array('admin'=>$admin));
    governance_recovery_organization_metadata_ddl_cases($fixture,$check);
    return array('profile'=>'isolated-native-organization-producer-component-v1',
      'source_revision'=>$revision,'registered_organization_workers'=>2,
      'organization_producer_SQL_obligations'=>12,'organization_metadata_DDL_SQL_obligations'=>8,
      'actual_native_dao_insert'=>true,'actual_primary_mutex_waits'=>true,
      'caller_nested_and_raw_transaction_controls'=>true,
      'actual_fixed_parent_table_incoming_fk_write_mdl_probe'=>true,
      'all_table_all_ddl_absence_fence_proved'=>false,
      'metadata_live_privilege_collector_executed'=>false,
      'all_mode_native_evidence'=>false,'all_thirteen_families_complete'=>false,
      'restoration_authorized'=>false,'HTTP_controller_evidence'=>false);
  }finally{$_COOKIE=$cookies;$_SERVER=$server;}
}
