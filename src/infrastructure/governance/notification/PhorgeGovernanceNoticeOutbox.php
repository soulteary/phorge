<?php

/**
 * Candidate producer adapter. No hooks are installed until worker/policy/tests
 * are complete. It borrows the source transaction and never opens or commits
 * another application's connection. No caller array is an authority proof.
 */
final class PhorgeGovernanceNoticeOutbox extends Phobject {
  public static function enabled(){
    $value=PhabricatorEnv::getEnvConfig('governance.notifications.enabled');
    if(!is_bool($value)){throw new RuntimeException('governance_notice_control_unknown');}
    return$value;
  }
  public static function assertRuntime(){
    PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    if(PhabricatorEnv::getEnvConfig('cluster.databases')!==array()||PhabricatorEnv::isReadOnly()!==false||
      !in_array(PhabricatorEnv::getEnvConfig('gorge.db.uri'),array('',null),true)||
      LiskDAO::shouldIsolateAllLiskEffectsToCurrentProcess()||LiskDAO::shouldIsolateAllLiskEffectsToTransactions()||
      (new ReflectionProperty(PhabricatorLiskDAO::class,'namespaceStack'))->getValue()!==array()){
      throw new RuntimeException('governance_notice_runtime_profile_refused');}
  }
  public static function primary(AphrontDatabaseConnection $conn,PhabricatorLiskDAO $reference){
    if(get_class($conn)!==AphrontMySQLiDatabaseConnection::class){throw new RuntimeException('governance_notice_primary_required');}
    $row=queryfx_one($conn,'SELECT DATABASE() db,@@server_uuid uuid,@@read_only ro');
    if(!$row||$row['db']!==$reference->getAphrontRefDatabaseName()||!is_string($row['uuid'])||$row['uuid']===''||
      !in_array($row['ro'],array(0,'0'),true)){throw new RuntimeException('governance_notice_primary_required');}
    return $row['uuid'];
  }
  private static function profile(AphrontDatabaseConnection $conn,$app){
    self::assertRuntime();
    if(get_class($conn)!==AphrontMySQLiDatabaseConnection::class||!$conn->isInsideTransaction()||
      PhabricatorEnv::getEnvConfig('cluster.databases')!==array()||PhabricatorEnv::isReadOnly()!==false||
      !PhorgePrivateMailPolicy::enabled()||PhabricatorEnv::getEnvConfig('metamta.gorge-delivery-mode')!=='native'){
      throw new RuntimeException('governance_notice_transaction_profile_refused');}
    $reference=$app==='user'?new PhabricatorUser():new PhabricatorProject();
    $source=$reference->getAphrontRefDatabaseName();$meta=(new PhabricatorMetaMTAGorgeOutbox())->getAphrontRefDatabaseName();
    $row=queryfx_one($conn,'SELECT DATABASE() db,@@server_uuid uuid,@@read_only ro');
    if(!$row||$row['db']!==$source||!is_string($row['uuid'])||$row['uuid']===''||!in_array($row['ro'],array(0,'0'),true)){
      throw new RuntimeException('governance_notice_primary_required');}
    $meta_reference=new PhabricatorMetaMTAGorgeOutbox();$meta_route=$meta_reference->establishConnection('w');
    if(($meta_route!==$conn&&$meta_route->isInsideTransaction())||
      self::primary($meta_route,$meta_reference)!==$row['uuid']){throw new RuntimeException('governance_notice_same_primary_required');}
    foreach(array('governance_notice_event','governance_notice_delivery','metamta_gorgeoutbox')as$table){
      queryfx_one($conn,'SELECT eventID FROM %T.%T WHERE 1=0 LOCK IN SHARE MODE',$meta,$table);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$meta,$table);
      if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('governance_notice_innodb_required');}
    }
    return$meta;
  }
  private static function append(AphrontDatabaseConnection $conn,$app,array $source){
    // A partial installation may never advertise durable delivery readiness.
    if(!class_exists('PhorgeGovernanceNoticeWorker')||!is_subclass_of('PhorgeGovernanceNoticeWorker',PhabricatorWorker::class)){
      throw new RuntimeException('governance_notice_worker_not_installed');}
    $meta=self::profile($conn,$app);$event=PhorgeGovernanceNoticeEventValue::fromAudit($source);
    $json=json_encode($event['metadata'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($json)>2048){throw new RuntimeException('governance_notice_metadata_budget');}
    $digest=hash('sha256',$json);$payload=json_encode(PhorgeGovernanceNoticeEventValue::queuePayload($event),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $row=queryfx_one($conn,'SELECT eventID,metadataDigest,CASE WHEN OCTET_LENGTH(metadataJSON)<=2048 THEN metadataJSON ELSE NULL END metadataJSON '.
      'FROM %T.governance_notice_event WHERE eventID=%s FOR UPDATE',$meta,$event['event_id']);
    if($row){
      $relay=queryfx_one($conn,'SELECT eventID,CASE WHEN OCTET_LENGTH(payload)<=2048 THEN payload ELSE NULL END payload '.
        'FROM %T.metamta_gorgeoutbox WHERE eventID=%s LOCK IN SHARE MODE',$meta,'governance.notice/'.$event['event_id'].'/1');
      if($row['eventID']!==$event['event_id']||$row['metadataJSON']!==$json||!hash_equals($digest,$row['metadataDigest'])||
        !$relay||$relay['payload']!==$payload){throw new RuntimeException('governance_notice_event_conflict');}
      return;
    }
    queryfx($conn,'INSERT INTO %T.governance_notice_event (eventID,schemaVersion,sourceKind,sourceID,operationDigest,actorDigest,action,'.
      'metadataJSON,metadataDigest,createdAt,state) VALUES (%s,1,%s,%s,%s,%s,%s,%s,%s,%d,%s)',
      $meta,$event['event_id'],$source['source_kind'],$source['source_id'],$event['metadata']['operation_sha256'],$event['metadata']['actor_sha256'],
      $source['action'],$json,$digest,$source['created_at'],'pending');
    queryfx($conn,'INSERT INTO %T.metamta_gorgeoutbox (eventID,payload,attempts,nextAttempt,lastError) VALUES (%s,%s,0,0,%s)',
      $meta,'governance.notice/'.$event['event_id'].'/1',$payload,'');
  }
  public static function organizationAudit(PhorgeOrganizationService $service,$id){
    if(!self::enabled()){return;}
    if(get_class($service)!==PhorgeOrganizationService::class){throw new RuntimeException('governance_notice_owner_required');}
    $get=static function($name)use($service){return(new ReflectionProperty(PhorgeOrganizationService::class,$name))->getValue($service);};
    $nonce=$get('transactionNonce');$conn=$get('connection');
    // Check lifetime before making any query on the borrowed connection.
    if(!is_string($nonce)||!preg_match('/\A[a-f0-9]{32}\z/D',$nonce)||$get('readStartedAt')!==null||
      !($conn instanceof AphrontDatabaseConnection)||!$conn->isInsideTransaction()){
      throw new RuntimeException('governance_notice_owner_ended');}
    $actor=PhorgeGovernanceNativeIdentitySource::revalidate($get('actorSnapshot'),$conn);
    $row=queryfx_one($conn,'SELECT id,operationID,actorPHID,action,createdAt FROM organization_audit WHERE id=%s LOCK IN SHARE MODE',(string)$id);
    if(!$row||$row['actorPHID']!==$actor->getUserPHID()){
      throw new RuntimeException('governance_notice_source_refused');}
    self::append($conn,'user',array('source_kind'=>'organization','source_id'=>(string)$row['id'],
      'operation_id'=>$row['operationID'],'actor_phid'=>$row['actorPHID'],'action'=>$row['action'],'created_at'=>(int)$row['createdAt']));
    if($get('transactionNonce')!==$nonce||$get('connection')!==$conn){throw new RuntimeException('governance_notice_owner_ended');}
    PhorgeGovernanceNativeIdentitySource::revalidate($get('actorSnapshot'),$conn);
  }
  public static function projectApprovalAudit(PhorgeProjectNativeExecutionScope $scope,$id){
    if(!self::enabled()){return;}
    if(get_class($scope)!==PhorgeProjectNativeExecutionScope::class||!in_array($scope->getMode(),array('propose','approve','execute'),true)){
      throw new RuntimeException('governance_notice_scope_required');}
    $scope->assertOpen();$conn=(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'connection'))->getValue($scope);
    $row=queryfx_one($conn,'SELECT id,operationID,actorPHID,action,createdAt FROM governance_executionaudit WHERE id=%s LOCK IN SHARE MODE',(string)$id);
    if(!$row||$row['actorPHID']!==$scope->getSnapshot()->getUserPHID()){
      throw new RuntimeException('governance_notice_source_refused');}
    self::append($conn,'project',array('source_kind'=>'project-approval','source_id'=>(string)$row['id'],
      'operation_id'=>$row['operationID'],'actor_phid'=>$row['actorPHID'],'action'=>$row['action'],'created_at'=>(int)$row['createdAt']));
    $scope->assertOpen();
  }
  private static function nativeAdminGrantTables(AphrontDatabaseConnection $conn){
    foreach(array(new PhabricatorUser(),new PhabricatorUserTransaction())as$dao){
      if($dao->establishConnection('w')!==$conn){throw new RuntimeException('notice_native_admin_same_writer_required');}
      queryfx_one($conn,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$dao);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
        $dao->getAphrontRefDatabaseName(),$dao->getTableName());
      if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('notice_native_admin_innodb_required');}
    }
  }
  public static function preflightNativeAdminGrant(PhabricatorUserTransactionEditor $editor,
    PhabricatorLiskDAO $object,PhabricatorApplicationTransaction $xaction){
    if(!self::enabled()){return;}
    $editor->assertNoticeAdminGrantActive($object,$xaction,'preflight');
    if(get_class($object)!==PhabricatorUser::class){throw new RuntimeException('notice_native_admin_owner_refused');}
    $conn=$object->establishConnection('w');self::profile($conn,'user');self::nativeAdminGrantTables($conn);
  }
  public static function nativeAdminGrant(PhabricatorUserTransactionEditor $editor,
    PhabricatorLiskDAO $object,PhabricatorApplicationTransaction $xaction){
    if(!self::enabled()){return;}
    $editor->assertNoticeAdminGrantActive($object,$xaction);
    $actor=$editor->getActor();
    if(get_class($editor)!==PhabricatorUserTransactionEditor::class||get_class($object)!==PhabricatorUser::class||
      get_class($xaction)!==PhabricatorUserTransaction::class||!($actor instanceof PhabricatorUser)||
      $actor->isOmnipotent()||!$actor->getPHID()||!$actor->getID()||!$xaction->getPHID()){
      throw new RuntimeException('notice_native_admin_owner_refused');}
    $conn=$object->establishConnection('w');self::profile($conn,'user');self::nativeAdminGrantTables($conn);
    if((new PhabricatorUserTransaction())->establishConnection('w')!==$conn){
      throw new RuntimeException('notice_native_admin_same_writer_required');}
    $row=queryfx_one($conn,'SELECT id,phid,objectPHID,authorPHID,transactionType,dateCreated,'.
      'CASE WHEN OCTET_LENGTH(oldValue)<=5 THEN oldValue ELSE NULL END oldValue,'.
      'CASE WHEN OCTET_LENGTH(newValue)<=5 THEN newValue ELSE NULL END newValue '.
      'FROM %R WHERE id=%d AND BINARY phid=BINARY %s LOCK IN SHARE MODE',new PhabricatorUserTransaction(),$xaction->getID(),$xaction->getPHID());
    if(!$row||$row['objectPHID']!==$object->getPHID()||$row['authorPHID']!==$actor->getPHID()||
      (string)$row['id']!==(string)$xaction->getID()){
      throw new RuntimeException('notice_native_admin_source_refused');}
    $subject=queryfx_one($conn,'SELECT id,phid,isAdmin FROM %R FORCE INDEX (PRIMARY) WHERE id=%d LOCK IN SHARE MODE',
      new PhabricatorUser(),$object->getID());
    if(!$subject||(string)$subject['id']!==(string)$object->getID()||$subject['phid']!==$object->getPHID()||
      !in_array($subject['isAdmin'],array(1,'1'),true)){throw new RuntimeException('notice_native_admin_effect_refused');}
    $fresh=queryfx_one($conn,'SELECT phid,isAdmin,isDisabled FROM %R '.
      'WHERE id=%d AND BINARY phid=BINARY %s LOCK IN SHARE MODE',new PhabricatorUser(),$actor->getID(),$actor->getPHID());
    if(!$fresh||$fresh['phid']!==$actor->getPHID()) {throw new RuntimeException('notice_native_admin_actor_refused');}
    foreach(array('isAdmin'=>1,'isDisabled'=>0)as$key=>$value){
      if(!in_array($fresh[$key],array($value,(string)$value),true)){throw new RuntimeException('notice_native_admin_actor_refused');}}
    self::append($conn,'user',PhorgeGovernanceNoticeSource::nativeAdminGrantAudit($row));
  }
  public static function recoveryStoppedAudit(AphrontDatabaseConnection $conn,PhorgeGovernanceNativeIdentitySnapshot $snapshot,$nativeID){
    if(!self::enabled()){return;}
    if(!$conn->isInsideTransaction()){throw new RuntimeException('governance_notice_owner_ended');}
    $actor=PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn);
    if(!$actor->isSystemAdmin()){throw new RuntimeException('governance_notice_actor_refused');}
    $row=queryfx_one($conn,'SELECT nativeTransactionID,operationID,actorPHID,createdAt FROM recovery_stop_audit '.
      'WHERE nativeTransactionID=%s LOCK IN SHARE MODE',(string)$nativeID);
    if(!$row||$row['actorPHID']!==$actor->getUserPHID()){throw new RuntimeException('governance_notice_source_refused');}
    self::append($conn,'user',array('source_kind'=>'recovery-stop','source_id'=>(string)$row['nativeTransactionID'],
      'operation_id'=>$row['operationID'],'actor_phid'=>$row['actorPHID'],'action'=>'classified-stopped','created_at'=>(int)$row['createdAt']));
    PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn);
  }
}
