<?php
/**
 * Native SQL candidate, UNRUN. The Root-owned runner must prepare the existing
 * File fixture with its real paired Gorge service and owned-RR configuration.
 * This is deliberately separate from File39 / partial31; neither old receipt
 * certifies these observations. No account, policy or optional flags changed.
 */
/** Selected physical reader relationships only, never a shared snapshot. */
function governance_recovery_file_parent_policy_reader_relationships($scope) {
  $scope->getFacts();
  $connections=array('user'=>(new PhabricatorUser())->establishConnection('w'),
    'file'=>(new PhabricatorFile())->establishConnection('r'),
    'task'=>(new ManiphestTask())->establishConnection('r'));
  $identities=array();$namespace=null;
  foreach($connections as$kind=>$connection) {
    if(get_class($connection)!==AphrontMySQLiDatabaseConnection::class||
        ($kind!=='user'&&($connection->isInsideTransaction()||
        $connection->isReadLocking()||$connection->isWriteLocking()))) {
      throw new RuntimeException('file_parent_policy_reader_route_unknown');
    }
    $row=queryfx_one($connection,'SELECT CAST(CONNECTION_ID() AS CHAR) connection_id,'.
      '@@server_uuid server_uuid,DATABASE() database_name');
    if(!$row||array_keys($row)!==array('connection_id','server_uuid','database_name')||
        !is_string($row['connection_id'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$row['connection_id'])||
        !is_string($row['server_uuid'])||!preg_match('/\A[a-f0-9-]{36}\z/D',$row['server_uuid'])||
        !is_string($row['database_name'])) {
      throw new RuntimeException('file_parent_policy_reader_identity_unknown');
    }
    if($kind==='user') {
      if(!preg_match('/\A(governance_native_[a-f0-9]{16})_user\z/D',$row['database_name'],$m)) {
        throw new RuntimeException('file_parent_policy_reader_route_unknown');
      }
      $namespace=$m[1];
    }else if($row['database_name']!==$namespace.($kind==='file'?'_file':'_maniphest')||
        $row['server_uuid']!==$identities['user']['server_uuid']) {
      throw new RuntimeException('file_parent_policy_reader_route_unknown');
    }
    $identities[$kind]=$row;
  }
  if(count(array_unique(array_column($identities,'connection_id')))!==3) {
    throw new RuntimeException('file_parent_policy_reader_alias_unproved');
  }
  $scope->getFacts();
  return array('owned_User_RR_scope_observed'=>true,'same_server_observed'=>true,
    'File_reader_is_User_writer'=>false,'Task_reader_is_User_writer'=>false,
    'File_Task_same_physical_reader'=>false,'same_snapshot_proved'=>false);
}

function governance_recovery_file_parent_policy_observe($fixture,$observer,array $state,$viewer) {
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class) {
    throw new RuntimeException('file_parent_policy_fixture_unknown');
  }
  $fixture->assertOwned();
  $scope=PhorgeRecoveryOwnedRRScope::begin($observer);
  try {
    $facts=PhorgeRecoveryFileSourceFacts::captureParentPolicies(
      $scope,$state['users']['subject']->getPHID(),$viewer)->finishParentPolicies();
    $facts['native_reader_relationships']=governance_recovery_file_parent_policy_reader_relationships($scope);
    $scope->rollback();
    return $facts;
  } catch(Throwable $error) {
    try {governance_task_owner_abort($scope);}
    catch(Throwable $cleanup) {
      throw new PhutilAggregateException('file_parent_policy_cleanup_unproved',
        array('primary'=>$error,'cleanup'=>$cleanup));
    }
    throw $error;
  }
}
/** Real native admin-policy decisions; raw role fault is isolated and rolled back. */
function governance_recovery_file_parent_policy_admin_cases($fixture,$observer,array $state) {
  $fixture->assertOwned();$admin=$state['users']['actor'];$subject=$state['users']['subject'];
  $task=(new ManiphestTask())->load($state['task']->getID());$phid=$state['file']->getPHID();
  $edit_policy=static function($policy)use($subject,$task) {
    governance_task_owner_edit($subject,$task,array((new ManiphestTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue($policy)));
  };
  $edit_policy(PhabricatorPolicies::POLICY_ADMIN);
  $rule=new PhorgeGlobalPolicyRule();
  if(!$rule->applyRule($admin,PhabricatorPolicies::POLICY_ADMIN,$task)) {
    throw new RuntimeException('file_parent_policy_native_admin_prerequisite');
  }
  $positive=governance_recovery_file_parent_policy_observe($fixture,$observer,$state,$admin);
  if(($positive['observations'][$phid]['state']??null)!=='admitted'||
      $positive['current_account_flags_bound']!==array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList')) {
    throw new RuntimeException('file_parent_policy_native_admin_positive_unproved');
  }
  foreach(array('stale-object','visible-binding-drift','malformed-SQL','malformed-viewer')as$mode) {
    $edit_policy($mode==='visible-binding-drift'?PhabricatorPolicies::POLICY_USER:PhabricatorPolicies::POLICY_ADMIN);
    $viewer=clone $admin;$scope=PhorgeRecoveryOwnedRRScope::begin($observer);
    try {
      $capture=PhorgeRecoveryFileSourceFacts::captureParentPolicies($scope,$subject->getPHID(),$viewer);
      $writer=(new PhabricatorUser())->establishConnection('w');
      // Explicit fault injection on the exact fixture viewer, never an online
      // permission migration or a claim of full native role-producer coverage.
      $wanted=$mode==='malformed-SQL'?2:($mode==='malformed-viewer'?1:0);
      if($mode!=='malformed-viewer') {
        queryfx($writer,'UPDATE %R SET isAdmin=%d WHERE id=%d AND phid=%s',
          new PhabricatorUser(),$wanted,$admin->getID(),$admin->getPHID());
      }
      $current=queryfx_one($writer,'SELECT isAdmin FROM %R WHERE id=%d AND phid=%s',
        new PhabricatorUser(),$admin->getID(),$admin->getPHID());
      if(!$current||!in_array($current['isAdmin'],array($wanted,(string)$wanted),true)) {
        throw new RuntimeException('file_parent_policy_admin_fault_unproved');
      }
      if($mode!=='visible-binding-drift') {
        if($mode==='malformed-viewer'){$viewer->setIsAdmin('truthy-noncanonical');}
        if(!$rule->applyRule($viewer,PhabricatorPolicies::POLICY_ADMIN,$task)) {
          throw new RuntimeException('file_parent_policy_stale_admin_object_unproved');
        }
        $expected='recovery_file_parent_viewer_refused';
      }else {
        $viewer->setIsAdmin(0);
        if(!$rule->applyRule($viewer,PhabricatorPolicies::POLICY_USER,$task)) {
          throw new RuntimeException('file_parent_policy_user_visibility_prerequisite');
        }
        $expected='recovery_file_parent_review_stale';
      }
      $refused=false;
      try{$capture->finishParentPolicies();}
      catch(RuntimeException$error){if($error->getMessage()!==$expected){throw$error;}$refused=true;}
      if(!$refused){throw new RuntimeException('file_parent_policy_admin_drift_admitted');}
      $scope->rollback();
      $restored=queryfx_one($writer,'SELECT isAdmin FROM %R WHERE id=%d AND phid=%s',
        new PhabricatorUser(),$admin->getID(),$admin->getPHID());
      if(!$restored||!in_array($restored['isAdmin'],array(1,'1'),true)) {
        throw new RuntimeException('file_parent_policy_admin_rollback_unproved');
      }
    }catch(Throwable$error){
      try{governance_task_owner_abort($scope);}catch(Throwable$cleanup){throw new PhutilAggregateException(
        'file_parent_policy_admin_cleanup_unproved',array('primary'=>$error,'cleanup'=>$cleanup));}
      throw$error;
    }
  }
  return true;
}

function governance_recovery_file_parent_policy_cases($fixture,$observer,array $state,callable $check) {
  require_once __DIR__.'/file-source-cases.php';
  $reader=$state['users']['other'];$phid=$state['file']->getPHID();
  // Real attachment producer and real native viewer-aware File/Task queries.
  $before=governance_recovery_file_parent_policy_observe($fixture,$observer,$state,$reader);
  $check(($before['observations'][$phid]['state']??null)==='unsupported',
    'file-parent-policy-unattached-author-root-is-unsupported');
  governance_recovery_file_source_native_attachment($fixture,$state,
    PhabricatorFileAttachment::MODE_ATTACH);
  $attached=governance_recovery_file_parent_policy_observe($fixture,$observer,$state,$reader);
  $check(($attached['observations'][$phid]['state']??null)==='admitted'&&
    $before['review_binding']!==$attached['review_binding'],
    'file-parent-policy-native-Task-attachment-changes-endpoint-binding');
  $check($attached['same_transaction_snapshot_proved']===false&&
    $attached['durable_review_compatible']===false&&
    $attached['all_object_classes_covered']===false&&
    $attached['all_sources_complete']===false&&
    $attached['restoration_authorized']===false&&$attached['execution_blocked']===true&&
    $attached['native_reader_relationships']['same_snapshot_proved']===false,
    'file-parent-policy-native-endpoints-never-become-restoration-permit');
  $task=(new ManiphestTask())->load($state['task']->getID());
  governance_task_owner_edit($state['users']['subject'],$task,array(
    (new ManiphestTransaction())->setTransactionType(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE)
      ->setNewValue($state['users']['subject']->getPHID()),
    (new ManiphestTransaction())->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
      ->setNewValue(PhabricatorPolicies::POLICY_NOONE)));
  $denied=governance_recovery_file_parent_policy_observe($fixture,$observer,$state,$reader);
  $check(($denied['observations'][$phid]['state']??null)==='denied'&&
    ($denied['observations'][$phid]['Task_parents'][$task->getPHID()]??null)==='denied'&&
    $attached['review_binding']!==$denied['review_binding'],
    'file-parent-policy-native-invisible-parent-policy-changes-binding');
  // The disabled account is created by the existing real fixture producer.
  $admin_bound=governance_recovery_file_parent_policy_admin_cases($fixture,$observer,$state);
  $refused=false;
  try {governance_recovery_file_parent_policy_observe($fixture,$observer,$state,$state['users']['disabled']);}
  catch(RuntimeException $error) {
    if($error->getMessage()!=='recovery_file_parent_viewer_refused'){throw $error;}
    $refused=true;
  }
  $check($refused&&$admin_bound,'file-parent-policy-current-account-flags-admission-bound');
  $anonymous=(new PhabricatorUser());$refused=false;
  try {governance_recovery_file_parent_policy_observe($fixture,$observer,$state,$anonymous);}
  catch(RuntimeException $error) {
    if($error->getMessage()!=='recovery_file_parent_viewer_refused'){throw $error;}
    $refused=true;
  }
  $check($refused,'file-parent-policy-anonymous-refused-before-native-author-grant');
  return array('native_reader_relationships'=>$attached['native_reader_relationships']);
}
