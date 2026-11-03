<?php

/** Actual native self-join/leave and source-writer failures; no worker delivery. */
function governance_project_membership_lease_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$a=$state['executor'];$b=$state['approver'];
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try{$project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic Native Membership Lease')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($b['viewer']->getPHID())
    ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($off);}
  $conn=$project->establishConnection('w');$actor=$a['viewer']->getPHID();$phid=$project->getPHID();
  $direct=PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
  $inverse=PhabricatorProjectMemberOfProjectEdgeType::EDGECONST;
  $derived=PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;
  $people=(new PhabricatorUser())->getAphrontRefDatabaseName();
  $rows=function() use($conn,$phid,$actor,$direct,$inverse,$derived,$people){
    return array('people_transaction_open'=>(new PhabricatorUser())->establishConnection('w')->isInsideTransaction(),
      'project'=>queryfx_all($conn,'SELECT src,dst,type FROM edge WHERE src=%s AND type IN (%Ld) ORDER BY type,dst',$phid,array($direct,$derived)),
      'inverse'=>queryfx_all($conn,'SELECT src,dst,type FROM %T.edge WHERE src=%s AND type=%d AND dst=%s',$people,$actor,$inverse,$phid),
      'publishing_count'=>(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorWorkerActiveTask(),$phid)['n'],
      'native_count'=>(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorProjectTransaction(),$phid)['n'],
      'approval_count'=>(int)queryfx_one($conn,'SELECT COUNT(*) n FROM governance_projectapproval')['n'],
      'audit_count'=>(int)queryfx_one($conn,'SELECT COUNT(*) n FROM governance_executionaudit')['n']);
  };
  $execute=function($op) use($a,$project,$direct,$actor){
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
    return (new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($a['request'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->setContinueOnMissingFields(true)->setContinueOnNoEffect(true)
      ->applyTransactions($project,array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)->setMetadataValue('edge:type',$direct)
        ->setNewValue(array($op=>array($actor=>$actor)))));
  };
  $check(!PhabricatorPolicyFilter::hasCapability($a['viewer'],$project,PhabricatorPolicyCapability::CAN_EDIT) &&
    PhabricatorPolicyFilter::hasCapability($a['viewer'],$project,PhabricatorPolicyCapability::CAN_JOIN),
    'project-membership-fixture-native-join-without-edit');
  $before=$rows();
  foreach(array(array($phid,$direct,$actor),array($actor,$inverse,$phid),array($phid,$derived,$actor),
    array($phid,(string)$direct,$actor),array($actor,(string)$inverse,$phid),array($phid,(string)$derived,$actor)) as $index=>$edge){
    $data_before=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM edgedata')['n'];
    $refused=false;try{(new PhabricatorEdgeEditor())->addEdge($edge[0],$edge[1],$edge[2],array('data'=>array('synthetic'=>true)))->save();}
    catch(RuntimeException $ex){if(get_class($ex)!==RuntimeException::class || $ex->getMessage()!=='project_execution_native_membership_lease_required'){throw $ex;}$refused=true;}
    $check($refused && $rows()===$before && (int)queryfx_one($conn,'SELECT COUNT(*) n FROM edgedata')['n']===$data_before,
      'project-membership-raw-source-'.$index.'-refused-before-edge-data');
  }
  $joined=$execute('+');$after=$rows();
  $check(count($joined)===1 && !$after['people_transaction_open'] && count($after['project'])===2 && count($after['inverse'])===1 &&
    $after['native_count']===$before['native_count']+1 && $after['approval_count']===$before['approval_count'] &&
    $after['audit_count']===$before['audit_count'] && !$conn->isInsideTransaction(),
    'project-membership-native-self-join-direct-inverse-derived-and-record-committed');
  $left=$execute('-');$after=$rows();
  $check(count($left)===1 && !$after['people_transaction_open'] && !$after['project'] && !$after['inverse'] &&
    $after['native_count']===$before['native_count']+2 && !$conn->isInsideTransaction(),
    'project-membership-native-self-leave-direct-inverse-derived-and-record-committed');
  foreach(array('inverse'=>array('user',$inverse),'derived'=>array('project',$derived)) as $label=>$definition){
    $baseline=$rows();$installed=false;$mode=(new mysqli_driver())->report_mode;
    $trigger='`'.$fixture->getNamespace().'_'.$definition[0].'`.`governance_membership_fault`';
    try{
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$setup=$fixture->getAdmin();
      $setup->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table($definition[0],'edge').
        " FOR EACH ROW BEGIN IF NEW.type=".$definition[1]." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated-native-membership-failure'; END IF; END");
      $installed=true;
      $exists=$setup->query("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
        $setup->real_escape_string($fixture->getNamespace().'_'.$definition[0])."' AND TRIGGER_NAME='governance_membership_fault'")->fetch_assoc();
      $check((int)$exists['n']===1,'project-membership-'.$label.'-actual-sql-fault-installed');
      $failed=false;try{$execute('+');}catch(Throwable $ex){
        if(!in_array(get_class($ex),array(AphrontQueryException::class,mysqli_sql_exception::class),true) ||
          (int)$ex->getCode()!==1644 || !preg_match('/\A(?:#1644:\s*)?isolated-native-membership-failure\z/',$ex->getMessage())){throw $ex;}
        $failed=true;
      }
      $check($failed && $rows()===$baseline && !$conn->isInsideTransaction() &&
        !$conn->isReadLocking() && !$conn->isWriteLocking(),
        'project-membership-'.$label.'-fault-rolls-back-direct-inverse-derived-record-and-preserves-approval-audit');
    }finally{
      try{mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);if($installed){$fixture->getAdmin()->query('DROP TRIGGER '.$trigger);}}
      finally{mysqli_report($mode);}
    }
  }
  $execute('+');$execute('-');
  $check(!$rows()['project'] && !$rows()['inverse'] && !$conn->isInsideTransaction(),
    'project-membership-native-join-and-leave-recover-after-real-sql-faults');
  queryfx($conn,'INSERT INTO edge (src,dst,type,dateCreated,seq,dataID) VALUES (%s,%s,%d,1,0,0)',$phid,$actor,$direct);
  try{
    $refused=false;try{(new PhabricatorProjectsMembershipIndexEngineExtension())->rematerialize($project);}
    catch(RuntimeException $ex){if(get_class($ex)!==RuntimeException::class || $ex->getMessage()!=='project_execution_unapproved_materialization_change'){throw $ex;}$refused=true;}
    $check($refused && count($rows()['project'])===1 && !$rows()['inverse'] && !$conn->isInsideTransaction(),
      'project-membership-standalone-derived-permission-repair-refused');
  }finally{queryfx($conn,'DELETE FROM edge WHERE src=%s AND dst=%s AND type=%d',$phid,$actor,$direct);}
  (new PhabricatorProjectsMembershipIndexEngineExtension())->rematerialize($project);
  $check(!$rows()['project'] && !$conn->isInsideTransaction(),
    'project-membership-standalone-proved-noop-materialization-preserved');
  return array('actual_native_self_join_leave'=>true,'current_native_capabilities_not_approval_grants'=>true,
    'same_project_transaction_for_exact_direct_inverse_derived'=>true,'actual_inverse_and_derived_fault_rollback'=>true,
    'high_risk_administrator_member_change_implemented'=>false,'hierarchy_membership_implemented'=>false);
}
