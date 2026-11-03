<?php

/** Stale cached hierarchy/member metadata must not authorize real native edits. */
function governance_project_flat_source_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$a=$state['executor'];$b=$state['approver'];$project=$state['project'];
  $conn=$project->establishConnection('w');
  $proposal=PhorgeProjectPolicyApprovalService::call($a['context'],'submit',array(
    'target_phid'=>$project->getPHID(),'new_policy'=>$a['viewer']->getPHID(),
    'reason'=>'Synthetic current hierarchy and derived-source fence','ttl_seconds'=>120));
  PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$proposal['approval_id']));
  $execute=function() use($a,$project,$proposal){
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
    (new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($a['request'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->setProjectApprovalID($proposal['approval_id'])->setContinueOnMissingFields(true)
      ->applyTransactions($project,array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue($a['viewer']->getPHID())));
  };
  $refuse=function($reason,$name) use($execute,$check,$conn,$project,$proposal){
    $before=queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
    $refused=false;try{$execute();}catch(RuntimeException $ex){
      if(get_class($ex)!==RuntimeException::class || $ex->getMessage()!==$reason){throw $ex;}$refused=true;
    }
    $ticket=queryfx_one($conn,'SELECT state,consumedAt FROM governance_projectapproval WHERE approvalID=%s',$proposal['approval_id']);
    $after=queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
    $check($refused && $ticket['state']==='approved' && $ticket['consumedAt']===null && $before===$after &&
      !$conn->isInsideTransaction() && !$conn->isReadLocking() && !$conn->isWriteLocking(),$name);
  };
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try{$child=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic Cached-Flag Child')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($a['viewer']->getPHID())
    ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($off);}
  try{
    // Deliberately model an inconsistent restored/legacy row: actual child,
    // while root hasSubprojects/hasMilestones remain false. Never repair it.
    queryfx($conn,'UPDATE %R SET parentProjectPHID=%s WHERE id=%d',new PhabricatorProject(),$project->getPHID(),$child->getID());
    $refuse('project_execution_hierarchy_unsupported','project-approval-actual-child-blocks-despite-stale-root-flags');
  }finally{queryfx($conn,'UPDATE %R SET parentProjectPHID=NULL WHERE id=%d',new PhabricatorProject(),$child->getID());}
  $direct=PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
  $materialized=PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;
  $existing=queryfx_all($conn,'SELECT src,dst,type FROM edge WHERE src=%s AND type IN (%Ld)',$project->getPHID(),array($direct,$materialized));
  if($existing){throw new RuntimeException('Synthetic flat-source regression requires initially empty membership.');}
  foreach(array($direct=>'direct-without-derived',$materialized=>'derived-without-direct') as $type=>$label){
    try{
      queryfx($conn,'INSERT INTO edge (src,dst,type,dateCreated,seq,dataID) VALUES (%s,%s,%d,1,0,0)',
        $project->getPHID(),$a['viewer']->getPHID(),$type);
      $refuse('project_execution_materialized_members_inconsistent','project-approval-'.$label.'-blocked-without-repair');
    }finally{queryfx($conn,'DELETE FROM edge WHERE src=%s AND dst=%s AND type=%d',$project->getPHID(),$a['viewer']->getPHID(),$type);}
  }
  $config=$fixture->getWriterConfig();$writer=null;$observer=null;$scope=null;
  $report_mode=(new mysqli_driver())->report_mode;
  try{
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $writer=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
    $observer=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
    $writer->query('SET SESSION innodb_lock_wait_timeout=10');
    $scope=PhorgeProjectNativeExecutionScope::open($a['context'],$project,$a['viewer']->getPHID(),'execute',$proposal['approval_id']);
    $sql='UPDATE '.$fixture->table('project',(new PhabricatorProject())->getTableName()).
      " SET parentProjectPHID='".$writer->real_escape_string($project->getPHID())."' WHERE id=".(int)$child->getID();
    $writer->query($sql,MYSQLI_ASYNC);$seen=false;$deadline=microtime(true)+4;
    do{
      foreach($observer->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $row){
        if((int)$row['Id']===$writer->thread_id && $row['Info']===$sql){$seen=true;break;}
      }
      if($seen){break;}usleep(20000);
    }while(microtime(true)<$deadline);
    $read=array($writer);$errors=array();$reject=array();
    $check($seen && mysqli::poll($read,$errors,$reject,0,1000)===0,
      'project-approval-actual-empty-parent-range-blocks-concurrent-child-producer');
    $scope->close();$scope=null;
    $read=array($writer);$errors=array();$reject=array();
    $check(mysqli::poll($read,$errors,$reject,5,0)===1 && $writer->reap_async_query()===true,
      'project-approval-child-producer-unblocks-after-owned-scope-release');
    $row=queryfx_one($conn,'SELECT parentProjectPHID FROM %R WHERE id=%d',new PhabricatorProject(),$child->getID());
    $check($row['parentProjectPHID']===$project->getPHID(),
      'project-approval-child-producer-actually-committed-after-range-release');
  }finally{
    try{if($scope!==null){$scope->close();}}
    finally{
      if($writer!==null){$writer->close();}if($observer!==null){$observer->close();}
      queryfx($conn,'UPDATE %R SET parentProjectPHID=NULL WHERE id=%d',new PhabricatorProject(),$child->getID());
      mysqli_report($report_mode);
    }
  }
  return array('actual_child_not_cached_flags'=>true,'flat_direct_and_derived_mismatch_refused'=>true,'actual_child_parent_range_wait'=>true,
    'actual_native_editor_used'=>true,'automatic_permission_repair'=>false,
    'all_hierarchy_and_raw_producers_closed'=>false);
}
