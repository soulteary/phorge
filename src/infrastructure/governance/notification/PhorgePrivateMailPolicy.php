<?php
/** Fixed source provenance and fresh native recipient/object query at egress. */
final class PhorgePrivateMailPolicy extends Phobject {
  // Serialized message bound matches the paired mailer transport profile.
  // Gorge independently bounds the complete encoded Conduit request.
  const MAX_PHYSICAL_MESSAGE_BYTES = 10 * 1024 * 1024;
  public static function enabled(){
    $value=PhabricatorEnv::getEnvConfigIfExists('governance.private-mail.enabled',false);
    if(!is_bool($value)){throw new RuntimeException('private_mail_control_unknown');}
    return $value;
  }
  public static function classify($producer){
    if(in_array($producer,array('PhabricatorBadgesEditor','PhabricatorCalendarEventEditor','PhabricatorCalendarNotificationEngine','ConpherenceEditor','PhabricatorCountdownEditor','PhabricatorFileEditor','FileCreateMailReceiver','HeraldRuleEditor','LegalpadDocumentEditor','PhabricatorMacroEditor','ManiphestTransactionEditor','PhameBlogEditor','PhamePostEditor','PholioMockEditor','PhrictionTransactionEditor','PhabricatorPhurlURLEditor','PhorgePolicyNamedPolicyTransactionEditor','PonderAnswerEditor','PonderQuestionEditor','PhabricatorProjectTransactionEditor','PhabricatorSlowvoteEditor'),true)){return 'business';}
    if(in_array($producer,array('PhabricatorAuthRegisterController','PhabricatorAuthContactNumberTestController','PhabricatorAuthSSHKeyEditor','PhabricatorSMSAuthFactor','PhabricatorAuthInviteWorker','LegalpadDocumentSignController','PhabricatorPeopleEmailLoginMailEngine','PhabricatorPeopleUsernameMailEngine','PhabricatorPeopleWelcomeMailEngine','PhabricatorUserEmail','PhabricatorUserApproveTransaction'),true)){return 'security';}
    if($producer==='PhabricatorMetaMTAReceivedMail'){return 'private-error';}
    return 'unknown';
  }
  public static function purpose(PhabricatorMetaMTAMail $mail){
    $producer=$mail->getPrivateMailPolicyValue('governance.mail-producer');
    if($producer==='LegalpadDocumentSignController'){return 'unsupported-signature';}
    if($producer==='PhabricatorAuthRegisterController' ||
      ($producer==='PhabricatorUserApproveTransaction'&&$mail->getPrivateMailPolicyValue('governance.mail-flow')==='account.approved.admin')){return 'admin-notice';}
    return self::classify($producer);
  }
  public static function isLinkOnly(PhabricatorMetaMTAMail $mail){
    return self::enabled()&&$mail->getMessageType()===PhabricatorMailEmailMessage::MESSAGETYPE&&
      in_array(self::purpose($mail),array('business','private-error','admin-notice'),true);
  }
  public static function authorize(PhabricatorMetaMTAMail $mail){
    if($mail->getMessageType()!==PhabricatorMailEmailMessage::MESSAGETYPE||!self::enabled()){return true;}
    if(!self::primarySourceProfile()){return false;}
    $property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');
    $prior=$property->getValue();$property->setValue(null,new PhutilInRequestKeyValueCache());
    try{$allowed=self::authorizeInCurrentCache($mail);return $allowed&&self::primarySourceProfile();}
    finally{$property->setValue(null,$prior);}
  }
  /** This candidate supports the deployed single-primary profile only.
   * A read-mode Lisk handle is not itself proof of a replica. Configured cluster
   * routing or any native read-only transition is rejected before authorization.
   */
  private static function primarySourceProfile(){
    return PhabricatorEnv::getEnvConfig('cluster.databases')===array()&&
      PhabricatorEnv::isReadOnly()===false;
  }
  private static function authorizeInCurrentCache(PhabricatorMetaMTAMail $mail){
    if($mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)!==false){return false;}
    $purpose=self::purpose($mail);
    if(in_array($purpose,array('security','admin-notice'),true)){return self::authorizeSecurityFlow($mail);}
    if(!in_array($purpose,array('business','private-error'),true)||$mail->getRawToAddresses()){return false;}
    $recipients=array_values(array_unique(array_merge($mail->getToPHIDs(),$mail->getCcPHIDs())));
    // No recipient roster disclosure in a shared business envelope.
    if(count($recipients)!==1){return false;}
    foreach($recipients as $phid){
      if(!is_string($phid)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/',$phid)){return false;}
      $viewer=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($phid))->executeOne();
      if(!$viewer||$viewer->getPHID()!==$phid||$viewer->getIsDisabled()||!$viewer->getIsApproved()||$viewer->getIsSystemAgent()){return false;}
      if($purpose==='business'){
        $related=$mail->getRelatedPHID();
        if(!is_string($related)||!preg_match('/\APHID-[A-Z0-9]{4}-[a-zA-Z0-9]{20}\z/',$related)){return false;}
        // Actual viewer query includes native query-level ancestor/Space gates.
        try{
          $object=(new PhabricatorObjectQuery())->setViewer($viewer)->withPHIDs(array($related))->executeOne();
        }catch(PhabricatorPolicyException $error){return false;}
        if(!$object||$object->getPHID()!==$related){return false;}
      }
    }
    return true;
  }
  public static function requireCurrent(PhabricatorMetaMTAMail $mail){
    if(!self::authorize($mail)){throw new PhabricatorMetaMTAPermanentFailureException(pht('Private notification is no longer deliverable.'));}
  }
  public static function sanitizeStored(PhabricatorMetaMTAMail $mail){
    if(!self::isLinkOnly($mail)){return;}
    $uri=self::linkPath($mail);
    $mail->setSubject(pht('Private notification'))->setSubjectPrefix('')->setVarySubjectPrefix('')
      ->setBody(pht('Sign in to view this notification.')."\n\n".PhabricatorEnv::getProductionURI($uri))
      ->setHTMLBody(null)->setAttachments(array())->setMailStamps(array())->setMailStampMetadata(array());
    $mail->clearPrivateBusinessMailHeaders();
  }
  public static function project(PhabricatorMetaMTAMail $mail,$message){
    if(!self::enabled()){return $message;}
    self::requireCurrent($mail);
    if(!self::isLinkOnly($mail)){return $message;}
    if(get_class($message)!==PhabricatorMailEmailMessage::class){throw new RuntimeException('private_mail_message_type_unsupported');}
    $uri=self::linkPath($mail);
    return self::newLinkOnlyMessage($message,PhabricatorEnv::getProductionURI($uri));
  }
  public static function newLinkOnlyMessage(PhabricatorMailEmailMessage $message,$uri){
    $result=new PhabricatorMailEmailMessage();
    $result->setFromAddress(new PhutilEmailAddress($message->getFromAddress()->getAddress()));
    foreach(array('To','CC') as $type){
      $getter='get'.$type.'Addresses';$setter='set'.$type.'Addresses';$addresses=array();
      foreach($message->$getter() as $address){$addresses[]=new PhutilEmailAddress($address->getAddress());}
      $result->$setter($addresses);
    }
    return $result->setSubject(pht('Private notification'))
      ->setTextBody(pht('Sign in to view this notification.')."\n\n".$uri)
      ->setHTMLBody(null)->setAttachments(array())->setHeaders(array());
  }
  private static function linkPath(PhabricatorMetaMTAMail $mail){
    if(self::purpose($mail)==='admin-notice'){return '/people/query/approval/';}
    return self::purpose($mail)==='business'?'/object/'.$mail->getRelatedPHID().'/':'/';
  }
  public static function flowCatalog(){return array(
    'account.login'=>'PhabricatorPeopleEmailLoginMailEngine',
    'account.welcome'=>'PhabricatorPeopleWelcomeMailEngine',
    'account.username'=>'PhabricatorPeopleUsernameMailEngine',
    'account.email.verify'=>'PhabricatorUserEmail',
    'account.email.old-primary'=>'PhabricatorUserEmail',
    'account.email.new-primary'=>'PhabricatorUserEmail',
    'account.approval.queue'=>'PhabricatorAuthRegisterController',
    'account.approved.owner'=>'PhabricatorUserApproveTransaction',
    'account.approved.admin'=>'PhabricatorUserApproveTransaction',
    'account.invite'=>'PhabricatorAuthInviteWorker',
    'account.ssh'=>'PhabricatorAuthSSHKeyEditor');}
  public static function validateFlow(PhabricatorMetaMTAMail $mail,$flow,array $binding){
    $catalog=self::flowCatalog();
    if(!is_string($flow)||!isset($catalog[$flow])||$catalog[$flow]!==$mail->getPrivateMailPolicyValue('governance.mail-producer')){
      throw new InvalidArgumentException('private_mail_flow_producer_refused');}
    $keys=array_keys($binding);sort($keys);
    if($keys!==array('account_hash','address_hash','admin','email','recipient','source','source_hash','subject')){
      throw new InvalidArgumentException('private_mail_flow_binding_refused');}
    foreach(array('account_hash','address_hash')as$key){if(!is_string($binding[$key])||!preg_match('/\A[a-f0-9]{64}\z/',$binding[$key])){
      throw new InvalidArgumentException('private_mail_flow_digest_refused');}}
    foreach(array('subject','recipient','admin')as$key){$value=$binding[$key];
      if(($key==='subject'||$value!==null)&&(!is_string($value)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/',$value))){throw new InvalidArgumentException('private_mail_flow_identity_refused');}}
    foreach(array('email','source')as$key){$value=$binding[$key];if($value!==null&&(!is_string($value)||!preg_match('/\APHID-(?:[A-Z0-9]{4}-[a-zA-Z0-9]{20}|XACT-[A-Z0-9]{4}-[a-zA-Z0-9]{15})\z/',$value))){throw new InvalidArgumentException('private_mail_flow_source_refused');}}
    if($binding['source_hash']!==null&&(!is_string($binding['source_hash'])||!preg_match('/\A[a-f0-9]{64}\z/',$binding['source_hash']))){throw new InvalidArgumentException('private_mail_flow_digest_refused');}
    if(in_array($flow,array('account.welcome','account.approval.queue','account.approved.admin','account.invite'),true)&&$binding['admin']===null){throw new InvalidArgumentException('private_mail_flow_admin_required');}
    if($flow!=='account.invite'&&($binding['recipient']===null||$binding['email']===null)){throw new InvalidArgumentException('private_mail_flow_destination_required');}
    if(!in_array($flow,array('account.invite','account.approval.queue','account.approved.admin'),true)&&$binding['subject']!==$binding['recipient']){throw new InvalidArgumentException('private_mail_flow_owner_required');}
    if(in_array($flow,array('account.approval.queue','account.approved.admin'),true)&&$binding['admin']!==$binding['recipient']){throw new InvalidArgumentException('private_mail_flow_admin_destination_required');}
    if(in_array($flow,array('account.username','account.approved.owner','account.approved.admin','account.email.verify','account.invite'),true)&&($binding['source']===null||$binding['source_hash']===null)){throw new InvalidArgumentException('private_mail_flow_source_required');}
    if($flow==='account.ssh'&&$binding['source']===null){throw new InvalidArgumentException('private_mail_flow_source_required');}
    if($flow==='account.email.verify'&&$binding['source']!==$binding['email']){throw new InvalidArgumentException('private_mail_flow_email_source_refused');}
  }
  public static function userBinding(PhabricatorUser $subject,PhabricatorUser $recipient,$email=null,$admin=null,$source=null,$source_hash=null){
    if($email===null){$email=$recipient->loadPrimaryEmail();}
    if(!$subject->getPHID()||!($email instanceof PhabricatorUserEmail)||!$email->getPHID()||$email->getUserPHID()!==$recipient->getPHID()){throw new InvalidArgumentException('private_mail_native_destination_required');}
    return array('subject'=>$subject->getPHID(),'recipient'=>$recipient->getPHID(),'email'=>$email->getPHID(),
      'account_hash'=>self::accountHash($subject),'address_hash'=>hash('sha256',$email->getAddress()),
      'admin'=>$admin,'source'=>$source,'source_hash'=>$source_hash);
  }
  public static function inviteBinding(PhabricatorAuthInvite $invite,PhabricatorUser $author){
    return array('subject'=>$author->getPHID(),'recipient'=>null,'email'=>null,'admin'=>$author->getPHID(),
      'account_hash'=>self::accountHash($author),'address_hash'=>hash('sha256',$invite->getEmailAddress()),
      'source'=>$invite->getPHID(),'source_hash'=>hash('sha256',$invite->getVerificationHash()));
  }
  public static function userEventBinding(PhabricatorUser $subject,PhabricatorUser $recipient,$phid,$type,$email=null,$admin=null){
    $event=self::userEvent($phid,$subject->getPHID(),$type);
    if($event===null){throw new InvalidArgumentException('private_mail_native_event_required');}
    return self::userBinding($subject,$recipient,$email,$admin,$phid,$event);
  }
  private static function userEvent($phid,$subject,$type){
    $result=self::observeUserEvent($phid,$subject,$type);
    return $result['state']==='present'?$result['hash']:null;
  }
  private static function observeUserEvent($phid,$subject,$type){
    if(!is_string($phid)||!preg_match('/\APHID-XACT-USER-[a-zA-Z0-9]{15}\z/',$phid)||
      !in_array($type,array(PhabricatorUserUsernameTransaction::TRANSACTIONTYPE,PhabricatorUserApproveTransaction::TRANSACTIONTYPE),true)){return array('state'=>'invalid');}
    $table=new PhabricatorUserTransaction();$conn=$table->establishConnection('w');
    $size=queryfx_one($conn,'SELECT COALESCE(OCTET_LENGTH(oldValue),0)+COALESCE(OCTET_LENGTH(newValue),0) n FROM %R WHERE phid=%s LIMIT 1',$table,$phid);
    if(!$size){return array('state'=>'missing');}
    if((int)$size['n']>8192){return array('state'=>'invalid');}
    $row=queryfx_one($conn,'SELECT phid,objectPHID,authorPHID,transactionType,oldValue,newValue FROM %R WHERE phid=%s LIMIT 1',$table,$phid);
    if(!$row){return array('state'=>'missing');}
    if($row['phid']!==$phid||$row['objectPHID']!==$subject||$row['transactionType']!==$type){return array('state'=>'invalid');}
    return array('state'=>'present','hash'=>hash('sha256',phutil_json_encode($row)));
  }
  /** A retry budget, never a permission or revocation grace period. */
  public static function sourceCanStillCommit($created,$now){
    foreach(array($created,$now)as$value){
      if((!is_int($value)&&!is_string($value))||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$value)||
        (float)$value>4294967295){return false;}
    }
    return (int)$now>=(int)$created&&((int)$now-(int)$created)<300;
  }
  private static function deferMissingUserEvent(PhabricatorMetaMTAMail $mail){
    if(!$mail->getID()){return false;}
    $table=new PhabricatorUserTransaction();$row=queryfx_one($table->establishConnection('w'),'SELECT UNIX_TIMESTAMP() n');
    if(!self::sourceCanStillCommit($mail->getDateCreated(),$row['n'])){return false;}
    // No cache, quarantine, status or snapshot mutation occurs before this error.
    throw new PhorgePrivateMailSourcePendingException(pht('Notification source transaction is still pending.'));
  }
  private static function accountHash(PhabricatorUser $user){
    $secret=$user->getAccountSecret();
    if(!is_string($secret)||strlen($secret)<16){throw new InvalidArgumentException('private_mail_account_source_unknown');}
    return hash('sha256',$secret);
  }
  private static function currentUser($phid){
    $user=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())->withPHIDs(array($phid))->executeOne();
    return $user&&$user->getPHID()===$phid?$user:null;
  }
  private static function authorizeSecurityFlow(PhabricatorMetaMTAMail $mail){
    $flow=$mail->getPrivateMailPolicyValue('governance.mail-flow');$binding=$mail->getPrivateMailPolicyValue('governance.mail-binding');
    if(!is_array($binding)){return false;}
    try{self::validateFlow($mail,$flow,$binding);}catch(InvalidArgumentException$error){return false;}
    $subject=self::currentUser($binding['subject']);
    if(!$subject||!$subject->canEstablishWebSessions()){return false;}
    if(in_array($flow,array('account.login','account.welcome'),true)){
      try{if(!hash_equals($binding['account_hash'],self::accountHash($subject))){return false;}}
      catch(InvalidArgumentException$error){return false;}
    }
    $event_missing=false;
    if(in_array($flow,array('account.username','account.approved.owner','account.approved.admin'),true)){
      $type=$flow==='account.username'?PhabricatorUserUsernameTransaction::TRANSACTIONTYPE:PhabricatorUserApproveTransaction::TRANSACTIONTYPE;
      $event=self::observeUserEvent($binding['source'],$binding['subject'],$type);
      if($event['state']==='invalid'){return false;}
      $event_missing=$event['state']==='missing';
      if(!$event_missing&&!hash_equals($binding['source_hash'],$event['hash'])){return false;}
    }
    $alerts=array('account.username','account.email.old-primary','account.email.new-primary','account.approved.owner','account.ssh');
    if($subject->getIsDisabled()&&!in_array($flow,$alerts,true)){return false;}
    if($binding['admin']!==null){$admin=self::currentUser($binding['admin']);
      if(!$admin||!$admin->getIsAdmin()||!$admin->getIsApproved()||$admin->getIsDisabled()||!$admin->canEstablishWebSessions()){return false;}}
    if($flow==='account.approval.queue'&&$subject->getIsApproved()){return false;}
    if($flow==='account.invite'){
      $invite=(new PhabricatorAuthInvite())->loadOneWhere('BINARY phid=BINARY %s',$binding['source']);
      return $invite&&$invite->getPHID()===$binding['source']&&$invite->getAuthorPHID()===$binding['subject']&&$invite->getAcceptedByPHID()===null&&
        hash_equals($binding['source_hash'],hash('sha256',$invite->getVerificationHash()))&&
        self::destinationMatches($mail,$binding,$invite->getEmailAddress());
    }
    $recipient=self::currentUser($binding['recipient']);
    if(!$recipient||!$recipient->canEstablishWebSessions()){return false;}
    $email=(new PhabricatorUserEmail())->loadOneWhere('BINARY phid=BINARY %s',$binding['email']);
    if(!$email||$email->getPHID()!==$binding['email']||$email->getUserPHID()!==$recipient->getPHID()||!self::destinationMatches($mail,$binding,$email->getAddress())){return false;}
    if($flow==='account.email.verify'&&($email->getIsVerified()||!hash_equals($binding['source_hash'],hash('sha256',$email->getVerificationCode())))){return false;}
    if($flow==='account.ssh'){
      $key=(new PhabricatorAuthSSHKey())->loadOneWhere('BINARY phid=BINARY %s',$binding['source']);
      if(!$key||$key->getPHID()!==$binding['source']||$key->getObjectPHID()!==$subject->getPHID()){return false;}
    }
    if($event_missing){return self::deferMissingUserEvent($mail);}
    return true;
  }
  private static function destinationMatches(PhabricatorMetaMTAMail $mail,array $binding,$address){
    if(!hash_equals($binding['address_hash'],hash('sha256',$address))||$mail->getCcPHIDs()){return false;}
    $raw=$mail->getRawToAddresses();$native=$mail->getToPHIDs();
    if($raw){return count($raw)===1&&!$native&&hash_equals($binding['address_hash'],hash('sha256',head($raw)));}
    if($binding['recipient']===null||$native!==array($binding['recipient'])){return false;}
    // MetaMTAActorQuery resolves native PHID recipients to their current primary
    // address. A retained old email row is not proof of the actual destination.
    $primary=(new PhabricatorUserEmail())->loadAllWhere('userPHID=%s AND isPrimary=1 LIMIT 2',$binding['recipient']);
    if(count($primary)!==1){return false;}$primary=head($primary);
    return $primary->getUserPHID()===$binding['recipient']&&$primary->getPHID()===$binding['email']&&
      hash_equals($binding['address_hash'],hash('sha256',$primary->getAddress()));
  }
  public static function canonicalMessage($value){
    if(!is_array($value)){return $value;}
    $list=array_is_list($value);$result=array();
    foreach($value as $key=>$item){
      if(!$list&&($item===null||$item===''||$item===array())){continue;}
      $result[$key]=self::canonicalMessage($item);
    }
    if(!$list){ksort($result,SORT_STRING);}
    return $result;
  }
  public static function snapshotSafe(PhabricatorMetaMTAMail $mail,array $snapshot){
    if(!self::enabled()){return true;}
    $binding=idx($snapshot,'authorization');
    return $mail->getPrivateMailPolicyValue('governance.delivery-policy')===1&&is_array($binding)&&
      ($binding['version']??null)===1&&($binding['mailID']??null)===(int)$mail->getID()&&
      is_string($binding['deliveryID']??null)&&$binding['deliveryID']===($snapshot['deliveryID']??null)&&
      is_string($binding['messageHash']??null)&&preg_match('/\A[a-f0-9]{64}\z/',$binding['messageHash'])===1&&
      isset($snapshot['message'])&&is_array($snapshot['message']);
  }
}
