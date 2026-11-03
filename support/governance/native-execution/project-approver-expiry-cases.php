<?php

/** Actual audit write delay crosses an independently approved native session TTL. */
function governance_project_approver_expiry_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned(); $a=$state['executor']; $cookie=$_COOKIE;
  $sessions=new PhabricatorAuthSessionEngine();
  $reviewer=(new PhabricatorUser())->setUsername('gexpiry'.substr($fixture->getNamespace(),-10))
    ->setRealName('Synthetic expiring Project reviewer')->setIsApproved(1)->setIsAdmin(1)->setIsEmailVerified(1)->save();
  $b=governance_native_request($reviewer,$sessions);
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try {
    $project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic approver expiry')
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($a['viewer']->getPHID())
      ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();
  } finally { unset($off); }
  $conn=$project->establishConnection('w'); $setup=$fixture->getAdmin();
  $trigger='`'.$fixture->getNamespace().'_project`.`governance_approver_expiry`';
  $installed=false; $mode=(new mysqli_driver())->report_mode;
  $prior_marker=queryfx_one($conn,'SELECT @governance_project_expiry_audit n')['n'];
  if($prior_marker!==null){throw new RuntimeException('project_execution_expiry_marker_preexisting');}
  try {
    $proposal=PhorgeProjectPolicyApprovalService::call($a['context'],'submit',array(
      'target_phid'=>$project->getPHID(),'new_policy'=>PhabricatorPolicies::POLICY_USER,
      'reason'=>'Synthetic current approver expiry after audit wait','ttl_seconds'=>120));
    $id=$proposal['approval_id'];
    // Set an actual native session expiry before approval captures its binding.
    // No request is made by this reviewer between approval and final audit.
    $session=$b['viewer']->getSession();
    $dbnow=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
    $session->setSessionExpires($dbnow+5)->save();
    PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$id));
    $before=queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$id);
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $setup->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('project','governance_executionaudit').
      " FOR EACH ROW BEGIN IF NEW.action='executed' AND BINARY NEW.approvalID=BINARY '".$setup->real_escape_string($id).
      "' THEN SET @governance_project_expiry_audit=UNIX_TIMESTAMP(); DO SLEEP(8); END IF; END");
    $installed=true;
    $present=$setup->query("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
      $setup->real_escape_string($fixture->getNamespace().'_project')."' AND TRIGGER_NAME='governance_approver_expiry'")->fetch_assoc();
    $check((int)$present['n']===1 && $before['state']==='approved',
      'project-approval-owned-audit-expiry-wait-trigger-installed');
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
    $refused=false;
    try {
      (new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($a['request'])
        ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setProjectApprovalID($id)
        ->setContinueOnMissingFields(true)->applyTransactions($project,array((new PhabricatorProjectTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue(PhabricatorPolicies::POLICY_USER)));
    } catch (RuntimeException $error) {
      if ($error->getMessage()!=='governance_native_credential_ineligible') { throw $error; }
      $refused=true;
    }
    $after=queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$id);
    $row=queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
    $native=queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorProjectTransaction(),$project->getPHID());
    $audit=queryfx_one($conn,'SELECT COUNT(*) n FROM governance_executionaudit WHERE approvalID=%s AND action=%s',$id,'executed');
    $worker=(new PhabricatorWorkerActiveTask())->establishConnection('r');
    $queue=queryfx_one($worker,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorWorkerActiveTask(),$project->getPHID());
    $entered=queryfx_one($conn,'SELECT @governance_project_expiry_audit n')['n'];
    $check($refused && $entered!==null && (int)$entered<$dbnow+5 && (int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n']>=$dbnow+5 &&
      $after===$before && $row['editPolicy']===$a['viewer']->getPHID() &&
      (int)$native['n']===0 && (int)$audit['n']===0 && (int)$queue['n']===0 && !$conn->isInsideTransaction(),
      'project-approval-approver-natural-expiry-during-audit-rolls-back-all-native-domain-writes');
    return array('actual_session_expiry'=>true,'actual_audit_write_delay'=>true,'same_native_transaction_rollback'=>true,
      'external_effect_rollback_claimed'=>false);
  } finally {
    try { mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); if($installed){$setup->query('DROP TRIGGER '.$trigger);} }
    finally {
      try { queryfx($conn,'SET @governance_project_expiry_audit=NULL'); }
      finally { mysqli_report($mode); $_COOKIE=$cookie; }
    }
  }
}
