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
}
