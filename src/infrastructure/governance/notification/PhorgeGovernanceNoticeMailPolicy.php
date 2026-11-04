<?php

/** Eligibility is re-read for every native/physical mail authorization attempt. */
final class PhorgeGovernanceNoticeMailPolicy extends Phobject {
  public static function validateBinding(array $binding){
    $keys=array_keys($binding);sort($keys);
    if($keys!==array('account_hash','address_hash','email','event_id','recipient','source_kind','target')){
      throw new InvalidArgumentException('governance_notice_mail_binding_refused');}
    if(!in_array($binding['source_kind'],array('organization','project-approval','recovery-stop'),true)||
      ($binding['source_kind']==='project-approval'?
        (!is_string($binding['target'])||!preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/D',$binding['target'])):
        $binding['target']!==null)){throw new InvalidArgumentException('governance_notice_mail_binding_refused');}
    foreach(array('event_id','account_hash','address_hash')as$key){
      if(!is_string($binding[$key])||!preg_match('/\A[a-f0-9]{64}\z/D',$binding[$key])){
        throw new InvalidArgumentException('governance_notice_mail_binding_refused');}}
    if(!is_string($binding['recipient'])||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$binding['recipient'])||
      !is_string($binding['email'])||!preg_match('/\APHID-EADR-[a-zA-Z0-9]{20}\z/D',$binding['email'])){
      throw new InvalidArgumentException('governance_notice_mail_binding_refused');}
  }
  private static function accountDigest($phid){
    $conn=(new PhabricatorUser())->establishConnection('w');
    if($conn->isInsideTransaction()||$conn->isReadLocking()||$conn->isWriteLocking()){throw new RuntimeException('governance_notice_inherited_identity_transaction');}
    $default=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation level');
    $restore=array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if(!$default||!isset($restore[$default['level']])){throw new RuntimeException('governance_notice_isolation_unknown');}
    $queued=false;$owned=false;
    try{
      // This also rejects an untracked raw START before taking ownership.
      queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$queued=true;
      $conn->openTransaction();$owned=true;$queued=false;
      $facts=PhorgeGovernanceNativeNoncredentialIdentitySource::lockIdentityPHID($phid,$conn);
      if(!$facts['eligible']||!$facts['isAdmin']){throw new RuntimeException('governance_notice_recipient_ineligible');}
      $security=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s AND transactionType IN (%Ls) '.
        'ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',new PhabricatorUserTransaction(),$phid,
        array(PhabricatorUserDisableTransaction::TRANSACTIONTYPE,PhabricatorUserApproveTransaction::TRANSACTIONTYPE,
          PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE));
      // A benign username transaction must not discard an otherwise deliverable
      // notice. Eligibility transitions, legacy roles and security-row bytes
      // remain bound; this digest is never a credential or execution receipt.
      unset($facts['nativeTransactionID']);
      $facts['noticeIdentityGeneration']=PhorgeGovernanceNoticeIdentityScope::readGeneration($conn,$phid);
      $facts['eligibilityTransactionID']=$security?(string)$security['id']:'0';
      if(!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$facts['eligibilityTransactionID'])){
        throw new RuntimeException('governance_notice_generation_unknown');}
      return hash('sha256',json_encode(array('notice-account-binding-v2',$facts),JSON_THROW_ON_ERROR));
    }finally{
      if($owned&&$conn->isInsideTransaction()){$conn->killTransaction();}
      if($queued){queryfx($conn,$restore[$default['level']]);}
    }
  }
  public static function binding(PhabricatorUser $recipient,PhabricatorUserEmail $email,$event){
    if($email->getUserPHID()!==$recipient->getPHID()||!$email->getIsVerified()||strlen($recipient->getAccountSecret())<16){
      throw new InvalidArgumentException('governance_notice_destination_refused');}
    $source=self::event($event,(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w'));
    $binding=array('event_id'=>$event,'recipient'=>$recipient->getPHID(),'email'=>$email->getPHID(),
      'account_hash'=>self::accountDigest($recipient->getPHID()),'address_hash'=>hash('sha256',$email->getAddress()),
      'source_kind'=>$source['event']['metadata']['source_kind'],'target'=>$source['target']);
    self::validateBinding($binding);return $binding;
  }
  public static function event($id,AphrontDatabaseConnection $conn){
    if(!is_string($id)||!preg_match('/\A[a-f0-9]{64}\z/D',$id)){
      throw new RuntimeException('governance_notice_event_refused');}
    $row=queryfx_one($conn,'SELECT eventID,schemaVersion,sourceKind,sourceID,operationDigest,actorDigest,action,createdAt,state,metadataDigest,CASE WHEN OCTET_LENGTH(metadataJSON)<=2048 THEN metadataJSON ELSE NULL END metadataJSON '.
      'FROM governance_notice_event WHERE eventID=%s LIMIT 1 LOCK IN SHARE MODE',$id);
    if(!$row||$row['eventID']!==$id||(string)$row['schemaVersion']!=='1'||!in_array($row['state'],array('pending','prepared'),true)||!is_string($row['metadataJSON'])||
      !hash_equals($row['metadataDigest'],hash('sha256',$row['metadataJSON']))){
      throw new RuntimeException('governance_notice_event_refused');}
    $metadata=json_decode($row['metadataJSON'],true,16,JSON_THROW_ON_ERROR);
    if(!is_array($metadata)||json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)!==$row['metadataJSON']){throw new RuntimeException('governance_notice_event_refused');}
    $value=PhorgeGovernanceNoticeEventValue::fromMetadata($metadata);
    if($value['event_id']!==$id||$value['metadata']!==$metadata){throw new RuntimeException('governance_notice_event_refused');}
    if($row['sourceKind']!==$metadata['source_kind']||(string)$row['sourceID']!==$metadata['source_id']||
      $row['operationDigest']!==$metadata['operation_sha256']||$row['actorDigest']!==$metadata['actor_sha256']||
      $row['action']!==$metadata['action']||(string)$row['createdAt']!==(string)$metadata['created_at']){
      throw new RuntimeException('governance_notice_event_refused');}
    $source=PhorgeGovernanceNoticeSource::load($metadata);
    if($source['event']['event_id']!==$id){throw new RuntimeException('governance_notice_event_refused');}
    $source['delivery_state']=$row['state'];return $source;
  }
  public static function authorize(PhabricatorMetaMTAMail $mail){
    if(!PhorgeGovernanceNoticeOutbox::enabled()||$mail->getPrivateMailPolicyValue('governance.mail-producer')!==PhorgeGovernanceNoticeWorker::class){return false;}
    PhorgeGovernanceNoticeOutbox::assertRuntime();
    $binding=$mail->getPrivateMailPolicyValue('governance.notice-binding');
    if(!is_array($binding)){return false;}
    try{self::validateBinding($binding);}catch(InvalidArgumentException $error){return false;}
    if($mail->getToPHIDs()!==array($binding['recipient'])||$mail->getCcPHIDs()||$mail->getRawToAddresses()||!$mail->getPHID()){return false;}
    $conn=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
    // Egress is a fresh request profile, never an inherited business snapshot.
    foreach(array($conn,(new PhabricatorUser())->establishConnection('w'),(new PhabricatorProject())->establishConnection('w'))as$writer){
      if($writer->isInsideTransaction()||$writer->isReadLocking()||$writer->isWriteLocking()){return false;}
    }
    $mapping=queryfx_one($conn,'SELECT mailPHID,state FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s LIMIT 1 LOCK IN SHARE MODE',
      $binding['event_id'],$binding['recipient']);
    if(!$mapping||$mapping['mailPHID']!==$mail->getPHID()||$mapping['state']!=='prepared'){return false;}
    try{$source=self::event($binding['event_id'],$conn);}
    catch(RuntimeException $error){return false;}
    catch(InvalidArgumentException $error){return false;}
    catch(JsonException $error){return false;}
    if($source['delivery_state']!=='prepared'||$binding['source_kind']!==$source['event']['metadata']['source_kind']||
      $binding['target']!==$source['target']){return false;}
    $recipient=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($binding['recipient']))->executeOne();
    if(!$recipient||$recipient->getPHID()!==$binding['recipient']||!PhorgeGovernanceNoticeSource::canReceive($recipient,$source)){
      return false;}
    $email_dao=new PhabricatorUserEmail();
    $emails=queryfx_all($email_dao->establishConnection('w'),
      'SELECT phid,userPHID,address,isVerified FROM %R WHERE BINARY userPHID=BINARY %s AND isPrimary=1 LIMIT 2 LOCK IN SHARE MODE',
      $email_dao,$binding['recipient']);
    if(count($emails)!==1){return false;}$email=head($emails);
    try{$account_digest=self::accountDigest($binding['recipient']);}catch(RuntimeException $error){return false;}
    return $email['phid']===$binding['email']&&$email['userPHID']===$binding['recipient']&&
      in_array($email['isVerified'],array(1,'1'),true)&&
      hash_equals($binding['account_hash'],$account_digest)&&
      hash_equals($binding['address_hash'],hash('sha256',$email['address']));
  }
  public static function path(PhabricatorMetaMTAMail $mail){
    // Formatting is not authorization. Quarantine persistence must not query a
    // source which has just become invalid or rewrite an immutable snapshot.
    $binding=$mail->getPrivateMailPolicyValue('governance.notice-binding');
    if(!is_array($binding)){return '/';}
    try{self::validateBinding($binding);}catch(InvalidArgumentException $error){return '/';}
    if($binding['source_kind']==='project-approval'){return '/object/'.$binding['target'].'/';}
    return $binding['source_kind']==='organization'?'/people/organization/history/':'/people/';
  }
}
