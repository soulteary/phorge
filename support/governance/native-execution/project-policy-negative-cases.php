<?php

/** Negative real native editor cases, with no mocked native pipeline. */
function governance_project_policy_negative_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();
  $a=$state['executor']; $b=$state['approver']; $project=$state['project'];
  $conn=$project->establishConnection('w');
  $source=PhabricatorContentSource::newForSource('console',array());
  $editor=function($ticket) use ($a,$source) {
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
    return (new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])
      ->setContentSource($source)->setRequest($a['request'])->setProjectApprovalID($ticket)
      ->setContinueOnMissingFields(true);
  };
  $refuse=function($callable,$reason,$id) use ($check) {
    $rejected=false;
    try { $callable(); } catch (RuntimeException $error) {
      if ($error->getMessage()!==$reason) { throw $error; }
      $rejected=true;
    }
    $check($rejected,$id);
  };
  $invalid_flag=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',null);
  try {
    $refuse(function() use ($editor,$project,$state) {
      $editor($state['consumed_id'])->applyTransactions($project,array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue(PhabricatorPolicies::POLICY_ADMIN)));
    },'project_execution_configuration_control_type_refused','project-approval-invalid-feature-flag-never-falls-back-to-native-write');
  } finally { unset($invalid_flag); }
  $old=$project->getEditPolicy();
  $project->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
  $refuse(function() use ($project) { $project->save(); },
    'project_execution_direct_security_save_refused','project-approval-direct-policy-dao-write-blocked');
  $project->setEditPolicy($old);
  $replay_before=queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$state['consumed_id']);
  $refuse(function() use ($editor,$project,$state) {
    $editor($state['consumed_id'])->setContinueOnNoEffect(true)->applyTransactions($project,array(
      (new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)
        ->setNewValue(PhabricatorPolicies::POLICY_USER)));
  },'project_execution_expanded_closure_unsupported','project-approval-noop-replay-is-not-a-second-native-execution');
  $replay_after=queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$state['consumed_id']);
  $check($replay_before['state']==='consumed' && $replay_before['consumedAt']!==null && $replay_before['nativeGroupID']!==null &&
    $replay_before===$replay_after && !$conn->isInsideTransaction() && !$conn->isReadLocking() && !$conn->isWriteLocking(),
    'project-approval-noop-replay-preserves-consumed-ticket-and-owned-frame');
  $spoof=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)
    ->setNewValue(PhabricatorPolicies::POLICY_ADMIN)->setAuthorPHID($b['viewer']->getPHID());
  $refuse(function() use ($editor,$project,$state,$spoof) {
    $editor($state['consumed_id'])->applyTransactions($project,array($spoof));
  },'project_execution_envelope_unsupported','project-approval-caller-cannot-spoof-native-author');
  $tag=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)
    ->setNewValue(PhabricatorPolicies::POLICY_ADMIN)->setMetadataValue('herald:transcriptID',1);
  $refuse(function() use ($editor,$project,$state,$tag) {
    $editor($state['consumed_id'])->applyTransactions($project,array($tag));
  },'project_execution_envelope_unsupported','project-approval-caller-producer-metadata-blocked');
  // An approved ticket must not override the native forced-policy validator.
  $loss=PhorgeProjectPolicyApprovalService::call($a['context'],'submit',array(
    'target_phid'=>$project->getPHID(),'new_policy'=>PhabricatorPolicies::POLICY_NOONE,
    'reason'=>'Synthetic native self-loss refusal','ttl_seconds'=>120));
  PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$loss['approval_id']));
  $native_refused=false;
  try {
    $editor($loss['approval_id'])->applyTransactions($project,array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue(PhabricatorPolicies::POLICY_NOONE)));
  } catch (PhabricatorApplicationTransactionValidationException $error) {
    $types=array_values(array_unique(mpull($error->getErrors(),'getType')));
    if ($types!==array(PhabricatorTransactions::TYPE_EDIT_POLICY)) { throw $error; }
    $native_refused=true;
  }
  $loss_ticket=queryfx_one($conn,'SELECT state,consumedAt FROM governance_projectapproval WHERE approvalID=%s',$loss['approval_id']);
  $check($native_refused && $loss_ticket['state']==='approved' && $loss_ticket['consumedAt']===null &&
    queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID())['editPolicy']===PhabricatorPolicies::POLICY_USER,
    'project-approval-never-overrides-native-self-loss-policy-validation');
  // A distinct native target isolates the rollback from previous committed work.
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try {
    $rollback=PhabricatorProject::initializeNewProject($a['viewer'])
      ->setName('Synthetic Approval Rollback')->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy($a['viewer']->getPHID())->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();
  } finally { unset($off); }
  $proposal=PhorgeProjectPolicyApprovalService::call($a['context'],'submit',array(
    'target_phid'=>$rollback->getPHID(),'new_policy'=>PhabricatorPolicies::POLICY_USER,
    'reason'=>'Synthetic native transaction failure','ttl_seconds'=>120));
  $id=$proposal['approval_id'];
  PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$id));
  $trigger='`'.$fixture->getNamespace().'_project`.`governance_execution_fail`';
  $installed=false; $mode=(new mysqli_driver())->report_mode;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $setup=$fixture->getAdmin();
    $setup->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.
      $fixture->table('project',(new PhabricatorProjectTransaction())->getTableName()).
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated-project-native-failure'");
    $installed=true;
    $present=$setup->query("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
      $setup->real_escape_string($fixture->getNamespace().'_project')."' AND TRIGGER_NAME='governance_execution_fail'")->fetch_assoc();
    $check((int)$present['n']===1,'project-approval-owned-native-transaction-fault-installed');
    $failed=false;
    try {
      $editor($id)->applyTransactions($rollback,array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue(PhabricatorPolicies::POLICY_USER)));
    } catch (Throwable $error) {
      if (!in_array(get_class($error),array(AphrontQueryException::class,mysqli_sql_exception::class),true) ||
          $error->getCode()!==1644 ||
          !preg_match('/\A(?:#1644:\s*)?isolated-project-native-failure\z/',$error->getMessage())) { throw $error; }
      $failed=true;
    }
    $check($failed,'project-approval-actual-native-record-write-fault-raised');
  } finally {
    try {
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      if ($installed) { $fixture->getAdmin()->query('DROP TRIGGER '.$trigger); }
    }
    finally { mysqli_report($mode); }
  }
  $ticket=queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$id);
  $row=queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$rollback->getID());
  $native=queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorProjectTransaction(),$rollback->getPHID());
  $audit=queryfx_one($conn,'SELECT COUNT(*) n FROM governance_executionaudit WHERE approvalID=%s AND action=%s',$id,'executed');
  $worker=(new PhabricatorWorkerActiveTask())->establishConnection('r');
  $queue=queryfx_one($worker,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorWorkerActiveTask(),$rollback->getPHID());
  $check($ticket['state']==='approved' && $ticket['consumedAt']===null && $ticket['nativeGroupID']===null &&
    $row['editPolicy']===$a['viewer']->getPHID() && (int)$native['n']===0 &&
    (int)$audit['n']===0 && (int)$queue['n']===0,
    'project-approval-native-failure-rolls-back-consume-row-record-and-audit-with-no-publish');
  $check(!$conn->isInsideTransaction() && !$conn->isReadLocking(),
    'project-approval-failed-native-execution-releases-owned-locks');
  // Unknown nested native frames are intentionally NOT guessed or drained.
  // A fresh owned exec dies with those frames; the independent parent must
  // prove terminal process, exact SQL thread absence and no commit before cleanup.
  require_once __DIR__.'/project-policy-nested-process.php';
  $nested=governance_project_policy_nested_process($fixture,$check,$a,$rollback,$id);
  return array('actual_project_transaction_write_fault'=>true,'same_transaction_rollback'=>true,
    'external_postcommit_rollback_claim'=>false,'nested_unknown'=>$nested);
}
