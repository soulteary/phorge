<?php

/** Actual Project writer scope, not a separate People transaction or grant. */
final class PhorgeProjectNativeExecutionScope extends Phobject {
  private static $active=array();
  private static $membershipEdgeWrites=array();
  private $initialMembers=array();
  private $expectedMembers=array();
  private $membershipEdgeEditor;
  private $effectsNonce;
  private $connection;
  private $context;
  private $snapshot;
  private $actor;
  private $object;
  private $mode;
  private $approvalID;
  private $newPolicy;
  private $intent;
  private $sources;
  private $oldRow;
  private $fence;
  private $cache;
  private $cachePrior=array();
  private $cacheMissing;
  private $state='new';
  private $cleanupFailed=false;
  private $editor;
  private $configuration;
  private $plannedIdentities=array();
  private $descriptionRequest;
  private $descriptionTransaction;
  private $descriptionGrant;
  private $descriptionField;
  private $descriptionOldValue;
  private $descriptionOtherFields;
  private $descriptionGroupID;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('project_execution_scope_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('project_execution_scope_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'actual-native-project-writer'); }

  public static function isSourceConnection(AphrontDatabaseConnection $conn) {
    $ref=idx(self::$active,spl_object_id($conn)); $scope=$ref?$ref->get():null;
    return $scope!==null && $scope->connection===$conn &&
      in_array($scope->state,array('authorizing','open','consumed'),true) && $conn->isInsideTransaction();
  }
  public static function sourceDatabase(AphrontDatabaseConnection $conn,$table) {
    if (!self::isSourceConnection($conn) || !in_array(get_class($table),array(
      PhabricatorUser::class,PhabricatorAuthSession::class,PhabricatorUserTransaction::class,
      PhorgeGovernanceIdentityEpoch::class,PhabricatorSpacesNamespace::class,
      PhabricatorProject::class,PhabricatorProjectTransaction::class,PhabricatorConfigEntry::class,PhorgeProjectConfigEpoch::class,
      PhorgeOrganizationUnit::class,PhorgeOrganizationMember::class),true)) {
      throw new RuntimeException('project_execution_source_scope_required');
    }
    if (in_array(get_class($table),array(PhorgeOrganizationUnit::class,PhorgeOrganizationMember::class),true)) {
      $ref=self::$active[spl_object_id($conn)]??null;$scope=$ref?$ref->get():null;
      if (!$scope || !in_array($scope->mode,array('history','content-description'),true) || $scope->snapshot===null || ($scope->mode==='history'&&!$scope->snapshot->isSystemAdmin())) {
        throw new RuntimeException('content_grant_team_source_scope_required');
      }
      PhorgeProjectContentGrantService::assertEnabled();
    }
    $route=$table->establishConnection('w');
    if (get_class($route)!==AphrontMySQLiDatabaseConnection::class ||
        ($route!==$conn && $route->isInsideTransaction())) {
      throw new RuntimeException('project_execution_source_transaction_unsupported');
    }
    $actual=queryfx_one($conn,'SELECT @@server_uuid uuid');
    $expected=queryfx_one($route,'SELECT @@server_uuid uuid,DATABASE() db');
    if (!$actual || !$expected || $actual['uuid']!==$expected['uuid'] ||
        $table->getAphrontRefDatabaseName()!==$expected['db'] ||
        !is_string($expected['db']) || !preg_match('/\A[a-zA-Z0-9_]+\z/',$expected['db'])) {
      throw new RuntimeException('project_execution_same_primary_required');
    }
    return $expected['db'];
  }

  public static function open(PhorgeGovernanceNativeActorContext $context,
    PhabricatorProject $object,$new_policy,$mode,$approval_id=null,$intent=null,?PhorgeProjectContentGrantPlan $content_plan=null,?PhorgeProjectDescriptionRequest $description_request=null) {
    if (!PhorgeProjectExecutionCatalog::enabled() ||
        !in_array($mode,array('propose','approve','execute','history','self-join','self-leave','content-description'),true) ||
        get_class($object)!==PhabricatorProject::class || !$object->getID() ||
        !is_string($object->getPHID()) ||
        !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/',$object->getPHID())) {
      throw new RuntimeException('project_execution_profile_unsupported');
    }
    if(($mode==='content-description')!==($description_request!==null) || ($description_request!==null && $description_request->getTargetPHID()!==$object->getPHID())) { throw new RuntimeException('description_scope_request_required'); }
    if($mode==='content-description'&&($approval_id!==null||$intent!==null||$content_plan!==null)){throw new RuntimeException('description_scope_controls_refused');}
    $content_participants=array();
    if ($content_plan!==null) {
      PhorgeProjectContentGrantService::assertEnabled();
      if (get_class($content_plan)!==PhorgeProjectContentGrantPlan::class) {
        throw new RuntimeException('project_execution_content_participant_plan_refused');
      }
      $content_plan->assertScope($mode,$object->getPHID());
      $content_participants=$content_plan->getIdentityPHIDs();
    }
    $intent=$intent===null?PhorgeProjectExecutionIntent::newPolicy($new_policy):$intent;
    if(!($intent instanceof PhorgeProjectExecutionIntent) || get_class($intent)!==PhorgeProjectExecutionIntent::class || $intent->getPolicy()!==$new_policy){
      throw new RuntimeException('project_execution_intent_binding_refused');
    }
    if($mode==='propose' && $intent->isMembership() && $intent->getMembers()===array($context->getUserPHID())){
      throw new RuntimeException('project_execution_membership_ticket_not_applicable');
    }
    $conn=$object->establishConnection('w');
    if ($conn!==(new PhabricatorProject())->establishConnection('w') ||
        $object->getAphrontRefDatabaseName()!==(new PhabricatorProject())->getAphrontRefDatabaseName() ||
        get_class($conn)!==AphrontMySQLiDatabaseConnection::class ||
        $conn->isInsideTransaction() || $conn->isReadLocking() || $conn->isWriteLocking() ||
        isset(self::$active[spl_object_id($conn)])) {
      throw new RuntimeException('project_execution_existing_scope_refused');
    }
    PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    $scope=new self(); $scope->connection=$conn; $scope->context=$context;
    $scope->descriptionRequest=$description_request;
    $scope->object=$object; $scope->newPolicy=$new_policy; $scope->intent=$intent; $scope->mode=$mode; $scope->approvalID=$approval_id;
    try {
      $ids=array_merge(array($context->getUserPHID()),$intent->getMembers(),$content_participants);
      if ($approval_id!==null) {
        $ids=array_merge($ids,PhorgeProjectPolicyApprovalStore::planning($conn,$approval_id));
      }
      // Planning is not authority, and a later source-set change is refused.
      $planning=queryfx_one($conn,'SELECT viewPolicy,editPolicy,joinPolicy FROM %R WHERE id=%d',
        new PhabricatorProject(),$object->getID());
      if (!$planning) { throw new RuntimeException('project_execution_target_missing'); }
      $policy_ids=array();
      $application=new PhabricatorProjectApplication();
      $application_policy=$application->getPolicy(PhabricatorPolicyCapability::CAN_VIEW);
      foreach (array_merge(array_values($planning),$intent->isMembership()?array($application_policy):array($new_policy,$application_policy)) as $policy) {
        PhorgeProjectExecutionCatalog::policy($policy);
        if (str_starts_with($policy,'PHID-USER-')) { $ids[]=$policy; $policy_ids[]=$policy; }
      }
      $ids=array_unique($ids); sort($ids,SORT_STRING); $scope->plannedIdentities=$ids;
      queryfx($conn,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
      $conn->openTransaction(); $scope->state='authorizing';
      self::$active[spl_object_id($conn)]=WeakReference::create($scope);
      foreach ($ids as $id) { PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($id,$conn); }
      $scope->snapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      $scope->actor=$scope->freshUser($scope->snapshot);
      $scope->configuration=PhorgeProjectExecutionConfigSource::capture($conn);
      foreach (array('governance_executionguard'=>'id','governance_projectapproval'=>'approvalID',
        'governance_executionaudit'=>'id','edge'=>'src','project_datasourcetoken'=>'id',
        (new PhabricatorProjectSlug())->getTableName()=>'id',(new PhabricatorProject())->getTableName()=>'id',
        (new PhabricatorProjectTransaction())->getTableName()=>'id') as $table=>$key) {
        queryfx_one($conn,'SELECT %C FROM %T WHERE 1=0 LOCK IN SHARE MODE',$key,$table);
        $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
        if (!$engine || $engine['ENGINE']!=='InnoDB') { throw new RuntimeException('project_execution_transactional_schema_required'); }
      }
      $guard=queryfx_one($conn,'SELECT id,version FROM governance_executionguard WHERE id=1 FOR UPDATE');
      if (!$guard || (string)$guard['id']!=='1' || (int)$guard['version']<1) {
        throw new RuntimeException('project_execution_guard_required');
      }
      $target_rows=$scope->boundedRows(new PhabricatorProject(),qsprintf($conn,'id=%d',$object->getID()),1,true);
      $scope->oldRow=$target_rows?head($target_rows):null;
      if (!$scope->oldRow || $scope->oldRow['phid']!==$object->getPHID() ||
          $scope->oldRow['parentProjectPHID']!==null || (string)$scope->oldRow['hasSubprojects']!=='0' ||
          (string)$scope->oldRow['hasMilestones']!=='0' || $scope->oldRow['milestoneNumber']!==null ||
          $scope->oldRow['subtype']!==PhabricatorEditEngineSubtype::SUBTYPE_DEFAULT ||
          (string)$scope->oldRow['status']!==(string)PhabricatorProjectStatus::STATUS_ACTIVE) {
        throw new RuntimeException('project_execution_hierarchy_unsupported');
      }
      // Flags are cached metadata, not proof that no child/milestone exists.
      // The native parent/milestone index fences the actual empty parent range
      // until this Project transaction finishes; inconsistent flags fail closed.
      $child=queryfx_one($conn,
        'SELECT phid FROM %R FORCE INDEX (%C) WHERE parentProjectPHID=%s LIMIT 1 LOCK IN SHARE MODE',
        new PhabricatorProject(),'key_milestone',$object->getPHID());
      if ($child!==null) { throw new RuntimeException('project_execution_hierarchy_unsupported'); }
      foreach (array('viewPolicy','editPolicy','joinPolicy') as $key) {
        if ($scope->oldRow[$key]!==$planning[$key]) { throw new RuntimeException('project_execution_identity_plan_changed'); }
      }
      $scope->captureSpaces();
      $edges=queryfx_all($conn,'SELECT src,dst,type,dateCreated,seq,dataID FROM %T WHERE src=%s AND type IN (%Ld) ORDER BY type,dst LIMIT 257 LOCK IN SHARE MODE',
        'edge',$object->getPHID(),array(PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,
          PhabricatorProjectMaterializedMemberEdgeType::EDGECONST));
      if (count($edges)>256) { throw new RuntimeException('project_execution_source_budget'); }
      $members=array();$direct_members=array();
      foreach ($edges as $edge) {
        if($intent->isMembership() && $edge['dataID']!==null && (string)$edge['dataID']!=='0'){
          throw new RuntimeException('project_execution_member_edge_data_unsupported');
        }
        if ((int)$edge['type']===PhabricatorProjectMaterializedMemberEdgeType::EDGECONST) { $members[]=$edge['dst']; }
        else { $direct_members[]=$edge['dst']; }
      }
      sort($members,SORT_STRING);sort($direct_members,SORT_STRING);
      // A root with no children has exactly its direct members. Accepting a
      // stale derived set could make postcommit rematerialization change an
      // unapproved authorization source. This scope never repairs that state.
      if ($members!==$direct_members) {
        throw new RuntimeException('project_execution_materialized_members_inconsistent');
      }
      $scope->initialMembers=$members;
      if(in_array($mode,array('propose','approve','execute'),true) &&
          $scope->hasMemberIntent() && count($scope->plannedMembers())>128){
        throw new RuntimeException('project_execution_source_budget');
      }
      $object->loadFromArray($scope->oldRow)->attachParentProject(null)->attachMemberPHIDs($members);
      $scope->requireNativeView($scope->actor);
      $scope->requireWebEnrollment($scope->snapshot,$scope->actor);
      if (in_array($mode,array('propose','execute'),true)) {
        if (!PhabricatorPolicyFilter::hasCapability($scope->actor,$object,PhabricatorPolicyCapability::CAN_EDIT)) {
          throw PhorgeProjectPolicyRefusalException::newForReason('native-edit');
        }
      } else if(in_array($mode,array('self-join','self-leave'),true)){
        if(($mode==='self-join' && !PhabricatorPolicyFilter::hasCapability($scope->actor,$object,PhabricatorPolicyCapability::CAN_JOIN)) ||
          ($mode==='self-leave' && $object->getIsMembershipLocked() &&
          !PhabricatorPolicyFilter::hasCapability($scope->actor,$object,PhabricatorPolicyCapability::CAN_EDIT))){
          throw new RuntimeException('project_execution_native_membership_capability_required');
        }
        $scope->lockInverseMembership();
      } else if ($mode==='content-description') {
        $scope->authorizeDescription();
      } else if (!$scope->snapshot->isSystemAdmin()) {
        throw PhorgeProjectPolicyRefusalException::newForReason('current-approver');
      }
      if($scope->hasMemberIntent()){$scope->lockInverseMembership();}
      $last=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
        new PhabricatorProjectTransaction(),$object->getPHID());
      $identities=array();
      $policy_ids=array_merge($policy_ids,$intent->getMembers());
      $policy_ids=array_unique($policy_ids); sort($policy_ids,SORT_STRING);
      foreach ($policy_ids as $id) {
        $identities[$id]=PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($id,$conn);
        if(in_array($mode,array('propose','approve','execute'),true) &&
            $intent->isMembership() && $intent->isAddition() && in_array($id,$intent->getMembers(),true) &&
            !$identities[$id]['eligible']) {
          throw new RuntimeException('project_execution_member_add_subject_ineligible');
        }
        if($intent->isMembership()){
          $generation=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',new PhabricatorUserTransaction(),$id);
          $legacy=queryfx_one($conn,'SELECT userPHID,epoch FROM %R WHERE BINARY userPHID=BINARY %s LOCK IN SHARE MODE',new PhorgeGovernanceIdentityEpoch(),$id);
          // Initializing credential metadata at epoch 1 is not a role change.
          // The mandatory legacy role writer starts at 2 on its first change.
          // This source fact never initializes credentials or authorizes a user;
          // actual executor/approver bindings retain their strict source checks.
          $role_epoch=$legacy?(string)$legacy['epoch']:'1';
          if(($legacy&&$legacy['userPHID']!==$id)||!preg_match('/\A[1-9][0-9]{0,19}\z/',$role_epoch)||
            (strlen($role_epoch)===20&&strcmp($role_epoch,'18446744073709551615')>0)){
            throw new RuntimeException('project_execution_subject_generation_unknown');}
          unset($identities[$id]['accountEpoch']);
          $identities[$id]['nativeTransactionID']=$generation?(string)$generation['id']:'0';
          $identities[$id]['legacyRoleEpoch']=$role_epoch;
        }
      }
      $scope->sources=array('row'=>self::digest($scope->oldRow),'members'=>self::digest($edges),'identities'=>self::digest($identities),
        'configuration'=>$scope->configuration->getDigest(),'spaces'=>$scope->sources['spaces'],'nativeTransactionID'=>$last?(string)$last['id']:'0');
      $scope->fence=PhorgeProjectExecutionProducerFence::acquire($conn);
      $scope->state='open'; return $scope;
    } catch (Throwable $error) {
      try { $scope->close(); } catch (Throwable $cleanup) {
        throw new RuntimeException('project_execution_scope_cleanup_failed',0,$error);
      }
      throw $error;
    }
  }
  private static function digest($value) {
    return PhorgeProjectExecutionValue::digest($value);
  }
  /** Fixed native model/column profiles; length checks precede blob hydration. */
  private function boundedRows(PhabricatorLiskDAO $table,PhutilQueryString $where,$limit,$exclusive=false) {
    if (!in_array(get_class($table),array(PhabricatorProject::class,PhabricatorUser::class,
        PhabricatorAuthSession::class,PhabricatorSpacesNamespace::class),true) ||
        !in_array($limit,array(1,64),true)) {
      throw new RuntimeException('project_execution_row_profile_unsupported');
    }
    $columns=array_keys($table->getSchemaColumns()); sort($columns,SORT_STRING);
    if (!$columns || count($columns)>64) { throw new RuntimeException('project_execution_row_profile_unsupported'); }
    $terms=array(qsprintf($this->connection,'0'));
    foreach ($columns as $column) { $terms[]=qsprintf($this->connection,'+ COALESCE(OCTET_LENGTH(%C),0)',$column); }
    $lock=$exclusive?qsprintf($this->connection,'FOR UPDATE'):qsprintf($this->connection,'LOCK IN SHARE MODE');
    $sizes=queryfx_all($this->connection,'SELECT id,(%LJ) AS row_bytes FROM %R WHERE %Q ORDER BY id LIMIT %d %Q',
      $terms,$table,$where,$limit+1,$lock);
    $schema=self::sourceDatabase($this->connection,$table);
    $engine=queryfx_one($this->connection,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table->getTableName());
    if (!$engine || $engine['ENGINE']!=='InnoDB') { throw new RuntimeException('project_execution_transactional_schema_required'); }
    $actual=queryfx_all($this->connection,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY COLUMN_NAME LIMIT 65',
      $schema,$table->getTableName());
    $actual=ipull($actual,'COLUMN_NAME'); sort($actual,SORT_STRING);
    if ($actual!==$columns) { throw new RuntimeException('project_execution_row_profile_unsupported'); }
    if (count($sizes)>$limit) { throw new RuntimeException('project_execution_source_budget'); }
    $total=0;
    foreach ($sizes as $row) {
      if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/',(string)$row['row_bytes']) ||
          (int)$row['row_bytes']>65536 || ($total+=(int)$row['row_bytes'])>262144) {
        throw new RuntimeException('project_execution_source_budget');
      }
    }
    return queryfx_all($this->connection,'SELECT * FROM %R WHERE %Q ORDER BY id LIMIT %d %Q',$table,$where,$limit+1,$lock);
  }
  private function freshUser(PhorgeGovernanceNativeIdentitySnapshot $snapshot) {
    $rows=$this->boundedRows(new PhabricatorUser(),qsprintf($this->connection,'BINARY phid=BINARY %s',$snapshot->getUserPHID()),1);
    $row=$rows?head($rows):null;
    if (!$row || $row['phid']!==$snapshot->getUserPHID()) { throw new RuntimeException('project_execution_identity_missing'); }
    $user=(new PhabricatorUser())->loadFromArray($row);
    if ($snapshot->getCredentialType()==='web') {
      $sessions=$this->boundedRows(new PhabricatorAuthSession(),qsprintf($this->connection,'BINARY phid=BINARY %s',$snapshot->getSessionPHID()),1);
      $session=$sessions?head($sessions):null;
      if (!$session || $session['userPHID']!==$user->getPHID()) { throw new RuntimeException('project_execution_identity_missing'); }
      $user->attachSession((new PhabricatorAuthSession())->loadFromArray($session));
    }
    return $user;
  }
  /** Read-only native display values for this exact locked web actor. */
  private function attachWebDisplayCaches() {
    $factories=array(PhabricatorUserPreferencesCacheType::KEY_PREFERENCES=>new PhabricatorUserPreferencesCacheType());
    if(PhabricatorApplication::isClassInstalledForViewer(PhabricatorConpherenceApplication::class,$this->actor)){
      $factories[PhabricatorUserMessageCountCacheType::KEY_COUNT]=new PhabricatorUserMessageCountCacheType();
    }
    if(PhabricatorApplication::isClassInstalledForViewer(PhabricatorNotificationsApplication::class,$this->actor)){
      $factories[PhabricatorUserNotificationCountCacheType::KEY_COUNT]=new PhabricatorUserNotificationCountCacheType();
    }
    $phid=$this->actor->getPHID();$raw=array();
    foreach($factories as$key=>$factory){
      $values=$factory->newValueForUsers($key,array($this->actor));
      if(array_keys($values)!==array($phid)||
        ($key===PhabricatorUserPreferencesCacheType::KEY_PREFERENCES?!is_string($values[$phid]):
          ((!is_int($values[$phid])&&!is_string($values[$phid]))||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$values[$phid])))){
        throw new RuntimeException('project_execution_actor_display_cache_invalid');
      }
      $raw[$key]=$values[$phid];
    }
    // A real preceding GET normally already populated the native avatar cache.
    // Reuse only an actual row valid for this locked actor; never call the
    // profile factory, which may create files and save user authority fields.
    $profile=new PhabricatorUserProfileImageCacheType();$profile_key=PhabricatorUserProfileImageCacheType::KEY_URI;
    $profiles=PhabricatorUserCache::readCaches($profile,$profile_key,array($phid));
    if(isset($profiles[$phid])&&is_string($profiles[$phid])&&
      $profile->isRawCacheDataValid($this->actor,$profile_key,$profiles[$phid])){
      $raw[$profile_key]=$profiles[$phid];
    }
    // Missing or invalid profile cache is deliberately left unattached. A
    // direct editor need not render an avatar; no new cache-presence gate is
    // added before mutation. A later renderer still fails rather than writes.
    // Attach only computed raw display data. Never generate inline, write the
    // durable user cache, replace the authority actor, or generate avatars.
    $this->actor->attachRawCacheData($raw);
  }
  private function captureSpaces() {
    $table=new PhabricatorSpacesNamespace();
    $schema=self::sourceDatabase($this->connection,$table);
    $rows=$this->boundedRows($table,qsprintf($this->connection,'id>=0'),64);
    $engine=queryfx_one($this->connection,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
      $schema,$table->getTableName());
    if (!$engine || $engine['ENGINE']!=='InnoDB' || count($rows)>64) { throw new RuntimeException('project_execution_space_profile_unsupported'); }
    $spaces=array(); $default=null;
    foreach ($rows as $row) {
      PhorgeProjectExecutionCatalog::policy($row['viewPolicy']);
      $space=(new PhabricatorSpacesNamespace())->loadFromArray($row); $spaces[$row['phid']]=$space;
      if ($space->getIsDefaultNamespace()) {
        if ($default!==null) { throw new RuntimeException('project_execution_space_profile_unsupported'); }
        $default=$space;
      }
    }
    $this->cache=PhabricatorCaches::getRequestCache(); $this->cacheMissing=new stdClass();
    foreach (array(PhabricatorSpacesNamespaceQuery::KEY_ALL=>$spaces,
      PhabricatorSpacesNamespaceQuery::KEY_DEFAULT=>$default) as $key=>$value) {
      $this->cachePrior[$key]=$this->cache->getKey($key,$this->cacheMissing);
      $this->cache->setKey($key,$value);
    }
    // The viewer-specific cache key is PHID-only in native code; do not reuse
    // its possibly older isAdmin/Space decision for this current actor.
    $key=PhabricatorSpacesNamespaceQuery::KEY_VIEWER.'('.$this->actor->getCacheFragment().')';
    $this->cachePrior[$key]=$this->cache->getKey($key,$this->cacheMissing);
    $this->cache->deleteKeys(array($key));
    $this->sources=array('spaces'=>self::digest($rows));
  }
  public function requireNativeView(PhabricatorUser $user) {
    if ($this->configuration===null) { throw new RuntimeException('project_execution_configuration_required'); }
    $this->configuration->requireApplicationView($user);
    $key=PhabricatorSpacesNamespaceQuery::KEY_VIEWER.'('.$user->getCacheFragment().')';
    if (!array_key_exists($key,$this->cachePrior)) {
      $this->cachePrior[$key]=$this->cache->getKey($key,$this->cacheMissing);
    }
    $this->cache->deleteKeys(array($key));
    if (!PhabricatorPolicyFilter::hasCapability($user,$this->object,PhabricatorPolicyCapability::CAN_VIEW)) {
      throw PhorgeProjectPolicyRefusalException::newForReason('native-view');
    }
  }
  private function requireWebEnrollment(PhorgeGovernanceNativeIdentitySnapshot $snapshot,PhabricatorUser $user) {
    if ($snapshot->getCredentialType()==='web' &&
        PhabricatorEnv::getEnvConfig('security.require-multi-factor-auth') &&
        !$user->getIsEnrolledInMultiFactor()) {
      throw new RuntimeException('project_execution_native_mfa_enrollment_required');
    }
  }
  public function validateCredential(array $binding,$approver=false) {
    if (!isset($binding['userPHID']) || !in_array($binding['userPHID'],$this->plannedIdentities,true)) {
      throw new RuntimeException('project_execution_identity_plan_changed');
    }
    $snapshot=PhorgeGovernanceNativeIdentitySource::lockCredentialBinding($binding,$this->connection);
    if ($approver && !$snapshot->isSystemAdmin()) { throw PhorgeProjectPolicyRefusalException::newForReason('current-approver'); }
    $user=$this->freshUser($snapshot);
    if ($this->state==='consumed' && $this->intent->isViewPolicy()) {
      // The only accepted transition is the approved VIEW field. Re-evaluate
      // native VIEW against the locked pre-transition object, not a stale user
      // or an extra future-VIEW requirement. Credential/role/TTL remain fresh.
      $current=$this->object;
      $this->object=(new PhabricatorProject())->loadFromArray($this->oldRow)
        ->attachParentProject(null)->attachMemberPHIDs($this->initialMembers);
      try { $this->requireNativeView($user); } finally { $this->object=$current; }
    } else { $this->requireNativeView($user); }
    $this->requireWebEnrollment($snapshot,$user);
    return $snapshot;
  }
  /** Actual base-editor hook. A credentialless actor is never an executor. */
  public static function beginEditor(PhabricatorApplicationTransactionEditor $editor,
    PhabricatorLiskDAO $object,array $xactions) {
    foreach($xactions as$x){
      if(get_class($x)===PhabricatorProjectTransaction::class && $x->getTransactionType()==='governance:project-description'){
        return PhorgeProjectDescriptionExecutor::beginEditor($editor,$object,$xactions);
      }
    }
    $decision=PhorgeProjectExecutionCatalog::decide($editor,$object,$xactions);
    if ($decision==='native') { return null; }
    if ($editor->getIsPreview() || $editor->getIsSilent() ||
        $editor->getIsHeraldEditor() || $editor->getIsInverseEdgeEditor()) {
      throw new RuntimeException('project_execution_editor_mode_unsupported');
    }
    $context=$editor->getProjectApprovalActorContext();
    $request=$editor->getRequest();
    if ($request!==null) {
      if (!$request->isHTTPPost()) { throw new RuntimeException('project_execution_post_required'); }
      $request->validateCSRF();
      $web=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      if ($context!==null && ($context->getUserPHID()!==$web->getUserPHID() ||
          $context->getCredentialDigest()!==$web->getCredentialDigest())) {
        throw new RuntimeException('project_execution_request_binding_changed');
      }
      $context=$web;
    }
    if ($context===null || !$editor->getActor() ||
        $context->getUserPHID()!==$editor->getActor()->getPHID() ||
        $context->getUserPHID()!==$editor->getActingAsPHID()) {
      throw new RuntimeException('project_execution_authenticated_source_required');
    }
    $approval=$editor->getProjectApprovalID();
    if ($approval===null && $request!==null) { $approval=$request->getStr('governanceApprovalID'); }
    $membership=in_array($decision,array('self-join','self-leave'),true);
    if(!$membership){PhorgeProjectPolicyTicketState::identifier($approval);}
    else if($approval!==null && $approval!==''){throw new RuntimeException('project_execution_membership_ticket_not_applicable');}
    $policy_transaction=in_array($decision,array('view-policy','edit-policy'),true)?
      PhorgeProjectExecutionCatalog::policyTransaction($editor,$object,$xactions,$decision):null;
    $intent=in_array($decision,array('members-add','members-remove'),true)?
      PhorgeProjectExecutionIntent::newFromNativeMembership($xactions[0]):
      ($decision==='view-policy'?PhorgeProjectExecutionIntent::newViewPolicy($policy_transaction->getNewValue()):null);
    $policy=$intent!==null?$intent->getPolicy():($membership?PhabricatorPolicies::POLICY_USER:$policy_transaction->getNewValue());
    $scope=self::open($context,$object,$policy,$membership?$decision:'execute',$membership?null:$approval,$intent);
    $scope->editor=$editor;
    try {
    if($request!==null){$scope->attachWebDisplayCaches();}
    $editor->setActor($scope->actor);
    if ($request!==null) {
      // Replace only this authenticated viewer with its locked current row and
      // actual native session. Native CSRF and MFA still run on real sources.
      $request->setUser($scope->actor); $request->validateCSRF();
    }
    return $scope;
    } catch (Throwable $error) { $scope->close(); throw $error; }
  }
  public function beforeEffects(array $xactions) {
    $this->assertOpen();
    if($this->mode==='content-description'){$this->beforeDescriptionEffects($xactions);return;}
    if($this->isMembership()){$this->authorizeMembershipEffects($xactions);return;}
    if ($this->state!=='open' || count($xactions)!==1 ||
        get_class($xactions[0])!==PhabricatorProjectTransaction::class ||
        $xactions[0]->getTransactionType()!==$this->intent->getTransactionType() ||
        $xactions[0]->getNewValue()!==$this->newPolicy || $xactions[0]->getComment()!==null) {
      if($this->state==='open'&&PhorgeProjectExecutionCatalog::hasKnownAdditionalFormEffects(
          $this->editor,$this->object,$xactions,$this->intent->getTransactionType(),$this->newPolicy)){
        throw PhorgeProjectPolicyRefusalException::newForReason('additional-effects');
      }
      throw new RuntimeException('project_execution_expanded_closure_unsupported');
    }
    $metadata=$xactions[0]->getMetadata();
    if (!is_array($metadata) || array_diff(array_keys($metadata),array('core.mfa')) ||
        (array_key_exists('core.mfa',$metadata) && $metadata['core.mfa']!==true) ||
        $xactions[0]->getAuthorPHID()!==$this->snapshot->getUserPHID()) {
      throw new RuntimeException('project_execution_expanded_envelope_unsupported');
    }
    PhorgeProjectPolicyApprovalStore::forScope($this)->consume($this->approvalID);
    $this->state='consumed';
  }
  public function beforeCommit(array $xactions) {
    $this->assertOpen();
    if ($this->state!=='consumed') { throw new RuntimeException('project_execution_consumption_required'); }
    if($this->mode==='content-description'){$this->finishDescription($xactions);return;}
    if($this->isMembership()){$this->finishMembership($xactions);return;}
    $rows=$this->boundedRows(new PhabricatorProject(),qsprintf($this->connection,'id=%d',$this->object->getID()),1,true);
    $row=$rows?head($rows):null;
    $field=$this->intent->getPolicyField();
    if (!$row || $row[$field]!==$this->newPolicy) {
      throw new RuntimeException('project_execution_planned_transition_mismatch');
    }
    // The approved field may change; no new post-policy EDIT requirement is
    // added. The original native validator/capabilities remain authoritative.
    $row[$field]=$this->oldRow[$field];
    $row['dateModified']=$this->oldRow['dateModified'];
    if (self::digest($row)!==self::digest($this->oldRow)) {
      throw new RuntimeException('project_execution_unplanned_target_change');
    }
    PhorgeProjectPolicyApprovalStore::forScope($this)->finishNative($this->approvalID,$xactions);
  }
  private function descriptionRows() {
    $storage=new PhabricatorProjectCustomFieldStorage();
    if($storage->establishConnection('w')!==$this->connection){throw new RuntimeException('description_storage_primary_refused');}
    $table=$storage->getTableName();
    queryfx_one($this->connection,'SELECT id FROM %T WHERE 1=0 LOCK IN SHARE MODE',$table);
    $engine=queryfx_one($this->connection,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('description_storage_engine_refused');}
    $rows=queryfx_all($this->connection,'SELECT id,objectPHID,fieldIndex,CASE WHEN OCTET_LENGTH(fieldValue)<=65536 THEN fieldValue ELSE NULL END fieldValue,OCTET_LENGTH(fieldValue) fieldBytes FROM %T WHERE objectPHID=%s ORDER BY fieldIndex LIMIT 65 FOR UPDATE',$table,$this->object->getPHID());
    if(count($rows)>64){throw new RuntimeException('description_storage_budget');}
    foreach($rows as$row){if($row['fieldValue']===null||PhorgeProjectDescriptionRequest::unsigned($row['fieldBytes'])>65536){throw new RuntimeException('description_storage_budget');}}
    return$rows;
  }
  private function descriptionGrantRow() {
    PhorgeProjectContentGrantService::assertEnabled();$r=$this->descriptionRequest;$c=$this->connection;
    foreach(array('governance_contentgrant'=>'grantID','governance_contentapproval'=>'approvalID','governance_contentaudit'=>'id')as$t=>$key){
      queryfx_one($c,'SELECT %C FROM %T WHERE 1=0 LOCK IN SHARE MODE',$key,$t);
      $engine=queryfx_one($c,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t);
      if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('description_grant_schema_required');}
    }
    $row=queryfx_one($c,'SELECT grantID,targetPHID,operation,subjectKind,subjectID,validFrom,validUntil,revokedAt,version FROM governance_contentgrant WHERE grantID=%s LOCK IN SHARE MODE',$r->getGrantID());
    $now=(int)queryfx_one($c,'SELECT UNIX_TIMESTAMP() n')['n'];
    PhorgeProjectDescriptionRequest::requireGrantFields($row,$r,$this->snapshot->getUserPHID(),$now);
    if($row['subjectKind']==='user'){
      if($row['subjectID']!==$this->snapshot->getUserPHID()){throw new RuntimeException('description_explicit_user_required');}
    }else if($row['subjectKind']==='business-team'){
      $membership=PhorgeProjectContentSource::directMembership($this,$row['subjectID'],$this->snapshot->getUserPHID());
      if($membership['observed_direct_member']!==true){throw new RuntimeException('description_direct_team_member_required');}
      $row['membership_digest']=$membership['source_digest'];
    }else{throw new RuntimeException('description_subject_kind_refused');}
    // The persisted grant must be backed by the actually applied dual-admin
    // request and its append-only grant audit, not an incoming approval flag.
    $audit=queryfx_all($c,'SELECT approvalID,actorPHID FROM governance_contentaudit WHERE grantID=%s AND targetPHID=%s AND action=%s ORDER BY id LIMIT 2 LOCK IN SHARE MODE',$r->getGrantID(),$r->getTargetPHID(),'granted');
    if(count($audit)!==1){throw new RuntimeException('description_grant_provenance_required');}
    $a=queryfx_one($c,'SELECT approvalID,targetPHID,CASE WHEN OCTET_LENGTH(bindingJSON)<=65536 THEN bindingJSON ELSE NULL END bindingJSON,bindingDigest,state,CASE WHEN OCTET_LENGTH(approverBindingJSON)<=65536 THEN approverBindingJSON ELSE NULL END approverBindingJSON,appliedAt,version FROM governance_contentapproval WHERE approvalID=%s LOCK IN SHARE MODE',$audit[0]['approvalID']);
    if(!$a||$a['state']!=='applied'||(string)$a['version']!=='2'||!is_string($a['bindingJSON'])||strlen($a['bindingJSON'])>65536||
       !is_string($a['approverBindingJSON'])||strlen($a['approverBindingJSON'])>65536||$a['targetPHID']!==$r->getTargetPHID()){
      throw new RuntimeException('description_applied_approval_required');
    }
    $b=json_decode($a['bindingJSON'],true,16,JSON_THROW_ON_ERROR);$approver=json_decode($a['approverBindingJSON'],true,16,JSON_THROW_ON_ERROR);
    $keys=is_array($b)?array_keys($b):array();sort($keys,SORT_STRING);
    if($keys!==array('grantExpiresAt','grantID','issuedAt','request','requester','sources','subject','ticketExpiresAt')||
       PhorgeProjectExecutionValue::canonical($b)!==$a['bindingJSON']||PhorgeProjectExecutionValue::canonical($approver)!==$a['approverBindingJSON']){
      throw new RuntimeException('description_approval_shape_refused');
    }
    $request=PhorgeProjectContentGrantRequest::stored($b['request']);
    if(PhorgeProjectExecutionValue::digest($b)!==$a['bindingDigest']||$b['grantID']!==$r->getGrantID()||
       $request['action']!=='grant'||$request['target_phid']!==$row['targetPHID']||$request['subject_kind']!==$row['subjectKind']||
       $request['subject_id']!==$row['subjectID']||$b['grantExpiresAt']!==PhorgeProjectDescriptionRequest::unsigned($row['validUntil'])||
       !PhorgeGovernanceOrganizationRequest::userID($b['requester']['userPHID']??null)||
       !PhorgeGovernanceOrganizationRequest::userID($approver['userPHID']??null)||
       $approver['userPHID']===$b['requester']['userPHID']||$audit[0]['actorPHID']!==$approver['userPHID']||
       PhorgeProjectDescriptionRequest::unsigned($a['appliedAt'])!==PhorgeProjectDescriptionRequest::unsigned($row['validFrom'])) {
      throw new RuntimeException('description_dual_admin_binding_required');
    }
    $row['approval_digest']=self::digest(array($a,$audit));return$row;
  }
  private function authorizeDescription() {
    $this->descriptionGrant=$this->descriptionGrantRow();
    $field=PhabricatorCustomField::getObjectField($this->object,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS,PhorgeProjectDescriptionExecutor::FIELD);
    if(!$field||!$field->shouldAppearInApplicationTransactions()||
       !$field->shouldEnableForRole(PhabricatorCustomField::ROLE_EDIT)||!$field->shouldEnableForRole(PhabricatorCustomField::ROLE_STORAGE)||
       get_class($field->newStorageObject())!==PhabricatorProjectCustomFieldStorage::class){throw new RuntimeException('description_native_field_required');}
    $field->setViewer($this->actor);$this->descriptionField=$field;$value=null;$others=array();
    foreach($this->descriptionRows()as$row){
      if($row['fieldIndex']===$field->getFieldIndex()){$value=$row['fieldValue'];}
      else{$others[]=$row;}
    }
    $field->setValueFromStorage($value);$field->didSetValueFromStorage();$this->descriptionOldValue=$field->getOldValueForApplicationTransactions();
    if(!hash_equals($this->descriptionRequest->getExpectedDigest(),self::digest($this->descriptionOldValue))){throw new RuntimeException('description_stale_value');}
    if($this->descriptionOldValue===$this->descriptionRequest->getDescription()){throw new RuntimeException('description_no_effect');}
    $this->descriptionOtherFields=self::digest($others);
  }
  public function bindDescriptionEditor($editor,$transaction) {
    if($this->mode!=='content-description'||$this->state!=='open'||$this->editor!==null||
       get_class($editor)!==PhabricatorProjectTransactionEditor::class||get_class($transaction)!==PhabricatorProjectTransaction::class||
       (new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'transactionGroupID'))->getValue($editor)!==null||
       (new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'projectGovernanceEffectsNonce'))->getValue($editor)!==null||
       array_key_exists('core.groupID',$transaction->getMetadata())){throw new RuntimeException('description_editor_binding_refused');}
    $this->editor=$editor;$this->descriptionTransaction=$transaction;
  }
  public function assertDescriptionTransaction($xaction) {
    if($this->mode!=='content-description'||$this->descriptionRequest===null||
       $xaction!==$this->descriptionTransaction||$xaction->getTransactionType()!==PhorgeProjectDescriptionExecutor::TYPE||
       $xaction->getNewValue()!==$this->descriptionRequest->getDescription()||$xaction->getComment()!==null) {
      throw new RuntimeException('description_transaction_binding_refused');
    }
    $this->assertDescriptionMetadata($xaction);
    $this->assertOpen();
  }
  /** Native metadata changes only after this exact editor enters effects.
   * Read the existing private group value: never allocate one or accept a caller
   * supplied group merely because it has the native string format.
   */
  private function assertDescriptionMetadata($xaction) {
    if($this->editor===null||get_class($this->editor)!==PhabricatorProjectTransactionEditor::class||
       !in_array($this->state,array('open','consumed'),true)){
      throw new RuntimeException('description_native_metadata_phase_required');
    }
    $m=$xaction->getMetadata();$mfa=$xaction->getMetadataValue('core.mfa');unset($m['core.mfa']);
    $group=(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'transactionGroupID'))->getValue($this->editor);
    $nonce=(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'projectGovernanceEffectsNonce'))->getValue($this->editor);
    if($this->state==='consumed'&&($this->effectsNonce===null||$nonce!==$this->effectsNonce)){
      throw new RuntimeException('description_native_metadata_phase_required');
    }
    if($group!==null){
      if($this->state!=='consumed'||!is_string($group)||!preg_match('/\A[a-z2-7]{32}\z/D',$group)||
         !array_key_exists('core.groupID',$m)||$m['core.groupID']!==$group||
         ($this->descriptionGroupID!==null&&$this->descriptionGroupID!==$group)){
        throw new RuntimeException('description_native_group_binding_refused');
      }
      unset($m['core.groupID']);
    }else if(array_key_exists('core.groupID',$m)||$this->descriptionGroupID!==null){
      throw new RuntimeException('description_native_group_binding_refused');
    }
    if($m!==array('customfield:key'=>PhorgeProjectDescriptionExecutor::FIELD)||($mfa!==null&&$mfa!==true)){
      throw new RuntimeException('description_transaction_metadata_refused');
    }
    if($group!==null){$this->descriptionGroupID=$group;}
  }
  public function getDescriptionField($xaction) {
    $this->assertDescriptionTransaction($xaction);
    if($this->descriptionField===null||$this->descriptionField->getObject()!==$this->object){
      throw new RuntimeException('description_field_identity_changed');
    }
    $this->descriptionField->setViewer($this->actor);return$this->descriptionField;
  }
  private function beforeDescriptionEffects(array $xactions) {
    if(count($xactions)!==1||$this->state!=='open'){throw new RuntimeException('description_expanded_closure_refused');}
    $this->assertDescriptionTransaction($xactions[0]);
    if($xactions[0]->getAuthorPHID()!==$this->snapshot->getUserPHID()||
       self::digest($this->descriptionGrantRow())!==self::digest($this->descriptionGrant)) {
      throw new RuntimeException('description_grant_source_changed');
    }
    $nonce=(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'projectGovernanceEffectsNonce'))->getValue($this->editor);
    if(!is_string($nonce)||!preg_match('/\A[a-f0-9]{32}\z/D',$nonce)){
      throw new RuntimeException('description_native_metadata_phase_required');
    }
    $this->effectsNonce=$nonce;$this->state='consumed';
  }
  private function finishDescription(array $xactions) {
    if(count($xactions)!==1){throw new RuntimeException('description_final_closure_refused');}
    $this->assertDescriptionTransaction($xactions[0]);
    if(self::digest($this->descriptionGrantRow())!==self::digest($this->descriptionGrant)){throw new RuntimeException('description_grant_source_changed');}
    $rows=$this->boundedRows(new PhabricatorProject(),qsprintf($this->connection,'id=%d',$this->object->getID()),1,true);$row=$rows?head($rows):null;
    if(!$row){throw new RuntimeException('description_target_missing');}$row['dateModified']=$this->oldRow['dateModified'];
    if(self::digest($row)!==self::digest($this->oldRow)){throw new RuntimeException('description_unplanned_project_change');}
    $other=array();$stored=null;
    foreach($this->descriptionRows()as$value){if($value['fieldIndex']===$this->descriptionField->getFieldIndex()){$stored=$value['fieldValue'];}else{$other[]=$value;}}
    if(self::digest($other)!==$this->descriptionOtherFields||$stored!==$this->descriptionField->getValueForStorage()||
       $this->descriptionField->getNewValueForApplicationTransactions()!==$this->descriptionRequest->getDescription()) {
      throw new RuntimeException('description_storage_postcondition_failed');
    }
    $edges=queryfx_all($this->connection,'SELECT src,dst,type,dateCreated,seq,dataID FROM edge WHERE src=%s AND type IN (%Ld) ORDER BY type,dst LIMIT 257 LOCK IN SHARE MODE',$this->object->getPHID(),array(PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,PhabricatorProjectMaterializedMemberEdgeType::EDGECONST));
    if(self::digest($edges)!==$this->sources['members']){throw new RuntimeException('description_membership_changed');}
    $this->requireNativeView($this->actor);
    $audit=queryfx_one($this->connection,'SELECT approvalID FROM governance_contentaudit WHERE grantID=%s AND action=%s ORDER BY id LIMIT 1 LOCK IN SHARE MODE',$this->descriptionRequest->getGrantID(),'granted');
    if(!$audit){throw new RuntimeException('description_audit_source_missing');}
    queryfx($this->connection,'INSERT INTO governance_contentaudit (approvalID,grantID,targetPHID,actorPHID,action,createdAt) VALUES (%s,%s,%s,%s,%s,%d)',
      $audit['approvalID'],$this->descriptionRequest->getGrantID(),$this->object->getPHID(),$this->snapshot->getUserPHID(),'description-updated',(int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n']);
  }
  public function didCommit() {
    if ($this->connection->isInsideTransaction() || $this->state!=='consumed') {
      throw new RuntimeException('project_execution_real_commit_required');
    }
    $this->state='committed';
  }
  public static function assertProjectSave(PhabricatorProject $object) {
    if (!self::guardEnabled()) { return; }
    if (!$object->getID()) { throw new RuntimeException('project_execution_creation_unsupported'); }
    $conn=$object->establishConnection('w');
    if ($object->getAphrontRefDatabaseName()!==(new PhabricatorProject())->getAphrontRefDatabaseName()) {
      throw new RuntimeException('project_execution_foreign_target_refused');
    }
    $row=queryfx_one($conn,'SELECT viewPolicy,editPolicy,joinPolicy,spacePHID,parentProjectPHID,hasSubprojects,hasMilestones,milestoneNumber,isMembershipLocked FROM %R WHERE id=%d',
      new PhabricatorProject(),$object->getID());
    if (!$row) { throw new RuntimeException('project_execution_target_missing'); }
    $changed=array();
    foreach ($row as $key=>$value) {
      $getter='get'.ucfirst($key); $actual=$object->$getter();
      if ($actual===null || $value===null ? $actual!==$value : (string)$actual!==(string)$value) { $changed[]=$key; }
    }
    if (!$changed) { return; }
    $ref=idx(self::$active,spl_object_id($conn)); $scope=$ref?$ref->get():null;
    if($scope!==null&&$scope->mode==='content-description'){throw new RuntimeException('description_direct_security_save_refused');}
    if (!$scope || $scope->isMembership() || $scope->state!=='consumed' || $scope->object!==$object ||
        $changed!==array($scope->intent->getPolicyField()) ||
        ($scope->intent->isViewPolicy()?$object->getViewPolicy():$object->getEditPolicy())!==$scope->newPolicy) {
      throw new RuntimeException('project_execution_direct_security_save_refused');
    }
  }
  public static function guardEnabled() {
    $enabled=PhorgeProjectExecutionCatalog::enabled();
    if(!$enabled && self::$active){throw new RuntimeException('project_execution_configuration_scope_changed');}
    return $enabled;
  }
  public static function guardMaterializationProject(PhabricatorProject $project,
    AphrontDatabaseConnection $conn,array $sources,$has_subprojects,$has_milestones) {
    if(!self::guardEnabled()){return;}
    if(get_class($project)!==PhabricatorProject::class ||
      $conn!==(new PhabricatorProject())->establishConnection('w') || !$conn->isInsideTransaction() ||
      $project->getAphrontRefDatabaseName()!==(new PhabricatorProject())->getAphrontRefDatabaseName()){
      throw new RuntimeException('project_execution_materialization_source_unsupported');
    }
    foreach(array((new PhabricatorProject())->getTableName(),'edge') as $table){
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
      if(!$engine || $engine['ENGINE']!=='InnoDB'){throw new RuntimeException('project_execution_transactional_schema_required');}
    }
    $row=queryfx_one($conn,'SELECT phid,parentProjectPHID,milestoneNumber,hasSubprojects,hasMilestones FROM %R WHERE id=%d FOR UPDATE',new PhabricatorProject(),$project->getID());
    $child=queryfx_one($conn,'SELECT phid FROM %R FORCE INDEX (%C) WHERE parentProjectPHID=%s LIMIT 1 LOCK IN SHARE MODE',
      new PhabricatorProject(),'key_milestone',$project->getPHID());
    if(!$row || $row['phid']!==$project->getPHID() || $row['parentProjectPHID']!==null ||
      $row['milestoneNumber']!==null || (string)$row['hasSubprojects']!=='0' ||
      (string)$row['hasMilestones']!=='0' || $project->getParentProjectPHID()!==null ||
      $project->getMilestoneNumber()!==null || $child!==null || $sources!==array($project->getPHID()) ||
      $has_subprojects || $has_milestones){throw new RuntimeException('project_execution_materialization_hierarchy_unsupported');}
    $edges=queryfx_all($conn,'SELECT dst,type FROM edge WHERE src=%s AND type IN (%Ld) ORDER BY type,dst LIMIT 257 LOCK IN SHARE MODE',
      $project->getPHID(),array(PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,PhabricatorProjectMaterializedMemberEdgeType::EDGECONST));
    if(count($edges)>256){throw new RuntimeException('project_execution_source_budget');}
  }
  public static function guardMaterializationDiff(PhabricatorProject $project,array $add,array $remove) {
    if(!self::guardEnabled()){return;}
    $scope=null;
    foreach(self::$active as $reference){$candidate=$reference->get();
      if($candidate!==null && $candidate->isMembership() && $candidate->state==='consumed' &&
        $candidate->object->getPHID()===$project->getPHID()){$scope=$candidate;break;}
    }
    if($scope===null){
      if($add || $remove){throw new RuntimeException('project_execution_unapproved_materialization_change');}
      return;
    }
    $scope->assertMembershipLease();
    $expected_add=array_values(array_diff($scope->expectedMembers,$scope->initialMembers));
    $expected_remove=array_values(array_diff($scope->initialMembers,$scope->expectedMembers));
    sort($add,SORT_STRING);sort($remove,SORT_STRING);sort($expected_add,SORT_STRING);sort($expected_remove,SORT_STRING);
    if($add!==$expected_add || $remove!==$expected_remove){
      throw new RuntimeException('project_execution_materialization_delta_changed');
    }
  }
  private function isMembership() {
    return $this->hasMemberIntent() || in_array($this->mode,array('self-join','self-leave'),true);
  }
  private function hasMemberIntent() {
    return $this->intent!==null && $this->intent->isMembership();
  }
  private function membershipSubjects() {
    return $this->hasMemberIntent()?$this->intent->getMembers():array($this->snapshot->getUserPHID());
  }
  private function isMembershipAddition() {
    return $this->hasMemberIntent()?$this->intent->isAddition():$this->mode==='self-join';
  }
  private function plannedMembers() {
    $members=$this->isMembershipAddition()?array_values(array_unique(array_merge($this->initialMembers,$this->membershipSubjects()))):
      array_values(array_diff($this->initialMembers,$this->membershipSubjects()));
    sort($members,SORT_STRING);return $members;
  }
  public function getProposedMembershipDelta() {
    if(!$this->hasMemberIntent()){throw new RuntimeException('project_execution_membership_scope_required');}
    $planned=$this->plannedMembers();
    return array('added'=>array_values(array_diff($planned,$this->initialMembers)),
      'removed'=>array_values(array_diff($this->initialMembers,$planned)));
  }
  public function getMembershipDelta() {
    if(!$this->isMembership()){throw new RuntimeException('project_execution_membership_scope_required');}
    return array('added'=>array_values(array_diff($this->expectedMembers,$this->initialMembers)),
      'removed'=>array_values(array_diff($this->initialMembers,$this->expectedMembers)));
  }
  private function lockInverseMembership($members=null) {
    $members=$members===null?$this->initialMembers:$members;
    $database=self::sourceDatabase($this->connection,new PhabricatorUser());
    queryfx_one($this->connection,'SELECT src FROM %T.%T WHERE 1=0 LOCK IN SHARE MODE',$database,'edge');
    $engine=queryfx_one($this->connection,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$database,'edge');
    if(!$engine || $engine['ENGINE']!=='InnoDB'){throw new RuntimeException('project_execution_inverse_transactional_schema_required');}
    foreach($this->membershipSubjects() as $subject){
      $rows=queryfx_all($this->connection,'SELECT src,dst,type FROM %T.%T WHERE src=%s AND type=%d AND dst=%s LIMIT 2 FOR UPDATE',
        $database,'edge',$subject,PhabricatorProjectMemberOfProjectEdgeType::EDGECONST,$this->object->getPHID());
      $expected=in_array($subject,$members,true)?1:0;
      if(count($rows)!==$expected || ($rows && ($rows[0]['src']!==$subject ||
        $rows[0]['dst']!==$this->object->getPHID()))){throw new RuntimeException('project_execution_inverse_membership_inconsistent');}
    }
    return $database;
  }
  private function authorizeMembershipEffects(array $xactions) {
    $nonce=(new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'projectGovernanceEffectsNonce'))->getValue($this->editor);
    if($this->state!=='open' || !is_string($nonce) || !preg_match('/\A[a-f0-9]{32}\z/',$nonce)){
      throw new RuntimeException('project_execution_native_effects_phase_required');
    }
    $actor=$this->snapshot->getUserPHID();$expected=$this->plannedMembers();
    if(!$xactions && $expected===$this->initialMembers && !$this->hasMemberIntent()){
      $this->expectedMembers=$expected;$this->effectsNonce=$nonce;$this->state='consumed';return;
    }
    if(count($xactions)!==1 || get_class($xactions[0])!==PhabricatorProjectTransaction::class ||
      $xactions[0]->getTransactionType()!==PhabricatorTransactions::TYPE_EDGE ||
      $xactions[0]->getMetadataValue('edge:type')!==PhabricatorProjectProjectHasMemberEdgeType::EDGECONST ||
      $xactions[0]->getAuthorPHID()!==$actor || $xactions[0]->getComment()!==null ||
      !is_array($xactions[0]->getOldValue()) || !is_array($xactions[0]->getNewValue())){
      throw new RuntimeException('project_execution_native_membership_expansion_unsupported');
    }
    $metadata=$xactions[0]->getMetadata();
    if(array_diff(array_keys($metadata),array('edge:type','core.mfa')) ||
      (array_key_exists('core.mfa',$metadata) && $metadata['core.mfa']!==true)){
      throw new RuntimeException('project_execution_native_membership_expansion_unsupported');
    }
    $old=array_keys($xactions[0]->getOldValue());$new=array_keys($xactions[0]->getNewValue());
    sort($old,SORT_STRING);sort($new,SORT_STRING);
    if($old!==$this->initialMembers || $new!==$expected){
      throw new RuntimeException('project_execution_native_membership_delta_changed');
    }
    if($this->hasMemberIntent()){PhorgeProjectPolicyApprovalStore::forScope($this)->consume($this->approvalID);}
    $this->expectedMembers=$expected;$this->effectsNonce=$nonce;$this->state='consumed';
  }
  private function assertMembershipLease() {
    $this->assertOpen();
    if(!$this->isMembership() || $this->state!=='consumed' || $this->editor===null ||
      $this->effectsNonce===null ||
      (new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'projectGovernanceEffectsNonce'))->getValue($this->editor)!==$this->effectsNonce){
      throw new RuntimeException('project_execution_native_membership_lease_required');
    }
  }
  public static function beforeMembershipEdgeWrite(PhabricatorEdgeEditor $editor) {
    $adds=(new ReflectionProperty(PhabricatorEdgeEditor::class,'addEdges'))->getValue($editor);
    $removes=(new ReflectionProperty(PhabricatorEdgeEditor::class,'remEdges'))->getValue($editor);
    $relevant=array(PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,
      PhabricatorProjectMemberOfProjectEdgeType::EDGECONST,PhabricatorProjectMaterializedMemberEdgeType::EDGECONST);
    $touches=false;foreach(array_merge($adds,$removes) as $edge){
      $type=$edge['type'];
      // EdgeType lookup and SQL integer formatting accept numeric aliases.
      // Recognize their sensitive family, then require canonical exact specs.
      if((is_int($type)||is_float($type)||is_string($type)) && in_array((int)$type,$relevant,true)){$touches=true;}
    }
    if(!$touches || !self::guardEnabled()){return;}
    $scope=null;
    foreach(self::$active as $reference){$candidate=$reference->get();
      if($candidate!==null && $candidate->isMembership() && $candidate->state==='consumed'){
        if($scope!==null){throw new RuntimeException('project_execution_membership_lease_ambiguous');}$scope=$candidate;
      }
    }
    if($scope===null){throw new RuntimeException('project_execution_native_membership_lease_required');}
    $scope->assertMembershipLease();
    $delta=$scope->getMembershipDelta();
    $subjects=$scope->isMembershipAddition()?$delta['added']:$delta['removed'];
    if($scope->membershipEdgeEditor!==null || !$subjects || count($adds)+count($removes)!==2*count($subjects) ||
      ($scope->isMembershipAddition() && $removes) || (!$scope->isMembershipAddition() && $adds)){
      throw new RuntimeException('project_execution_membership_edge_batch_unsupported');
    }
    $seen=array();$project=$scope->object->getPHID();
    foreach(array_merge($adds,$removes) as $edge){
      $direct=$edge['type']===PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
      $inverse=$edge['type']===PhabricatorProjectMemberOfProjectEdgeType::EDGECONST;
      $subject=$direct?$edge['dst']:$edge['src'];$key=$edge['type'].':'.$subject;
      if((!$direct && !$inverse) || !in_array($subject,$subjects,true) ||
        $edge['src']!==($direct?$project:$subject) || $edge['dst']!==($direct?$subject:$project) ||
        $edge['src_type']!==($direct?'PROJ':'USER') || $edge['dst_type']!==($direct?'USER':'PROJ') ||
        $edge['data']!==array() || isset($seen[$key])){
        throw new RuntimeException('project_execution_membership_edge_batch_unsupported');
      }
      $seen[$key]=true;
    }
    $id=spl_object_id($editor);$scope->membershipEdgeEditor=$id;
    self::$membershipEdgeWrites[$id]=array(WeakReference::create($editor),WeakReference::create($scope));
  }
  public static function membershipEdgeWriteContext(PhabricatorEdgeEditor $editor,$source_type) {
    $entry=self::$membershipEdgeWrites[spl_object_id($editor)]??null;
    if($entry===null){return null;}
    $scope=$entry[1]->get();
    if($entry[0]->get()!==$editor || $scope===null){throw new RuntimeException('project_execution_native_membership_lease_required');}
    $scope->assertMembershipLease();
    if($source_type==='PROJ'){$database=(new PhabricatorProject())->getAphrontRefDatabaseName();}
    else if($source_type==='USER'){$database=$scope->lockInverseMembership();}
    else{throw new RuntimeException('project_execution_membership_edge_source_unsupported');}
    return array('connection'=>$scope->connection,'table'=>qsprintf($scope->connection,'%T.%T',$database,'edge'));
  }
  private function finishMembership(array $xactions) {
    $this->assertMembershipLease();
    $rows=$this->boundedRows(new PhabricatorProject(),qsprintf($this->connection,'id=%d',$this->object->getID()),1,true);
    $row=$rows?head($rows):null;
    if($row!==null){$row['dateModified']=$this->oldRow['dateModified'];}
    if($row===null || self::digest($row)!==self::digest($this->oldRow)){
      throw new RuntimeException('project_execution_unplanned_membership_target_change');
    }
    foreach(array(PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,
      PhabricatorProjectMaterializedMemberEdgeType::EDGECONST) as $type){
      $actual=queryfx_all($this->connection,'SELECT dst FROM edge WHERE src=%s AND type=%d ORDER BY dst LIMIT 257 LOCK IN SHARE MODE',$this->object->getPHID(),$type);
      if(ipull($actual,'dst')!==$this->expectedMembers){throw new RuntimeException('project_execution_membership_native_postcondition_failed');}
    }
    $this->lockInverseMembership($this->expectedMembers);
    if(count($xactions)>1 || ($xactions && $this->membershipEdgeEditor===null)){
      throw new RuntimeException('project_execution_membership_native_record_required');
    }
    foreach($xactions as $xaction){
      $native=queryfx_one($this->connection,'SELECT * FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorProjectTransaction(),$xaction->getPHID());
      if(!$native || $native['objectPHID']!==$this->object->getPHID() || $native['authorPHID']!==$this->snapshot->getUserPHID() ||
        $native['transactionType']!==PhabricatorTransactions::TYPE_EDGE){throw new RuntimeException('project_execution_membership_native_record_required');}
      $record=(new PhabricatorProjectTransaction())->loadFromArray($native);
      $old=$record->getOldValue();$new=$record->getNewValue();
      if(!is_array($old) || !is_array($new)){throw new RuntimeException('project_execution_membership_native_record_required');}
      // Native storage deliberately saves the slim removed/added lists while
      // keeping full edge dictionaries on the in-memory transaction object.
      if(!array_is_list($old) || !array_is_list($new)){
        throw new RuntimeException('project_execution_membership_native_record_required');
      }
      $change=PhabricatorEdgeChangeRecord::newFromTransaction($record);
      $removed=$change->getRemovedPHIDs();$added=$change->getAddedPHIDs();
      $expected_removed=array_values(array_diff($this->initialMembers,$this->expectedMembers));
      $expected_added=array_values(array_diff($this->expectedMembers,$this->initialMembers));
      sort($removed,SORT_STRING);sort($added,SORT_STRING);sort($expected_removed,SORT_STRING);sort($expected_added,SORT_STRING);
      if($removed!==$expected_removed || $added!==$expected_added ||
        $record->getMetadataValue('edge:type')!==PhabricatorProjectProjectHasMemberEdgeType::EDGECONST){
        throw new RuntimeException('project_execution_membership_native_record_required');
      }
    }
    if($this->hasMemberIntent()){PhorgeProjectPolicyApprovalStore::forScope($this)->finishNative($this->approvalID,$xactions);}
  }
  public function getIntent() { return $this->intent; }
  public function getSnapshot() { return $this->snapshot; }
  public function getActor() { return $this->actor; }
  public function getObject() { return $this->object; }
  public function getMode() { return $this->mode; }
  public function getNewPolicy() { return $this->newPolicy; }
  public function getSources() { return $this->sources; }
  public function getApprovalID() { return $this->approvalID; }
  public function assertOpen() {
    if (!self::isSourceConnection($this->connection)) { throw new RuntimeException('project_execution_scope_ended'); }
    if ($this->configuration!==null) { $this->configuration->assertCurrent(); }
    PhorgeGovernanceNativeIdentitySource::revalidate($this->snapshot,$this->connection);
  }
  public function commitService() {
    if (!in_array($this->mode,array('propose','approve','history'),true)) {
      throw new RuntimeException('project_execution_native_editor_commit_required');
    }
    $this->assertOpen(); $this->connection->saveTransaction(); $this->state='committed';
  }
  public function close() {
    if ($this->state==='closed') {
      if ($this->cleanupFailed) { throw new RuntimeException('project_execution_scope_cleanup_failed'); }
      return;
    }
    // No connection/lock existed on entry. Only this invocation's Project
    // connection stack and modes are owned here, including nested Error paths.
    $this->state='closed'; $failed=false;
    if($this->membershipEdgeEditor!==null){unset(self::$membershipEdgeWrites[$this->membershipEdgeEditor]);}
    if ($this->connection!==null) {
      foreach (array('read','write','transaction') as $kind) {
        try {
          $steps=0;
          while ($kind==='read'?$this->connection->isReadLocking():
              ($kind==='write'?$this->connection->isWriteLocking():$this->connection->isInsideTransaction())) {
            if (++$steps>64) { throw new RuntimeException('project_execution_scope_cleanup_failed'); }
            if ($kind==='read') { $this->connection->endReadLocking(); }
            else if ($kind==='write') { $this->connection->endWriteLocking(); }
            else { $this->connection->killTransaction(); }
          }
        } catch (Throwable $error) { $failed=true; }
      }
      unset(self::$active[spl_object_id($this->connection)]);
    }
    foreach ($this->cachePrior as $key=>$value) {
      try {
        if ($value===$this->cacheMissing) { $this->cache->deleteKeys(array($key)); }
        else { $this->cache->setKey($key,$value); }
      } catch (Throwable $error) { $failed=true; }
    }
    try { if ($this->fence!==null) { $this->fence->release(); } }
    catch (Throwable $error) { $failed=true; }
    $this->cleanupFailed=$failed;
    if ($failed) { throw new RuntimeException('project_execution_scope_cleanup_failed'); }
  }
  public function __destruct() { try { $this->close(); } catch (Throwable $ignored) {} }
}
