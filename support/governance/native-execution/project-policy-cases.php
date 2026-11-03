<?php

/** Writable, real native editor integration in an owned disposable full schema. */
function governance_project_policy_execution_cases($fixture, callable $check) {
  $fixture->assertOwned();
  if (get_class($fixture)!==PhorgeGovernanceCompletionFixture::class ||
      PHP_SAPI!=='cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1') {
    throw new RuntimeException('project_execution_owned_fixture_required');
  }
  $old=PhabricatorEnv::getEnvConfigIfExists('governance.project-approval.enabled',false);
  $check($old===false && !PhorgeProjectExecutionCatalog::enabled(),'project-approval-default-disabled-actual-config');
  $override=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  $cookie_before=$_COOKIE;
  try {
  $sessions=new PhabricatorAuthSessionEngine();
  $suffix=substr($fixture->getNamespace(),-10);
  $executor=(new PhabricatorUser())->setUsername('gx'.$suffix)->setRealName('Synthetic Project Executor')
    ->setIsApproved(1)->setIsEmailVerified(1)->save();
  $approver=(new PhabricatorUser())->setUsername('gp'.$suffix)->setRealName('Synthetic Project Approver')
    ->setIsApproved(1)->setIsAdmin(1)->setIsEmailVerified(1)->save();
  $make_request=function(PhabricatorUser $user) use ($sessions) {
    return governance_native_request($user,$sessions);
  };
  $a=$make_request($executor); $b=$make_request($approver);
  $source=PhabricatorContentSource::newForSource('console',array());
  $project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic Approval Project')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($executor->getPHID())
    ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)
    ->setSubtype(PhabricatorEditEngineSubtype::SUBTYPE_DEFAULT)->save();
  $project->attachMemberPHIDs(array())->attachParentProject(null);
  $check(!PhabricatorPolicyFilter::hasCapability($b['viewer'],$project,PhabricatorPolicyCapability::CAN_EDIT) &&
    PhabricatorPolicyFilter::hasCapability($b['viewer'],$project,PhabricatorPolicyCapability::CAN_VIEW),
    'project-approval-reviewer-native-view-without-edit');
  $override->overrideEnvConfig('governance.project-approval.enabled',true);
  $conn=$project->establishConnection('w');
  $payload=array('target_phid'=>$project->getPHID(),'new_policy'=>PhabricatorPolicies::POLICY_USER,
    'reason'=>'Synthetic bounded approval transition','ttl_seconds'=>120);
  $submit=PhorgeProjectPolicyApprovalService::call($a['context'],'submit',$payload);
  $id=$submit['approval_id'];
  $check(is_string($id) && strlen($id)===64,'project-approval-real-native-proposal-persisted');
  $rejected=false;
  try { PhorgeProjectPolicyApprovalService::call($a['context'],'approve',array('approval_id'=>$id)); }
  catch (RuntimeException $error) {
    if ($error->getMessage()!=='project_execution_approver_admin_required') { throw $error; }
    $rejected=true;
  }
  $check($rejected,'project-approval-no-self-nonadmin-approval');
  PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$id));
  $check(queryfx_one($conn,'SELECT state FROM governance_projectapproval WHERE approvalID=%s',$id)['state']==='approved',
    'project-approval-independent-current-native-admin-approved');
  $misuse=PhorgeProjectNativeExecutionScope::open($a['context'],$project,
    PhabricatorPolicies::POLICY_USER,'execute',$id);
  try {
    $rejected=false;
    try { $misuse->commitService(); }
    catch (RuntimeException $error) {
      if ($error->getMessage()!=='project_execution_native_editor_commit_required') { throw $error; }
      $rejected=true;
    }
    $check($rejected,'project-approval-service-cannot-commit-executor-scope');
  } finally { $misuse->close(); }
  $xaction=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)
    ->setNewValue(PhabricatorPolicies::POLICY_USER);
  $editor=(new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])
    ->setContentSource($source)->setRequest($a['request'])->setProjectApprovalID($id)
    ->setContinueOnMissingFields(true);
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
  $applied=$editor->applyTransactions($project,array($xaction));
  $ticket=queryfx_one($conn,'SELECT state,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$id);
  $saved=queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
  $audit=queryfx_one($conn,'SELECT COUNT(*) n FROM governance_executionaudit WHERE approvalID=%s AND action=%s',$id,'executed');
  $check($ticket['state']==='consumed' && $saved['editPolicy']===PhabricatorPolicies::POLICY_USER &&
    count($applied)===1 && $ticket['nativeGroupID']===$applied[0]->getTransactionGroupID() && (int)$audit['n']===1,
    'project-approval-native-mutation-consume-native-record-audit-committed');
  $check(!$conn->isInsideTransaction() && !$conn->isReadLocking(),
    'project-approval-real-commit-and-native-locks-released');
  $check(true,'project-approval-real-editor-postcommit-returned');
  require_once __DIR__.'/project-policy-negative-cases.php';
  $negative=governance_project_policy_negative_cases($fixture,$check,array(
    'executor'=>$a,'approver'=>$b,'project'=>$project,'consumed_id'=>$id));
  require_once __DIR__.'/project-approver-expiry-cases.php';
  $approver_expiry=governance_project_approver_expiry_cases($fixture,$check,array('executor'=>$a));
  require_once __DIR__.'/project-flat-source-cases.php';
  $flat_sources=governance_project_flat_source_cases($fixture,$check,array(
    'executor'=>$a,'approver'=>$b,'project'=>$project));
  require_once __DIR__.'/project-membership-lease-cases.php';
  $membership=governance_project_membership_lease_cases($fixture,$check,array('executor'=>$a,'approver'=>$b));
  require_once __DIR__.'/project-mfa-cases.php';
  $mfa=governance_project_mfa_cases($fixture,$check,array('approver'=>$b));
  require_once __DIR__.'/config-epoch-cases.php';
  $generations=governance_project_config_epoch_cases($fixture,$check,$b['viewer'],array(
    'executor'=>$a,'approver'=>$b,'project'=>$project));
  return array('final_approver_expiry'=>$approver_expiry,'configured_native_mfa'=>$mfa,'native_self_membership'=>$membership,'flat_source_checks'=>$flat_sources,'configuration_generations'=>$generations,'negative_cases'=>$negative,'native_project_edit_policy'=>true,'native_capabilities_retained'=>true,
    'approver_view_not_edit'=>true,'same_native_transaction'=>true,
    'whole_family_ready'=>false,'whole_pipeline_side_effect_atomic'=>false);
  } finally {
    $override->overrideEnvConfig('governance.project-approval.enabled',$old);
    $_COOKIE=$cookie_before;
  }
}
