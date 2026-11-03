<?php

/** Actual fixed flat-root member execution, using native editors and credentials. */
function governance_project_approved_membership_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$a=$state['executor'];$original_admin=$state['approver'];
  $actor=$a['viewer']->getPHID();$suffix=substr($fixture->getNamespace(),-10);
  $sessions=new PhabricatorAuthSessionEngine();
  $reviewer=(new PhabricatorUser())->setUsername('gmemberreview'.$suffix)->setRealName('Synthetic Member Reviewer')
    ->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
  $b=governance_native_request($reviewer,$sessions);
  $subjects=array();
  foreach(array('c','d','e') as $name){
    $subjects[]=(new PhabricatorUser())->setUsername('gmember'.$suffix.$name)
      ->setRealName('Synthetic Approved Member')->setIsApproved(1)->setIsEmailVerified(1)->save();
  }
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try{$project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic Approved Membership')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($actor)
    ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($off);}
  $conn=$project->establishConnection('w');$phid=$project->getPHID();
  $people=(new PhabricatorUser())->getAphrontRefDatabaseName();
  $people_connection=(new PhabricatorUser())->establishConnection('w');
  $direct=PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
  $inverse=PhabricatorProjectMemberOfProjectEdgeType::EDGECONST;
  $derived=PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;
  $fingerprint=function() use($conn,$phid,$people,$inverse,$people_connection){
    return array('people_transaction_open'=>$people_connection->isInsideTransaction(),'project'=>queryfx_all($conn,'SELECT * FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorProject(),$phid),
      'edges'=>queryfx_all($conn,'SELECT * FROM edge WHERE BINARY src=BINARY %s ORDER BY type,dst',$phid),
      'inverse'=>queryfx_all($conn,'SELECT * FROM %T.edge WHERE BINARY dst=BINARY %s AND type=%d ORDER BY src',$people,$phid,$inverse),
      'native'=>queryfx_all($conn,'SELECT * FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id',new PhabricatorProjectTransaction(),$phid),
      'tickets'=>queryfx_all($conn,'SELECT * FROM governance_projectapproval WHERE BINARY targetPHID=BINARY %s ORDER BY approvalID',$phid),
      'audit'=>queryfx_all($conn,'SELECT * FROM governance_executionaudit WHERE BINARY targetPHID=BINARY %s ORDER BY id',$phid),
      'publishing'=>queryfx_all($conn,'SELECT id,taskClass FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id',new PhabricatorWorkerActiveTask(),$phid));
  };
  $proposal=function($operation,array $members,$context=null) use($a,$b,$phid){
    $context=$context===null?$a['context']:$context;
    $ticket=PhorgeProjectPolicyApprovalService::call($context,'submit',array('target_phid'=>$phid,
      'intent'=>array('version'=>2,'operation'=>$operation,'members'=>$members),
      'reason'=>'Synthetic fixed member delta','ttl_seconds'=>120));
    PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$ticket['approval_id']));
    return $ticket['approval_id'];
  };
  $execute=function($operation,array $members,$id) use($a,$project,$direct){
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];$map=array();foreach($members as$member){$map[$member]=$member;}
    return (new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($a['request'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setProjectApprovalID($id)
      ->setContinueOnMissingFields(true)->setContinueOnNoEffect(true)
      ->applyTransactions($project,array((new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue('edge:type',$direct)->setNewValue(array($operation==='project.members.add'?'+':'-'=>$map))));
  };
  foreach(array('disabled','unapproved','agent','mailing')as$kind){
    $ineligible=(new PhabricatorUser())->setUsername('gmdeny'.$kind.$suffix)
      ->setRealName('Synthetic ineligible member target')->setIsApproved($kind==='unapproved'?0:1)
      ->setIsDisabled($kind==='disabled'?1:0)->setIsSystemAgent($kind==='agent'?1:0)
      ->setIsMailingList($kind==='mailing'?1:0)->save();
    $before=$fingerprint();$denied=false;
    try{$proposal('project.members.add',array($ineligible->getPHID()));}
    catch(RuntimeException$error){if($error->getMessage()!=='project_execution_member_add_subject_ineligible'){throw$error;}$denied=true;}
    $check($denied&&$fingerprint()===$before,'project-approved-members-add-'.$kind.'-target-refused-without-write');
  }
  // The beneficiary may be an independent approver. Their first credential
  // metadata initialization must not masquerade as a legacy role change.
  $initial_epoch=queryfx_one($conn,'SELECT epoch FROM %R WHERE userPHID=%s',new PhorgeGovernanceIdentityEpoch(),$reviewer->getPHID());
  $first=$proposal('project.members.add',array($reviewer->getPHID()));
  $result=$execute('project.members.add',array($reviewer->getPHID()),$first);
  $initialized=queryfx_one($conn,'SELECT epoch FROM %R WHERE userPHID=%s',new PhorgeGovernanceIdentityEpoch(),$reviewer->getPHID());
  $check($initial_epoch===null&&$initialized!==null&&(string)$initialized['epoch']==='1'&&count($result)===1,
    'project-approved-members-first-issuer-beneficiary-approver-is-not-a-role-change');
  $undo=$proposal('project.members.remove',array($reviewer->getPHID()));
  $execute('project.members.remove',array($reviewer->getPHID()),$undo);
  $members=array($subjects[0]->getPHID(),$subjects[1]->getPHID());sort($members,SORT_STRING);
  $baseline=$fingerprint();$self_ticket=false;
  try{PhorgeProjectPolicyApprovalService::call($a['context'],'submit',array('target_phid'=>$phid,
    'intent'=>array('version'=>2,'operation'=>'project.members.add','members'=>array($actor)),
    'reason'=>'Synthetic inapplicable self ticket','ttl_seconds'=>120));}
  catch(RuntimeException$error){if($error->getMessage()!=='project_execution_membership_ticket_not_applicable'){throw$error;}$self_ticket=true;}
  $check($self_ticket&&$fingerprint()===$baseline,'project-approved-members-self-only-ticket-refused-retaining-native-path');
  $baseline=$fingerprint();$unapproved=false;
  try{$execute('project.members.add',$members,str_repeat('0',64));}
  catch(RuntimeException$error){if($error->getMessage()!=='project_execution_ticket_refused'){throw$error;}$unapproved=true;}
  $check($unapproved&&$fingerprint()===$baseline,'project-approved-members-unapproved-ticket-cannot-mutate');
  $add=$proposal('project.members.add',$members);$baseline=$fingerprint();
  $native_map=array();foreach($members as$member){$native_map[$member]=$member;}
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$b['token'];$native_denied=false;
  try{(new PhabricatorProjectTransactionEditor())->setActor($b['viewer'])->setRequest($b['request'])
    ->setProjectApprovalID($add)->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->setContinueOnMissingFields(true)->applyTransactions($project,array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)->setMetadataValue('edge:type',$direct)
      ->setNewValue(array('+'=>$native_map))));}
  catch(RuntimeException$error){if($error->getMessage()!=='project_execution_native_edit_required'){throw$error;}$native_denied=true;}
  $check($native_denied&&$fingerprint()===$baseline,'project-approved-members-valid-ticket-does-not-grant-native-edit');

  $wrong=false;try{$execute('project.members.add',array($subjects[2]->getPHID()),$add);}
  catch(RuntimeException $error){if($error->getMessage()!=='project_execution_ticket_binding_changed'){throw$error;}$wrong=true;}
  $check($wrong&&$fingerprint()===$baseline,'project-approved-members-wrong-subject-ticket-refused-without-write');
  $result=$execute('project.members.add',$members,$add);
  $rows=queryfx_all($conn,'SELECT dst,type FROM edge WHERE src=%s AND type IN (%Ld) ORDER BY type,dst',$phid,array($direct,$derived));
  $inverse_rows=queryfx_all($conn,'SELECT src FROM %T.edge WHERE dst=%s AND type=%d ORDER BY src',$people,$phid,$inverse);
  $ticket=queryfx_one($conn,'SELECT state,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$add);
  $check(count($result)===1&&count($rows)===4&&ipull($inverse_rows,'src')===$members&&$ticket['state']==='consumed'&&!$people_connection->isInsideTransaction()&&
    is_string($ticket['nativeGroupID'])&&$ticket['nativeGroupID']!==''&&!$conn->isInsideTransaction(),
    'project-approved-members-two-user-add-consume-native-inverse-derived-audit-commit');
  $remove=$proposal('project.members.remove',$members);$baseline=$fingerprint();
  $wrong=false;try{$execute('project.members.add',array($subjects[2]->getPHID()),$remove);}
  catch(RuntimeException $error){if($error->getMessage()!=='project_execution_ticket_binding_changed'){throw$error;}$wrong=true;}
  $check($wrong&&$fingerprint()===$baseline,'project-approved-members-wrong-operation-ticket-refused-without-write');
  $result=$execute('project.members.remove',$members,$remove);
  $rows=queryfx_all($conn,'SELECT dst,type FROM edge WHERE src=%s AND type IN (%Ld)',$phid,array($direct,$derived));
  $inverse_rows=queryfx_all($conn,'SELECT src FROM %T.edge WHERE dst=%s AND type=%d',$people,$phid,$inverse);
  $ticket=queryfx_one($conn,'SELECT state FROM governance_projectapproval WHERE approvalID=%s',$remove);
  $check(count($result)===1&&!$rows&&!$inverse_rows&&$ticket['state']==='consumed'&&!$people_connection->isInsideTransaction()&&!$conn->isInsideTransaction(),
    'project-approved-members-two-user-remove-consume-native-inverse-derived-audit-commit');
  $disabled_member=$subjects[2];$disabled_phid=$disabled_member->getPHID();
  $disabled_add=$proposal('project.members.add',array($disabled_phid));
  $execute('project.members.add',array($disabled_phid),$disabled_add);
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$b['token'];
  (new PhabricatorUserTransactionEditor())->setActor($b['viewer'])->setRequest($b['request'])
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->applyTransactions($disabled_member,array((new PhabricatorUserTransaction())
      ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true)));
  $history_before=queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$disabled_add);
  $history_audit_before=queryfx_all($conn,'SELECT * FROM governance_executionaudit WHERE approvalID=%s ORDER BY id',$disabled_add);
  $history=PhorgeProjectPolicyApprovalService::call($b['context'],'history',array('approval_id'=>$disabled_add));
  $check($history['state']==='consumed'&&$history['operation']==='project.members.add'&&
    $history['intent']['members']===array($disabled_phid)&&count($history['audit'])===count($history_audit_before)&&
    queryfx_one($conn,'SELECT state,consumedAt,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$disabled_add)===$history_before&&
    queryfx_all($conn,'SELECT * FROM governance_executionaudit WHERE approvalID=%s ORDER BY id',$disabled_add)===$history_audit_before,
    'project-approved-members-current-admin-can-read-durable-add-history-after-subject-disabled');
  $disabled_remove=$proposal('project.members.remove',array($disabled_phid));
  $disabled_result=$execute('project.members.remove',array($disabled_phid),$disabled_remove);
  $check(count($disabled_result)===1&&!queryfx_all($conn,'SELECT dst FROM edge WHERE src=%s AND type IN (%Ld)',
      $phid,array($direct,$derived)),
    'project-approved-members-disabled-current-target-can-be-removed-with-approval');
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$b['token'];
  (new PhabricatorUserTransactionEditor())->setActor($b['viewer'])->setRequest($b['request'])
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->applyTransactions($disabled_member,array((new PhabricatorUserTransaction())
      ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(false)));
  // Actual current capacity, not a hypothetical replay of an old addition.
  $capacity=array();for($i=0;$i<128;$i++){
    $u=(new PhabricatorUser())->setUsername('gcapacity'.$suffix.$i)->setRealName('Synthetic history capacity')
      ->setIsApproved(1)->setIsEmailVerified(1)->save();$capacity[$u->getPHID()]=$u->getPHID();}
  $capacity_edit=function($add)use($a,$phid,$direct,$capacity){
    $native=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
    try{
      $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
      $target=(new PhabricatorProjectQuery())->setViewer($a['viewer'])->withPHIDs(array($phid))->needMembers(true)->executeOne();
      (new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($a['request'])
        ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
        ->setContinueOnMissingFields(true)->applyTransactions($target,array((new PhabricatorProjectTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)->setMetadataValue('edge:type',$direct)
          ->setNewValue(array($add?'+':'-'=>$capacity))));
    }finally{unset($native);}
  };
  $capacity_edit(true);
  try{
    $history_baseline=$fingerprint();
    $capacity_history=PhorgeProjectPolicyApprovalService::call($b['context'],'history',array('approval_id'=>$disabled_add));
    $actual=queryfx_all($conn,'SELECT type,COUNT(*) n FROM edge WHERE src=%s AND type IN (%Ld) GROUP BY type ORDER BY type',
      $phid,array($direct,$derived));
    $check(count($actual)===2&&(int)$actual[0]['n']===128&&(int)$actual[1]['n']===128&&
      $capacity_history['state']==='consumed'&&$capacity_history['intent']['members']===array($disabled_phid)&&
      $fingerprint()===$history_baseline,
      'project-approved-members-history-at-current-capacity-does-not-replay-removed-subject');
    $oversize=false;try{$proposal('project.members.add',array($disabled_phid));}
    catch(RuntimeException$error){if($error->getMessage()!=='project_execution_source_budget'){throw$error;}$oversize=true;}
    $check($oversize&&$fingerprint()===$history_baseline,
      'project-approved-members-new-addition-over-current-capacity-still-refused-without-write');
  }finally{$capacity_edit(false);}
  foreach(array('inverse'=>array('user','edge','NEW.type='.$inverse),
      'derived'=>array('project','edge','NEW.type='.$derived),
      'record'=>array('project',(new PhabricatorProjectTransaction())->getTableName(),'1'),
      'audit'=>array('project','governance_executionaudit',"NEW.action='executed'")) as$label=>$definition){
    $id=$proposal('project.members.add',$members);$baseline=$fingerprint();$created=false;$report=(new mysqli_driver())->report_mode;
    $trigger='`'.$fixture->getNamespace().'_'.$definition[0].'`.`governance_approved_members_fault`';
    try{
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      $fixture->getAdmin()->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table($definition[0],$definition[1]).
        " FOR EACH ROW BEGIN IF ".$definition[2]." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated-approved-members-failure'; END IF; END");$created=true;
      $exists=$fixture->getAdmin()->query("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
        $fixture->getNamespace().'_'.$definition[0]."' AND TRIGGER_NAME='governance_approved_members_fault'")->fetch_assoc();
      $check((int)$exists['n']===1,'project-approved-members-'.$label.'-owned-native-fault-installed');
      $failed=false;try{$execute('project.members.add',$members,$id);}
      catch(Throwable$error){
        if(!in_array(get_class($error),array(AphrontQueryException::class,mysqli_sql_exception::class),true)||
          (int)$error->getCode()!==1644||!preg_match('/\A(?:#1644:\\s*)?isolated-approved-members-failure\z/',$error->getMessage())){throw$error;}
        $failed=true;
      }
      $check($failed&&$fingerprint()===$baseline&&!$conn->isInsideTransaction()&&!$conn->isWriteLocking(),
        'project-approved-members-'.$label.'-actual-fault-rolls-back-ticket-edges-record-audit-queue');
    }finally{
      try{mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);if($created){$fixture->getAdmin()->query('DROP TRIGGER '.$trigger);}}
      finally{mysqli_report($report);}
    }
  }
  // Use an actual native User transaction for a subject ABA, without touching
  // the account in the middle through this scope. Its immutable ID is bound.
  $stale=$proposal('project.members.add',$members);$baseline=$fingerprint();
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$b['token'];
  foreach(array(true,false) as$disabled){
    $subject=(new PhabricatorUser())->loadOneWhere('phid=%s',$members[0]);
    (new PhabricatorUserTransactionEditor())->setActor($b['viewer'])->setRequest($b['request'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->applyTransactions($subject,array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled)));
  }
  $refused=false;try{$execute('project.members.add',$members,$stale);}
  catch(RuntimeException$error){if($error->getMessage()!=='project_execution_sources_stale'){throw$error;}$refused=true;}
  $check($refused&&$fingerprint()===$baseline,'project-approved-members-subject-native-disable-enable-aba-invalidates-ticket');
  // Public Conduit producer and executor use the real successful token branch.
  $token=PhabricatorConduitToken::initializeNewToken($actor,PhabricatorConduitToken::TYPE_STANDARD)->save();
  $authenticated=function(array$params,$method) use($token){
    $request=new ConduitAPIRequest($params,true);
    $controller=(new PhabricatorConduitAPIController())->setRequest((new AphrontRequest('fixture.invalid','/api/'.$method))->setUser(new PhabricatorUser()));
    $authenticate=new ReflectionMethod(PhabricatorConduitAPIController::class,'authenticateUser');
    if($authenticate->invoke($controller,$request,array('token'=>$token->getToken()),$method)!==null){
      throw new RuntimeException('project_approved_members_api_authentication_failed');}
    return $request;
  };
  try{
    $member=$subjects[2]->getPHID();
    $request=$authenticated(array('action'=>'submit','payload'=>array('target_phid'=>$phid,
      'intent'=>array('version'=>2,'operation'=>'project.members.add','members'=>array($member)),
      'reason'=>'Synthetic actual API member delta','ttl_seconds'=>120)),'project.approval');
    $submitted=(new ProjectPolicyApprovalConduitAPIMethod())->executeMethod($request);
    PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$submitted['approval_id']));
    $request=$authenticated(array('objectIdentifier'=>$phid,'governanceApprovalID'=>$submitted['approval_id'],
      'transactions'=>array(array('type'=>'members.add','value'=>array($member)))),'project.edit');
    $result=(new ProjectEditConduitAPIMethod())->executeMethod($request);
    $ticket=queryfx_one($conn,'SELECT state FROM governance_projectapproval WHERE approvalID=%s',$submitted['approval_id']);
    $actual=queryfx_all($conn,'SELECT dst,type FROM edge WHERE src=%s AND dst=%s AND type IN (%Ld) ORDER BY type',
      $phid,$member,array($direct,$derived));
    $check(is_array($result)&&$ticket['state']==='consumed'&&count($actual)===2&&!$people_connection->isInsideTransaction()&&!$conn->isInsideTransaction(),
      'project-approved-members-actual-api-token-proposal-and-member-edit-commit');
  }finally{$token->delete();}
  $revoked=$proposal('project.members.add',$members);$baseline=$fingerprint();
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$original_admin['token'];
  (new PhabricatorUserTransactionEditor())->setActor($original_admin['viewer'])->setRequest($original_admin['request'])
    ->setCancelURI('/people/')->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->applyTransactions((new PhabricatorUser())->loadOneWhere('phid=%s',$reviewer->getPHID()),array((new PhabricatorUserTransaction())
      ->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(false)));
  $refused=false;try{$execute('project.members.add',$members,$revoked);}
  catch(RuntimeException$error){if(!in_array($error->getMessage(),array('governance_native_credential_ineligible','project_execution_approver_admin_required'),true)){throw$error;}$refused=true;}
  $check($refused&&$fingerprint()===$baseline,'project-approved-members-current-approver-role-revocation-invalidates-ticket');
  return array('actual_typed_member_add_remove'=>true,'actual_multi_subject_delta'=>true,
    'same_writer_approval_native_and_edges'=>true,'native_policy_not_granted_by_approval'=>true,
    'all_hierarchy_or_raw_producers_complete'=>false,'production_activation'=>false);
}
