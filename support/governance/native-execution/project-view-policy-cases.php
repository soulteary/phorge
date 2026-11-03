<?php

/** Real native VIEW-only transition. Not proof of JOIN/Space/hierarchy support. */
function governance_project_view_policy_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$a=$state['executor'];$b=$state['approver'];
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try{$project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic approved VIEW transition')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($a['viewer']->getPHID())
    ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($off);}
  $project->attachParentProject(null)->attachMemberPHIDs(array());$conn=$project->establishConnection('w');
  // Independent approval must not override the native self-VIEW validator.
  $invalid=PhorgeProjectPolicyApprovalService::call($a['context'],'submit',array(
    'target_phid'=>$project->getPHID(),
    'intent'=>array('version'=>3,'operation'=>'project.view-policy','policy'=>PhabricatorPolicies::POLICY_NOONE),
    'reason'=>'Synthetic native validation refusal','ttl_seconds'=>120));
  PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$invalid['approval_id']));
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];$refused=false;
  try{(new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($a['request'])
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->setProjectApprovalID($invalid['approval_id'])->setContinueOnMissingFields(true)
    ->applyTransactions($project,array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue(PhabricatorPolicies::POLICY_NOONE)));}
  catch(PhabricatorApplicationTransactionValidationException$error){
    foreach($error->getErrors()as$validation){if($validation->getType()===PhabricatorTransactions::TYPE_VIEW_POLICY){$refused=true;}}
    if(!$refused){throw$error;}
  }
  $unchanged=queryfx_one($conn,'SELECT viewPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
  $unused=queryfx_one($conn,'SELECT state,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$invalid['approval_id']);
  $records=queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorProjectTransaction(),$project->getPHID());
  $check($refused&&$unchanged['viewPolicy']===PhabricatorPolicies::POLICY_USER&&$unused['state']==='approved'&&
    $unused['nativeGroupID']===null&&(int)$records['n']===0,
    'project-view-approval-does-not-override-native-self-view-validator');
  $intent=array('version'=>3,'operation'=>'project.view-policy','policy'=>$a['viewer']->getPHID());
  $proposal=PhorgeProjectPolicyApprovalService::call($a['context'],'submit',array(
    'target_phid'=>$project->getPHID(),'intent'=>$intent,'reason'=>'Synthetic bounded VIEW approval','ttl_seconds'=>120));
  PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$proposal['approval_id']));
  $check(PhabricatorPolicyFilter::hasCapability($b['viewer'],$project,PhabricatorPolicyCapability::CAN_VIEW)&&
    !PhabricatorPolicyFilter::hasCapability($b['viewer'],$project,PhabricatorPolicyCapability::CAN_EDIT),
    'project-view-approver-current-view-does-not-require-edit');
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
  $applied=(new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($a['request'])
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->setProjectApprovalID($proposal['approval_id'])->setContinueOnMissingFields(true)
    ->applyTransactions($project,array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue($a['viewer']->getPHID())));
  $saved=queryfx_one($conn,'SELECT viewPolicy,editPolicy,joinPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
  $ticket=queryfx_one($conn,'SELECT state,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$proposal['approval_id']);
  $audit=queryfx_one($conn,'SELECT COUNT(*) n FROM governance_executionaudit WHERE approvalID=%s AND action=%s',$proposal['approval_id'],'executed');
  $check(count($applied)===1&&$applied[0]->getTransactionType()===PhabricatorTransactions::TYPE_VIEW_POLICY&&
    $saved['viewPolicy']===$a['viewer']->getPHID()&&$saved['editPolicy']===$a['viewer']->getPHID()&&
    $saved['joinPolicy']===PhabricatorPolicies::POLICY_USER&&$ticket['state']==='consumed'&&
    $ticket['nativeGroupID']===$applied[0]->getTransactionGroupID()&&(int)$audit['n']===1,
    'project-view-native-record-policy-ticket-audit-commit-together');
  $check(!PhabricatorPolicyFilter::hasCapability($b['viewer'],$project,PhabricatorPolicyCapability::CAN_VIEW),
    'project-view-approved-transition-may-remove-approver-future-view');
  $before=queryfx_all($conn,'SELECT * FROM governance_executionaudit WHERE approvalID=%s ORDER BY id',$proposal['approval_id']);
  $refused=false;
  try{PhorgeProjectPolicyApprovalService::call($b['context'],'history',array('approval_id'=>$proposal['approval_id']));}
  catch(RuntimeException$error){if($error->getMessage()!=='project_execution_native_view_required'){throw$error;}$refused=true;}
  $check($refused&&$before===queryfx_all($conn,'SELECT * FROM governance_executionaudit WHERE approvalID=%s ORDER BY id',$proposal['approval_id']),
    'project-view-history-still-requires-current-native-view');
  $check(!$conn->isInsideTransaction()&&!$conn->isReadLocking(),
    'project-view-native-writer-scope-released');
  return array('native_view_only'=>true,'future_approver_view_not_added'=>true,
    'whole_family_ready'=>false,'join_and_space_supported'=>false);
}
