<?php

/**
 * Separate sealed Task grant-metadata writer. Never native content EDIT authority.
 * Only a specific native service call and sealed plan open this writer. A Store
 * completion proof is required for commit. Otherwise the owned scope rolls back.
 */
final class PhorgeTaskContentGrantWriteScope extends Phobject {
  private static $active = array();
  private static $quarantined = array();
  private static $cacheFrameOwner;
  private $transactionOwnership = 'none';
  private $ownedTransactionState;
  private $ownedGeneration;
  private $connection;
  private $context;
  private $snapshot;
  private $actor;
  private $object;
  private $configuration;
  private $plannedIdentities = array();
  private $row;
  private $sources;
  private $state = 'new';
  private $cache;
  private $cachePrior = array();
  private $cacheMissing;
  private $cleanupFailed = false;
  private $metadataPlan;
  private $requesterBinding;

  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('task_scope_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('task_scope_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'sealed-native-task-grant-metadata'); }

  public static function isSourceConnection(AphrontDatabaseConnection $conn) {
    $ref=self::$active[spl_object_id($conn)]??null;
    $scope=$ref?$ref->get():null;
    return $scope!==null && get_class($scope)===self::class &&
      $scope->connection===$conn &&
      in_array($scope->state,array('authorizing','open'),true) &&
      $scope->transactionOwnership==='owned' &&
      $scope->hasOwnedDepth();
  }

  public static function sourceDatabase(AphrontDatabaseConnection $conn,$table) {
    if (!self::isSourceConnection($conn) || !is_object($table) ||
        !in_array(get_class($table),array(
          ManiphestTask::class,ManiphestTransaction::class,PhabricatorUser::class,
          PhabricatorAuthSession::class,PhabricatorUserTransaction::class,
          PhorgeGovernanceIdentityEpoch::class,PhabricatorSpacesNamespace::class,
          PhabricatorConfigEntry::class,PhorgeProjectConfigEpoch::class,
          PhorgeOrganizationUnit::class,PhorgeOrganizationMember::class),true)) {
      throw new RuntimeException('task_source_scope_required');
    }
    if (in_array(get_class($table),array(PhorgeOrganizationUnit::class,PhorgeOrganizationMember::class),true)) {
      $ref=self::$active[spl_object_id($conn)]??null;$scope=$ref?$ref->get():null;
      if (!$scope || $scope->state!=='open' || $scope->snapshot===null ||
          PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==true) {
        throw new RuntimeException('task_team_source_scope_required');
      }
      PhorgeTaskContentGrantConfiguration::assertEnabled();
    }
    // A People/Project connection cannot be registered as a Task source route.
    $route=$table->establishConnection('w');
    if (get_class($route)!==AphrontMySQLiDatabaseConnection::class ||
        ($route!==$conn && $route->isInsideTransaction())) {
      throw new RuntimeException('task_source_route_transaction_refused');
    }
    $actual=queryfx_one($conn,'SELECT @@server_uuid uuid');
    $expected=queryfx_one($route,'SELECT @@server_uuid uuid,DATABASE() db');
    if (!$actual || !$expected || !is_string($actual['uuid']) ||
        $actual['uuid']!==$expected['uuid'] ||
        $table->getAphrontRefDatabaseName()!==$expected['db'] ||
        !is_string($expected['db']) ||
        !preg_match('/\A[a-zA-Z0-9_]+\z/D',$expected['db'])) {
      throw new RuntimeException('task_same_native_primary_required');
    }
    return $expected['db'];
  }

  public static function open(PhorgeGovernanceNativeActorContext $context,
    ManiphestTask $task,PhorgeTaskContentGrantPlan $plan) {
    self::requireServiceCaller();
    self::validateWriteProfile($context->getCredentialType(),PhabricatorEnv::getEnvConfig('security.require-multi-factor-auth'));
    PhorgeTaskContentGrantConfiguration::assertEnabled();
    if (!in_array($plan->getPurpose(),array('submit','approve'),true)) { throw new RuntimeException('task_grant_write_plan_refused'); }
    if (get_class($context)!==PhorgeGovernanceNativeActorContext::class ||
        get_class($task)!==ManiphestTask::class || !$task->getID() ||
        !is_string($task->getPHID()) ||
        !preg_match('/\APHID-TASK-[a-zA-Z0-9]{20}\z/D',$task->getPHID())) {
      throw new RuntimeException('task_source_target_required');
    }
    $ids=array($context->getUserPHID());
    if ($plan!==null) {
      if (get_class($plan)!==PhorgeTaskContentGrantPlan::class) {
        throw new RuntimeException('task_source_participant_plan_refused');
      }
      $plan->assertScope('task-content-grant',$task->getPHID());
      $ids=array_merge($ids,$plan->getIdentityPHIDs());
    }
    $conn=$task->establishConnection('w');
    self::assertAdmissionReservation($conn);
    if ($conn!==(new ManiphestTask())->establishConnection('w') ||
        get_class($conn)!==AphrontMySQLiDatabaseConnection::class ||
        $task->getAphrontRefDatabaseName()!==(new ManiphestTask())->getAphrontRefDatabaseName() ||
        $conn->isInsideTransaction() || $conn->isReadLocking() ||
        $conn->isWriteLocking() || isset(self::$active[spl_object_id($conn)]) ||
        PhorgeProjectNativeExecutionScope::isSourceConnection($conn)) {
      throw new RuntimeException('task_existing_scope_refused');
    }
    PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    $scope=new self();
    $scope->connection=$conn;
    $scope->context=$context;
    $scope->metadataPlan=$plan;
    // Supplied objects are locator hints only. Nothing in them grants VIEW.
    $scope->object=new ManiphestTask();
    try {
      $planning=queryfx_one($conn,
        'SELECT phid,viewPolicy,editPolicy FROM %R WHERE id=%d',
        new ManiphestTask(),$task->getID());
      if (!$planning || $planning['phid']!==$task->getPHID()) {
        throw new RuntimeException('task_source_target_missing');
      }
      $spaces=new PhabricatorSpacesNamespace();
      $route=$spaces->establishConnection('w');
      $space_plan=queryfx_all($route,
        'SELECT viewPolicy FROM %R ORDER BY id LIMIT 65',$spaces);
      if (count($space_plan)>64) { throw new RuntimeException('task_source_budget'); }
      $app=new PhabricatorManiphestApplication();
      $policies=array($planning['viewPolicy'],$planning['editPolicy'],
        $app->getPolicy(PhabricatorPolicyCapability::CAN_VIEW));
      foreach ($space_plan as $space) { $policies[]=$space['viewPolicy']; }
      foreach ($policies as $policy) {
        PhorgeProjectExecutionCatalog::policy($policy);
        if (str_starts_with($policy,'PHID-USER-')) { $ids[]=$policy; }
      }
      $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);
      if (count($ids)>70) { throw new RuntimeException('task_source_budget'); }
      foreach ($ids as $id) {
        if (!PhorgeGovernanceOrganizationRequest::userID($id)) {
          throw new RuntimeException('task_source_identity_plan_refused');
        }
      }
      $scope->plannedIdentities=$ids;
      queryfx($conn,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
      $scope->transactionOwnership='opening';
      $conn->openTransaction();
      $scope->ownedTransactionState=$scope->nativeTransactionState();
      $scope->ownedGeneration=$scope->ownedTransactionState->getGeneration();
      $scope->transactionOwnership='owned';
      if (!$scope->hasOwnedDepth()) { throw new RuntimeException('task_owned_transaction_depth_refused'); }
      $scope->state='authorizing';
      self::$active[spl_object_id($conn)]=WeakReference::create($scope);
      $issuer=null;
      foreach ($ids as $id) {
        $identity=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($id,$conn);
        if ($id===$context->getUserPHID()) { $issuer=$identity; }
      }
      self::requireExistingIssuerEpoch($issuer);
      $scope->snapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      if (!$scope->snapshot->isSystemAdmin()) { throw new RuntimeException('task_grant_admin_required'); }
      $scope->actor=$scope->freshUser($scope->snapshot);
      $scope->configuration=PhorgeTaskGrantExecutionConfigSource::capture($conn);
      if (get_class($scope->configuration)!==PhorgeTaskGrantExecutionConfigSource::class) {
        throw new RuntimeException('task_configuration_scope_refused');
      }
      PhorgeTaskContentGrantConfiguration::assertEnabled();
      $rows=$scope->boundedRows(new ManiphestTask(),
        qsprintf($conn,'id=%d',$task->getID()),1);
      $scope->row=$rows?head($rows):null;
      if (!$scope->row || $scope->row['phid']!==$task->getPHID() ||
          $scope->row['viewPolicy']!==$planning['viewPolicy'] ||
          $scope->row['editPolicy']!==$planning['editPolicy']) {
        throw new RuntimeException('task_source_plan_changed');
      }
      $scope->object->loadFromArray($scope->row);
      $scope->captureSpaces();
      $scope->requireNativeView($scope->actor);
      $generation=$scope->transactionGeneration();
      $scope->sources=array(
        'task'=>PhorgeProjectExecutionValue::digest($scope->row),
        'spaces'=>$scope->sources['spaces'],
        'configuration'=>$scope->configuration->getDigest(),
        'credential'=>$scope->snapshot->getBindingDigest(),
        'nativeTransactionID'=>$generation);
      $scope->state='open';
      return $scope;
    } catch (Throwable $error) {
      try { $scope->close(); }
      catch (Throwable $cleanup) { throw new RuntimeException('task_scope_cleanup_failed',0,$error); }
      throw $error;
    }
  }

  /** Value check only: the caller always supplies the real prelocked issuer row. */
  private static function requireExistingIssuerEpoch($identity) {
    if (!is_array($identity) || !is_string($identity['accountEpoch']??null) ||
        !preg_match('/\A[a-f0-9]{64}\z/D',$identity['accountEpoch'])) {
      throw new RuntimeException('task_grant_existing_issuer_epoch_required');
    }
  }

  private function transactionGeneration() {
    $tx=new ManiphestTransaction();
    $this->requireEngine($tx);
    $last=queryfx_one($this->connection,
      'SELECT id FROM %R FORCE INDEX (%C) WHERE objectPHID=%s AND BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
      $tx,'key_object',$this->object->getPHID(),$this->object->getPHID());
    $id=$last?(string)$last['id']:'0';
    if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$id)) {
      throw new RuntimeException('task_source_generation_unknown');
    }
    return $id;
  }

  private function requireEngine(PhabricatorLiskDAO $table) {
    $schema=self::sourceDatabase($this->connection,$table);
    $engine=queryfx_one($this->connection,
      'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
      $schema,$table->getTableName());
    if (!$engine || $engine['ENGINE']!=='InnoDB') {
      throw new RuntimeException('task_transactional_source_required');
    }
    return $schema;
  }

  private function boundedRows(PhabricatorLiskDAO $table,PhutilQueryString $where,$limit) {
    if (!in_array(get_class($table),array(ManiphestTask::class,PhabricatorUser::class,
        PhabricatorAuthSession::class,PhabricatorSpacesNamespace::class),true) ||
        !in_array($limit,array(1,64),true)) {
      throw new RuntimeException('task_source_row_profile_refused');
    }
    $columns=array_keys($table->getSchemaColumns());sort($columns,SORT_STRING);
    if (!$columns || count($columns)>128) { throw new RuntimeException('task_source_row_profile_refused'); }
    $terms=array(qsprintf($this->connection,'0'));
    foreach ($columns as $column) {
      $terms[]=qsprintf($this->connection,'+ COALESCE(OCTET_LENGTH(%C),0)',$column);
    }
    $sizes=queryfx_all($this->connection,
      'SELECT id,(%LJ) row_bytes FROM %R WHERE %Q ORDER BY id LIMIT %d LOCK IN SHARE MODE',
      $terms,$table,$where,$limit+1);
    $schema=$this->requireEngine($table);
    $actual=queryfx_all($this->connection,
      'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY COLUMN_NAME LIMIT 129',
      $schema,$table->getTableName());
    $actual=ipull($actual,'COLUMN_NAME');sort($actual,SORT_STRING);
    if ($actual!==$columns || count($sizes)>$limit) { throw new RuntimeException('task_source_row_profile_refused'); }
    $total=0;
    foreach ($sizes as $size) {
      if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$size['row_bytes']) ||
          (int)$size['row_bytes']>131072 || ($total+=(int)$size['row_bytes'])>524288) {
        throw new RuntimeException('task_source_budget');
      }
    }
    return queryfx_all($this->connection,
      'SELECT * FROM %R WHERE %Q ORDER BY id LIMIT %d LOCK IN SHARE MODE',
      $table,$where,$limit+1);
  }

  private function freshUser(PhorgeGovernanceNativeIdentitySnapshot $snapshot) {
    $rows=$this->boundedRows(new PhabricatorUser(),
      qsprintf($this->connection,'BINARY phid=BINARY %s',$snapshot->getUserPHID()),1);
    $row=$rows?head($rows):null;
    if (!$row || $row['phid']!==$snapshot->getUserPHID()) {
      throw new RuntimeException('task_source_identity_missing');
    }
    $user=(new PhabricatorUser())->loadFromArray($row);
    if ($snapshot->getCredentialType()==='web') {
      $sessions=$this->boundedRows(new PhabricatorAuthSession(),
        qsprintf($this->connection,'BINARY phid=BINARY %s',$snapshot->getSessionPHID()),1);
      $session=$sessions?head($sessions):null;
      if (!$session || $session['userPHID']!==$user->getPHID()) {
        throw new RuntimeException('task_source_identity_missing');
      }
      $user->attachSession((new PhabricatorAuthSession())->loadFromArray($session));
    }
    return $user;
  }

  private function captureSpaces() {
    $rows=$this->boundedRows(new PhabricatorSpacesNamespace(),
      qsprintf($this->connection,'id>=0'),64);
    $spaces=array();$default=null;
    foreach ($rows as $row) {
      $policy=PhorgeProjectExecutionCatalog::policy($row['viewPolicy']);
      if (str_starts_with($policy,'PHID-USER-') &&
          !in_array($policy,$this->plannedIdentities,true)) {
        throw new RuntimeException('task_source_identity_plan_changed');
      }
      $space=(new PhabricatorSpacesNamespace())->loadFromArray($row);
      $spaces[$row['phid']]=$space;
      if ($space->getIsDefaultNamespace()) {
        if ($default!==null) { throw new RuntimeException('task_source_space_profile_refused'); }
        $default=$space;
      }
    }
    $owner=self::$cacheFrameOwner?self::$cacheFrameOwner->get():null;
    if ($owner!==null && $owner!==$this) { throw new RuntimeException('task_cache_frame_busy'); }
    self::$cacheFrameOwner=WeakReference::create($this);
    $this->cache=PhabricatorCaches::getRequestCache();
    $this->cacheMissing=new stdClass();
    foreach (array(PhabricatorSpacesNamespaceQuery::KEY_ALL=>$spaces,
        PhabricatorSpacesNamespaceQuery::KEY_DEFAULT=>$default) as $key=>$value) {
      $this->cachePrior[$key]=$this->cache->getKey($key,$this->cacheMissing);
      $this->cache->setKey($key,$value);
    }
    $this->sources=array('spaces'=>PhorgeProjectExecutionValue::digest($rows));
  }

  private function requireNativeView(PhabricatorUser $user) {
    $this->configuration->requireApplicationView($user);
    $key=PhabricatorSpacesNamespaceQuery::KEY_VIEWER.'('.$user->getCacheFragment().')';
    if (!array_key_exists($key,$this->cachePrior)) {
      $this->cachePrior[$key]=$this->cache->getKey($key,$this->cacheMissing);
    }
    $this->cache->deleteKeys(array($key));
    if (!PhabricatorPolicyFilter::hasCapability($user,$this->object,
        PhabricatorPolicyCapability::CAN_VIEW)) {
      throw new RuntimeException('task_native_view_required');
    }
  }

  public function assertCurrent() {
    if ($this->connection===null || !self::isSourceConnection($this->connection) ||
        $this->state!=='open' || !isset(self::$active[spl_object_id($this->connection)]) ||
        self::$active[spl_object_id($this->connection)]->get()!==$this) { throw new RuntimeException('task_scope_ended'); }
    PhorgeTaskContentGrantConfiguration::assertEnabled();
    self::validateWriteProfile($this->snapshot->getCredentialType(),PhabricatorEnv::getEnvConfig('security.require-multi-factor-auth'));
    $this->configuration->assertCurrent();
    PhorgeGovernanceNativeIdentitySource::revalidate($this->snapshot,$this->connection);
    if (!$this->snapshot->isSystemAdmin()) { throw new RuntimeException('task_grant_admin_required'); }
    $this->actor=$this->freshUser($this->snapshot);
    $rows=$this->boundedRows(new ManiphestTask(),
      qsprintf($this->connection,'id=%d',$this->row['id']),1);
    if ($rows!==array($this->row)) { throw new RuntimeException('task_source_changed'); }
    $spaces=$this->boundedRows(new PhabricatorSpacesNamespace(),
      qsprintf($this->connection,'id>=0'),64);
    if (!hash_equals($this->sources['spaces'],PhorgeProjectExecutionValue::digest($spaces))) {
      throw new RuntimeException('task_source_spaces_changed');
    }
    if ($this->transactionGeneration()!==$this->sources['nativeTransactionID']) {
      throw new RuntimeException('task_source_generation_changed');
    }
    $this->requireNativeView($this->actor);
  }

  /** No raw connection or mutable authority actor/object escapes the scope. */
  public function captureSourceSummary() {
    $this->assertCurrent();
    return array('operation'=>PhorgeTaskContentGrantRequest::OPERATION,
      'targetPHID'=>$this->object->getPHID(),'sources'=>$this->sources,
      'current_source_verified'=>true,'runtime_qualified'=>false,
      'authority'=>false,'can_execute'=>false);
  }

  /** Only the credential holder's direct effective team relation is read. */
  public function captureDirectMembership($team) {
    $this->assertCurrent();
    if (!PhorgeGovernanceOrganizationRequest::identifier($team,64) ||
        PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==true) {
      throw new RuntimeException('task_team_source_unavailable');
    }
    $conn=$this->connection;
    $unit=new PhorgeOrganizationUnit();$member=new PhorgeOrganizationMember();
    $schema=$this->requireEngine($unit);
    if ($this->requireEngine($member)!==$schema) {
      throw new RuntimeException('task_team_same_primary_required');
    }
    queryfx_one($conn,'SELECT id FROM %T.%T WHERE 1=0 LOCK IN SHARE MODE',$schema,'organization_guard');
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,'organization_guard');
    $guard=queryfx_one($conn,'SELECT id,version FROM %T.%T WHERE id=1 LOCK IN SHARE MODE',$schema,'organization_guard');
    if (!$engine || $engine['ENGINE']!=='InnoDB' || !$guard ||
        !preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$guard['version'])) {
      throw new RuntimeException('task_team_generation_required');
    }
    $row=queryfx_one($conn,
      'SELECT unitID,kind,state,version,visibilityModel,visibilityVersion FROM %R WHERE unitID=%s LOCK IN SHARE MODE',
      $unit,$team);
    if (!$row || $row['unitID']!==$team || $row['kind']!=='team' ||
        $row['state']!=='active' || $row['visibilityModel']!=='restricted-admin-v1' ||
        (string)$row['visibilityVersion']!=='1' ||
        !preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$row['version'])) {
      throw new RuntimeException('task_team_source_refused');
    }
    $identity=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID(
      $this->snapshot->getUserPHID(),$conn);
    $membership=queryfx_one($conn,
      'SELECT unitID,userPHID,state,validFrom,validUntil,version FROM %R WHERE unitID=%s AND userPHID=%s LOCK IN SHARE MODE',
      $member,$team,$this->snapshot->getUserPHID());
    $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
    $eligible=$identity['eligible'] &&
      PhorgeTaskContentSource::decodeMembership($membership,$team,$identity['userPHID'],$now);
    return array('observed_direct_member'=>(bool)$eligible,
      'source_digest'=>PhorgeProjectExecutionValue::digest(array($row,$membership,$identity)),
      'runtime_qualified'=>false,'authority'=>false,'can_execute'=>false);
  }

  /** Pure profile validation is never an authentication or MFA receipt. */
  public static function validateWriteProfile($credentialType,$requireMFA) {
    if ($credentialType!=='api-token' || $requireMFA!==false) {
      throw new RuntimeException('task_grant_sensitive_transport_unavailable');
    }
  }
  private static function requireServiceCaller() {
    $frame=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,3)[2]??array();
    if (($frame['class']??null)!==PhorgeTaskContentGrantService::class ||
        ($frame['function']??null)!=='call') {
      throw new RuntimeException('task_grant_native_service_required');
    }
  }
  private static function requireStoreCaller() {
    $frame=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,3)[2]??array();
    if (!in_array($frame['class']??null,array(PhorgeTaskContentGrantStore::class,PhorgeTaskContentSource::class),true)) {
      throw new RuntimeException('task_grant_native_store_required');
    }
  }
  public function connectionForStore() {
    self::requireStoreCaller();$this->assertCurrent();return $this->connection;
  }
  public function getActorPHID() { $this->assertCurrent();return $this->snapshot->getUserPHID(); }
  public function subjectIdentity($id) {
    $this->assertCurrent();
    if (!in_array($id,$this->plannedIdentities,true)) { throw new RuntimeException('task_grant_identity_plan_required'); }
    return PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($id,$this->connection);
  }
  public function getMetadataPlan() { return $this->metadataPlan; }
  public function getTargetPHID() { return $this->object->getPHID(); }
  public function getCredentialBinding() {
    $this->assertCurrent();return $this->snapshot->getSource()->getSnapshotData();
  }
  public function getSharedApprovalSources() {
    $this->assertCurrent();return PhorgeTaskContentApprovalBinding::sharedSources($this->sources);
  }
  /** Stored requester credentials are separately current, never dropped from approval. */
  public function validateApprovalCredential(array $binding) {
    $this->assertCurrent();
    if ($this->metadataPlan->getPurpose()!=='approve' || !isset($binding['userPHID']) ||
        $binding['userPHID']!==$this->metadataPlan->getRequesterPHID() ||
        $binding['userPHID']===$this->snapshot->getUserPHID() ||
        !in_array($binding['userPHID'],$this->plannedIdentities,true)) {
      throw new RuntimeException('task_grant_identity_plan_changed');
    }
    $snapshot=PhorgeGovernanceNativeIdentitySource::lockCredentialBinding($binding,$this->connection);
    if (!$snapshot->isSystemAdmin()) { throw new RuntimeException('task_grant_admin_required'); }
    self::validateWriteProfile($snapshot->getCredentialType(),PhabricatorEnv::getEnvConfig('security.require-multi-factor-auth'));
    $user=$this->freshUser($snapshot);$this->requireNativeView($user);
    $this->requesterBinding=$snapshot->getSource()->getSnapshotData();return $snapshot;
  }
  /** No generic commit. Only native service plus a same-scope Store completion. */
  public function commitMetadata(PhorgeTaskContentGrantCommitProof $proof) {
    self::requireServiceCaller();$this->assertCurrent();$proof->assertForScope($this);
    $deadline=$proof->getValidBefore();
    if ($this->metadataPlan->getPurpose()==='approve') {
      if ($this->requesterBinding===null) { throw new RuntimeException('task_grant_requester_binding_required'); }
      $requester=$this->validateApprovalCredential($this->requesterBinding);
      if ($requester->getExpiresAt()!==null) { $deadline=min($deadline,$requester->getExpiresAt()); }
    }
    if ($this->snapshot->getExpiresAt()!==null) { $deadline=min($deadline,$this->snapshot->getExpiresAt()); }
    $now=(int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n'];
    if ($now>=$deadline) { throw new RuntimeException('task_grant_commit_deadline_expired'); }
    $this->finishOwnedTransaction();
  }
  /** Native stack identity/depth is not a physical server transaction lease. */
  private function nativeTransactionState() {
    $property=new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState');
    return $property->getValue($this->connection);
  }
  private function hasOwnedDepth() {
    $ref=self::$active[spl_object_id($this->connection)]??null;
    if ($this->state!=='new' && (!$ref || $ref->get()!==$this)) { return false; }
    $state=$this->nativeTransactionState();
    return $this->transactionOwnership==='owned' &&
      $state!==null && $state===$this->ownedTransactionState &&
      $state->getGeneration()===$this->ownedGeneration &&
      $state->getDepth()===1 && !$this->connection->isReadLocking() && !$this->connection->isWriteLocking();
  }
  private static function assertAdmissionReservation(AphrontDatabaseConnection $conn) {
    $owner=self::$cacheFrameOwner?self::$cacheFrameOwner->get():null;
    if ($owner!==null) { throw new RuntimeException('task_cache_frame_busy'); }
    $quarantine=self::$quarantined[spl_object_id($conn)]??null;
    if ($quarantine && $quarantine->get()===$conn) {
      throw new RuntimeException('task_connection_quarantined');
    }
    $ref=self::$active[spl_object_id($conn)]??null;
    if ($ref && $ref->get()!==null) {
      throw new RuntimeException('task_existing_scope_refused');
    }
  }
  private function releaseSourceReservation() {
    $key=spl_object_id($this->connection);
    $ref=self::$active[$key]??null;
    if ($ref && $ref->get()===$this) { unset(self::$active[$key]); }
  }
  private function quarantineTransaction() {
    $this->transactionOwnership='unknown';
    $this->cleanupFailed=true;
    $this->releaseSourceReservation();
    self::$quarantined[spl_object_id($this->connection)]=WeakReference::create($this->connection);
  }
  private function finishOwnedTransaction() {
    if (!$this->hasOwnedDepth()) {
      $this->quarantineTransaction();
      throw new RuntimeException('task_owned_transaction_depth_refused');
    }
    // Retire the ownership before COMMIT: acknowledgement loss is unknown,
    // even when native saveTransaction has already decreased its depth to zero.
    $this->transactionOwnership='committing';
    // Reserve this connection until close restores this scope's cache frame.
    try { $this->connection->saveTransaction(); }
    catch (Throwable $error) {
      $this->quarantineTransaction();
      throw new RuntimeException('task_commit_outcome_unknown',0,$error);
    }
    if ($this->nativeTransactionState()!==$this->ownedTransactionState ||
        $this->ownedTransactionState->getGeneration()!==$this->ownedGeneration ||
        $this->ownedTransactionState->getDepth()!==0) {
      $this->quarantineTransaction();throw new RuntimeException('task_commit_outcome_unknown');
    }
    $this->transactionOwnership='ended';$this->ownedTransactionState=null;
    $this->state='committed';
  }
  public function close() {
    if ($this->state==='closed') {
      if ($this->cleanupFailed) { throw new RuntimeException('task_scope_cleanup_failed'); }
      return;
    }
    $this->state='closed';$failed=$this->cleanupFailed;
    if ($this->connection!==null) {
      try {
        if ($this->transactionOwnership==='owned') {
          if (!$this->hasOwnedDepth()) {
            $this->quarantineTransaction();
            throw new RuntimeException('task_owned_transaction_depth_refused');
          }
          $this->transactionOwnership='rolling-back';
          $this->connection->killTransaction();
          if ($this->nativeTransactionState()!==$this->ownedTransactionState ||
              $this->ownedTransactionState->getGeneration()!==$this->ownedGeneration ||
              $this->connection->isInsideTransaction()) {
            throw new RuntimeException('task_scope_cleanup_failed');
          }
          $this->transactionOwnership='ended';$this->ownedTransactionState=null;
        } else if (in_array($this->transactionOwnership,array('opening','committing','unknown','rolling-back'),true)) {
          throw new RuntimeException('task_scope_cleanup_failed');
        }
      } catch (Throwable $error) { $this->quarantineTransaction();$failed=true; }
      $this->releaseSourceReservation();
    }
    $cache_owner=self::$cacheFrameOwner?self::$cacheFrameOwner->get():null;
    if ($this->cachePrior && $cache_owner!==$this) {
      // Never restore an older frame over another scope's current frame.
      $failed=true;
    } else if ($cache_owner===$this && $this->cache!==PhabricatorCaches::getRequestCache()) {
      $failed=true;
    } else if ($cache_owner===$this && !$failed) {
      foreach ($this->cachePrior as $key=>$value) {
        try {
          if ($value===$this->cacheMissing) { $this->cache->deleteKeys(array($key)); }
          else { $this->cache->setKey($key,$value); }
        } catch (Throwable $error) { $failed=true; }
      }
      self::$cacheFrameOwner=null;
    }
    $this->cleanupFailed=$failed;
    if ($failed) { throw new RuntimeException('task_scope_cleanup_failed'); }
  }
  public function __destruct() { try { $this->close(); } catch (Throwable $ignored) {} }
}
