<?php

/** Explicit policy-only handoff, executed by the native Project editor. */
final class PhorgeProjectHandoffService extends Phobject {
  const TYPE='project:permission-handoff';
  private static $calls;
  private static $poisoned;

  public static function enabled() {
    return PhabricatorEnv::getEnvConfig('projects.permission-handoff.enabled')===true;
  }
  private static function available() {
    if(!self::enabled()||PhorgeProjectExecutionCatalog::enabled()){
      throw new RuntimeException('handoff_disabled_or_approval_interoperation_pending');}
    if(PhabricatorEnv::getEnvConfig('cluster.databases')!==array()){
      throw new RuntimeException('handoff_database_cluster_unsupported');}
  }
  public static function isEditor($editor) {
    return self::$calls!==null&&isset(self::$calls[$editor]);
  }
  private static function call($editor,$object,$transaction) {
    self::available();$call=self::$calls[$editor]??null;
    if(!$call||get_class($editor)!==PhabricatorProjectTransactionEditor::class||
      $call['object']!==$object||$call['transaction']!==$transaction||
      $editor->getIsPreview()||$editor->getIsSilent()||$editor->getIsHeraldEditor()||
      $editor->getIsInverseEdgeEditor()||$editor->getActor()->getPHID()!==$call['context']->getUserPHID()||
      $editor->getActingAsPHID()!==$call['context']->getUserPHID()){
      throw new RuntimeException('handoff_sealed_dispatch_required');}
    return$call;
  }
  /** Require one primary and transactional source tables; never borrow a writer. */
  private static function table(AphrontDatabaseConnection $conn,PhabricatorLiskDAO $table) {
    if(isset(self::$poisoned[$conn])||get_class($conn)!==AphrontMySQLiDatabaseConnection::class||$conn->getPersistent()||!$conn->isInsideTransaction()){
      throw new RuntimeException('handoff_native_transaction_required');}
    $route=$table->establishConnection('w');
    if(get_class($route)!==AphrontMySQLiDatabaseConnection::class||($route!==$conn&&$route->isInsideTransaction())){
      throw new RuntimeException('handoff_foreign_transaction');}
    $a=queryfx_one($conn,'SELECT @@server_uuid uuid,@@transaction_isolation isolationLevel');
    $b=queryfx_one($route,'SELECT @@server_uuid uuid,DATABASE() db');
    if(!$a||!$b||$a['isolationLevel']!=='REPEATABLE-READ'||$a['uuid']!==$b['uuid']||$b['db']!==$table->getAphrontRefDatabaseName()){
      throw new RuntimeException('handoff_same_primary_required');}
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
      $b['db'],$table->getTableName());
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('handoff_transactional_source_required');}
  }
  private static function now($conn) { return(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n']; }
  private static function user($conn,$phid) {
    self::table($conn,new PhabricatorUser());
    $row=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',new PhabricatorUser(),$phid);
    if(!$row||$row['phid']!==$phid||(string)$row['isApproved']!=='1'||(string)$row['isDisabled']!=='0'||
      (string)$row['isSystemAgent']!=='0'||(string)$row['isMailingList']!=='0'){
      throw new RuntimeException('handoff_account_unavailable');}
    if(!(new PhabricatorUser())->loadFromArray($row)->isUserActivated()){throw new RuntimeException('handoff_account_unavailable');}
    return$row;
  }
  private static function credential($conn,PhorgeGovernanceNativeActorContext $context,?PhabricatorAuthSession &$session=null) {
    $session=null;
    $secret=$context->getCredentialEnvelope()->openEnvelope();$now=self::now($conn);
    if($context->getCredentialType()==='web'){
      $table=new PhabricatorAuthSession();self::table($conn,$table);
      $row=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',$table,$context->getSessionPHID());
      if(!$row||$row['userPHID']!==$context->getUserPHID()||$row['type']!==PhabricatorAuthSession::TYPE_WEB||
        (string)$row['isPartial']!=='0'||!hash_equals($row['sessionKey'],$secret)||
        (int)$row['sessionStart']>$now||(int)$row['sessionExpires']<=$now){throw new RuntimeException('handoff_credential_unavailable');}
      $session=(new PhabricatorAuthSession())->loadFromArray($row);
      $row=array_select_keys($row,array('id','phid','userPHID','type','sessionKey','sessionStart','sessionExpires','isPartial'));
    }else if($context->getCredentialType()==='api-token'){
      $table=new PhabricatorConduitToken();self::table($conn,$table);
      $row=queryfx_one($conn,'SELECT id,objectPHID,tokenType,token,expires FROM %R WHERE BINARY token=BINARY %s LOCK IN SHARE MODE',$table,$secret);
      if(!$row||$row['objectPHID']!==$context->getUserPHID()||!hash_equals($row['token'],$secret)||
        !in_array($row['tokenType'],array('api','cli'),true)||($row['expires']!==null&&(int)$row['expires']<=$now)){
        throw new RuntimeException('handoff_credential_unavailable');}
    }else{throw new RuntimeException('handoff_credential_kind');}
    return PhorgeProjectHandoffRequest::digest($row);
  }
  private static function supportedPolicy($policy) {
    // Arbitrary custom rules and cross-project membership sources need their
    // own reviewed locking read sets. Refuse them instead of approximating.
    if(!in_array($policy,array('public','users','admin','no-one','obj.project.members'),true)&&
      (!is_string($policy)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$policy))){
      throw new RuntimeException('handoff_policy_readset_unsupported');}
  }
  private static function receipt($conn,array $request,$actor) {
    $rows=queryfx_all($conn,'SELECT * FROM %R WHERE objectPHID=%s AND transactionType=%s ORDER BY id DESC LIMIT 1001',
      new PhabricatorProjectTransaction(),$request['projectPHID'],self::TYPE);
    if(count($rows)>1000){throw new RuntimeException('handoff_history_bound');}
    foreach($rows as$row){$x=(new PhabricatorProjectTransaction())->loadFromArray($row);$value=$x->getNewValue();
      if(!is_array($value)||!isset($value['operationID'])){throw new RuntimeException('handoff_receipt_invalid');}
      if($value['operationID']!==$request['operationID']){continue;}
      if($x->getAuthorPHID()!==$actor||$value!==$request){throw new RuntimeException('handoff_operation_conflict');}
      return array('committed'=>true,'replayed'=>true,'projectPHID'=>$request['projectPHID'],
        'recipientPHID'=>$request['recipientPHID'],'operationID'=>$request['operationID'],'transactionPHID'=>$x->getPHID());
    }
    return null;
  }
  private static function snapshot($conn,PhorgeGovernanceNativeActorContext $context,array $request,$expires) {
    self::available();self::table($conn,new PhabricatorProject());self::table($conn,new PhabricatorProjectTransaction());
    $actors=array($context->getUserPHID(),$request['recipientPHID']);sort($actors,SORT_STRING);$users=array();
    foreach(array_unique($actors)as$phid){$users[$phid]=self::user($conn,$phid);}
    $actor=(new PhabricatorUser())->loadFromArray($users[$context->getUserPHID()]);
    $recipient=(new PhabricatorUser())->loadFromArray($users[$request['recipientPHID']]);
    if(!$actor->getIsAdmin()||$actor->getPHID()===$recipient->getPHID()){
      throw new RuntimeException('handoff_current_admin_and_distinct_recipient_required');}
    $session=null;$credential=self::credential($conn,$context,$session);
    $rows=array();$objects=array();$phid=$request['projectPHID'];
    while($phid!==null){
      if(isset($rows[$phid])||count($rows)>=32){throw new RuntimeException('handoff_hierarchy_bound');}
      $row=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY phid=BINARY %s FOR UPDATE',new PhabricatorProject(),$phid);
      if(!$row||$row['phid']!==$phid){throw new RuntimeException('handoff_project_unavailable');}
      foreach(array('viewPolicy','editPolicy','joinPolicy')as$key){self::supportedPolicy($row[$key]);}
      $rows[$phid]=$row;$objects[$phid]=(new PhabricatorProject())->loadFromArray($row);
      $phid=$row['parentProjectPHID'];
    }
    $project=$objects[$request['projectPHID']];
    if($project->isMilestone()||$project->isArchived()){throw new RuntimeException('handoff_ordinary_active_project_required');}
    foreach($objects as$id=>$ancestor){if($id!==$project->getPHID()&&$ancestor->isMilestone()){
      throw new RuntimeException('handoff_milestone_ancestor_unsupported');}}
    $children=queryfx_all($conn,'SELECT id FROM %R WHERE parentProjectPHID=%s LIMIT 1 FOR UPDATE',
      new PhabricatorProject(),$project->getPHID());
    if($children){throw new RuntimeException('handoff_descendant_impact_review_required');}
    $edgeConn=PhabricatorEdgeConfig::establishConnection('PROJ','w');
    if($edgeConn!==$conn){throw new RuntimeException('handoff_same_edge_writer_required');}
    $edgeEngine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','edge');
    if(!$edgeEngine||$edgeEngine['ENGINE']!=='InnoDB'){throw new RuntimeException('handoff_transactional_edges_required');}
    $userTable=new PhabricatorUser();self::table($conn,$userTable);
    $userEdge=PhabricatorEdgeConfig::establishConnection('USER','w');
    if($userEdge!==$userTable->establishConnection('w')){throw new RuntimeException('handoff_user_edge_route_unsupported');}
    $userDatabase=$userTable->getAphrontRefDatabaseName();
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$userDatabase,'edge');
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('handoff_transactional_user_edges_required');}
    $members=array();$membershipEdges=array();$ruleMemberships=array();
    foreach($objects as$id=>$object){
      $edges=queryfx_all($conn,'SELECT type,dst FROM edge WHERE src=%s AND type IN (%Ld) ORDER BY type,dst LIMIT 2001 LOCK IN SHARE MODE',
        $id,array(PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,PhabricatorProjectMaterializedMemberEdgeType::EDGECONST));
      $direct=array_filter($edges,static fn($edge)=>(int)$edge['type']===PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);
      $materialized=array_filter($edges,static fn($edge)=>(int)$edge['type']===PhabricatorProjectMaterializedMemberEdgeType::EDGECONST);
      if(count($edges)>2000||count($direct)>1000||count($materialized)>1000){throw new RuntimeException('handoff_member_bound');}
      // Automatic project VIEW uses the native materialized edge set. Do not
      // reinterpret historical direct edges or rebuild membership here.
      $membershipEdges[$id]=$edges;$members[$id]=array_column($materialized,'dst');
      $object->attachMemberPHIDs($members[$id]);
      $object->attachParentProject($rows[$id]['parentProjectPHID']===null?null:$objects[$rows[$id]['parentProjectPHID']]);
      // The Project Members object policy separately reads inverse user
      // membership edges. It must not receive an aggregate-membership hint.
      $inverse=queryfx_all($conn,'SELECT src FROM %T.%T WHERE dst=%s AND type=%d AND src IN (%Ls) ORDER BY src LOCK IN SHARE MODE',
        $userDatabase,'edge',$id,PhabricatorProjectMemberOfProjectEdgeType::EDGECONST,$actors);
      $ruleMemberships[$id]=array_column($inverse,'src');
      PhabricatorPolicyRule::passTransactionHintToRule($object,new PhabricatorProjectMembersPolicyRule(),array_fuse($ruleMemberships[$id]));
    }
    // Publish only current locked Space rows to this bounded evaluation, then
    // restore the caller's request-local caches in every terminal path.
    self::table($conn,new PhabricatorSpacesNamespace());
    $spaces=queryfx_all($conn,'SELECT * FROM %R ORDER BY id LIMIT 65 LOCK IN SHARE MODE',new PhabricatorSpacesNamespace());
    if(count($spaces)>64){throw new RuntimeException('handoff_space_bound');}
    $spaceObjects=array();foreach($spaces as$row){self::supportedPolicy($row['viewPolicy']);
      $spaceObjects[$row['phid']]=(new PhabricatorSpacesNamespace())->loadFromArray($row);}
    $cache=PhabricatorCaches::getRequestCache();$missing=new stdClass();$prior=array();
    foreach(array(PhabricatorSpacesNamespaceQuery::KEY_ALL,PhabricatorSpacesNamespaceQuery::KEY_DEFAULT)as$key){$prior[$key]=$cache->getKey($key,$missing);}
    $cache->setKey(PhabricatorSpacesNamespaceQuery::KEY_ALL,$spaceObjects);$cache->deleteKey(PhabricatorSpacesNamespaceQuery::KEY_DEFAULT);
    try{
      foreach(array(PhabricatorPolicyCapability::CAN_VIEW,PhabricatorPolicyCapability::CAN_EDIT)as$cap){
        if(!PhabricatorPolicyFilter::hasCapability($actor,$project,$cap)){throw new RuntimeException('handoff_actor_native_permission');}}
      $final=clone$project;$final->setViewPolicy($recipient->getPHID())->setEditPolicy($recipient->getPHID());
      foreach(array(PhabricatorPolicyCapability::CAN_VIEW,PhabricatorPolicyCapability::CAN_EDIT)as$cap){
        if(!PhabricatorPolicyFilter::hasCapability($recipient,$final,$cap)){throw new RuntimeException('handoff_recipient_native_permission');}}
      $actorFinal=array('view'=>PhabricatorPolicyFilter::hasCapability($actor,$final,PhabricatorPolicyCapability::CAN_VIEW),
        'edit'=>PhabricatorPolicyFilter::hasCapability($actor,$final,PhabricatorPolicyCapability::CAN_EDIT));
    }finally{foreach($prior as$key=>$value){if($value===$missing){$cache->deleteKey($key);}else{$cache->setKey($key,$value);}}}
    $basis=array('schema'=>'project-handoff-preview-v1','intent'=>array('operationID'=>$request['operationID'],
      'projectPHID'=>$request['projectPHID'],'recipientPHID'=>$request['recipientPHID']),'actorPHID'=>$actor->getPHID(),
      'credential'=>$credential,'users'=>$users,'projects'=>$rows,'members'=>$members,'membershipEdges'=>$membershipEdges,'ruleMemberships'=>$ruleMemberships,'spaces'=>$spaces,'expiresAt'=>$expires,
      'allowPublic'=>PhabricatorEnv::getEnvConfig('policy.allow-public'),'requireEmailVerification'=>(bool)PhabricatorUserEmail::isEmailVerificationRequired());
    return array('digest'=>PhorgeProjectHandoffRequest::digest($basis),'project'=>$project,'actor'=>$actor,
      'recipient'=>$recipient,'actorFinal'=>$actorFinal,'session'=>$session);
  }
  public static function preview(PhorgeGovernanceNativeActorContext $context,array $payload) {
    self::available();$request=PhorgeProjectHandoffRequest::parse($payload,false);$object=new PhabricatorProject();$conn=$object->establishConnection('w');
    self::idle($conn);
    $state=self::transactionState($conn);$generation=$state->getGeneration();
    try{$conn->openTransaction();$expires=self::now($conn)+300;$s=self::snapshot($conn,$context,$request,$expires);
      $result=$request+array('expiresAt'=>$expires,'expectedDigest'=>$s['digest'],'recipientName'=>$s['recipient']->getUsername(),
        'before'=>array('view'=>$s['project']->getViewPolicy(),'edit'=>$s['project']->getEditPolicy()),
        'after'=>array('view'=>$request['recipientPHID'],'edit'=>$request['recipientPHID']),
        'actorAfter'=>$s['actorFinal'],'membersChanged'=>false,'joinSpaceHierarchyChanged'=>false);
      $conn->saveTransaction();return$result;
    }catch(Throwable$e){self::rollbackOwnedError($conn,$state,$generation);throw$e;}
  }
  public static function validateTransaction($editor,$object,$transaction) {
    $call=self::call($editor,$object,$transaction);$request=$call['request'];
    if($transaction->getNewValue()!==$request||$object->getPHID()!==$request['projectPHID']){throw new RuntimeException('handoff_request_changed');}
    $conn=$object->establishConnection('w');$now=self::now($conn);
    if($request['expiresAt']<=$now||$request['expiresAt']>$now+300){throw new RuntimeException('handoff_preview_expired');}
    if(self::receipt($conn,$request,$call['context']->getUserPHID())!==null){throw new RuntimeException('handoff_already_committed');}
    $snapshot=self::snapshot($conn,$call['context'],$request,$request['expiresAt']);
    if(!hash_equals($snapshot['digest'],$request['expectedDigest'])){throw new RuntimeException('handoff_preview_changed');}
    if($snapshot['session']!==null){$snapshot['actor']->attachSession($snapshot['session']);}
    $editor->setActor($snapshot['actor']);
    self::$calls[$editor]=$call+array('validated'=>true);
  }
  public static function apply($editor,$object,$transaction) {
    $call=self::call($editor,$object,$transaction);
    if(!($call['validated']??false)){throw new RuntimeException('handoff_validation_required');}
    // Locking source reads are retained by the native Project transaction.
    self::validateTransaction($editor,$object,$transaction);
    $object->setViewPolicy($call['request']['recipientPHID'])->setEditPolicy($call['request']['recipientPHID']);
  }
  public static function applyValue($editor,$object,$value) {
    $call=self::$calls[$editor]??null;
    if(!$call||$value!==$call['request']){throw new RuntimeException('handoff_unsealed_value');}
    self::apply($editor,$object,$call['transaction']);
  }
  private static function transactionState($conn) {
    return (new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState'))->invoke($conn);
  }
  private static function idle($conn) {
    if(isset(self::$poisoned[$conn])||get_class($conn)!==AphrontMySQLiDatabaseConnection::class||$conn->getPersistent()||
      $conn->isInsideTransaction()||$conn->isReadLocking()||$conn->isWriteLocking()||$conn->isHoldingAnyLock()){
      throw new RuntimeException('handoff_idle_native_writer_required');}
  }
  private static function rollbackOwnedError($conn,$state,$generation) {
    if(self::transactionState($conn)!==$state){throw new RuntimeException('handoff_native_state_changed');}
    $ownedGeneration=$state->getGeneration()===$generation+1;
    if((!$ownedGeneration&&$state->getGeneration()!==$generation)||$state->getDepth()>8){
      throw new RuntimeException('handoff_transaction_ownership_unknown');}
    if($ownedGeneration){
      try{
        while($conn->isInsideTransaction()){$conn->killTransaction();}
        // Native saveTransaction decrements its local depth before COMMIT.
        // An Error before the physical COMMIT must not let a subsequent
        // START TRANSACTION implicitly commit the unacknowledged write.
        $conn->query('ROLLBACK');
      }catch(Throwable$rollbackError){
        if(self::$poisoned===null){self::$poisoned=new WeakMap();}
        self::$poisoned[$conn]=true;
        $conn->close();
        // The nonpersistent physical connection is now disposed. Clear only
        // this invocation's proved local counters, without reconnecting or SQL.
        while($state->getDepth()>0){$state->decreaseDepth();}
        while($state->isReadLocking()){$state->endReadLocking();}
        while($state->isWriteLocking()){$state->endWriteLocking();}
        throw$rollbackError;
      }
    }
    while($conn->isReadLocking()){$conn->endReadLocking();}
    while($conn->isWriteLocking()){$conn->endWriteLocking();}
    self::idle($conn);
  }
  public static function commit(PhorgeGovernanceNativeActorContext $context,array $payload,?AphrontRequest $web=null) {
    self::available();$request=PhorgeProjectHandoffRequest::parse($payload,true);
    if(($context->getCredentialType()==='web')!==($web!==null)){throw new RuntimeException('handoff_actual_web_request_required');}
    if($web!==null){$web->validateCSRF();$actual=PhorgeGovernanceNativeActorContext::newFromWebRequest($web);
      if(!$web->isHTTPPost()||$actual->getCredentialDigest()!==$context->getCredentialDigest()||$actual->getUserPHID()!==$context->getUserPHID()){
        throw new RuntimeException('handoff_web_context_changed');}}
    $object=(new PhabricatorProject())->loadOneWhere('phid=%s',$request['projectPHID']);
    if(!$object){throw new RuntimeException('handoff_project_unavailable');}
    $conn=$object->establishConnection('w');
    self::idle($conn);
    // Replays disclose only the caller's own exact receipt, with current
    // administrator and credential checks, even after losing object access.
    $state=self::transactionState($conn);$generation=$state->getGeneration();
    try{$conn->openTransaction();$user=self::user($conn,$context->getUserPHID());self::credential($conn,$context);
      if((string)$user['isAdmin']!=='1'){throw new RuntimeException('handoff_current_admin_required');}
      $receipt=self::receipt($conn,$request,$context->getUserPHID());$conn->saveTransaction();
    }catch(Throwable$e){self::rollbackOwnedError($conn,$state,$generation);throw$e;}
    if($receipt!==null){return$receipt;}
    self::idle((new PhabricatorProject())->establishConnection('r'));
    $viewer=(new PhabricatorUser())->loadFromArray($user);
    $object=(new PhabricatorProjectQuery())->setViewer($viewer)->withPHIDs(array($request['projectPHID']))
      ->needMembers(true)->needAncestorMembers(true)->executeOne();
    if(!$object){throw new RuntimeException('handoff_project_unavailable');}
    $editor=$object->getApplicationTransactionEditor()->setActor($viewer)->setProjectApprovalActorContext($context);
    if($web!==null){$editor->setContentSourceFromRequest($web)->setCancelURI($object->getURI());}else{$editor->setContentSource(PhabricatorContentSource::newForSource(PhabricatorConduitContentSource::SOURCECONST));}
    $x=(new PhabricatorProjectTransaction())->setTransactionType(self::TYPE)->setNewValue($request);
    self::idle($conn);$state=self::transactionState($conn);$generation=$state->getGeneration();
    if(self::$calls===null){self::$calls=new WeakMap();}
    self::$calls[$editor]=array('context'=>$context,'object'=>$object,'transaction'=>$x,'request'=>$request);
    try{$editor->applyTransactions($object,array($x));}
    catch(Throwable$error){
      self::rollbackOwnedError($conn,$state,$generation);
      // Post-commit cache/publication failure must not be reported as rollback.
      // Reconcile only an exact durable native transaction; unknown outcomes
      // remain errors and must never cause an automatic second write.
      if(!$conn->isInsideTransaction()){
        $receiptState=self::transactionState($conn);$receiptGeneration=$receiptState->getGeneration();
        try{$conn->openTransaction();$current=self::user($conn,$context->getUserPHID());self::credential($conn,$context);
          if((string)$current['isAdmin']!=='1'){throw new RuntimeException('handoff_current_admin_required');}
          $receipt=self::receipt($conn,$request,$context->getUserPHID());$conn->saveTransaction();
          if($receipt!==null){return$receipt+($receipt['transactionPHID']===$x->getPHID()?array('postCommitProcessingComplete'=>false):array());}
        }catch(Throwable$ignored){self::rollbackOwnedError($conn,$receiptState,$receiptGeneration);}
      }
      throw$error;
    }
    finally{unset(self::$calls[$editor]);}
    return array('committed'=>true,'replayed'=>false,'projectPHID'=>$request['projectPHID'],
      'recipientPHID'=>$request['recipientPHID'],'operationID'=>$request['operationID'],'transactionPHID'=>$x->getPHID());
  }
}
