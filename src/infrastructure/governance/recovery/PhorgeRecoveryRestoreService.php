<?php

/** Durable isolated review. Acknowledgement never substitutes for complete sources. */
final class PhorgeRecoveryRestoreService extends Phobject {
  const MAX_OPERATION_SECONDS=10;
  private static function schema(AphrontDatabaseConnection $conn,$table) {
    $shared=array('requestID'=>'varbinary(64)','operationID'=>'varbinary(128)',
      'actorPHID'=>'varbinary(64)','action'=>'varbinary(32)','createdAt'=>'bigint unsigned');
    $profiles=array(
      'recovery_restore_request'=>array('requestID'=>'varbinary(64)','operationID'=>'varbinary(128)',
        'subjectPHID'=>'varbinary(64)','nativeTransactionID'=>'bigint unsigned',
        'requesterPHID'=>'varbinary(64)','requesterBindingJSON'=>'longtext',
        'approverPHID'=>'varbinary(64)','approverBindingJSON'=>'longtext','reviewState'=>'varbinary(32)',
        'permitState'=>'varbinary(32)','requestDigest'=>'varbinary(64)','bindingDigest'=>'varbinary(64)',
        'bindingJSON'=>'longtext','createdAt'=>'bigint unsigned','expiresAt'=>'bigint unsigned',
        'reviewedAt'=>'bigint unsigned','version'=>'bigint unsigned'),
      'recovery_restore_operation'=>$shared+array('requestDigest'=>'varbinary(64)','resultJSON'=>'longtext'),
      'recovery_restore_audit'=>$shared+array('id'=>'bigint unsigned','bindingDigest'=>'varbinary(64)'));
    if (!isset($profiles[$table])) {throw new RuntimeException('recovery_restore_schema_unknown');}
    $columns=queryfx_all($conn,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA '.
      'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
    $actual=array();
    foreach($columns as$column){
      $name=$column['COLUMN_NAME'];
      $type=preg_replace('/\Abigint\([0-9]+\)/','bigint',$column['COLUMN_TYPE']);
      $actual[$name]=$type;
      $nullable=$table==='recovery_restore_request'&&in_array($name,array('approverPHID','approverBindingJSON','reviewedAt'),true);
      if($column['IS_NULLABLE']!==($nullable?'YES':'NO')||
        $column['EXTRA']!==($table==='recovery_restore_audit'&&$name==='id'?'auto_increment':'')){
        throw new RuntimeException('recovery_restore_schema_unknown');}
    }
    $expected=$profiles[$table];ksort($expected,SORT_STRING);ksort($actual,SORT_STRING);
    if($actual!==$expected){throw new RuntimeException('recovery_restore_schema_unknown');}
    $keys=queryfx_all($conn,'SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS '.
      'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX',$table);
    $unique=array();foreach($keys as$key){$unique[$key['INDEX_NAME']][]=$key['COLUMN_NAME'];}
    $expected_keys=$table==='recovery_restore_request'
      ?array('PRIMARY'=>array('requestID'),'key_operation'=>array('operationID'))
      :($table==='recovery_restore_operation'?array('PRIMARY'=>array('operationID'))
        :array('PRIMARY'=>array('id'),'key_operation'=>array('operationID')));
    ksort($unique,SORT_STRING);ksort($expected_keys,SORT_STRING);
    if($unique!==$expected_keys){throw new RuntimeException('recovery_restore_schema_unknown');}
  }
  private static function sourceNow(AphrontDatabaseConnection $conn) {
    $row=queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n');
    if (!$row || !preg_match('/\A[0-9]{1,12}\z/D',(string)$row['n'])) {
      throw new RuntimeException('recovery_restore_clock_unknown');
    }
    return (int)$row['n'];
  }

  private static function planning($id, $operation=false) {
    $conn=(new PhabricatorUser())->establishConnection('w');
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){PhorgeRecoveryConduitProducerFence::assertWriterAvailable($conn);}
    $row=$operation
      ? queryfx_one($conn,'SELECT r.subjectPHID,r.requesterPHID,r.approverPHID '.
        'FROM recovery_restore_operation o JOIN recovery_restore_request r ON r.requestID=o.requestID '.
        'WHERE o.operationID=%s',$id)
      : queryfx_one($conn,'SELECT subjectPHID,requesterPHID,approverPHID '.
        'FROM recovery_restore_request WHERE requestID=%s',$id);
    $ids=array();
    foreach ($row?:array() as $value) {
      if (is_string($value) && preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$value)) { $ids[]=$value; }
    }
    // Planning is not returned to the caller or treated as authorization.
    return $ids;
  }

  // No production observer adoption/factory exists. Isolated support proofs
  // may exercise this private seam after provisioning and proving ownership.
  // No public configuration, descriptor, boolean or fixture API installs it.
  private static $borrowedObserver;
  private static $borrowedOwner;

  public static function assertBorrowedOwner($token,$writer,$observer) {
    $owner=self::$borrowedOwner;
    if(!is_object($token)||!is_array($owner)||$owner['token']!==$token||
      $owner['writer']!==$writer||$owner['observer']!==$observer||
      self::$borrowedObserver!==$observer){
      throw new RuntimeException('recovery_borrowed_owner_unknown');}
  }
  public static function registerBorrowedParticipant($token,PhorgeRecoverySessionProducerFence $participant) {
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['token']!==$token||$owner['participant']!==null){
      throw new RuntimeException('recovery_borrowed_registration_refused');}
    self::$borrowedOwner['participant']=$participant;
  }
  public static function captureBorrowedSessionFacts(AphrontDatabaseConnection $writer,$subject) {
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['writer']!==$writer||$owner['participant']===null){
      throw new RuntimeException('recovery_borrowed_production_provider_unavailable');}
    return$owner['participant']->borrowedCapture($subject);
  }
  /** A same-process client write must not wait on its own borrowed source locks. */
  public static function assertOAuthLifecycleWriteAvailable() {
    if(self::$borrowedOwner!==null){throw new RuntimeException('recovery_oauth_lifecycle_borrowed_owner_reserved');}
  }
  public static function assertConduitProducerOwnerAvailable($writer) {
    if(self::$borrowedOwner!==null&&self::$borrowedOwner['writer']===$writer){
      throw new RuntimeException('recovery_conduit_borrowed_owner_reserved');}
  }
  public static function captureBorrowedConduitFacts(AphrontDatabaseConnection $writer,$subject) {
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['writer']!==$writer||$owner['participant']===null){
      throw new RuntimeException('recovery_borrowed_production_provider_unavailable');}
    return$owner['participant']->borrowedCaptureConduit($subject);
  }
  public static function captureBorrowedOAuthAccessFacts(AphrontDatabaseConnection $writer,$subject) {
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['writer']!==$writer||$owner['participant']===null){
      throw new RuntimeException('recovery_borrowed_production_provider_unavailable');}
    return$owner['participant']->borrowedCaptureOAuthAccess($subject);
  }
  public static function captureBorrowedOAuthCodeFacts(AphrontDatabaseConnection $writer,$subject) {
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['writer']!==$writer||$owner['participant']===null){
      throw new RuntimeException('recovery_borrowed_production_provider_unavailable');}
    return$owner['participant']->borrowedCaptureOAuthCode($subject);
  }
  /** Actual assessment consumer of the three selected native participants.
   * No observer is created/adopted here. Missing production provenance stays
   * explicit; an existing invalid/quarantined owner never downgrades to absent.
   */
  public static function captureBorrowedOAuthAuthorizationFacts(AphrontDatabaseConnection $writer,$subject) {
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['writer']!==$writer||$owner['observer']!==self::$borrowedObserver){
      throw new RuntimeException('recovery_assessment_credential_owner_unknown');}
    self::assertBorrowedOwner($owner['token'],$writer,$owner['observer']);
    return$owner['participant']->borrowedCaptureOAuthAuthorization($subject);
  }
  public static function captureAssessmentCredentialSources(AphrontDatabaseConnection $writer,$subject) {
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($writer);
    $subject=PhorgeRecoveryStopRequest::user($subject);
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){
      PhorgeRecoveryConduitProducerFence::assertWriterAvailable($writer);}
    $owner=self::$borrowedOwner;$available=false;$facts=array();
    if($owner!==null||self::$borrowedObserver!==null){
      if(!is_array($owner)||$owner['writer']!==$writer||$owner['observer']!==self::$borrowedObserver||
        !is_object($owner['participant']??null)||
        get_class($owner['participant'])!==PhorgeRecoverySessionProducerFence::class){
        throw new RuntimeException('recovery_assessment_credential_owner_unknown');}
      self::assertBorrowedOwner($owner['token'],$writer,$owner['observer']);
      // Client locks precede every OAuth credential record SHARE lock.
      $owner['participant']->borrowedPrepareOAuthClients($subject);
      // These are real source reads under this Store owner, not incoming hashes.
      $facts=array('session'=>self::captureBorrowedSessionFacts($writer,$subject),
        'conduit'=>self::captureBorrowedConduitFacts($writer,$subject),
        'oauth-access'=>self::captureBorrowedOAuthAccessFacts($writer,$subject),
        'oauth-code'=>self::captureBorrowedOAuthCodeFacts($writer,$subject),
        'oauth-authorization'=>self::captureBorrowedOAuthAuthorizationFacts($writer,$subject));
      foreach($facts as$row){
        if(!is_array($row)||($row['schema']??null)!==1||
          ($row['mode']??null)!=='borrowed-current-User-writer-RC-observation'||
          ($row['all_sources_complete']??null)!==false||($row['restoration_authorized']??null)!==false||
          ($row['execution_blocked']??null)!==true||!is_string($row['source_binding']??null)||
          !preg_match('/\A[a-f0-9]{64}\z/D',$row['source_binding'])){
          throw new RuntimeException('recovery_assessment_credential_facts_unknown');}}
      // Revalidate every actual captured source and the private owner identity.
      $owner['participant']->borrowedValidateBeforeOwnerEnd();
      self::assertBorrowedOwner($owner['token'],$writer,$owner['observer']);$available=true;
    }
    $profiles=array('session'=>array('web-credentials','native-authsession-rows-v1'),
      'conduit'=>array('api-credentials','native-conduit-token-rows-v1'),
      'oauth-access'=>array('api-credentials','native-oauth-access-token-rows-v1'),
      'oauth-code'=>array('api-credentials','native-oauth-authorization-code-rows-v1'),
      'oauth-authorization'=>array('api-credentials','native-oauth-client-authorization-rows-v1'));
    $sources=array();foreach($profiles as$name=>$profile){$sources[$name]=array(
      'provider_schema'=>1,'family'=>$profile[0],'scope'=>$profile[1],
      'source_digest'=>$available?$facts[$name]['source_binding']:null,'complete'=>false);}
    $readset=array('provider_schema'=>3,'scope'=>'selected-native-credential-participants-v3',
      'mode'=>'borrowed-current-User-writer-RC-observation',
      'availability'=>$available?'observed-partial':'production-observer-unavailable','sources'=>$sources,
      'production_observer_factory_available'=>false,'complete'=>false,'all_sources_complete'=>false,
      'restoration_authorized'=>false,'execution_blocked'=>true);
    PhorgeRecoveryRestoreRequest::credentialReadSet($readset);return$readset;
  }
  private static function prepareBorrowedOwner(AphrontDatabaseConnection $writer) {
    if(self::$borrowedObserver===null){return;}
    if(self::$borrowedOwner!==null){throw new RuntimeException('recovery_borrowed_owner_reentry');}
    $GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;
    $token=new stdClass();self::$borrowedOwner=array('token'=>$token,'writer'=>$writer,
      'observer'=>self::$borrowedObserver,'participant'=>null);
  }
  private static function beginBorrowedOwner(AphrontDatabaseConnection $writer) {
    if(self::$borrowedObserver===null){return;}
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['writer']!==$writer||$owner['observer']!==self::$borrowedObserver||$owner['participant']!==null){
      throw new RuntimeException('recovery_borrowed_owner_unknown');}
    PhorgeRecoverySessionProducerFence::beginBorrowed($owner['token'],$writer,self::$borrowedObserver);
  }
  private static function finishBorrowedOwner() {
    if(self::$borrowedObserver===null){return;}
    $owner=self::$borrowedOwner;
    if(!is_array($owner)||$owner['participant']===null){
      throw new RuntimeException('recovery_borrowed_terminal_unknown');}
    $facts=$owner['participant']->borrowedObserveTerminal();
    if(($facts['cleanup_proved']??null)!==true){throw new RuntimeException('recovery_borrowed_terminal_unknown');}
    $GLOBALS['governance_recovery_borrowed_cleanup_proved']=true;
    $GLOBALS['governance_recovery_borrowed_terminal']=$facts;
    self::$borrowedOwner=null;
  }

  private static function withTransaction(PhorgeGovernanceNativeActorContext $context,
    array $subjects, callable $work) {
    if(self::$borrowedOwner!==null){throw new RuntimeException('recovery_borrowed_owner_quarantined');}
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    $user=new PhabricatorUser(); $conn=$user->establishConnection('w');
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){PhorgeRecoveryConduitProducerFence::assertWriterAvailable($conn);}
    if (get_class($conn)!==AphrontMySQLiDatabaseConnection::class || $conn->isInsideTransaction() ||
        $conn->isReadLocking() || $conn->isWriteLocking()) {
      throw new RuntimeException('recovery_restore_outer_transaction_refused');
    }
    $level=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation level');
    $levels=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if (!$level || !isset($levels[$level['level']])) { throw new RuntimeException('recovery_restore_isolation_unknown'); }
    $owned=false; $queued=false; $primary=null;
    if(self::$borrowedObserver!==null){$GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;unset($GLOBALS['governance_recovery_borrowed_terminal']);}
    try {
      queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); $queued=true;
      // Quarantine the exact owner before BEGIN can lose its acknowledgement.
      self::prepareBorrowedOwner($conn);
      $conn->openTransaction(); $owned=true; $queued=false;
      $started=self::sourceNow($conn);
      self::beginBorrowedOwner($conn);
      PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
      $subjects[]=$context->getUserPHID(); $subjects=array_values(array_unique($subjects));
      sort($subjects,SORT_STRING);
      foreach ($subjects as $subject) {
        PhorgeRecoveryStopRequest::user($subject);
        $row=queryfx_one($conn,'SELECT id,phid FROM %R WHERE phid=%s FOR UPDATE',$user,$subject);
        if (!$row || $row['phid']!==$subject) { throw new RuntimeException('recovery_restore_identity_unknown'); }
      }
      $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      if (!$actor->isSystemAdmin()) { throw new RuntimeException('recovery_restore_admin_required'); }
      foreach (array('recovery_restore_request','recovery_restore_operation','recovery_restore_audit') as $table) {
        queryfx_one($conn,'SELECT requestID FROM %T WHERE 1=0 LOCK IN SHARE MODE',$table);
        $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES '.
          'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
        if (!$engine || $engine['ENGINE']!=='InnoDB') { throw new RuntimeException('recovery_restore_schema_unknown'); }
        self::schema($conn,$table);
      }
      $plan=$work($conn,$actor,$subjects);
      if (isset($plan['sources'])) {
        $fresh=PhorgeRecoveryRestoreAssessment::capture($context,$conn,$plan['subject'],$plan['stop']);
        if (!hash_equals($plan['sources'],$fresh->getDigest())) { throw new RuntimeException('recovery_restore_sources_stale'); }
      }
      $snapshots=array(PhorgeGovernanceNativeIdentitySource::revalidate($actor,$conn));
      foreach ($plan['bindings'] as $binding) {
        $snapshot=PhorgeGovernanceNativeIdentitySource::lockCredentialBinding($binding,$conn);
        if (!$snapshot->isSystemAdmin() ||
            $snapshot->getSource()->getSnapshotData()!==$binding) {
          throw new RuntimeException('recovery_restore_admin_binding_changed');
        }
        $snapshots[]=$snapshot;
      }
      if (isset($plan['request'])) {
        list($final_row,$final_binding)=self::stored($conn,$plan['request'],$subjects);
        if (!hash_equals($plan['request_row_digest'],PhorgeRecoveryRestoreRequest::digest($final_row)) ||
            !hash_equals($plan['request_binding_digest'],PhorgeRecoveryRestoreRequest::digest($final_binding))) {
          throw new RuntimeException('recovery_restore_postcondition_failed');
        }
      }
      $participant=self::$borrowedOwner['participant']??null;
      if($participant!==null){$participant->borrowedValidateBeforeOwnerEnd();}
      if (PhorgeRecoveryRestoreProfile::assertCurrent()!==$profile) {
        throw new RuntimeException('recovery_restore_profile_changed');
      }
      $now=self::sourceNow($conn);
      foreach ($snapshots as $snapshot) {
        if (!$snapshot->isSystemAdmin()) { throw new RuntimeException('recovery_restore_admin_required'); }
        if ($snapshot->getExpiresAt()!==null && $now>=$snapshot->getExpiresAt()) {
          throw new RuntimeException('governance_native_credential_ineligible');
        }
      }
      if ($plan['deadline']!==null && $now>=$plan['deadline']) { throw new RuntimeException('recovery_restore_expired'); }
      if($now<$started||$now-$started>self::MAX_OPERATION_SECONDS){
        throw new RuntimeException('recovery_restore_operation_deadline');}
      $conn->saveTransaction(); $owned=false;
      return $plan['result'];
    } catch (Throwable $error) {
      $primary=$error;$errors=array('primary'=>$error);
      try{if ($owned && $conn->isInsideTransaction()) { $conn->killTransaction(); }}
      catch(Throwable $cleanup){$errors['owner-rollback']=$cleanup;}
      if(count($errors)>1){$primary=new PhutilAggregateException('recovery_restore_owner_cleanup_failed',$errors);}
      throw$primary;
    } finally {
      $errors=$primary===null?array():array('primary'=>$primary);
      try{if ($queued) { queryfx($conn,$levels[$level['level']]); }}catch(Throwable $cleanup){$errors['isolation']=$cleanup;}
      try{self::finishBorrowedOwner();}catch(Throwable $cleanup){$errors['borrowed-terminal']=$cleanup;}
      if(count($errors)>($primary===null?0:1)){
        throw new PhutilAggregateException('recovery_restore_participant_cleanup_failed',$errors);}
    }
  }

  private static function stored(AphrontDatabaseConnection $conn, $id, array $planned) {
    $row=queryfx_one($conn,'SELECT requestID,operationID,subjectPHID,nativeTransactionID,requesterPHID,'.
      'approverPHID,reviewState,permitState,requestDigest,bindingDigest,createdAt,expiresAt,reviewedAt,version,'.
      'CASE WHEN OCTET_LENGTH(bindingJSON)<=65536 THEN bindingJSON ELSE NULL END bindingJSON,'.
      'CASE WHEN OCTET_LENGTH(requesterBindingJSON)<=8192 THEN requesterBindingJSON ELSE NULL END requesterBindingJSON,'.
      'CASE WHEN OCTET_LENGTH(approverBindingJSON)<=8192 THEN approverBindingJSON ELSE NULL END approverBindingJSON '.
      'FROM recovery_restore_request WHERE requestID=%s FOR UPDATE',$id);
    if (!$row) { throw new RuntimeException('recovery_restore_request_unavailable'); }
    if ($row['requestID']!==$id || !is_string($row['bindingJSON']) ||
        !is_string($row['requesterBindingJSON']) || $row['permitState']!=='blocked' ||
        !in_array($row['reviewState'],array('submitted-blocked','reviewed-blocked'),true)) {
      throw new RuntimeException('recovery_restore_storage_invalid');
    }
    foreach (array($row['subjectPHID'],$row['requesterPHID'],$row['approverPHID']) as $subject) {
      if ($subject!==null && !in_array($subject,$planned,true)) {
        throw new RuntimeException('recovery_restore_identity_plan_changed');
      }
    }
    $binding=json_decode($row['bindingJSON'],true,16,JSON_THROW_ON_ERROR);
    $requester=json_decode($row['requesterBindingJSON'],true,8,JSON_THROW_ON_ERROR);
    if (!is_array($binding) || !is_array($binding['input']??null)) {
      throw new RuntimeException('recovery_restore_storage_invalid');
    }
    PhorgeRecoveryRestoreRequest::storedInput($binding['input']);
    if(!is_array($binding['sources']??null)){
      throw new RuntimeException('recovery_restore_storage_invalid');}
    PhorgeRecoveryRestoreRequest::sourceVersion($binding['sources']);
    // Version1 remains stored evidence; it is never reinterpreted as the new
    // external-incarnation/producer profile. Exact current assessment matching
    // makes its review/execute path stale, while metadata history stays usable.
    $binding_keys=array_keys($binding);sort($binding_keys,SORT_STRING);
    $expected_keys=array('expiresAt','input','issuedAt','requester','schema_version','sources');
    if($row['reviewState']==='reviewed-blocked'){$expected_keys[]='approver';sort($expected_keys,SORT_STRING);}
    if($binding_keys!==$expected_keys){throw new RuntimeException('recovery_restore_storage_invalid');}
    if (!is_array($binding) || !is_array($requester) || ($binding['schema_version']??null)!==1 ||
        ($binding['requester']??null)!==$requester || ($requester['userPHID']??null)!==$row['requesterPHID'] ||
        !is_string($row['bindingDigest']) ||
        !hash_equals($row['bindingDigest'],PhorgeRecoveryRestoreRequest::digest($binding)) ||
        ($binding['input']['subject']??null)!==$row['subjectPHID'] ||
        ($binding['input']['native_stop_transaction']??null)!==(string)$row['nativeTransactionID'] ||
        ($binding['input']['operation_id']??null)!==$row['operationID'] ||
        !is_int($binding['issuedAt']??null) || !is_int($binding['expiresAt']??null) ||
        (string)$binding['issuedAt']!==(string)$row['createdAt'] ||
        (string)$binding['expiresAt']!==(string)$row['expiresAt'] ||
        $binding['expiresAt']<=$binding['issuedAt'] ||
        $binding['expiresAt']-$binding['issuedAt']!==$binding['input']['ttl_seconds'] ||
        !hash_equals($row['requestDigest'],PhorgeRecoveryRestoreRequest::digest($binding['input']))) {
      throw new RuntimeException('recovery_restore_storage_invalid');
    }
    $version=PhorgeRecoveryStopRequest::transactionID($row['version']);
    if ($version==='18446744073709551615') { throw new RuntimeException('recovery_restore_version_exhausted'); }
    if (self::sourceNow($conn)>=$binding['expiresAt']) { throw new RuntimeException('recovery_restore_expired'); }
    if ($row['reviewState']==='reviewed-blocked') {
      if (!is_string($row['approverBindingJSON'])) { throw new RuntimeException('recovery_restore_storage_invalid'); }
      $approver=json_decode($row['approverBindingJSON'],true,8,JSON_THROW_ON_ERROR);
      if (!is_array($approver) || ($binding['approver']??null)!==$approver ||
          ($approver['userPHID']??null)!==$row['approverPHID'] ||
          (!is_string($row['reviewedAt'])&&!is_int($row['reviewedAt'])) ||
          !preg_match('/\A[0-9]{1,12}\z/D',(string)$row['reviewedAt']) ||
          (int)$row['reviewedAt']<$binding['issuedAt'] || (int)$row['reviewedAt']>=$binding['expiresAt'] ||
          $row['approverPHID']===$row['requesterPHID']) { throw new RuntimeException('recovery_restore_storage_invalid'); }
    } else if ($row['approverPHID']!==null || $row['approverBindingJSON']!==null ||
        $row['reviewedAt']!==null || array_key_exists('approver',$binding)) {
      throw new RuntimeException('recovery_restore_storage_invalid');
    }
    return array($row,$binding);
  }

  private static function receipt(array $row, $replayed) {
    return array('request_id'=>$row['requestID'],'version'=>(string)$row['version'],
      'review_state'=>$row['reviewState'],'permit_state'=>'blocked','replayed'=>$replayed,
      'restoration_authorized'=>false,'all_sources_complete'=>false);
  }

  private static function operation(AphrontDatabaseConnection $conn, $operation, $action, $actor, $digest) {
    $row=queryfx_one($conn,'SELECT requestID,actorPHID,action,requestDigest '.
      'FROM recovery_restore_operation WHERE operationID=%s FOR UPDATE',$operation);
    if ($row && ($row['actorPHID']!==$actor || $row['action']!==$action ||
        !hash_equals($row['requestDigest'],$digest))) { throw new RuntimeException('recovery_restore_operation_conflict'); }
    return $row;
  }

  private static function append(AphrontDatabaseConnection $conn, $operation, $action,
    PhorgeGovernanceNativeIdentitySnapshot $actor, array $row, $digest, array $result) {
    queryfx($conn,'INSERT INTO recovery_restore_operation '.
      '(operationID,requestID,action,actorPHID,requestDigest,resultJSON,createdAt) VALUES (%s,%s,%s,%s,%s,%s,%d)',
      $operation,$row['requestID'],$action,$actor->getUserPHID(),$digest,json_encode($result,JSON_THROW_ON_ERROR),self::sourceNow($conn));
    queryfx($conn,'INSERT INTO recovery_restore_audit '.
      '(requestID,operationID,actorPHID,action,bindingDigest,createdAt) VALUES (%s,%s,%s,%s,%s,%d)',
      $row['requestID'],$operation,$actor->getUserPHID(),$action,$row['bindingDigest'],self::sourceNow($conn));
  }

  private static function plan(PhorgeRecoveryRestoreAssessment $assessment, array $row,
    array $binding, array $result) {
    $bindings=array($binding['requester']);
    if (isset($binding['approver'])) { $bindings[]=$binding['approver']; }
    return array('result'=>$result,'bindings'=>$bindings,'deadline'=>$binding['expiresAt'],
      'request'=>$row['requestID'],'request_row_digest'=>PhorgeRecoveryRestoreRequest::digest($row),
      'request_binding_digest'=>PhorgeRecoveryRestoreRequest::digest($binding),
      'sources'=>$assessment->getDigest(),'subject'=>$row['subjectPHID'],'stop'=>(string)$row['nativeTransactionID']);
  }

  public static function submit(PhorgeGovernanceNativeActorContext $context, array $input) {
    $request=PhorgeRecoveryRestoreRequest::normalize($input);
    PhorgeRecoveryRestoreProfile::assertCurrent();
    $subjects=array_merge(array($request['subject']),self::planning($request['operation_id'],true));
    return self::withTransaction($context,$subjects,static function($conn,$actor,$planned) use ($context,$request) {
      $assessment=PhorgeRecoveryRestoreAssessment::capture($context,$conn,$request['subject'],$request['native_stop_transaction']);
      $digest=PhorgeRecoveryRestoreRequest::digest(array('action'=>'submit','actor'=>$actor->getUserPHID(),'input'=>$request));
      $old=self::operation($conn,$request['operation_id'],'submit',$actor->getUserPHID(),$digest);
      if ($old) {
        list($row,$binding)=self::stored($conn,$old['requestID'],$planned);
        if ($binding['sources']!==$assessment->getBinding()) { throw new RuntimeException('recovery_restore_sources_stale'); }
        return self::plan($assessment,$row,$binding,self::receipt($row,true));
      }
      $id=bin2hex(random_bytes(32)); $now=self::sourceNow($conn);
      $binding=array('schema_version'=>1,'input'=>$request,'issuedAt'=>$now,
        'expiresAt'=>$now+$request['ttl_seconds'],'sources'=>$assessment->getBinding(),
        'requester'=>$actor->getSource()->getSnapshotData());
      $json=json_encode($binding,JSON_THROW_ON_ERROR);
      if (strlen($json)>65536) { throw new RuntimeException('recovery_restore_binding_budget'); }
      $hash=PhorgeRecoveryRestoreRequest::digest($binding);
      queryfx($conn,'INSERT INTO recovery_restore_request '.
        '(requestID,operationID,subjectPHID,nativeTransactionID,requesterPHID,requesterBindingJSON,'.
        'reviewState,permitState,requestDigest,bindingDigest,bindingJSON,createdAt,expiresAt,version) '.
        'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,1)',
        $id,$request['operation_id'],$request['subject'],$request['native_stop_transaction'],$actor->getUserPHID(),
        json_encode($binding['requester'],JSON_THROW_ON_ERROR),'submitted-blocked','blocked',
        PhorgeRecoveryRestoreRequest::digest($request),$hash,$json,$now,$binding['expiresAt']);
      list($row,$stored)=self::stored($conn,$id,$planned); $result=self::receipt($row,false);
      self::append($conn,$request['operation_id'],'submit',$actor,$row,$digest,$result);
      return self::plan($assessment,$row,$stored,$result);
    });
  }

  public static function review(PhorgeGovernanceNativeActorContext $context, $id, $version, $operation) {
    $id=PhorgeRecoveryRestoreRequest::identifier($id);
    $version=PhorgeRecoveryStopRequest::transactionID($version);
    $operation=PhorgeRecoveryRestoreRequest::operation($operation);
    PhorgeRecoveryRestoreProfile::assertCurrent(); $subjects=self::planning($id);
    return self::withTransaction($context,$subjects,static function($conn,$actor,$planned) use ($context,$id,$version,$operation) {
      list($row,$binding)=self::stored($conn,$id,$planned);
      if ($row['requesterPHID']===$actor->getUserPHID()) { throw new RuntimeException('recovery_restore_independent_admin_required'); }
      $assessment=PhorgeRecoveryRestoreAssessment::capture($context,$conn,$row['subjectPHID'],$row['nativeTransactionID']);
      if ($binding['sources']!==$assessment->getBinding()) { throw new RuntimeException('recovery_restore_sources_stale'); }
      $digest=PhorgeRecoveryRestoreRequest::digest(array('action'=>'review','actor'=>$actor->getUserPHID(),
        'request'=>$id,'version'=>$version));
      $old=self::operation($conn,$operation,'review',$actor->getUserPHID(),$digest);
      if ($old) {
        if ($old['requestID']!==$id) { throw new RuntimeException('recovery_restore_storage_invalid'); }
        return self::plan($assessment,$row,$binding,self::receipt($row,true));
      }
      if ((string)$row['version']!==$version || $row['reviewState']!=='submitted-blocked') {
        throw new RuntimeException('recovery_restore_version_stale');
      }
      $binding['approver']=$actor->getSource()->getSnapshotData();
      $hash=PhorgeRecoveryRestoreRequest::digest($binding);
      queryfx($conn,'UPDATE recovery_restore_request SET approverPHID=%s,approverBindingJSON=%s,'.
        'reviewState=%s,reviewedAt=%d,bindingJSON=%s,bindingDigest=%s,version=version+1 '.
        'WHERE requestID=%s AND version=%s AND reviewState=%s',
        $actor->getUserPHID(),json_encode($binding['approver'],JSON_THROW_ON_ERROR),'reviewed-blocked',self::sourceNow($conn),
        json_encode($binding,JSON_THROW_ON_ERROR),$hash,$id,$version,'submitted-blocked');
      if ($conn->getAffectedRows()!==1) { throw new RuntimeException('recovery_restore_cas_failed'); }
      list($updated,$stored)=self::stored($conn,$id,$planned); $result=self::receipt($updated,false);
      self::append($conn,$operation,'review',$actor,$updated,$digest,$result);
      return self::plan($assessment,$updated,$stored,$result);
    });
  }

  public static function execute(PhorgeGovernanceNativeActorContext $context, $id, $version) {
    $id=PhorgeRecoveryRestoreRequest::identifier($id); $version=PhorgeRecoveryStopRequest::transactionID($version);
    PhorgeRecoveryRestoreProfile::assertCurrent(); $subjects=self::planning($id);
    return self::withTransaction($context,$subjects,static function($conn,$actor,$planned) use ($context,$id,$version) {
      list($row,$binding)=self::stored($conn,$id,$planned);
      if ($row['requesterPHID']!==$actor->getUserPHID() || (string)$row['version']!==$version ||
          $row['reviewState']!=='reviewed-blocked') { throw new RuntimeException('recovery_restore_execution_binding_refused'); }
      foreach (array($binding['requester'],$binding['approver']) as $credential) {
        $current=PhorgeGovernanceNativeIdentitySource::lockCredentialBinding($credential,$conn);
        if (!$current->isSystemAdmin()) { throw new RuntimeException('recovery_restore_admin_required'); }
      }
      $assessment=PhorgeRecoveryRestoreAssessment::capture($context,$conn,$row['subjectPHID'],$row['nativeTransactionID']);
      if ($binding['sources']!==$assessment->getBinding()) { throw new RuntimeException('recovery_restore_sources_stale'); }
      // No native mutation or lease can occur while any real family is unknown.
      $assessment->requireCompleteForExecution();
      throw new RuntimeException('recovery_restore_native_transition_unimplemented');
    });
  }

  /** Live, source-bound review summary; never return credential bindings. */
  public static function preview(PhorgeGovernanceNativeActorContext $context,$id,$version) {
    $id=PhorgeRecoveryRestoreRequest::identifier($id);$version=PhorgeRecoveryStopRequest::transactionID($version);
    PhorgeRecoveryRestoreProfile::assertCurrent();$subjects=self::planning($id);
    $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      return self::withTransaction($context,$subjects,
        static function($conn,$actor,$planned)use($context,$id,$version){
          list($row,$binding)=self::stored($conn,$id,$planned);
          if((string)$row['version']!==$version){throw new RuntimeException('recovery_restore_version_stale');}
          $assessment=PhorgeRecoveryRestoreAssessment::capture($context,$conn,$row['subjectPHID'],$row['nativeTransactionID']);
          if($binding['sources']!==$assessment->getBinding()){throw new RuntimeException('recovery_restore_sources_stale');}
          $sources=$assessment->getBinding();$blockers=array();
          foreach(PhorgeGovernanceRecoverySourceInventory::requiredFamilies()as$family){
            if(($sources['families'][$family]['complete']??null)!==true){$blockers[]=$family;}
          }
          $result=array('request_id'=>$id,'version'=>$version,'category'=>$sources['category'],
            'review_state'=>$row['reviewState'],'permit_state'=>$row['permitState'],
            'expires_at'=>$binding['expiresAt'],'source_schema_version'=>$sources['schema_version'],
            'source_digest'=>$assessment->getDigest(),'families'=>$sources['families'],
            'incomplete_families'=>$blockers,'preview_is_authorization'=>false,
            'restoration_authorized'=>false);
          return self::plan($assessment,$row,$binding,$result);
        });
    }finally{unset($unguarded);}
  }

  /** Current-admin metadata only; old credentials and expired tickets are not grants. */
  public static function history(PhorgeGovernanceNativeActorContext $context,
    $subject, $after=null, $limit=25) {
    $subject=PhorgeRecoveryStopRequest::user($subject);
    if ($after!==null) { $after=PhorgeRecoveryRestoreRequest::identifier($after); }
    if (!is_int($limit) || $limit<1 || $limit>100) {
      throw new InvalidArgumentException('recovery_restore_page_invalid');
    }
    // Fixed read-only entry: Source may initialize its own issuer epoch and
    // native transaction bookkeeping still passes the request write guard.
    // Never expose this scope to submit/review/execute or arbitrary callbacks.
    $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      return self::withTransaction($context,array($subject),
        static function($conn,$actor,$planned) use ($subject,$after,$limit) {
          $predicate=$after===null
            ? qsprintf($conn,'subjectPHID=%s',$subject)
            : qsprintf($conn,'subjectPHID=%s AND requestID>%s',$subject,$after);
          $rows=queryfx_all($conn,'SELECT requestID,nativeTransactionID,reviewState,permitState,'.
            'createdAt,expiresAt,version FROM recovery_restore_request WHERE %Q '.
            'ORDER BY requestID LIMIT %d LOCK IN SHARE MODE',$predicate,$limit);
          foreach ($rows as $row) {
            PhorgeRecoveryRestoreRequest::identifier($row['requestID']);
            PhorgeRecoveryStopRequest::transactionID($row['nativeTransactionID']);
            PhorgeRecoveryStopRequest::transactionID($row['version']);
            if ($row['permitState']!=='blocked' ||
                !in_array($row['reviewState'],array('submitted-blocked','reviewed-blocked'),true)) {
              throw new RuntimeException('recovery_restore_storage_invalid');
            }
          }
          return array('result'=>array('rows'=>$rows,
            'next_cursor'=>count($rows)===$limit?last($rows)['requestID']:null,
            'restoration_authorized'=>false,'all_sources_complete'=>false),
            'bindings'=>array(),'deadline'=>null);
        });
    } finally { unset($unguarded); }
  }
}
