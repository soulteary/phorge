<?php

/** This is a synthetic component driver, never a restoration entry point. */
function governance_recovery_org_lifecycle_focused($fixture,callable $check) {
  $fixture->assertOwned();$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
    throw new RuntimeException('recovery_lifecycle_source_revision');}
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once __DIR__.'/account-cases.php';
  require_once __DIR__.'/organization-lifecycle-cases.php';
  $cookies=$_COOKIE;$server=$_SERVER;
  try{
    $users=array();foreach(array('admin','member')as$role){
      $users[$role]=(new PhabricatorUser())->setUsername('rlf'.$role.substr($fixture->getNamespace(),-10))
        ->setRealName('Synthetic lifecycle '.$role)->setIsAdmin($role==='admin'?1:0)
        ->setIsApproved(1)->setIsEmailVerified(1)->save();
    }
    $sessions=new PhabricatorAuthSessionEngine();$admin=governance_native_request($users['admin'],$sessions);
    $writer=(new PhabricatorUser())->establishConnection('w');$writer->openTransaction();
    try{PhorgeGovernanceNativeIdentitySource::lockCurrent($admin['context'],$writer);$writer->saveTransaction();}
    finally{if($writer->isInsideTransaction()){$writer->killTransaction();}}
    governance_recovery_org_lifecycle_cases($fixture,$check,array('users'=>$users,'sessions'=>$sessions,'admin'=>$admin));
    return array('profile'=>'isolated-indexed-organization-lifecycle-generation-v1','source_revision'=>$revision,
      'actual_service_proposal_approval_success_rollback_replay'=>true,
      'actual_transfer_handover_success_rollback_replay'=>true,'SQL_obligations'=>36,
      'derived_metadata_is_authority'=>false,'automatic_legacy_backfill'=>false,
      'retained_metadata_privilege_DDL_lease'=>false,'producer_concurrency_proved'=>true,
      'organization_lifecycle_readset_complete'=>false,'all_thirteen_families_complete'=>false,
      'restoration_authorized'=>false,'HTTP_controller_evidence'=>false);
  }finally{$_COOKIE=$cookies;$_SERVER=$server;}
}
