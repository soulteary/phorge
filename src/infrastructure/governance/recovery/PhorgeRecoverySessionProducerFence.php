<?php

/** Neutral serialization of native session INSERTs, not an authentication grant. */
final class PhorgeRecoverySessionProducerFence extends Phobject {
  private $connection;
  private $session;
  private $profile;
  private $owned=false;
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('recovery_session_fence_serialization_refused');}
  public function __unserialize(array $data){throw new RuntimeException('recovery_session_fence_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'native-session-insert-serialization-only');}

  public static function begin(PhabricatorAuthSession $session,$mode) {
    if($mode!=='INSERT'||get_class($session)!==PhabricatorAuthSession::class||$session->getID()!==null){
      throw new RuntimeException('recovery_session_insert_profile_unknown');}
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    $subject=PhorgeRecoveryStopRequest::user($session->getUserPHID());
    if(!in_array($session->getType(),array(PhabricatorAuthSession::TYPE_WEB,PhabricatorAuthSession::TYPE_CONDUIT),true)||
      !is_string($session->getSessionKey())||!preg_match('/\A[a-f0-9]{64}\z/D',$session->getSessionKey())||
      !in_array($session->getIsPartial(),array(0,1,'0','1'),true)||
      !in_array($session->getSignedLegalpadDocuments(),array(0,1,'0','1'),true)){
      throw new RuntimeException('recovery_session_insert_profile_unknown');}
    foreach(array($session->getSessionStart(),$session->getSessionExpires())as$value){
      if((!is_int($value)&&!is_string($value))||!preg_match('/\A[0-9]{1,10}\z/D',(string)$value)||
        (int)$value>4294967295){throw new RuntimeException('recovery_session_insert_profile_unknown');}}
    $forced=new ReflectionProperty(LiskDAO::class,'forcedConnection');
    if($forced->getValue($session)!==null){throw new RuntimeException('recovery_session_writer_unknown');}
    $conn=$session->establishConnection('w');
    if($conn!==(new PhabricatorUser())->establishConnection('w')||
      get_class($conn)!==AphrontMySQLiDatabaseConnection::class||
      $conn->isReadLocking()||$conn->isWriteLocking()){
      throw new RuntimeException('recovery_session_writer_unknown');}
    if(self::$leaseActive!==null&&isset(self::$leaseActive[$conn])){
      throw new RuntimeException('recovery_session_lease_writer_reserved');}
    $scope=new self();$scope->connection=$conn;$scope->session=$session;$scope->profile=$profile;
    $queued=false;$restore=null;
    try {
      if(!$conn->isInsideTransaction()){
        $row=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation level');
        $levels=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
          'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
          'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
          'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        if(!$row||!isset($levels[$row['level']])){throw new RuntimeException('recovery_session_isolation_unknown');}
        $restore=$levels[$row['level']];
        queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$queued=true;
      }
      $conn->openTransaction();$scope->owned=true;$queued=false;
      PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
      foreach(array(new PhabricatorUser(),$session)as$table){
        queryfx_one($conn,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$table);
        $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES '.
          'WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$table->getAphrontRefDatabaseName(),$table->getTableName());
        if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_session_transactional_source_required');}
      }
      // Same ordering as the restoration service: User before credential rows.
      // Do not add eligibility rules or revoke anything: native session policy
      // remains in the existing authentication engine and current-source check.
      // The PHID lookup plans one native PRIMARY key; it grants no authority.
      // A covering secondary SHARE can coexist with a clustered-record X lock.
      // Current-lock the exact PRIMARY row, then revalidate its PHID under lock.
      $plan=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY phid=BINARY %s',
        new PhabricatorUser(),$subject);
      if(!$plan||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$plan['id'])||
        (int)$plan['id']>4294967295){throw new RuntimeException('recovery_session_subject_unknown');}
      $user=queryfx_one($conn,'SELECT id,phid FROM %R FORCE INDEX (PRIMARY) WHERE id=%d LOCK IN SHARE MODE',
        new PhabricatorUser(),(int)$plan['id']);
      if(!$user||(string)$user['id']!==(string)$plan['id']||$user['phid']!==$subject){
        throw new RuntimeException('recovery_session_subject_unknown');}
      return $scope;
    }catch(Throwable $error){$scope->close();throw$error;}
    finally{if($queued&&$restore!==null){queryfx($conn,$restore);}}
  }

  public function finish() {
    $conn=$this->connection;$session=$this->session;
    if(!$this->owned||!$conn->isInsideTransaction()||
      $conn!==$session->establishConnection('w')||PhorgeRecoveryRestoreProfile::assertCurrent()!==$this->profile){
      throw new RuntimeException('recovery_session_fence_changed');}
    $row=queryfx_one($conn,'SELECT phid,userPHID,type,sessionKey,sessionStart,sessionExpires,isPartial,highSecurityUntil,signedLegalpadDocuments '.
      'FROM %R WHERE id=%d LOCK IN SHARE MODE',$session,$session->getID());
    if(!$row||$row['phid']!==$session->getPHID()||$row['userPHID']!==$session->getUserPHID()||$row['type']!==$session->getType()||
      !is_string($row['sessionKey'])||!is_string($session->getSessionKey())||
      !hash_equals($row['sessionKey'],$session->getSessionKey())||
      (string)$row['sessionStart']!==(string)$session->getSessionStart()||
      (string)$row['sessionExpires']!==(string)$session->getSessionExpires()||
      (string)$row['isPartial']!==(string)$session->getIsPartial()||
      (string)$row['signedLegalpadDocuments']!==(string)$session->getSignedLegalpadDocuments()||
      ($session->getHighSecurityUntil()===null?$row['highSecurityUntil']!==null:
        (string)$row['highSecurityUntil']!==(string)$session->getHighSecurityUntil())){
      throw new RuntimeException('recovery_session_insert_postcondition_failed');}
    $conn->saveTransaction();$this->owned=false;
  }
  public function close(){if($this->owned){$this->owned=false;$this->connection->killTransaction();}}

  // Independent protected-capture lease; never adopted by the producer hook.

  private $leaseSubject;
  private $leaseSnapshot;
  /** Fixed selected producer only: no authentication or source-completeness grant. */
  private function leaseReadSessions() {
    $conn=$this->leaseWriter;$user=new PhabricatorUser();$session=new PhabricatorAuthSession();
    if($session->establishConnection('w')!==$conn){throw new RuntimeException('recovery_session_lease_route_changed');}
    foreach(array($user,$session)as$table){
      queryfx_one($conn,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$table);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
        $table->getAphrontRefDatabaseName(),$table->getTableName());
      if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_session_lease_engine_unknown');}
    }
    $plan=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY phid=BINARY %s',$user,$this->leaseSubject);
    if(!$plan||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$plan['id'])||(int)$plan['id']>4294967295){
      throw new RuntimeException('recovery_session_lease_subject_unknown');}
    // Exact clustered X lock conflicts with the unchanged real INSERT fence's
    // PRIMARY SHARE before any native Session INSERT, including an empty set.
    $locked=queryfx_one($conn,'SELECT id,phid FROM %R FORCE INDEX (PRIMARY) WHERE id=%d FOR UPDATE',$user,(int)$plan['id']);
    if(!$locked||(string)$locked['id']!==(string)$plan['id']||$locked['phid']!==$this->leaseSubject){
      throw new RuntimeException('recovery_session_lease_subject_unknown');}
    $writerGrants=$this->leaseReadGrants($conn,'writer',$this->leaseIdentity['writer_principal']);
    $observerGrants=$this->leaseReadGrants($this->leaseObserver,'observer',$this->leaseIdentity['observer_principal']);
    $lengths=queryfx_all($conn,'SELECT id,OCTET_LENGTH(phid)+OCTET_LENGTH(userPHID)+OCTET_LENGTH(type)+OCTET_LENGTH(sessionKey) row_bytes '.
      'FROM %R FORCE INDEX (key_identity) WHERE userPHID=%s ORDER BY id LIMIT 65 LOCK IN SHARE MODE',$session,$this->leaseSubject);
    if(count($lengths)>64){throw new RuntimeException('recovery_session_lease_budget');}$budget=0;
    foreach($lengths as$row){
      if(array_keys($row)!==array('id','row_bytes')||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['id'])||
        !is_numeric($row['row_bytes'])||!preg_match('/\A[0-9]{1,10}\z/D',(string)$row['row_bytes'])){
        throw new RuntimeException('recovery_session_lease_row_unknown');}
      $budget+=(int)$row['row_bytes']+128;if($budget>65536){throw new RuntimeException('recovery_session_lease_budget');}
    }
    $rows=queryfx_all($conn,'SELECT id,phid,userPHID,type,sessionKey,sessionStart,sessionExpires,isPartial,highSecurityUntil,signedLegalpadDocuments '.
      'FROM %R FORCE INDEX (key_identity) WHERE userPHID=%s ORDER BY id LIMIT 65 LOCK IN SHARE MODE',$session,$this->leaseSubject);
    if(count($rows)!==count($lengths)||count($rows)>64){throw new RuntimeException('recovery_session_lease_budget');}$bytes=0;$ids=array();
    foreach($rows as$i=>$row){
      if((string)$row['id']!==(string)$lengths[$i]['id']){throw new RuntimeException('recovery_session_lease_row_unknown');}
      if(array_keys($row)!==array('id','phid','userPHID','type','sessionKey','sessionStart','sessionExpires','isPartial','highSecurityUntil','signedLegalpadDocuments')||
        !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['id'])||isset($ids[(string)$row['id']])||$row['userPHID']!==$this->leaseSubject){
        throw new RuntimeException('recovery_session_lease_row_unknown');}
      foreach($row as$value){if($value!==null&&!is_string($value)&&!is_int($value)){throw new RuntimeException('recovery_session_lease_row_unknown');}}
      if((int)$row['id']>4294967295||!is_string($row['phid'])||!preg_match('/\APHID-[A-Z]{4}-[a-z0-9]{20}\z/D',$row['phid'])){throw new RuntimeException('recovery_session_lease_row_unknown');}
      $ids[(string)$row['id']]=true;
      if(!in_array($row['type'],array('web','conduit'),true)||!is_string($row['sessionKey'])||!preg_match('/\A[a-f0-9]{64}\z/D',$row['sessionKey'])||
        !in_array($row['isPartial'],array(0,1,'0','1'),true)||!in_array($row['signedLegalpadDocuments'],array(0,1,'0','1'),true)){
        throw new RuntimeException('recovery_session_lease_row_unknown');}
      foreach(array('sessionStart','sessionExpires')as$key){if(!preg_match('/\A[0-9]{1,10}\z/D',(string)$row[$key])||(int)$row[$key]>4294967295){throw new RuntimeException('recovery_session_lease_row_unknown');}}
      if($row['highSecurityUntil']!==null&&(!preg_match('/\A[0-9]{1,10}\z/D',(string)$row['highSecurityUntil'])||(int)$row['highSecurityUntil']>4294967295)){throw new RuntimeException('recovery_session_lease_row_unknown');}
      foreach($row as$value){$bytes+=strlen((string)$value);}
      if($bytes>65536){throw new RuntimeException('recovery_session_lease_budget');}
    }
    return array('subject'=>$locked,'sessions_digest'=>hash('sha256',serialize($rows)),
      'session_count'=>count($rows),'writer_grants_digest'=>$writerGrants['source_digest'],'observer_grants_digest'=>$observerGrants['source_digest']);
  }

  /** Fixed native fixture grant syntax, never a privilege/absence lease. */
  public static function leaseDescribeGrants(array $grants,$role,$principal,$namespace) {
    if(!in_array($role,array('writer','observer'),true)||!is_string($namespace)||
      !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)||!is_string($principal)||
      !preg_match($role==='writer'?'/\Agov_c_writer_[a-f0-9]{12}@%\z/D':'/\Agov_c_metadata_[a-f0-9]{16}@%\z/D',$principal)||
      !$grants||count($grants)>66){throw new RuntimeException('recovery_session_lease_grants_unknown');}
    $user=substr($principal,0,-2);$recipient=' TO `'.$user.'`@`%`';$seen=array();$usage=0;$selected=false;$guard=false;$metadata=false;$process=false;$performance=false;
    $source=file_get_contents(dirname(__DIR__,4).'/resources/sql/quickstart.sql');
    if(!is_string($source)||strlen($source)>1048576){throw new RuntimeException('recovery_session_lease_grants_unknown');}
    preg_match_all('/^CREATE DATABASE .*`\{\$NAMESPACE\}_([a-z_]+)`/m',$source,$matches);
    $apps=$matches[1];if(count($apps)<2||count($apps)>64||count($apps)!==count(array_unique($apps))){throw new RuntimeException('recovery_session_lease_grants_unknown');}
    $allowed=array();foreach($apps as$app){$db=$namespace.'_'.$app;$allowed['`'.str_replace('_','\\_',$db).'`.*']=$db;}
    $allowed['`'.str_replace('_','\\_',$namespace.'_governance').'`.*']=$namespace.'_governance';
    foreach($grants as$entry){
      if(!is_string($entry)||strlen($entry)>4096||preg_match('/[\x00-\x1f\x7f]/',$entry)||!str_ends_with($entry,$recipient)||isset($seen[$entry])){
        throw new RuntimeException('recovery_session_lease_grants_unknown');}
      $seen[$entry]=true;$body=substr($entry,0,-strlen($recipient));
      if($body==='GRANT USAGE ON *.*'){if(++$usage!==1){throw new RuntimeException('recovery_session_lease_grants_unknown');}continue;}
      if(!preg_match('/\AGRANT ([A-Z, ]+) ON (.+)\z/D',$body,$m)){throw new RuntimeException('recovery_session_lease_grants_unknown');}
      $privileges=explode(', ',$m[1]);sort($privileges,SORT_STRING);
      if(count($privileges)!==count(array_unique($privileges))){throw new RuntimeException('recovery_session_lease_grants_unknown');}
      if($role==='observer'){
        // Minimum separate observer: global PROCESS and schema-limited P_S
        // SELECT. Also recognize the already owned legacy global profiles.
        if($m[2]==='*.*'&&$privileges===array('PROCESS')&&!$process&&!$metadata){$process=true;continue;}
        if($m[2]==='`performance\\_schema`.*'&&$privileges===array('SELECT')&&!$performance&&!$metadata){$performance=true;continue;}
        if($m[2]!=='*.*'||$metadata||$process||$performance||!in_array($privileges,array(array('PROCESS','SELECT'),array('PROCESS','SELECT','TRIGGER')),true)){
          throw new RuntimeException('recovery_session_lease_grants_unknown');}
        $metadata=true;continue;
      }
      if(!isset($allowed[$m[2]])||isset($seen['db:'.$m[2]])){throw new RuntimeException('recovery_session_lease_grants_unknown');}
      $seen['db:'.$m[2]]=true;$db=$allowed[$m[2]];
      if($privileges!==($db===$namespace.'_governance'?array('SELECT'):array('DELETE','INSERT','SELECT','UPDATE'))){
        throw new RuntimeException('recovery_session_lease_grants_unknown');}
      $selected=$selected||$db===$namespace.'_user';$guard=$guard||$db===$namespace.'_governance';
    }
    if(($role==='writer'&&($usage!==1||!$selected||!$guard))||($role==='observer'&&!$metadata&&!($process&&$performance))){
      throw new RuntimeException('recovery_session_lease_grants_unknown');}
    $canonical=$grants;sort($canonical,SORT_STRING);
    return array('profile'=>$role==='writer'?'owned-native-fixture-scoped-CRUD-v1':'independent-observer-PS-SELECT-PROCESS-v2',
      'source_digest'=>hash('sha256',serialize($canonical)),'writer_global_privileges_required'=>false,
      'grant_absence_lease_proved'=>false,'restoration_authorized'=>false);
  }
  private function leaseReadGrants(AphrontDatabaseConnection $conn,$role,$principal) {
    $rows=queryfx_all($conn,'SHOW GRANTS FOR CURRENT_USER()');$grants=array();
    foreach($rows as$row){if(count($row)!==1){throw new RuntimeException('recovery_session_lease_grants_unknown');}$grants[]=reset($row);}
    return self::leaseDescribeGrants($grants,$role,$principal,$this->leaseProfile['namespace']);
  }

  private static $leaseActive;
  private $leaseWriter;
  private $leaseOwnerToken;
  private $leaseObserver;
  private $leaseProfile;
  private $leaseIdentity;
  private $leaseEvent;
  private $leaseDefaultIsolation;
  private $leaseState='new';
  private $leaseFailureState='none';
  private $leaseCleanupProved=false;
  public static function leaseIsolationPlan($value) {
    $plans=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if(!is_string($value)||!isset($plans[$value])){throw new RuntimeException('recovery_session_lease_isolation_unknown');}
    return$plans[$value];
  }

  /** Pure decoder only. Missing/changed events are unknown, never rollback. */
  public static function leaseClassifyEvent(array $rows,array $expected) {
    if(array_keys($expected)!==array('thread','event')||
      !is_string($expected['thread'])||!is_string($expected['event'])||
      !preg_match('/\A[1-9][0-9]{0,19}\z/D',$expected['thread'])||
      !preg_match('/\A[1-9][0-9]{0,19}\z/D',$expected['event'])){
      throw new RuntimeException('recovery_session_lease_event_unknown');}
    if(count($rows)!==1){return'unknown';}$row=$rows[0];
    if(array_keys($row)!==array('THREAD_ID','EVENT_ID','STATE','END_EVENT_ID','ISOLATION_LEVEL')||
      $row['THREAD_ID']!==$expected['thread']||$row['EVENT_ID']!==$expected['event']||
      $row['ISOLATION_LEVEL']!=='READ COMMITTED'){return'unknown';}
    if($row['STATE']==='ACTIVE'&&$row['END_EVENT_ID']===null){return'active';}
    if(!is_string($row['END_EVENT_ID'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$row['END_EVENT_ID'])){return'unknown';}
    if($row['STATE']==='COMMITTED'){return'committed-observed';}
    if($row['STATE']==='ROLLED BACK'){return'rollback-observed';}
    return'unknown';
  }

  private static function leaseDepth(AphrontDatabaseConnection $writer) {
    return(new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState'))->invoke($writer)->getDepth();
  }
  private function leaseObserve() {
    return queryfx_all($this->leaseObserver,'SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,'.
      'CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,CAST(e.END_EVENT_ID AS CHAR) END_EVENT_ID,e.ISOLATION_LEVEL '.
      'FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t '.
      'ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=%s',$this->leaseIdentity['writer_id']);
  }
  private function leaseObservationState() {
    return self::leaseClassifyEvent($this->leaseObserve(),$this->leaseEvent);
  }
  private function leaseAssertIdentity() {
    $writer=queryfx_one($this->leaseWriter,'SELECT CAST(CONNECTION_ID() AS CHAR) writer_id,'.
      '@@server_uuid uuid,DATABASE() db,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy');
    $observer=queryfx_one($this->leaseObserver,'SELECT CAST(CONNECTION_ID() AS CHAR) observer_id,'.
      '@@server_uuid uuid,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy');
    if(!$writer||!$observer||array_keys($writer)!==array('writer_id','uuid','db','principal','role','proxy')||
      array_keys($observer)!==array('observer_id','uuid','principal','role','proxy')||
      !is_string($writer['writer_id'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$writer['writer_id'])||
      !is_string($observer['observer_id'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$observer['observer_id'])||
      !is_string($writer['principal'])||strlen($writer['principal'])>512||
      !is_string($observer['principal'])||strlen($observer['principal'])>512){
      throw new RuntimeException('recovery_session_lease_physical_identity_unknown');}
    $actual=array('writer_id'=>$writer['writer_id'],'writer_uuid'=>$writer['uuid'],
      'writer_db'=>$writer['db'],'writer_principal'=>$writer['principal'],
      'observer_id'=>$observer['observer_id'],'observer_uuid'=>$observer['uuid'],
      'observer_principal'=>$observer['principal']);
    if($writer['role']!=='NONE'||$observer['role']!=='NONE'||$writer['proxy']!==null||$observer['proxy']!==null||
      $writer['uuid']!==$observer['uuid']||$writer['writer_id']===$observer['observer_id']||
      !is_string($writer['uuid'])||!preg_match('/\A[a-f0-9-]{36}\z/D',$writer['uuid'])||
      $writer['db']!==$this->leaseProfile['namespace'].'_user'||
      ($this->leaseIdentity!==null&&$this->leaseIdentity!==$actual)){
      throw new RuntimeException('recovery_session_lease_physical_identity_unknown');}
    $this->leaseIdentity=$actual;
  }

  public static function beginLease(AphrontDatabaseConnection $observer,$subject) {
    if(PhabricatorEnv::getEnvConfig('governance.account-recovery.session-lease.enabled')!==true){
      throw new RuntimeException('recovery_session_lease_scope_disabled');}
    $subject=PhorgeRecoveryStopRequest::user($subject);
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    $writer=(new PhabricatorUser())->establishConnection('w');
    if(get_class($writer)!==AphrontMySQLiDatabaseConnection::class||get_class($observer)!==AphrontMySQLiDatabaseConnection::class||
      $observer===$writer||$observer->isInsideTransaction()||$observer->isReadLocking()||$observer->isWriteLocking()||$writer->isInsideTransaction()||$writer->isReadLocking()||$writer->isWriteLocking()){
      throw new RuntimeException('recovery_session_lease_outer_transaction_refused');}
    if(self::$leaseActive===null){self::$leaseActive=new WeakMap();}
    if(isset(self::$leaseActive[$writer])){throw new RuntimeException('recovery_session_lease_writer_quarantined');}
    $scope=new self();$scope->leaseOwnerToken=new stdClass();$scope->leaseSubject=$subject;$scope->leaseWriter=$writer;$scope->leaseObserver=$observer;$scope->leaseProfile=$profile;
    $scope->leaseAssertIdentity();
    $default=queryfx_one($writer,'SELECT @@SESSION.transaction_isolation level')['level'];
    self::leaseIsolationPlan($default);$scope->leaseDefaultIsolation=$default;
    $queued=false;$attempted=false;
    try{
      // Raw untracked outer transactions refuse here (native MySQL1568).
      queryfx($writer,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$queued=true;
      self::$leaseActive[$writer]=$scope->leaseOwnerToken;$attempted=true;
      $writer->openTransaction();$queued=false;$scope->leaseState='active';
      $rows=$scope->leaseObserve();
      if(count($rows)!==1){throw new RuntimeException('recovery_session_lease_event_unknown');}
      $scope->leaseEvent=array('thread'=>$rows[0]['THREAD_ID'],'event'=>$rows[0]['EVENT_ID']);
      PhorgeRecoveryRestoreProfile::assertOwnedWriter($writer);
      $scope->leaseAssertActive();$scope->leaseSnapshot=$scope->leaseReadSessions();$scope->leaseAssertActive();return$scope;
    }catch(Throwable$error){
      // Never infer physical rollback from the native bookkeeping depth.
      if($attempted){$scope->leaseRecoverFailure();}
      if($queued){try{queryfx($writer,self::leaseIsolationPlan($default));}catch(Throwable$reset_error){/* Active/unknown transaction stays quarantined; preserve primary. */}}
      throw$error;
    }
  }

  private function leaseOwnsWriter() {
    return $this->leaseOwnerToken!==null&&self::$leaseActive!==null&&isset(self::$leaseActive[$this->leaseWriter])&&
      self::$leaseActive[$this->leaseWriter]===$this->leaseOwnerToken;
  }
  private function leaseReleaseOwnedWriter() {
    if(!$this->leaseOwnsWriter()){throw new RuntimeException('recovery_session_lease_scope_owner_changed');}
    unset(self::$leaseActive[$this->leaseWriter]);
  }
  private function leaseAssertActive() {
    // Terminal/invalid reentry never changes state or invokes recovery.
    if($this->leaseState!=='active'){throw new RuntimeException('recovery_session_lease_scope_not_active');}
    try{
      if(!$this->leaseOwnsWriter()||self::leaseDepth($this->leaseWriter)!==1||
        $this->leaseWriter!==(new PhabricatorUser())->establishConnection('w')||
        PhorgeRecoveryRestoreProfile::assertCurrent()!==$this->leaseProfile){
        throw new RuntimeException('recovery_session_lease_scope_changed');}
      $this->leaseAssertIdentity();
      if($this->leaseObservationState()!=='active'){throw new RuntimeException('recovery_session_lease_event_changed');}
      if(queryfx_one($this->leaseWriter,'SELECT @@SESSION.transaction_isolation level')['level']!==$this->leaseDefaultIsolation){
        throw new RuntimeException('recovery_session_lease_session_isolation_changed');}
    }catch(Throwable$error){$this->leaseState='invalid';throw$error;}
  }
  private function leaseRecoverFailure() {
    if(in_array($this->leaseState,array('committed','rolled-back'),true)||($this->leaseState==='invalid'&&$this->leaseCleanupProved)){return;}
    if(!$this->leaseOwnsWriter()){
      $this->leaseState='invalid';$this->leaseFailureState='ownership-changed';$this->leaseCleanupProved=false;return;
    }
    $this->leaseState='invalid';$this->leaseFailureState='unknown';
    try{
      if($this->leaseEvent!==null){$this->leaseFailureState=$this->leaseObservationState();}
      if($this->leaseFailureState==='active'){
        $this->leaseAssertIdentity();
        // COMMIT decrements native depth before sending SQL. A guard failure
        // may leave the exact owned physical transaction active at depth0.
        if(self::leaseDepth($this->leaseWriter)===1){$this->leaseWriter->killTransaction();}
        elseif(self::leaseDepth($this->leaseWriter)===0){queryfx($this->leaseWriter,'ROLLBACK');}
        else{throw new RuntimeException('recovery_session_lease_depth_changed');}
        $this->leaseFailureState=$this->leaseObservationState();
      }
      $this->leaseCleanupProved=$this->leaseFailureState==='rollback-observed'||$this->leaseFailureState==='committed-observed';
      if($this->leaseCleanupProved){$this->leaseReleaseOwnedWriter();}
    }catch(Throwable$cleanup_error){$this->leaseCleanupProved=false;}
    // Unknown stays quarantined. No retry/reconnect, forged receipt or global
    // closeAllConnections may turn an unknown outcome into success/denial.
  }

  public function leaseGetFacts() {
    try {
    $this->leaseAssertActive();
    $current=$this->leaseReadSessions();
    if($current!==$this->leaseSnapshot){throw new RuntimeException('recovery_session_lease_source_changed');}
    $this->leaseAssertActive();
    return array('profile'=>'isolated-native-AuthSession-INSERT-lease-RC-v2-scoped-writer-grants',
      'source_binding'=>hash('sha256',serialize(array($this->leaseProfile,$this->leaseSubject,$current))),
      'selected_native_INSERT_interval_protected'=>true,'same_native_writer_transaction_observed'=>true,
      'isolation'=>'READ COMMITTED','all_session_producers_closed'=>false,
      'grant_DDL_lease_proved'=>false,'durable_review_compatible'=>false,
      'all_sources_complete'=>false,'restoration_authorized'=>false,'execution_blocked'=>true);
    }catch(Throwable $error){if($this->leaseState==='active'){$this->leaseState='invalid';}throw$error;}
  }
  public function leaseCommit() {
    if($this->leaseState!=='active'){throw new RuntimeException('recovery_session_lease_scope_not_active');}
    try{
      $this->leaseGetFacts();$this->leaseWriter->saveTransaction();
      if($this->leaseObservationState()!=='committed-observed'){
        throw new RuntimeException('recovery_session_lease_commit_outcome_unknown');}
      $this->leaseState='committed';$this->leaseCleanupProved=true;$this->leaseReleaseOwnedWriter();
    }catch(Throwable$error){$this->leaseRecoverFailure();throw$error;}
  }
  public function leaseRollback() {
    if($this->leaseState!=='active'){throw new RuntimeException('recovery_session_lease_scope_not_active');}
    try{
      $this->leaseGetFacts();$this->leaseWriter->killTransaction();
      if($this->leaseObservationState()!=='rollback-observed'){
        throw new RuntimeException('recovery_session_lease_rollback_outcome_unknown');}
      $this->leaseState='rolled-back';$this->leaseCleanupProved=true;$this->leaseReleaseOwnedWriter();
    }catch(Throwable$error){$this->leaseRecoverFailure();throw$error;}
  }
  /** Caller finally path; no reauthorization or transaction recreation. */
  public function leaseAbort() {
    if($this->leaseState==='active'||$this->leaseState==='invalid'){$this->leaseRecoverFailure();}
    return$this->leaseGetTerminalFacts();
  }
  public function leaseGetTerminalFacts() {
    return array('state'=>$this->leaseState,'failure_physical_state'=>$this->leaseFailureState,
      'cleanup_proved'=>$this->leaseCleanupProved,'restoration_authorized'=>false,
      'outcome_is_authorization'=>false);
  }
}
