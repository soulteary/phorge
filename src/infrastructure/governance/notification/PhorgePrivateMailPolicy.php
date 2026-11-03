<?php
/** Fixed source provenance and fresh native recipient/object query at egress. */
final class PhorgePrivateMailPolicy extends Phobject {
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
    return self::classify($mail->getPrivateMailPolicyValue('governance.mail-producer'));
  }
  public static function isLinkOnly(PhabricatorMetaMTAMail $mail){
    return self::enabled()&&in_array(self::purpose($mail),array('business','private-error'),true);
  }
  public static function authorize(PhabricatorMetaMTAMail $mail){
    if(!self::enabled()){return true;}
    $property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');
    $prior=$property->getValue();$property->setValue(null,new PhutilInRequestKeyValueCache());
    try{return self::authorizeInCurrentCache($mail);}
    finally{$property->setValue(null,$prior);}
  }
  private static function authorizeInCurrentCache(PhabricatorMetaMTAMail $mail){
    if($mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)!==false){return false;}
    $purpose=self::purpose($mail);
    // Explicit native account/challenge producers keep native force/raw-address
    // verification semantics. They cannot be selected by an incoming mail field.
    if($purpose==='security'){return true;}
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
    $uri=self::purpose($mail)==='business'?'/object/'.$mail->getRelatedPHID().'/':'/';
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
    $uri=self::purpose($mail)==='business'?'/object/'.$mail->getRelatedPHID().'/':'/';
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
    return !self::enabled()||($mail->getPrivateMailPolicyValue('governance.delivery-policy')===1&&
      isset($snapshot['authorization']['version'])&&$snapshot['authorization']['version']===1);
  }
}
