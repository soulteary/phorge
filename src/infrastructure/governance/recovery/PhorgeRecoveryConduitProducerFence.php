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
    return self::insertSelected($token,$mode,$insert,false);
  }
  /** Selected retained native OAuth access-token INSERT, not OAuth authorization. */
  public static function insertOAuthAccessToken(PhabricatorOAuthServerAccessToken $token,$mode,callable $insert) {
    return self::insertSelected($token,$mode,$insert,true);
  }
  /** Selected authorization-code INSERT only; not authorization scope/client mutation. */
  public static function insertOAuthAuthorizationCode(PhabricatorOAuthServerAuthorizationCode $code,$mode,callable $insert) {
    if(get_class($code)!==PhabricatorOAuthServerAuthorizationCode::class){
      throw new RuntimeException('recovery_oauth_code_class_unknown');}
    return self::insertSelected($code,$mode,$insert,'authorization-code');
  }
  /** Bound persisted scope syntax, not an authorization or a new native scope. */
  public static function authorizationScope($scope) {
    if(!is_array($scope)||count($scope)>64){throw new RuntimeException('recovery_oauth_authorization_scope_unknown');}
    foreach($scope as$key=>$value){
      if(!is_string($key)||!preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D',$key)||$value!==$key){
        throw new RuntimeException('recovery_oauth_authorization_scope_unknown');}}
    ksort($scope,SORT_STRING);
    if(strlen(json_encode($scope,JSON_THROW_ON_ERROR))>16384){throw new RuntimeException('recovery_oauth_authorization_scope_budget');}
    return$scope;
  }
  private static function authorizationThreads($owner,$writer) {
    $ids=array();$uuid=null;
    foreach(array($owner,$writer)as$conn){
      $row=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u');
      if(!$row||!is_string($row['id'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$row['id'])||
        !is_string($row['u'])||($uuid!==null&&$uuid!==$row['u'])){throw new RuntimeException('recovery_oauth_authorization_thread_unknown');}
      $uuid=$row['u'];$ids[]=$row;
    }
    if($ids[0]['id']===$ids[1]['id']){throw new RuntimeException('recovery_oauth_authorization_thread_unknown');}
    return$ids;
  }
  /** Real DAO INSERT and scope UPDATE; owner/client/PHID reassignment is refused. */
  public static function saveOAuthClientAuthorization(PhabricatorOAuthClientAuthorization $authorization,$mode,callable $save) {
    if(get_class($authorization)!==PhabricatorOAuthClientAuthorization::class){
      throw new RuntimeException('recovery_oauth_authorization_class_unknown');}
    return self::insertSelected($authorization,$mode,$save,'client-authorization');
  }
  private static function insertSelected($token,$mode,callable $insert,$oauth) {
    $authorization=$oauth==='client-authorization';$update=$authorization&&$mode==='UPDATE';
    if(($update?(!is_int($token->getID())&&!is_string($token->getID())||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$token->getID())||(float)$token->getID()>4294967295):($mode!=='INSERT'||$token->getID()!==null))||
      (new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue($token)!==null){
      throw new RuntimeException('recovery_conduit_insert_scope_unknown');}
    $code=$oauth==='authorization-code';
    $authorizationExpected=null;
    if($authorization){
      $client=$token->getClientPHID();
      if(!is_string($client)||!preg_match('/\APHID-OASC-[a-zA-Z0-9]{20}\z/D',$client)){
        throw new RuntimeException('recovery_oauth_authorization_client_unknown');}
      $authorizationExpected=array('userPHID'=>$token->getUserPHID(),'clientPHID'=>$client,
        'scope'=>self::authorizationScope($token->getScope()));
      if($update){
        if(!is_string($token->getPHID())||!preg_match('/\APHID-OASA-[a-zA-Z0-9]{20}\z/D',$token->getPHID())){
          throw new RuntimeException('recovery_oauth_authorization_identity_unknown');}
        $authorizationExpected['phid']=$token->getPHID();
        $created=$token->getDateCreated();
        if((!is_int($created)&&!is_string($created))||!preg_match('/\A[0-9]{1,10}\z/D',(string)$created)||(float)$created>4294967295){
          throw new RuntimeException('recovery_oauth_authorization_identity_unknown');}
        $authorizationExpected['dateCreated']=(string)$created;
      }
    }
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    $subject=PhorgeRecoveryStopRequest::user($oauth?$token->getUserPHID():$token->getObjectPHID());
    $user=new PhabricatorUser();$owner=$user->establishConnection('w');$writer=$token->establishConnection('w');
    if($oauth&&$token->getAphrontRefDatabaseName()!==$profile['namespace'].'_oauth_server'){
      throw new RuntimeException('recovery_oauth_access_namespace_unknown');}
    if($owner===$writer){throw new RuntimeException('recovery_conduit_distinct_native_writers_required');}
    self::assertWriterAvailable($owner);
    PhorgeRecoveryRestoreService::assertConduitProducerOwnerAvailable($owner);
    PhorgeRecoverySessionProducerFence::assertConduitProducerWriterAvailable($owner);
    self::idle($owner);self::idle($writer);
    if(self::state($owner)['u']!==self::state($writer)['u']){throw new RuntimeException('recovery_conduit_primary_mismatch');}
    $authorizationID=$token->getID();
    $authorizationThreads=$authorization?self::authorizationThreads($owner,$writer):null;
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
      if($update){
        $prior=queryfx_one($writer,'SELECT userPHID,clientPHID,phid,dateCreated FROM %R FORCE INDEX(PRIMARY) WHERE id=%d FOR UPDATE',$token,$token->getID());
        if($prior&&isset($prior['dateCreated'])&&(is_string($prior['dateCreated'])||is_int($prior['dateCreated']))){$prior['dateCreated']=(string)$prior['dateCreated'];}
        if(!$prior||$prior!==array('userPHID'=>$subject,'clientPHID'=>$authorizationExpected['clientPHID'],'phid'=>$authorizationExpected['phid'],'dateCreated'=>$authorizationExpected['dateCreated'])){
          throw new RuntimeException('recovery_oauth_authorization_identity_changed');}
      }
      $result=$insert();
      if($token->establishConnection('w')!==$writer||$user->establishConnection('w')!==$owner||
        ($oauth?$token->getUserPHID():$token->getObjectPHID())!==$subject||PhorgeRecoveryRestoreProfile::assertCurrent()!==$profile){
        if($authorization){$ticket->unknown=true;}
        throw new RuntimeException('recovery_conduit_route_changed');}
      if($authorization){
        if(($update&&$token->getID()!==$authorizationID)||self::authorizationThreads($owner,$writer)!==$authorizationThreads){
          $ticket->unknown=true;throw new RuntimeException('recovery_oauth_authorization_thread_changed');}
        $row=queryfx_one($writer,'SELECT userPHID,clientPHID,phid,scope FROM %R WHERE id=%d LOCK IN SHARE MODE',$token,$token->getID());
        if(!$row||!is_string($row['scope'])||strlen($row['scope'])>16384){throw new RuntimeException('recovery_oauth_authorization_postcondition_failed');}
        $actualScope=json_decode($row['scope'],true,8,JSON_THROW_ON_ERROR);
        $expectedPHID=$update?$authorizationExpected['phid']:$token->getPHID();
        if(!is_string($expectedPHID)||!preg_match('/\APHID-OASA-[a-zA-Z0-9]{20}\z/D',$expectedPHID)||
          $row['userPHID']!==$subject||$row['clientPHID']!==$authorizationExpected['clientPHID']||$row['phid']!==$expectedPHID||
          self::authorizationScope($actualScope)!==$authorizationExpected['scope']){
          throw new RuntimeException('recovery_oauth_authorization_postcondition_failed');}
      }elseif($code){
        $row=queryfx_one($writer,'SELECT userPHID,clientPHID,code,clientSecret,redirectURI FROM %R WHERE id=%d LOCK IN SHARE MODE',$token,$token->getID());
        $expected=array('userPHID'=>$subject,'clientPHID'=>$token->getClientPHID(),
          'code'=>$token->getCode(),'clientSecret'=>$token->getClientSecret(),'redirectURI'=>$token->getRedirectURI());
        if(!$row||$row!==$expected){throw new RuntimeException('recovery_oauth_code_insert_postcondition_failed');}
      }else{
      $row=$oauth
        ?queryfx_one($writer,'SELECT userPHID,token FROM %R WHERE id=%d LOCK IN SHARE MODE',$token,$token->getID())
        :queryfx_one($writer,'SELECT objectPHID,token FROM %R WHERE id=%d LOCK IN SHARE MODE',$token,$token->getID());
      if(!$row||$row[$oauth?'userPHID':'objectPHID']!==$subject||!hash_equals($token->getToken(),$row['token'])){
        throw new RuntimeException('recovery_conduit_insert_postcondition_failed');}
      }
      // A failed COMMIT transport is never called rollback or noncommit.
      $ticket->unknown=true;$writer->saveTransaction();
      if($writer->isInsideTransaction()){throw new RuntimeException('recovery_conduit_commit_unknown');}
      if($authorization&&self::authorizationThreads($owner,$writer)!==$authorizationThreads){throw new RuntimeException('recovery_oauth_authorization_thread_changed');}
      $ticket->writer_started=false;$ticket->unknown=false;
      $ticket->unknown=true;$owner->killTransaction();
      if($owner->isInsideTransaction()){throw new RuntimeException('recovery_conduit_owner_end_unknown');}
      if($authorization&&self::authorizationThreads($owner,$writer)!==$authorizationThreads){throw new RuntimeException('recovery_oauth_authorization_thread_changed');}
      $ticket->owner_started=false;$ticket->unknown=false;
    }catch(Throwable$error){
      if($authorization&&($error instanceof AphrontConnectionLostQueryException||$error instanceof AphrontConnectionQueryException||
        ($error instanceof mysqli_sql_exception&&in_array($error->getCode(),array(2006,2013,2055),true)))){
        $ticket->unknown=true;
      }
      $primary=$error;
    }
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
