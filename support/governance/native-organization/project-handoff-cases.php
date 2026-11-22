<?php

require_once __DIR__.'/native-cases.php';

function governance_project_handoff_cases($fixture,callable $check,mysqli $control) {
  $fixture->assertOwned();$users=array();$contexts=array();$tokens=array();
  foreach(array('admin','recipient','ordinary')as$role){
    $u=(new PhabricatorUser())->setUsername('ho'.$role.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Handoff '.$role)->setIsApproved(1)->setIsEmailVerified(1)
      ->setIsAdmin($role==='admin'?1:0)->save();$users[$role]=$u;
    $token='api-'.bin2hex(random_bytes(14));$tokens[$role]=$token;
    (new PhabricatorConduitToken())->setObjectPHID($u->getPHID())->setTokenType('api')->setToken($token)->setTokenName('Synthetic handoff fixture')->save();
    $contexts[$role]=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($u,new PhutilOpaqueEnvelope($token));
  }
  $project=function($name,$parent=null)use($users){
    return PhabricatorProject::initializeNewProject($users['admin'],$parent)->setName($name)
      ->setParentProjectPHID($parent?$parent->getPHID():null)->setViewPolicy($users['admin']->getPHID())
      ->setEditPolicy($users['admin']->getPHID())->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
  };
  $intent=function($p,$operation)use($users){return array('projectPHID'=>$p->getPHID(),
    'recipientPHID'=>$users['recipient']->getPHID(),'operationID'=>$operation);};
  $confirm=function($preview){return array_intersect_key($preview,array_fuse(array('operationID','projectPHID','recipientPHID','expiresAt','expectedDigest')))+array('acknowledge'=>true);};
  $count=function()use($fixture){return$fixture->countRows('project','project_transaction');};
  $scope=PhabricatorEnv::beginScopedEnv();$scope->overrideEnvConfig('projects.permission-handoff.enabled',false);
  try{
    $ordinary=PhabricatorProject::initializeNewProject($users['admin']);
    $editor=(new PhabricatorProjectTransactionEditor())->setActor($users['admin'])
      ->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConduitContentSource::SOURCECONST));
    $editor->applyTransactions($ordinary,array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic native OFF create')));
    $check($ordinary->getID()!==null,'handoff-off-native-project-creation-unaffected');
    $editor=(new PhabricatorProjectTransactionEditor())->setActor($users['admin'])
      ->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConduitContentSource::SOURCECONST));
    $editor->applyTransactions($ordinary,array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic native OFF edited')));
    $check($ordinary->reload()->getName()==='Synthetic native OFF edited','handoff-off-native-project-edit-unaffected');
    governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($ordinary,'handoff_disabled01')),
      'handoff_disabled_or_approval_interoperation_pending',$check,'handoff-off-endpoint-refuses-before-authority');
  }finally{unset($scope);}
  $p=$project('Synthetic handoff success');$before=$count();
  $preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_success_0001'));
  $check($before===$count()&&$preview['after']['edit']===$users['recipient']->getPHID()&&
    $preview['actorAfter']===array('view'=>false,'edit'=>false),'handoff-preview-final-state-and-no-audit-write');
  $request=$confirm($preview);$bad=$request;$bad['acknowledge']=false;
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$bad),
    'handoff_confirmation',$check,'handoff-explicit-confirmation-required');
  $result=PhorgeProjectHandoffService::commit($contexts['admin'],$request);
  $p->reload();
  $check($result['committed']&&$result['replayed']===false&&!isset($result['postCommitProcessingComplete'])&&$p->getViewPolicy()===$users['recipient']->getPHID()&&
    $p->getEditPolicy()===$users['recipient']->getPHID()&&$p->getJoinPolicy()===PhabricatorPolicies::POLICY_NOONE&&
    $count()===$before+1,'handoff-two-policies-and-one-native-audit-commit');
  $read=(new PhabricatorProjectQuery())->setViewer($users['recipient'])->withPHIDs(array($p->getPHID()))
    ->needMembers(true)->needAncestorMembers(true)->executeOne();
  $check($read&&PhabricatorPolicyFilter::hasCapability($users['recipient'],$read,PhabricatorPolicyCapability::CAN_EDIT),
    'handoff-recipient-native-view-and-edit');
  $replay=PhorgeProjectHandoffService::commit($contexts['admin'],$request);
  $check($replay['replayed']&&$replay['transactionPHID']===$result['transactionPHID']&&$count()===$before+1,
    'handoff-exact-retry-after-actor-loses-access-no-second-write');
  $bad=$request;$bad['recipientPHID']=$users['ordinary']->getPHID();
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$bad),
    'handoff_operation_conflict',$check,'handoff-operation-reuse-with-different-intent-denied');

  $scope=PhabricatorEnv::beginScopedEnv();$scope->overrideEnvConfig('cluster.databases',array(array('host'=>'unsupported-replica.invalid')));
  try{governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_cluster_01')),
    'handoff_database_cluster_unsupported',$check,'handoff-replica-topology-refuses-before-read-or-write');}finally{unset($scope);}

  $bounded=$project('Synthetic bounded handoff history');
  $template=(new PhabricatorProjectTransaction())->loadOneWhere('phid=%s',$result['transactionPHID']);
  $oldest=null;$oldestPHID=null;
  for($i=1;$i<=999;++$i){
    $seed=PhorgeProjectHandoffRequest::parse(array('projectPHID'=>$bounded->getPHID(),
      'recipientPHID'=>$users['recipient']->getPHID(),'operationID'=>'handoff_history_seed_'.sprintf('%04d',$i),
      'acknowledge'=>true,'expiresAt'=>1,'expectedDigest'=>str_repeat('a',64)),true);
    $audit=clone$template;$audit->setID(null)->setPHID(null)->setObjectPHID($bounded->getPHID())->setNewValue($seed)->save();
    if($i===1){$oldest=$seed;$oldestPHID=$audit->getPHID();}
  }
  $capRequest=$confirm(PhorgeProjectHandoffService::preview($contexts['admin'],$intent($bounded,'handoff_history_last1')));
  $last=PhorgeProjectHandoffService::commit($contexts['admin'],$capRequest);
  $current=$count();$perProject=queryfx_one($bounded->establishConnection('w'),
    'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s AND transactionType=%s',new PhabricatorProjectTransaction(),
    $bounded->getPHID(),PhorgeProjectHandoffService::TYPE);
  $check($last['committed']&&(int)$perProject['n']===1000,'handoff-last-readable-history-slot-commits');
  $lastRetry=PhorgeProjectHandoffService::commit($contexts['admin'],$capRequest);
  $oldRetry=PhorgeProjectHandoffService::commit($contexts['admin'],$oldest);
  $check($lastRetry['replayed']&&$lastRetry['transactionPHID']===$last['transactionPHID']&&
    $oldRetry['replayed']&&$oldRetry['transactionPHID']===$oldestPHID&&$count()===$current,
    'handoff-history-limit-preserves-newest-and-oldest-exact-receipts');
  $unknown=$capRequest;$unknown['operationID']='handoff_history_over1';
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$unknown),
    'handoff_history_bound',$check,'handoff-history-limit-refuses-new-operation-before-write');
  $check($count()===$current&&$bounded->reload()->getViewPolicy()===$users['recipient']->getPHID()&&
    $bounded->getEditPolicy()===$users['recipient']->getPHID(),'handoff-history-limit-does-not-create-row-1001');

  $p=$project('Synthetic handoff stale');$preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_stale_00001'));
  $p->setName('Synthetic handoff changed')->save();$before=$count();
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview)),
    'handoff_preview_changed',$check,'handoff-concurrent-project-edit-requires-new-preview');
  $check($before===$count()&&$p->reload()->getEditPolicy()===$users['admin']->getPHID(),'handoff-stale-refusal-is-zero-write');
  $preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_expired_001'));$bad=$confirm($preview);$bad['expiresAt']=1;
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$bad),
    'handoff_preview_expired',$check,'handoff-expired-preview-rejected');
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['ordinary'],$intent($p,'handoff_nonadmin_01')),
    'handoff_current_admin_and_distinct_recipient_required',$check,'handoff-ordinary-editor-cannot-approve-policy-transfer');

  $preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_disabled_01'));
  $users['recipient']->setIsDisabled(1)->save();
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview)),
    'handoff_account_unavailable',$check,'handoff-recipient-disabled-after-preview-rejected');
  $users['recipient']->setIsDisabled(0)->save();
  $preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_admin_lost1'));
  $users['admin']->setIsAdmin(0)->save();
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview)),
    'handoff_current_admin_required',$check,'handoff-admin-revoked-after-preview-rejected');
  $GLOBALS['directory_product_diagnostic']=array('phase'=>'before-admin-reactivation',
    'project_writer_transaction'=>(new PhabricatorProject())->establishConnection('w')->isInsideTransaction(),
    'user_writer_transaction'=>(new PhabricatorUser())->establishConnection('w')->isInsideTransaction());
  $users['admin']->setIsAdmin(1)->save();

  $GLOBALS['directory_product_diagnostic']['phase']='create-parent';
  $parent=$project('Synthetic handoff hidden parent');
  $GLOBALS['directory_product_diagnostic']['phase']='create-child';
  $child=$project('Synthetic handoff child',$parent);
  $GLOBALS['directory_product_diagnostic']['phase']='preview-child';
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($child,'handoff_ancestor01')),
    'handoff_recipient_native_permission',$check,'handoff-recipient-must-see-native-ancestor');
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($parent,'handoff_parent_001')),
    'handoff_descendant_impact_review_required',$check,'handoff-parent-impact-not-silently-approved');

  $parent=$project('Synthetic handoff visible parent')->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
  $child=$project('Synthetic handoff native retained access',$parent);
  (new PhabricatorProjectTransactionEditor())->setActor($users['admin'])
    ->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConduitContentSource::SOURCECONST))
    ->applyTransactions($child,array((new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type',PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
      ->setNewValue(array('+'=>array_fuse(array($users['admin']->getPHID()))))));
  $preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($child,'handoff_native_keep1'));
  $check($preview['actorAfter']===array('view'=>true,'edit'=>true),
    'handoff-preview-retains-native-member-view-and-parent-edit');
  $space=$child->getSpacePHID();$join=$child->getJoinPolicy();
  $result=PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview));
  $read=(new PhabricatorProjectQuery())->setViewer($users['admin'])->withPHIDs(array($child->getPHID()))
    ->needMembers(true)->needAncestorMembers(true)->executeOne();
  $check($result['committed']&&$read&&$read->getParentProjectPHID()===$parent->getPHID()&&
    $read->getSpacePHID()===$space&&$read->getJoinPolicy()===$join&&
    in_array($users['admin']->getPHID(),$read->getMemberPHIDs(),true)&&
    PhabricatorPolicyFilter::hasCapability($users['admin'],$read,PhabricatorPolicyCapability::CAN_EDIT),
    'handoff-preserves-native-members-join-space-and-parent');
  $historical=$project('Synthetic historical raw membership only');
  (new PhabricatorEdgeEditor())->addEdge($historical->getPHID(),
    PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,$users['admin']->getPHID())->save();
  $historicalPreview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($historical,'handoff_raw_only_01'));
  $check($historicalPreview['actorAfter']===array('view'=>false,'edit'=>false),
    'handoff-historical-direct-edge-does-not-become-automatic-view');
  $materialOnly=$project('Synthetic materialized member without inverse rule')->setEditPolicy('obj.project.members')->save();
  (new PhabricatorEdgeEditor())->addEdge($materialOnly->getPHID(),
    PhabricatorProjectMaterializedMemberEdgeType::EDGECONST,$users['admin']->getPHID())->save();
  $native=(new PhabricatorProjectQuery())->setViewer($users['admin'])->withPHIDs(array($materialOnly->getPHID()))
    ->needMembers(true)->needAncestorMembers(true)->executeOne();
  $check($native&&!PhabricatorPolicyFilter::hasCapability($users['admin'],$native,PhabricatorPolicyCapability::CAN_EDIT),
    'handoff-native-object-policy-does-not-use-materialized-membership');
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($materialOnly,'handoff_material_01')),
    'handoff_actor_native_permission',$check,'handoff-materialized-edge-does-not-forge-object-policy-edit');
  $inverseOnly=$project('Synthetic inverse object policy without automatic view')->setEditPolicy('obj.project.members')->save();
  (new PhabricatorEdgeEditor())->addEdge($users['admin']->getPHID(),
    PhabricatorProjectMemberOfProjectEdgeType::EDGECONST,$inverseOnly->getPHID())->save();
  $native=(new PhabricatorProjectQuery())->setViewer($users['admin'])->withPHIDs(array($inverseOnly->getPHID()))
    ->needMembers(true)->needAncestorMembers(true)->executeOne();
  $inversePreview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($inverseOnly,'handoff_inverse_01'));
  $check($native&&PhabricatorPolicyFilter::hasCapability($users['admin'],$native,PhabricatorPolicyCapability::CAN_EDIT)&&
    $inversePreview['actorAfter']===array('view'=>false,'edit'=>false),
    'handoff-inverse-object-policy-is-not-automatic-materialized-view');
  $archived=$project('Synthetic handoff archived')->setStatus(PhabricatorProjectStatus::STATUS_ARCHIVED)->save();
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($archived,'handoff_archived_01')),
    'handoff_ordinary_active_project_required',$check,'handoff-archived-project-refused');
  $milestone=$project('Synthetic handoff milestone',$parent)->setMilestoneNumber(1)->save();
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($milestone,'handoff_milestone01')),
    'handoff_ordinary_active_project_required',$check,'handoff-milestone-project-refused');
  $nested=$project('Synthetic unsupported milestone ancestor',$milestone);
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($nested,'handoff_mile_anc01')),
    'handoff_milestone_ancestor_unsupported',$check,'handoff-milestone-ancestor-refused');

  $p=$project('Synthetic handoff rollback');$preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_rollback01'));
  $before=$count();$conn=$p->establishConnection('w');$db=$p->getAphrontRefDatabaseName();
  if(!preg_match('/\Agovernance_[a-zA-Z0-9_]+\z/D',$db)){throw new RuntimeException('handoff_trigger_database_ownership');}
  governance_native_setup_query($fixture,'CREATE TRIGGER `'.$db.'`.`handoff_fail_audit` BEFORE INSERT ON `'.$db.'`.`project_transaction` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'synthetic handoff audit failure\'');
  $failed=false;try{PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview));}catch(Throwable$e){$failed=true;}
  finally{governance_native_setup_query($fixture,'DROP TRIGGER `'.$db.'`.`handoff_fail_audit`');}
  $check($failed&&$before===$count()&&$p->reload()->getViewPolicy()===$users['admin']->getPHID()&&
    $p->getEditPolicy()===$users['admin']->getPHID(),'handoff-real-audit-failure-rolls-back-both-policies');
  $result=PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview));
  $check($result['committed']&&$count()===$before+1,'handoff-rollback-retry-commits-once');

  $p=$project('Synthetic handoff PHP Error');$preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_php_error1'));
  $before=$count();$profiler=PhutilServiceProfiler::getInstance();$property=new ReflectionProperty(PhutilServiceProfiler::class,'listeners');
  $prior=$property->getValue($profiler);$armed=true;$injected=false;
  $profiler->addListener(function($event,$id,$data)use(&$armed,&$injected){
    if($armed&&$event==='begin'&&($data['type']??null)==='query'&&
      preg_match('/\AINSERT INTO .*project_transaction[\x60 ]/s',$data['query']??'')){
      $armed=false;$injected=true;throw new Error('synthetic handoff PHP Error');}
  });
  $failed=false;try{PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview));}catch(Error$e){$failed=true;}
  finally{$armed=false;$property->setValue($profiler,$prior);}
  $conn=$p->establishConnection('w');
  $check($injected&&$failed&&$before===$count()&&$p->reload()->getEditPolicy()===$users['admin']->getPHID()&&
    !$conn->isInsideTransaction()&&!$conn->isReadLocking()&&!$conn->isWriteLocking(),
    'handoff-php-error-rolls-back-and-restores-native-writer-state');

  foreach(array('begin','end')as$commitPhase){
    $p=$project('Synthetic handoff physical commit '.$commitPhase);
    $request=$confirm(PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_commit_'.$commitPhase.'_1')));
    $before=$count();$prior=$property->getValue($profiler);$armed=true;$injected=false;$auditReached=false;
    $profiler->addListener(function($event,$id,$data)use(&$armed,&$injected,&$auditReached,$commitPhase){
      if($event==='end'&&($data['type']??null)==='query'&&preg_match('/\AINSERT INTO .*project_transaction[\x60 ]/s',$data['query']??'')){$auditReached=true;}
      if($armed&&$auditReached&&$event===$commitPhase&&($data['type']??null)==='query'&&($data['query']??null)==='COMMIT'){
        $armed=false;$injected=true;throw new Error('synthetic physical COMMIT Error');}
    });
    $failed=false;$result=null;
    try{$result=PhorgeProjectHandoffService::commit($contexts['admin'],$request);}catch(Error$e){$failed=true;}
    finally{$armed=false;$property->setValue($profiler,$prior);}
    $p->reload();$conn=$p->establishConnection('w');
    if($commitPhase==='begin'){
      $check($injected&&$failed&&$before===$count()&&$p->getViewPolicy()===$users['admin']->getPHID()&&
        $p->getEditPolicy()===$users['admin']->getPHID(), 'handoff-error-before-physical-commit-never-implicitly-commits');
    }else{
      $check($injected&&!$failed&&$result['committed']&&$result['postCommitProcessingComplete']===false&&
        $count()===$before+1&&$p->getViewPolicy()===$users['recipient']->getPHID()&&$p->getEditPolicy()===$users['recipient']->getPHID(),
        'handoff-error-after-physical-commit-reconciles-durable-receipt');
    }
    $check(!$conn->isInsideTransaction()&&!$conn->isReadLocking()&&!$conn->isWriteLocking(),
      'handoff-physical-commit-'.$commitPhase.'-restores-owned-writer');
  }

  // A second physical MySQL connection attempts a real recipient revocation
  // while the native editor holds the authorization read set. Observe an
  // actual InnoDB wait, not a sleep-based assumption or mocked row reader.
  $p=$project('Synthetic handoff concurrent revocation');
  $request=$confirm(PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_live_revoke1')));
  $writer=$fixture->getWriterConfig();
  $parallel=new mysqli($writer['host'],$writer['user'],$writer['password'],'',$writer['port']);
  $parallel->query('SET innodb_lock_wait_timeout=8');$thread=(int)$parallel->thread_id;
  $prior=$property->getValue($profiler);$armed=true;$waitProved=false;$sent=false;
  $profiler->addListener(function($event,$id,$data)use(&$armed,&$waitProved,&$sent,$parallel,$thread,$fixture,$writer,$users,$control){
    if(!$armed||$event!=='begin'||($data['type']??null)!=='query'||
      !preg_match('/\AINSERT INTO .*project_transaction[\x60 ]/s',$data['query']??'')){return;}
    $armed=false;
    $sql='UPDATE `'.$writer['namespace'].'_user`.`user` SET isDisabled=1 WHERE phid=\''.$parallel->real_escape_string($users['recipient']->getPHID()).'\'';
    if(!$parallel->query($sql,MYSQLI_ASYNC)){throw new RuntimeException('handoff_parallel_revoke_start');}$sent=true;
    $deadline=microtime(true)+3;
    do{
      $row=$control->query('SELECT COUNT(*) n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID='.$thread)->fetch_assoc();
      if((int)$row['n']>0){$waitProved=true;break;}usleep(10000);
    }while(microtime(true)<$deadline);
    if(!$waitProved){throw new RuntimeException('handoff_parallel_lock_wait_not_proved');}
  });
  try{
    $result=PhorgeProjectHandoffService::commit($contexts['admin'],$request);
    $read=array($parallel);$errors=$reject=array();
    $ready=mysqli_poll($read,$errors,$reject,5);
    $revoked=$ready===1&&$parallel->reap_async_query()===true;
    $current=(new PhabricatorUser())->loadOneWhere('phid=%s',$users['recipient']->getPHID());
    $check($sent&&$waitProved&&$revoked&&$result['committed']&&$current->getIsDisabled(),
      'handoff-real-concurrent-revocation-waits-for-native-commit');
  }finally{
    $armed=false;$property->setValue($profiler,$prior);
    // The independently opened connection belongs solely to this fixture.
    $control->query('KILL CONNECTION '.$thread);$parallel->close();
  }
  $users['recipient']->setIsDisabled(0)->save();

  $p=$project('Synthetic handoff rollback failure');
  $request=$confirm(PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_rb_failure1')));
  $before=$count();$prior=$property->getValue($profiler);$auditFailed=false;$rollbackFailed=false;$queriesAfterFailure=0;
  $profiler->addListener(function($event,$id,$data)use(&$auditFailed,&$rollbackFailed,&$queriesAfterFailure){
    if($event!=='begin'||($data['type']??null)!=='query'){return;}
    if($rollbackFailed){++$queriesAfterFailure;throw new Error('synthetic poisoned connection reused');}
    if(!$auditFailed&&preg_match('/\AINSERT INTO .*project_transaction[\x60 ]/s',$data['query']??'')){
      $auditFailed=true;throw new Error('synthetic audit Error before rollback failure');}
    if($auditFailed&&($data['query']??null)==='ROLLBACK'){
      $rollbackFailed=true;throw new Error('synthetic physical rollback failure');}
  });
  $failed=false;
  try{PhorgeProjectHandoffService::commit($contexts['admin'],$request);}catch(Error$e){$failed=true;}
  finally{$property->setValue($profiler,$prior);}
  $conn=$p->establishConnection('w');
  $check($failed&&$auditFailed&&$rollbackFailed&&$queriesAfterFailure===0&&$before===$count()&&
    $p->reload()->getEditPolicy()===$users['admin']->getPHID()&&!$conn->isInsideTransaction()&&
    !$conn->isReadLocking()&&!$conn->isWriteLocking(),'handoff-failed-rollback-disposes-without-reconciliation-or-write');
  governance_native_refused(fn()=>PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_poisoned_01')),
    'handoff_idle_native_writer_required',$check,'handoff-poisoned-writer-refuses-subsequent-operation');
  LiskDAO::closeAllConnections();

  $p=$project('Synthetic handoff sealed entry');$request=$confirm(PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_sealed_001')));
  $editor=(new PhabricatorProjectTransactionEditor())->setActor($users['admin'])
    ->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConduitContentSource::SOURCECONST));
  $x=(new PhabricatorProjectTransaction())->setTransactionType(PhorgeProjectHandoffService::TYPE)->setNewValue($request);
  governance_native_refused(fn()=>$editor->applyTransactions($p,array($x)),
    'handoff_sealed_dispatch_required',$check,'handoff-generic-editor-cannot-invoke-exception');

  $preview=PhorgeProjectHandoffService::preview($contexts['admin'],$intent($p,'handoff_token_gone1'));
  $token=(new PhabricatorConduitToken())->loadOneWhere('token=%s',$tokens['admin']);$token->delete();
  governance_native_refused(fn()=>PhorgeProjectHandoffService::commit($contexts['admin'],$confirm($preview)),
    'handoff_credential_unavailable',$check,'handoff-revoked-token-rejected');
  if(getenv('PHORGE_DIRECTORY_BROWSER_ORIGIN')!==false){
    require_once __DIR__.'/project-handoff-browser-fixture.php';
    $sessions=new PhabricatorAuthSessionEngine();$admin=governance_native_request($users['admin'],$sessions);
    governance_project_handoff_browser($fixture,$sessions,$admin,$users,array(
      'web'=>$project('Synthetic browser Web handoff'),'api'=>$project('Synthetic browser API handoff')),$check);
  }
  return array();
}
