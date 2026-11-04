<?php

/** Notice-only ABA generation. Never changes credential/recovery authority. */
final class PhorgeGovernanceNoticeIdentityScope extends Phobject {
  private $connection;
  private $user;
  private $before;
  private $owned=false;
  private $depth;
  private function __construct() {}
  private function __clone() {}
  public function __serialize(){throw new RuntimeException('notice_identity_scope_refused');}
  public function __unserialize(array $value){throw new RuntimeException('notice_identity_scope_refused');}
  public function __debugInfo(){return array('scope'=>'notice-only-native-identity-write');}

  /** Strict raw final state only: mirrors canReceive native User exclusions. */
  public static function definitelyIneligible(PhabricatorUser $user){
    foreach(array('isDisabled'=>true,'isApproved'=>false,'isAdmin'=>false,'isSystemAgent'=>true,'isMailingList'=>true)as$field=>$truth){
      $raw=(new ReflectionProperty(PhabricatorUser::class,$field))->getValue($user);
      if(in_array($raw,$truth?array(true,1,'1'):array(false,0,'0'),true)){return true;}
    }
    return false;
  }
  public static function fields(){return array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList','accountSecret','conduitCertificate');}
  public static function generation($value){
    if(is_int($value)){$value=(string)$value;}
    if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$value)||
      strlen($value)>20||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0)){
      throw new RuntimeException('notice_identity_generation_unknown');}
    return$value;
  }
  public static function nextGeneration($value){
    $value=self::generation($value);if($value==='18446744073709551615'){
      throw new RuntimeException('notice_identity_generation_exhausted');}
    $digits=str_split($value);$carry=1;
    for($i=count($digits)-1;$i>=0&&$carry;$i--){$next=(int)$digits[$i]+1;$digits[$i]=(string)($next%10);$carry=$next>9?1:0;}
    return($carry?'1':'').implode('',$digits);
  }
  private static function depth(AphrontDatabaseConnection $connection){
    return(new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState'))->invoke($connection)->getDepth();
  }
  private static function row(PhabricatorUser $user,AphrontDatabaseConnection $conn){
    $row=queryfx_one($conn,'SELECT id,phid,isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList,accountSecret,conduitCertificate '.
      'FROM %R FORCE INDEX (PRIMARY) WHERE id=%d FOR UPDATE',$user,$user->getID());
    if(!$row||$row['phid']!==$user->getPHID()||(string)$row['id']!==(string)$user->getID()){
      throw new RuntimeException('notice_identity_native_row_changed');}
    unset($row['id'],$row['phid']);return$row;
  }
  public static function begin(PhabricatorUser $user){
    if(!PhorgeGovernanceNoticeOutbox::enabled()||get_class($user)!==PhabricatorUser::class||!$user->getID()){
      throw new RuntimeException('notice_identity_scope_refused');}
    PhorgeGovernanceNoticeOutbox::assertRuntime();
    if((new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($user)!==null){
      throw new RuntimeException('notice_identity_forced_connection_refused');}
    $conn=$user->establishConnection('w');
    if($conn!==(new PhabricatorUser())->establishConnection('w')||get_class($conn)!==AphrontMySQLiDatabaseConnection::class){
      throw new RuntimeException('notice_identity_writer_refused');}
    $scope=new self();$scope->user=$user;$scope->connection=$conn;
    // Refuse raw untracked transactions without START implicitly committing them.
    $queued=false;$restore=null;
    try{
      if(!$conn->isInsideTransaction()){
        $default=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation level');
        $levels=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
          'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
          'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        if(!$default||!isset($levels[$default['level']])){throw new RuntimeException('notice_identity_isolation_unknown');}
        $restore=$levels[$default['level']];queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$queued=true;
      }
      $conn->openTransaction();$scope->owned=true;$queued=false;$scope->depth=self::depth($conn);
      PhorgeGovernanceNoticeOutbox::primary($conn,$user);
      foreach(array($user->getTableName(),'governance_notice_identityepoch')as$table){
        queryfx_all($conn,'SELECT 1 FROM %T WHERE 1=0 LOCK IN SHARE MODE',$table);
        $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
        if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('notice_identity_transactional_source_required');}
      }
      $scope->before=self::row($user,$conn);return$scope;
    }catch(Throwable$error){$scope->abort($error);}
    finally{if($queued&&$restore!==null){queryfx($conn,$restore);}}
  }
  /** Caller already holds the native User record while binding a notice. */
  public static function readGeneration(AphrontDatabaseConnection $conn,$phid){
    if(!$conn->isInsideTransaction()||$conn!==(new PhabricatorUser())->establishConnection('w')){
      throw new RuntimeException('notice_identity_read_transaction_required');}
    queryfx_all($conn,'SELECT 1 FROM governance_notice_identityepoch WHERE 1=0 LOCK IN SHARE MODE');
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','governance_notice_identityepoch');
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('notice_identity_transactional_source_required');}
    $row=queryfx_one($conn,'SELECT userPHID,generation FROM governance_notice_identityepoch WHERE userPHID=%s LOCK IN SHARE MODE',$phid);
    if($row&&$row['userPHID']!==$phid){throw new RuntimeException('notice_identity_generation_unknown');}
    $generation=self::generation($row?$row['generation']:'0');
    if($row&&$generation==='0'){throw new RuntimeException('notice_identity_generation_unknown');}
    return$generation;
  }
  public function finish(){
    $conn=$this->connection;
    if(!$this->owned||!$conn->isInsideTransaction()||self::depth($conn)!==$this->depth||
      $conn!==$this->user->establishConnection('w')||$conn!==(new PhabricatorUser())->establishConnection('w')||
      !PhorgeGovernanceNoticeOutbox::enabled()){
      throw new RuntimeException('notice_identity_scope_changed');}
    PhorgeGovernanceNoticeOutbox::assertRuntime();PhorgeGovernanceNoticeOutbox::primary($conn,$this->user);
    $after=self::row($this->user,$conn);
    if($after!==$this->before){
      $phid=$this->user->getPHID();$row=queryfx_one($conn,
        'SELECT userPHID,generation FROM governance_notice_identityepoch WHERE userPHID=%s FOR UPDATE',$phid);
      if($row&&$row['userPHID']!==$phid){throw new RuntimeException('notice_identity_generation_unknown');}
      $old=self::generation($row?$row['generation']:'0');
      if($row&&$old==='0'){throw new RuntimeException('notice_identity_generation_unknown');}
      $next=self::nextGeneration($old);
      if($row){queryfx($conn,'UPDATE governance_notice_identityepoch SET generation=CAST(%s AS UNSIGNED) WHERE userPHID=%s AND generation=CAST(%s AS UNSIGNED)',$next,$phid,$old);}
      else{queryfx($conn,'INSERT INTO governance_notice_identityepoch (userPHID,generation) VALUES (%s,1)',$phid);}
      if($conn->getAffectedRows()!==1||self::readGeneration($conn,$phid)!==$next){throw new RuntimeException('notice_identity_generation_changed');}
    }
    $conn->saveTransaction();$this->owned=false;
  }
  public function abort(Throwable $original){
    try{$this->close();}catch(Throwable$cleanup){throw new PhutilAggregateException('notice_identity_cleanup_failed',array('original'=>$original,'cleanup'=>$cleanup));}
    throw$original;
  }
  public function close(){
    if(!$this->owned){return;}$this->owned=false;$depth=self::depth($this->connection);
    if($depth===$this->depth){$this->connection->killTransaction();return;}
    if($this->depth===1&&$depth===0){queryfx($this->connection,'ROLLBACK');return;}
    throw new RuntimeException('notice_identity_cleanup_depth_changed');
  }
}
