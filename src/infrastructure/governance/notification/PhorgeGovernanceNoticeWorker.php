<?php

/** One committed event fanout. Native mail/outbox and delivery mapping are atomic. */
final class PhorgeGovernanceNoticeWorker extends PhabricatorWorker {
  public function getMaximumRetryCount(){return 20;}
  protected function doWork(){
    try{$data=PhorgeGovernanceNoticeEventValue::validateTask($this->getTaskData());}
    catch(InvalidArgumentException $error){
      throw new PhabricatorWorkerPermanentFailureException(pht('Invalid governance notification event.'));}
    if(!PhorgeGovernanceNoticeOutbox::enabled()||!PhorgePrivateMailPolicy::enabled()||
      PhabricatorEnv::getEnvConfig('metamta.gorge-delivery-mode')!=='native'||!PhabricatorGorgeTaskQueueClient::isConfigured()){
      throw new RuntimeException('governance_notice_delivery_not_ready');}
    PhorgeGovernanceNoticeOutbox::assertRuntime();
    // Read-only audience planning is never the egress authorization proof.
    $source=PhorgeGovernanceNoticeMailPolicy::event($data['event_id'],
      (new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w'));
    $users=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIsAdmin(true)->withIsDisabled(false)->withIsApproved(true)->setLimit(129)->execute();
    if(count($users)>128){throw new RuntimeException('governance_notice_recipient_budget');}
    $users=mpull($users,null,'getPHID');ksort($users,SORT_STRING);
    $bindings=array();
    foreach($users as$phid=>$user){
      if(!PhorgeGovernanceNoticeSource::canReceive($user,$source)){continue;}
      $email=$user->loadPrimaryEmail();if(!$email||!$email->getIsVerified()){continue;}
      try{$bindings[$phid]=PhorgeGovernanceNoticeMailPolicy::binding($user,$email,$data['event_id']);}
      catch(RuntimeException $error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='governance_notice_recipient_ineligible'){throw $error;}}
    }
    $dao=new PhabricatorMetaMTAGorgeOutbox();$conn=$dao->establishConnection('w');
    if(get_class($conn)!==AphrontMySQLiDatabaseConnection::class||$conn->isInsideTransaction()||$conn->isReadLocking()||$conn->isWriteLocking()||
      PhabricatorEnv::getEnvConfig('cluster.databases')!==array()||PhabricatorEnv::isReadOnly()!==false){
      throw new RuntimeException('governance_notice_transaction_profile_refused');}
    $physical=queryfx_one($conn,'SELECT DATABASE() db,@@server_uuid uuid,@@read_only ro');
    if(!$physical||$physical['db']!==$dao->getAphrontRefDatabaseName()||!is_string($physical['uuid'])||$physical['uuid']===''||
      !in_array($physical['ro'],array(0,'0'),true)){throw new RuntimeException('governance_notice_primary_required');}
    $default=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation level');
    $restore=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if(!$default||!isset($restore[$default['level']])){throw new RuntimeException('governance_notice_isolation_unknown');}
    $queued=false;$owned=false;
    try{
      queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$queued=true;
      $conn->openTransaction();$owned=true;$queued=false;
      foreach(array('governance_notice_event','governance_notice_delivery','metamta_gorgeoutbox','metamta_mail','edge','edgedata')as$table){
        $column=in_array($table,array('metamta_mail','edgedata'),true)?'id':($table==='edge'?'src':'eventID');
        queryfx_one($conn,'SELECT %C FROM %T WHERE 1=0 LOCK IN SHARE MODE',$column,$table);
        $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
        if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('governance_notice_innodb_required');}
      }
      if(PhabricatorEdgeConfig::establishConnection(PhabricatorMetaMTAMailPHIDType::TYPECONST,'w')!==$conn){
        throw new RuntimeException('governance_notice_edge_transaction_changed');}
      $row=queryfx_one($conn,'SELECT state FROM governance_notice_event WHERE eventID=%s FOR UPDATE',$data['event_id']);
      if(!$row){throw new RuntimeException('governance_notice_event_refused');}
      if($row['state']==='prepared'){$conn->saveTransaction();return;}
      if($row['state']!=='pending'){throw new RuntimeException('governance_notice_event_refused');}
      foreach($users as $user){
        // New request-cache isolation and per-attempt current revalidation also
        // apply later in PhorgePrivateMailPolicy, including provider fallback.
        $source=PhorgeGovernanceNoticeMailPolicy::event($data['event_id'],$conn);
        if(!PhorgeGovernanceNoticeSource::canReceive($user,$source)){continue;}
        if(!isset($bindings[$user->getPHID()])){continue;}
        $mail=(new PhabricatorMetaMTAMail())->setPrivateMailProducer(__CLASS__)->addTos(array($user->getPHID()))
          ->setSubject(pht('Private governance notification'))->setBody(pht('Sign in to view this notification.'));
        if($mail->establishConnection('w')!==$conn){throw new RuntimeException('governance_notice_mail_transaction_changed');}
        $mail->setGovernanceNoticeBinding($bindings[$user->getPHID()]);
        $mail->save();
        queryfx($conn,'INSERT INTO governance_notice_delivery (eventID,recipientPHID,mailPHID,state,createdAt,updatedAt) '.
          'VALUES (%s,%s,%s,%s,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())',$data['event_id'],$user->getPHID(),$mail->getPHID(),'prepared');
      }
      if(!PhorgeGovernanceNoticeOutbox::enabled()||!PhorgePrivateMailPolicy::enabled()){
        throw new RuntimeException('governance_notice_delivery_not_ready');}
      queryfx($conn,'UPDATE governance_notice_event SET state=%s WHERE eventID=%s','prepared',$data['event_id']);
      $conn->saveTransaction();
    }catch(Throwable $error){if($owned&&$conn->isInsideTransaction()){$conn->killTransaction();}throw $error;}
    finally{if($queued){queryfx($conn,$restore[$default['level']]);}}
  }
}
