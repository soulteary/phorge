<?php

/**
 * Isolated transaction mechanics only. No authorization, enable, source lease
 * or restoration permit. Caller supplies an independent metadata observer.
 */
final class PhorgeRecoveryOwnedRRScope extends Phobject {
  private static $active;
  private $writer;
  private $ownerToken;
  private $observer;
  private $profile;
  private $identity;
  private $event;
  private $defaultIsolation;
  private $state='new';
  private $failureState='none';
  private $cleanupProved=false;
  private function __construct() {$this->ownerToken=new stdClass();}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('recovery_rr_serialization_refused');}
  public function __unserialize(array $value){throw new RuntimeException('recovery_rr_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'isolated-owned-RR-mechanics','state'=>$this->state);}

  public static function isolationPlan($value) {
    $plans=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if(!is_string($value)||!isset($plans[$value])){throw new RuntimeException('recovery_rr_isolation_unknown');}
    return$plans[$value];
  }

  /** Pure decoder only. Missing/changed events are unknown, never rollback. */
  public static function classifyEvent(array $rows,array $expected) {
    if(array_keys($expected)!==array('thread','event')||
      !is_string($expected['thread'])||!is_string($expected['event'])||
      !preg_match('/\A[1-9][0-9]{0,19}\z/D',$expected['thread'])||
      !preg_match('/\A[1-9][0-9]{0,19}\z/D',$expected['event'])){
      throw new RuntimeException('recovery_rr_event_unknown');}
    if(count($rows)!==1){return'unknown';}$row=$rows[0];
    if(array_keys($row)!==array('THREAD_ID','EVENT_ID','STATE','END_EVENT_ID','ISOLATION_LEVEL')||
      $row['THREAD_ID']!==$expected['thread']||$row['EVENT_ID']!==$expected['event']||
      $row['ISOLATION_LEVEL']!=='REPEATABLE READ'){return'unknown';}
    if($row['STATE']==='ACTIVE'&&$row['END_EVENT_ID']===null){return'active';}
    if(!is_string($row['END_EVENT_ID'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$row['END_EVENT_ID'])){return'unknown';}
    if($row['STATE']==='COMMITTED'){return'committed-observed';}
    if($row['STATE']==='ROLLED BACK'){return'rollback-observed';}
    return'unknown';
  }

  private static function depth(AphrontDatabaseConnection $writer) {
    return(new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState'))->invoke($writer)->getDepth();
  }
  private function observe() {
    return queryfx_all($this->observer,'SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,'.
      'CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,CAST(e.END_EVENT_ID AS CHAR) END_EVENT_ID,e.ISOLATION_LEVEL '.
      'FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t '.
      'ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=%s',$this->identity['writer_id']);
  }
  private function observationState() {
    return self::classifyEvent($this->observe(),$this->event);
  }
  private function assertIdentity() {
    $writer=queryfx_one($this->writer,'SELECT CAST(CONNECTION_ID() AS CHAR) writer_id,'.
      '@@server_uuid uuid,DATABASE() db,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy');
    $observer=queryfx_one($this->observer,'SELECT CAST(CONNECTION_ID() AS CHAR) observer_id,'.
      '@@server_uuid uuid,CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy');
    if(!$writer||!$observer||array_keys($writer)!==array('writer_id','uuid','db','principal','role','proxy')||
      array_keys($observer)!==array('observer_id','uuid','principal','role','proxy')||
      !is_string($writer['writer_id'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$writer['writer_id'])||
      !is_string($observer['observer_id'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$observer['observer_id'])||
      !is_string($writer['principal'])||strlen($writer['principal'])>512||
      !is_string($observer['principal'])||strlen($observer['principal'])>512){
      throw new RuntimeException('recovery_rr_physical_identity_unknown');}
    $actual=array('writer_id'=>$writer['writer_id'],'writer_uuid'=>$writer['uuid'],
      'writer_db'=>$writer['db'],'writer_principal'=>$writer['principal'],
      'observer_id'=>$observer['observer_id'],'observer_uuid'=>$observer['uuid'],
      'observer_principal'=>$observer['principal']);
    if($writer['role']!=='NONE'||$observer['role']!=='NONE'||$writer['proxy']!==null||$observer['proxy']!==null||
      $writer['uuid']!==$observer['uuid']||$writer['writer_id']===$observer['observer_id']||
      !is_string($writer['uuid'])||!preg_match('/\A[a-f0-9-]{36}\z/D',$writer['uuid'])||
      $writer['db']!==$this->profile['namespace'].'_user'||
      ($this->identity!==null&&$this->identity!==$actual)){
      throw new RuntimeException('recovery_rr_physical_identity_unknown');}
    $this->identity=$actual;
  }

  public static function begin(AphrontDatabaseConnection $observer) {
    if(PhabricatorEnv::getEnvConfig('governance.account-recovery.owned-rr-scope.enabled')!==true){
      throw new RuntimeException('recovery_rr_scope_disabled');}
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    $writer=(new PhabricatorUser())->establishConnection('w');
    if(get_class($writer)!==AphrontMySQLiDatabaseConnection::class||get_class($observer)!==AphrontMySQLiDatabaseConnection::class||
      $observer===$writer||$writer->isInsideTransaction()||$writer->isReadLocking()||$writer->isWriteLocking()){
      throw new RuntimeException('recovery_rr_outer_transaction_refused');}
    if(self::$active===null){self::$active=new WeakMap();}
    if(isset(self::$active[$writer])){throw new RuntimeException('recovery_rr_writer_quarantined');}
    $scope=new self();$scope->writer=$writer;$scope->observer=$observer;$scope->profile=$profile;
    $scope->assertIdentity();
    $default=queryfx_one($writer,'SELECT @@SESSION.transaction_isolation level')['level'];
    self::isolationPlan($default);$scope->defaultIsolation=$default;
    $queued=false;$attempted=false;
    try{
      // Raw untracked outer transactions refuse here (native MySQL1568).
      queryfx($writer,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$queued=true;
      self::$active[$writer]=$scope->ownerToken;$attempted=true;
      $writer->openTransaction();$queued=false;$scope->state='active';
      $rows=$scope->observe();
      if(count($rows)!==1){throw new RuntimeException('recovery_rr_event_unknown');}
      $scope->event=array('thread'=>$rows[0]['THREAD_ID'],'event'=>$rows[0]['EVENT_ID']);
      PhorgeRecoveryRestoreProfile::assertOwnedWriter($writer);
      $scope->assertActive();return$scope;
    }catch(Throwable$error){
      // Never infer physical rollback from the native bookkeeping depth.
      if($attempted){$scope->recoverFailure();}
      if($queued){try{queryfx($writer,self::isolationPlan($default));}catch(Throwable$reset_error){/* Active/unknown transaction stays quarantined; preserve primary. */}}
      throw$error;
    }
  }

  private function ownsWriter() {
    return $this->ownerToken!==null&&self::$active!==null&&isset(self::$active[$this->writer])&&
      self::$active[$this->writer]===$this->ownerToken;
  }
  private function releaseOwnedWriter() {
    if(!$this->ownsWriter()){throw new RuntimeException('recovery_rr_scope_owner_changed');}
    unset(self::$active[$this->writer]);
  }
  private function assertActive() {
    // Terminal/invalid reentry never changes state or invokes recovery.
    if($this->state!=='active'){throw new RuntimeException('recovery_rr_scope_not_active');}
    try{
      if(!$this->ownsWriter()||self::depth($this->writer)!==1||
        $this->writer!==(new PhabricatorUser())->establishConnection('w')||
        PhorgeRecoveryRestoreProfile::assertCurrent()!==$this->profile){
        throw new RuntimeException('recovery_rr_scope_changed');}
      $this->assertIdentity();
      if($this->observationState()!=='active'){throw new RuntimeException('recovery_rr_event_changed');}
      if(queryfx_one($this->writer,'SELECT @@SESSION.transaction_isolation level')['level']!==$this->defaultIsolation){
        throw new RuntimeException('recovery_rr_session_isolation_changed');}
    }catch(Throwable$error){$this->state='invalid';throw$error;}
  }
  private function recoverFailure() {
    if(in_array($this->state,array('committed','rolled-back'),true)){return;}
    if(!$this->ownsWriter()){
      $this->state='invalid';$this->failureState='ownership-changed';$this->cleanupProved=false;return;
    }
    $this->state='invalid';$this->failureState='unknown';
    try{
      if($this->event!==null){$this->failureState=$this->observationState();}
      if($this->failureState==='active'){
        $this->assertIdentity();
        // COMMIT decrements native depth before sending SQL. A guard failure
        // may leave the exact owned physical transaction active at depth0.
        if(self::depth($this->writer)===1){$this->writer->killTransaction();}
        elseif(self::depth($this->writer)===0){queryfx($this->writer,'ROLLBACK');}
        else{throw new RuntimeException('recovery_rr_depth_changed');}
        $this->failureState=$this->observationState();
      }
      $this->cleanupProved=$this->failureState==='rollback-observed'||$this->failureState==='committed-observed';
      if($this->cleanupProved){$this->releaseOwnedWriter();}
    }catch(Throwable$cleanup_error){$this->cleanupProved=false;}
    // Unknown stays quarantined. No retry/reconnect, forged receipt or global
    // closeAllConnections may turn an unknown outcome into success/denial.
  }

  public function getFacts() {
    $this->assertActive();
    return array('profile'=>'isolated-owned-native-User-RR-mechanics-v1',
      'same_native_writer_transaction_observed'=>true,'isolation'=>'REPEATABLE READ',
      'source_families_complete'=>false,'grant_lease_proved'=>false,
      'restoration_authorized'=>false,'native_enable_executed'=>false);
  }
  public function commit() {
    if($this->state!=='active'){throw new RuntimeException('recovery_rr_scope_not_active');}
    try{
      $this->assertActive();$this->writer->saveTransaction();
      if($this->observationState()!=='committed-observed'){
        throw new RuntimeException('recovery_rr_commit_outcome_unknown');}
      $this->state='committed';$this->cleanupProved=true;$this->releaseOwnedWriter();
    }catch(Throwable$error){$this->recoverFailure();throw$error;}
  }
  public function rollback() {
    if($this->state!=='active'){throw new RuntimeException('recovery_rr_scope_not_active');}
    try{
      $this->assertActive();$this->writer->killTransaction();
      if($this->observationState()!=='rollback-observed'){
        throw new RuntimeException('recovery_rr_rollback_outcome_unknown');}
      $this->state='rolled-back';$this->cleanupProved=true;$this->releaseOwnedWriter();
    }catch(Throwable$error){$this->recoverFailure();throw$error;}
  }
  /** Caller finally path; no reauthorization or transaction recreation. */
  public function abort() {
    if($this->state==='active'||$this->state==='invalid'){$this->recoverFailure();}
    return$this->getTerminalFacts();
  }
  public function getTerminalFacts() {
    return array('state'=>$this->state,'failure_physical_state'=>$this->failureState,
      'cleanup_proved'=>$this->cleanupProved,'restoration_authorized'=>false,
      'outcome_is_authorization'=>false);
  }
}
