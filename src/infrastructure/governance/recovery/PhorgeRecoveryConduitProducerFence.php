<?php

/** Selected Conduit INSERT serialization, never an authentication grant.
 * Two native connections, NOT an atomic transaction. Unknown terminal state
 * retains ownership references until operator reconciliation / process exit.
 */
final class PhorgeRecoveryConduitProducerFence extends Phobject {
  private static $retained=array();
  private function __construct() {}
  /** Process-local reservation only; never a durable or cross-process lease. */
  public static function assertWriterAvailable($writer) {
    // A replaced pool connection does not make an unknown retained owner safe.
    if(self::$retained){throw new RuntimeException('recovery_conduit_writer_quarantined');}
  }
  private static function lockTransactionalTable($conn,$table) {
    if(!$conn->isInsideTransaction()){throw new RuntimeException('recovery_conduit_MDL_owner_required');}
    queryfx_one($conn,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$table);
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
      $table->getAphrontRefDatabaseName(),$table->getTableName());
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_conduit_transactional_source_required');}
  }
  private static function state($conn) {
    $row=queryfx_one($conn,'SELECT @@autocommit a,@@server_uuid u');
    if(!$row||!in_array($row['a'],array(1,'1'),true)||
      !is_string($row['u'])){
      throw new RuntimeException('recovery_conduit_physical_state_unknown');}
    return$row;
  }
  private static function idle($conn) {
    if(get_class($conn)!==AphrontMySQLiDatabaseConnection::class||$conn->isInsideTransaction()||
      $conn->isReadLocking()||$conn->isWriteLocking()){
      throw new RuntimeException('recovery_conduit_outer_transaction_refused');}
  }
  public static function insert(PhabricatorConduitToken $token,$mode,callable $insert) {
    if($mode!=='INSERT'||$token->getID()!==null||
      (new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($token)!==null){
      throw new RuntimeException('recovery_conduit_insert_scope_unknown');}
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    $subject=PhorgeRecoveryStopRequest::user($token->getObjectPHID());
    $user=new PhabricatorUser();$owner=$user->establishConnection('w');$writer=$token->establishConnection('w');
    if($owner===$writer){throw new RuntimeException('recovery_conduit_distinct_native_writers_required');}
    self::assertWriterAvailable($owner);
    PhorgeRecoveryRestoreService::assertConduitProducerOwnerAvailable($owner);
    PhorgeRecoverySessionProducerFence::assertConduitProducerWriterAvailable($owner);
    self::idle($owner);self::idle($writer);
    if(self::state($owner)['u']!==self::state($writer)['u']){throw new RuntimeException('recovery_conduit_primary_mismatch');}
    $ticket=new stdClass();$ticket->owner=$owner;$ticket->writer=$writer;
    $ticket->owner_started=false;$ticket->writer_started=false;$ticket->unknown=false;
    self::$retained[]=$ticket;
    $primary=null;$errors=array();$result=null;
    try {
      queryfx($owner,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
      // Mark before BEGIN: an exception need not mean the server did not begin.
      $ticket->owner_started=true;$ticket->unknown=true;$owner->openTransaction();$ticket->unknown=false;
      self::lockTransactionalTable($owner,$user);
      PhorgeRecoveryRestoreProfile::assertOwnedWriter($owner);
      $plan=queryfx_one($owner,'SELECT id FROM %R WHERE BINARY phid=BINARY %s',$user,$subject);
      if(!$plan||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$plan['id'])){throw new RuntimeException('recovery_conduit_subject_unknown');}
      $locked=queryfx_one($owner,'SELECT phid FROM %R FORCE INDEX(PRIMARY) WHERE id=%d LOCK IN SHARE MODE',$user,$plan['id']);
      if(!$locked||$locked['phid']!==$subject){throw new RuntimeException('recovery_conduit_subject_changed');}
      queryfx($writer,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
      $ticket->writer_started=true;$ticket->unknown=true;$writer->openTransaction();$ticket->unknown=false;
      self::lockTransactionalTable($writer,$token);
      $result=$insert();
      if($token->establishConnection('w')!==$writer||$user->establishConnection('w')!==$owner||
        $token->getObjectPHID()!==$subject||PhorgeRecoveryRestoreProfile::assertCurrent()!==$profile){
        throw new RuntimeException('recovery_conduit_route_changed');}
      $row=queryfx_one($writer,'SELECT objectPHID,token FROM %R WHERE id=%d LOCK IN SHARE MODE',$token,$token->getID());
      if(!$row||$row['objectPHID']!==$subject||!hash_equals($token->getToken(),$row['token'])){
        throw new RuntimeException('recovery_conduit_insert_postcondition_failed');}
      // A failed COMMIT transport is never called rollback or noncommit.
      $ticket->unknown=true;$writer->saveTransaction();
      if($writer->isInsideTransaction()){throw new RuntimeException('recovery_conduit_commit_unknown');}
      $ticket->writer_started=false;$ticket->unknown=false;
      $ticket->unknown=true;$owner->killTransaction();
      if($owner->isInsideTransaction()){throw new RuntimeException('recovery_conduit_owner_end_unknown');}
      $ticket->owner_started=false;$ticket->unknown=false;
    }catch(Throwable$error){$primary=$error;}
    if(!$ticket->unknown){
      foreach(array('writer','owner')as$role){
        if(!$ticket->{$role.'_started'}){continue;}
        try{$conn=$ticket->$role;
          if(!$conn->isInsideTransaction()){throw new RuntimeException('recovery_conduit_cleanup_unknown');}
          $ticket->unknown=true;$conn->killTransaction();
          if($conn->isInsideTransaction()){throw new RuntimeException('recovery_conduit_cleanup_unknown');}
          $ticket->{$role.'_started'}=false;$ticket->unknown=false;
        }catch(Throwable$error){$ticket->unknown=true;$errors[$role]=$error;break;}
      }
    }
    if(!$ticket->unknown&&!$ticket->owner_started&&!$ticket->writer_started){
      foreach(self::$retained as$key=>$value){if($value===$ticket){unset(self::$retained[$key]);}}
    }
    if($errors){if($primary!==null){$errors['primary']=$primary;}throw new PhutilAggregateException('recovery_conduit_cleanup_failed',$errors);}
    if($primary!==null){throw$primary;}
    return$result;
  }
}
