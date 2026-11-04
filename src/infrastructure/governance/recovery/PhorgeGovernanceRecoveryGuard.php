<?php

/** Opt-in native ORM fence. No restoration grant exists in this first slice. */
final class PhorgeGovernanceRecoveryGuard extends Phobject {
  private $user;
  private $connection;
  private $before;
  private $owned=false;
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('recovery_scope_serialization_refused');}
  public function __unserialize(array $value){throw new RuntimeException('recovery_scope_serialization_refused');}
  public function __debugInfo(){return array('scope'=>'isolated-account-restoration-fence');}
  public static function isEmergencyDisabled(PhabricatorUser $user){
    // A final disabled account cannot gain authentication eligibility here.
    // Never add a recovery table/configuration dependency to emergency disable.
    // User's public getter casts arbitrary raw strings to bool, whereas Lisk
    // serializes the actual property. Only a canonical raw1 may bypass the
    // fence; permissive MySQL must not coerce a truthy '0.0' into enabled0.
    $raw=(new ReflectionProperty(PhabricatorUser::class,'isDisabled'))->getValue($user);
    return in_array($raw,array(true,1,'1'),true);
  }
  public static function enabled(){
    $flag=PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled');
    if(!is_bool($flag)){throw new RuntimeException('recovery_feature_flag_unknown');}
    return $flag;
  }
  public static function state(PhabricatorUser $user){
    $state=array('userPHID'=>$user->getPHID());
    foreach(array('isApproved','isDisabled','isSystemAgent','isMailingList')as$key){
      $state[$key]=(new ReflectionProperty(PhabricatorUser::class,$key))->getValue($user);
    }
    return $state;
  }
  public static function begin(PhabricatorUser $user,$mode){
    if(!self::enabled()||get_class($user)!==PhabricatorUser::class||
      !in_array($mode,array('UPDATE','INSERT','REPLACE'),true)){
      throw new RuntimeException('recovery_scope_unavailable');}
    // Adoption is still blocked. The real ORM integration is executable only
    // inside the same disposable native profile as the restoration service.
    // The final-disabled fast path in User.update remains independent of it.
    PhorgeRecoveryRestoreProfile::assertCurrent();
    $forced=new ReflectionProperty(LiskDAO::class,'forcedConnection');
    if($forced->getValue($user)!==null||PhabricatorEnv::getEnvConfig('cluster.databases')!==array()||
      LiskDAO::shouldIsolateAllLiskEffectsToCurrentProcess()||LiskDAO::shouldIsolateAllLiskEffectsToTransactions()){
      throw new RuntimeException('recovery_writer_profile_unsupported');}
    $conn=$user->establishConnection('w');
    if($conn!==(new PhabricatorUser())->establishConnection('w')||
      get_class($conn)!==AphrontMySQLiDatabaseConnection::class||
      $user->getAphrontRefDatabaseName()!==(new PhabricatorUser())->getAphrontRefDatabaseName()){
      throw new RuntimeException('recovery_writer_profile_unsupported');}
    $scope=new self();$scope->user=$user;$scope->connection=$conn;
    $queued=false;$restore=null;
    try{
      if(!$conn->isInsideTransaction()){
        $level=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation level');
        $levels=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
          'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
          'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
          'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        if(!$level||!isset($levels[$level['level']])){throw new RuntimeException('recovery_isolation_unknown');}
        $restore=$levels[$level['level']];
        // MySQL refuses this with1568 inside an untracked raw transaction.
        // Do not issue START TRANSACTION and implicitly commit its caller.
        queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$queued=true;
      }
      $conn->openTransaction();$scope->owned=true;
      $queued=false;
      PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
      // Acquire metadata lock before inspecting the physical storage engine.
      queryfx_one($conn,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$user);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
        $user->getAphrontRefDatabaseName(),$user->getTableName());
      if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_transactional_user_required');}
      $keys=queryfx_all($conn,'SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS '.
        'WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX',
        $user->getAphrontRefDatabaseName(),$user->getTableName());
      $unique=array();foreach($keys as$key){$unique[$key['INDEX_NAME']][]=$key['COLUMN_NAME'];}
      ksort($unique,SORT_STRING);
      if($unique!==array('PRIMARY'=>array('id'),'phid'=>array('phid'),'userName'=>array('userName'))){
        throw new RuntimeException('recovery_native_unique_profile_unknown');}
      if($mode==='UPDATE'){
        $row=queryfx_one($conn,'SELECT id,phid,isApproved,isDisabled,isSystemAgent,isMailingList FROM %R WHERE id=%d FOR UPDATE',$user,$user->getID());
        if(!$row||$row['phid']!==$user->getPHID()){throw new RuntimeException('recovery_subject_identity_changed');}
        $scope->before=self::rowState($row);
        self::requireNoIncrease($scope->before,self::state($user));
      }else{
        // Lisk's parent insert allocates AUX_PHID in willSaveObject, after
        // this fence. Allocate the same native identifier once before checking
        // victims; do not call willSaveObject twice or manufacture authority.
        if($mode==='INSERT'&&$user->getID()===null&&$user->getPHID()===null){
          $user->setPHID($user->generatePHID());
        }
        // MySQL REPLACE may delete by *any* native unique key. Never classify
        // an existing username/PHID/ID victim as fresh registration.
        $victims=queryfx_all($conn,'SELECT id,phid FROM %R WHERE id=%d OR phid=%s OR userName=%s ORDER BY id LIMIT 4 FOR UPDATE',
          $user,$user->getID()?:0,$user->getPHID()?:'',$user->getUsername());
        if($victims){throw new RuntimeException('recovery_existing_identity_insert_refused');}
        if($mode==='REPLACE'){throw new RuntimeException('recovery_replace_absence_fence_unimplemented');}
        $phid=PhorgeRecoveryStopRequest::user($user->getPHID());
        $prior=queryfx_one($conn,'SELECT id FROM %R WHERE objectPHID=%s LIMIT 1 LOCK IN SHARE MODE',
          new PhabricatorUserTransaction(),$phid);
        $stopped=queryfx_one($conn,'SELECT nativeTransactionID FROM recovery_stop '.
          'WHERE subjectPHID=%s LIMIT 1 LOCK IN SHARE MODE',$phid);
        if($prior||$stopped){throw new RuntimeException('recovery_historical_identity_insert_refused');}
        if($user->getID()!==null || !in_array($user->getIsApproved(),array(false,0,'0'),true) ||
          !in_array($user->getIsAdmin(),array(false,0,'0'),true) ||
          !in_array($user->getIsSystemAgent(),array(false,0,'0'),true) ||
          !in_array($user->getIsMailingList(),array(false,0,'0'),true)){
          throw new RuntimeException('recovery_initial_admission_unknown');
        }
        PhorgeGovernanceRecoveryTransition::describe(self::state($user),self::state($user));
      }
      return $scope;
    }catch(Throwable$error){$scope->close();throw$error;}
    finally{if($queued&&$restore!==null){queryfx($conn,$restore);}}
  }
  private static function rowState(array $row){
    return array('userPHID'=>$row['phid'],'isApproved'=>$row['isApproved'],
      'isDisabled'=>$row['isDisabled'],'isSystemAgent'=>$row['isSystemAgent'],
      'isMailingList'=>$row['isMailingList']);
  }
  public static function requireNoIncrease(array $before,array $after){
    $transition=PhorgeGovernanceRecoveryTransition::describe($before,$after);
    if($transition['needs_source_assessment']){
      // Includes first approval of a persisted unapproved account. Distinguish
      // that workflow in the future source/stop ledger; do not guess here.
      throw new RuntimeException('recovery_all_sources_unavailable');}
  }
  public function finish(){
    if(!$this->owned||!$this->connection->isInsideTransaction()||!self::enabled()){
      throw new RuntimeException('recovery_scope_changed');}
    $row=queryfx_one($this->connection,'SELECT id,phid,isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList FROM %R WHERE id=%d FOR UPDATE',$this->user,$this->user->getID());
    if(!$row||$row['phid']!==$this->user->getPHID()){throw new RuntimeException('recovery_subject_identity_changed');}
    if($this->before!==null){self::requireNoIncrease($this->before,self::rowState($row));}
    else {
      PhorgeGovernanceRecoveryTransition::describe(self::rowState($row),self::rowState($row));
      foreach(array('isAdmin','isApproved','isSystemAgent','isMailingList')as$key){
        if(!in_array($row[$key],array(0,'0'),true)){
          throw new RuntimeException('recovery_initial_admission_postcondition_failed');}
      }
    }
    $this->connection->saveTransaction();$this->owned=false;
  }
  public function close(){
    if($this->owned){$this->owned=false;$this->connection->killTransaction();}
  }
}
