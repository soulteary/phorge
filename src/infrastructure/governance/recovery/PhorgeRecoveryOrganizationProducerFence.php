<?php

/** Serialize fixed native DAO producers against the existing directory mutex. */
final class PhorgeRecoveryOrganizationProducerFence extends Phobject {
  private $connection;
  private $profile;
  private $owned=false;
  private $ownedDepth;
  private $guardVersion;
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('recovery_org_fence_serialization_refused');}
  public function __unserialize(array $data){throw new RuntimeException('recovery_org_fence_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'native-organization-producer-serialization-only');}

  public static function tableForClass($class) {
    $tables=array(PhorgeOrganizationUnit::class=>'organization_unit',
      PhorgeOrganizationMember::class=>'organization_member',
      PhorgeOrganizationAppointment::class=>'organization_appointment',
      PhorgeOrganizationPrimaryDepartment::class=>'organization_primarydepartment');
    if(!is_string($class)||!isset($tables[$class])){throw new RuntimeException('recovery_org_producer_unknown');}
    return $tables[$class];
  }

  /** Canonical uint64 arithmetic, independent of platform signed integer size. */
  public static function nextGuardVersion($value) {
    $value=PhorgeRecoveryStopRequest::transactionID($value);
    if($value==='18446744073709551615'){
      throw new RuntimeException('recovery_org_producer_generation_exhausted');}
    $digits=str_split($value);$carry=1;
    for($i=count($digits)-1;$i>=0&&$carry;$i--){
      $n=ord($digits[$i])-48+$carry;$digits[$i]=chr(48+($n%10));$carry=intdiv($n,10);
    }
    if($carry){array_unshift($digits,'1');}
    return implode('',$digits);
  }

  public static function assertScalarModelData(PhorgeRecoveryOrganizationProducerDAO $object) {
    self::tableForClass(get_class($object));$bytes=0;
    foreach((new ReflectionClass($object))->getProperties()as$property){
      if($property->isStatic()||$property->getDeclaringClass()->getName()!==get_class($object)){continue;}
      $value=$property->getValue($object);
      if($value!==null&&!is_string($value)&&!is_int($value)&&!is_bool($value)){
        throw new RuntimeException('recovery_org_producer_scalar_required');}
      $bytes+=strlen((string)$value);
      if($bytes>4096){throw new RuntimeException('recovery_org_producer_row_budget');}
    }
  }

  public static function assertNativeScalarHooks(PhorgeRecoveryOrganizationProducerDAO $object) {
    self::tableForClass(get_class($object));
    if($object->getConfigOption(LiskDAO::CONFIG_TIMESTAMPS)!==false||
      !in_array($object->getConfigOption(LiskDAO::CONFIG_AUX_PHID),array(null,false),true)){
      throw new RuntimeException('recovery_org_producer_hooks_unknown');}
    foreach(array(LiskDAO::CONFIG_SERIALIZATION,PhabricatorLiskDAO::CONFIG_APPLICATION_SERIALIZERS)as$option){
      if(!in_array($object->getConfigOption($option),array(null,array()),true)){
        throw new RuntimeException('recovery_org_producer_hooks_unknown');}
    }
    foreach(array('willSaveObject'=>LiskDAO::class,'willWriteData'=>PhabricatorLiskDAO::class,
      'didWriteData'=>LiskDAO::class,'willDelete'=>LiskDAO::class,'didDelete'=>LiskDAO::class)as$hook=>$declaration){
      if((new ReflectionMethod($object,$hook))->getDeclaringClass()->getName()!==$declaration){
        throw new RuntimeException('recovery_org_producer_hooks_unknown');}
    }
    // Native PhabricatorLiskDAO caches serializers per concrete class. Also
    // reject a pre-existing nonempty cache rather than trusting only today's
    // Lisk metadata. Never invoke a serializer to test whether it is harmless.
    $static=(new ReflectionMethod(PhabricatorLiskDAO::class,'willWriteData'))->getStaticVariables();
    if(array_keys($static)!==array('custom')||!is_array($static['custom'])||
      (array_key_exists(get_class($object),$static['custom'])&&
       !in_array($static['custom'][get_class($object)],array(null,array()),true))){
      throw new RuntimeException('recovery_org_producer_hooks_unknown');}
  }

  public static function begin(PhorgeRecoveryOrganizationProducerDAO $object,$mode) {
    $table=self::tableForClass(get_class($object));
    if(!in_array($mode,array('INSERT','UPDATE','DELETE'),true)||$object->getTableName()!==$table||
      $object->getIDKey()!=='id'||($mode==='INSERT'?$object->getID()!==null:
        (!is_int($object->getID())&&!is_string($object->getID())))){
      throw new RuntimeException('recovery_org_producer_mode_unknown');}
    if($mode!=='INSERT'){PhorgeRecoveryStopRequest::transactionID($object->getID());}
    self::assertScalarModelData($object);
    self::assertNativeScalarHooks($object);
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    if((new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($object)!==null){
      throw new RuntimeException('recovery_org_producer_writer_unknown');}
    $conn=$object->establishConnection('w');
    if(get_class($conn)!==AphrontMySQLiDatabaseConnection::class||
      $conn!==(new PhabricatorUser())->establishConnection('w')||$conn->isReadLocking()||$conn->isWriteLocking()){
      throw new RuntimeException('recovery_org_producer_writer_unknown');}
    $scope=new self();$scope->connection=$conn;$scope->profile=$profile;
    $queued=false;$restore=null;
    try{
      if(!$conn->isInsideTransaction()){
        $level=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation level');
        $levels=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
          'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
          'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
          'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        if(!$level||!isset($levels[$level['level']])){throw new RuntimeException('recovery_org_producer_isolation_unknown');}
        $restore=$levels[$level['level']];
        // Reject a physically active raw START before claiming any transaction.
        queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$queued=true;
      }
      $conn->openTransaction();$scope->owned=true;$queued=false;$scope->ownedDepth=self::depth($conn);
      PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
      foreach(array('organization_guard',$table)as$name){
        queryfx_all($conn,'SELECT id FROM %T WHERE 1=0 LOCK IN SHARE MODE',$name);
        $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES '.
          'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name);
        if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_org_producer_schema_unknown');}
      }
      // No User/account/credential read is performed after this mutex. The four
      // final DAOs use only native scalar serialization/timestamps=false hooks.
      $guard=queryfx_one($conn,'SELECT id,version FROM organization_guard WHERE id=1 FOR UPDATE');
      if(!$guard||(string)$guard['id']!=='1'){throw new RuntimeException('recovery_org_producer_guard_missing');}
      $scope->guardVersion=PhorgeRecoveryStopRequest::transactionID($guard['version']);
      self::nextGuardVersion($scope->guardVersion);
      return $scope;
    }catch(Throwable$error){$scope->abort($error);}
    finally{if($queued&&$restore!==null){queryfx($conn,$restore);}}
  }

  public function finish() {
    if(!$this->owned||!$this->connection->isInsideTransaction()||
      self::depth($this->connection)!==$this->ownedDepth||
      PhorgeRecoveryRestoreProfile::assertCurrent()!==$this->profile){
      throw new RuntimeException('recovery_org_producer_scope_changed');}
    // The successful native write and its generation are one physical User
    // transaction, including a caller-owned outer transaction/savepoint.
    $next=self::nextGuardVersion($this->guardVersion);
    queryfx($this->connection,'UPDATE organization_guard SET version=version+1 '.
      'WHERE id=1 AND version=CAST(%s AS UNSIGNED) AND version<18446744073709551615',$this->guardVersion);
    if($this->connection->getAffectedRows()!==1){
      throw new RuntimeException('recovery_org_producer_generation_changed');}
    $row=queryfx_one($this->connection,'SELECT version FROM organization_guard WHERE id=1');
    if(!$row||PhorgeRecoveryStopRequest::transactionID($row['version'])!==$next){
      throw new RuntimeException('recovery_org_producer_generation_changed');}
    $this->connection->saveTransaction();$this->owned=false;
  }

  private static function depth(AphrontDatabaseConnection $connection) {
    return (new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState'))
      ->invoke($connection)->getDepth();
  }

  public function abort(Throwable $original) {
    try{$this->close();}
    catch(Throwable$cleanup){
      throw new PhutilAggregateException('recovery_org_producer_cleanup_failed',
        array('original'=>$original,'cleanup'=>$cleanup));
    }
    throw $original;
  }

  public function close() {
    if(!$this->owned){return;}$this->owned=false;
    $depth=self::depth($this->connection);
    if($depth===$this->ownedDepth){$this->connection->killTransaction();return;}
    // Native saveTransaction decrements depth before sending COMMIT. If that
    // send throws, only our own top-level transaction may be rolled back raw;
    // never underflow Lisk state or unwind a caller's unrelated savepoint.
    if($this->ownedDepth===1&&$depth===0){queryfx($this->connection,'ROLLBACK');return;}
    throw new RuntimeException('recovery_org_producer_cleanup_depth_changed');
  }
}
