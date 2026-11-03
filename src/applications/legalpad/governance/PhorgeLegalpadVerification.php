<?php

/** Authenticated native signature verification. No anonymous capability grant. */
final class PhorgeLegalpadVerification extends Phobject {
  private static function refused(){throw new PhorgeLegalpadVerificationException('legalpad_verification_unavailable');}
  public static function enabled(){return PhorgePrivateMailPolicy::enabled();}
  public static function signerIdentity($type,$signer,array $data){
    if($type===LegalpadDocument::SIGNATURE_TYPE_INDIVIDUAL){
      $actor=$signer;if(isset($data['actorPHID'])&&$data['actorPHID']!==$actor){self::refused();}
    }else if($type===LegalpadDocument::SIGNATURE_TYPE_CORPORATION&&$signer===null){$actor=idx($data,'actorPHID');}
    else{self::refused();}
    if(!is_string($actor)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$actor)){self::refused();}
    return$actor;
  }
  public static function securityFlag($value){
    if(in_array($value,array(false,0,'0'),true)){return false;}
    if(in_array($value,array(true,1,'1'),true)){return true;}
    self::refused();
  }
  public static function version($value){
    if((!is_int($value)&&!is_string($value))||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$value)||(float)$value>4294967295){self::refused();}
    return(int)$value;
  }
  public static function sourceDigest(LegalpadDocumentSignature $signature){
    $data=$signature->getSignatureData();if(!is_array($data)){self::refused();}
    $actor=self::signerIdentity($signature->getSignatureType(),$signature->getSignerPHID(),$data);
    if(!is_string($signature->getPHID())||!preg_match('/\APHID-LEGS-[a-zA-Z0-9]{20}\z/D',$signature->getPHID())||
      !is_string($signature->getDocumentPHID())||!preg_match('/\APHID-LEGD-[a-zA-Z0-9]{20}\z/D',$signature->getDocumentPHID())||$signature->getIsExemption()||!in_array($signature->getVerified(),array(1,'1'),true)||
      !is_string($signature->getSecretKey())||!preg_match('/\A[a-z2-7]{20}\z/D',$signature->getSecretKey())){self::refused();}
    return hash('sha256',phutil_json_encode(array('signature'=>$signature->getPHID(),'actor'=>$actor,
      'document'=>$signature->getDocumentPHID(),'version'=>self::version($signature->getDocumentVersion()),
      'secret'=>$signature->getSecretKey(),'email'=>$signature->getSignerEmail(),'type'=>$signature->getSignatureType())));
  }
  private static function profile(){
    if(!self::enabled()||PhabricatorEnv::getEnvConfig('cluster.databases')!==array()||PhabricatorEnv::isReadOnly()!==false){self::refused();}
  }
  private static function freshDocument(PhabricatorUser $viewer,$phid,$locked=null){
    $property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$old=$property->getValue();
    $property->setValue(null,new PhutilInRequestKeyValueCache());
    try{$doc=head((new LegalpadDocumentQuery())->setViewer($viewer)->withPHIDs(array($phid))->execute());}
    catch(PhabricatorPolicyException$error){self::refused();}
    finally{$property->setValue(null,$old);}
    if(!$doc||$doc->getPHID()!==$phid){self::refused();}
    if($locked!==null&&($doc->getPHID()!==$locked['phid']||$doc->getViewPolicy()!==$locked['viewPolicy']||
      $doc->getEditPolicy()!==$locked['editPolicy']||$doc->getCreatorPHID()!==$locked['creatorPHID']||
      self::version($doc->getVersions())!==self::version($locked['versions'])||$doc->getSignatureType()!==$locked['signatureType'])){self::refused();}
    return$doc;
  }
  /** Actual per-send source check, no session or endpoint permission invented. */
  public static function mailSource(LegalpadDocumentSignature $signature,PhabricatorUser $viewer){
    self::profile();$data=$signature->getSignatureData();if(!is_array($data)||idx($data,'email')!==$signature->getSignerEmail()){self::refused();}
    if(self::signerIdentity($signature->getSignatureType(),$signature->getSignerPHID(),$data)!==$viewer->getPHID()){self::refused();}
    $doc=self::freshDocument($viewer,$signature->getDocumentPHID());
    if(self::version($signature->getDocumentVersion())!==self::version($doc->getVersions())||$signature->getSignatureType()!==$doc->getSignatureType()){self::refused();}
    return self::sourceDigest($signature);
  }
  private static function contact(AphrontDatabaseConnection $conn,$user,$address){
    $rows=queryfx_all($conn,'SELECT * FROM %R WHERE BINARY userPHID=BINARY %s AND BINARY address=BINARY %s AND isVerified=1 LIMIT 2 LOCK IN SHARE MODE',new PhabricatorUserEmail(),$user,$address);
    if(count($rows)!==1||head($rows)['userPHID']!==$user||head($rows)['address']!==$address||!in_array(head($rows)['isVerified'],array(1,'1'),true)){self::refused();}
    return(new PhabricatorUserEmail())->loadFromArray(head($rows));
  }
  private static function finishTransaction(AphrontDatabaseConnection $conn,$commit,$read){
    $guard=$read?AphrontWriteGuard::beginScopedUnguardedWrites():null;
    try{if($commit){$conn->saveTransaction();}else{$conn->killTransaction();}}
    finally{if($guard!==null){unset($guard);}}
  }
  /** A request-local projection. POST owns the one-use state transition. */
  public static function inspect(AphrontRequest $request,$code,$verify=false){
    if(!is_bool($verify)){self::refused();}
    return self::run($request,$code,$verify?'verify':'read');
  }
  public static function prepareMail(AphrontRequest $request,$code){
    return self::run($request,$code,'mail')['mail_binding'];
  }
  public static function assertSigningContact(AphrontRequest $request,$address){
    self::profile();$request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    $conn=(new PhabricatorUser())->establishConnection('w');if($conn->isInsideTransaction()){self::refused();}
    $conn->openTransaction();try{$snapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      self::contact($conn,$snapshot->getUserPHID(),$address);PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn);$conn->saveTransaction();
    }catch(Throwable$error){$conn->killTransaction();throw$error;}
  }
  private static function run(AphrontRequest $request,$code,$action){
    $verify=$action==='verify';
    self::profile();if(!is_string($code)||!preg_match('/\A[a-z2-7]{20}\z/D',$code)||!is_bool($verify)){self::refused();}
    if($action!=='read'){if(!$request->isHTTPPost()){self::refused();}$request->validateCSRF();}
    else if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){self::refused();}
    $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    if($context->getCredentialType()!=='web'){self::refused();}
    $conn=(new PhabricatorUser())->establishConnection('w');$legal=(new LegalpadDocument())->establishConnection('w');
    if(get_class($conn)!==AphrontMySQLiDatabaseConnection::class||get_class($legal)!==AphrontMySQLiDatabaseConnection::class||
      $conn->isInsideTransaction()||$legal->isInsideTransaction()){self::refused();}
    $a=queryfx_one($conn,'SELECT @@server_uuid u');$b=queryfx_one($legal,'SELECT @@server_uuid u,DATABASE() d');
    if(!$a||!$b||!is_string($a['u'])||$a['u']!==$b['u']||$b['d']!==(new LegalpadDocument())->getAphrontRefDatabaseName()){self::refused();}
    $unguarded=$action==='read'?AphrontWriteGuard::beginScopedUnguardedWrites():null;$owned=false;
    try{
      $conn->openTransaction();$owned=true;$snapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      // Restore the GET web guard before running any native policy/application
      // code. Only source metadata initialization and txn bookkeeping bypass it.
      if($unguarded!==null){unset($unguarded);$unguarded=null;}
      foreach(array(new LegalpadDocument(),new LegalpadDocumentSignature(),new PhabricatorUserEmail())as$table){
        queryfx_one($conn,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$table);
        $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$table->getAphrontRefDatabaseName(),$table->getTableName());
        if(!$engine||$engine['ENGINE']!=='InnoDB'){self::refused();}
      }
      $sizes=queryfx_all($conn,'SELECT id,OCTET_LENGTH(signatureData) n FROM %R WHERE secretKey=%s LIMIT 2 FOR UPDATE',new LegalpadDocumentSignature(),$code);
      if(count($sizes)!==1||(int)head($sizes)['n']>16384){self::refused();}
      $row=queryfx_one($conn,'SELECT * FROM %R WHERE id=%d AND secretKey=%s FOR UPDATE',new LegalpadDocumentSignature(),head($sizes)['id'],$code);
      if(!$row||!hash_equals($code,$row['secretKey'])){self::refused();}
      $signature=(new LegalpadDocumentSignature())->loadFromArray($row);$data=$signature->getSignatureData();
      if(!is_array($data)||self::signerIdentity($signature->getSignatureType(),$signature->getSignerPHID(),$data)!==$snapshot->getUserPHID()||
        $signature->getIsExemption()||!in_array($signature->getVerified(),array(0,1,'0','1'),true)){self::refused();}
      $user_row=queryfx_one($conn,'SELECT * FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',new PhabricatorUser(),$snapshot->getUserPHID());
      $viewer=head((new PhabricatorPeopleQuery())->setViewer($request->getViewer())->withPHIDs(array($snapshot->getUserPHID()))->needProfileImage(false)->execute());
      if(!$viewer||$viewer->getPHID()!==$user_row['phid']){self::refused();}
      foreach(array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList')as$flag){$method='get'.ucfirst($flag);
        if(self::securityFlag($viewer->$method())!==self::securityFlag($user_row[$flag])){self::refused();}}
      if(!hash_equals($viewer->getAccountSecret(),$user_row['accountSecret'])){self::refused();}
      $viewer->attachSession($request->getViewer()->getSession());
      $email=self::contact($conn,$snapshot->getUserPHID(),$signature->getSignerEmail());
      if(!hash_equals($email->getAddress(),$signature->getSignerEmail())||idx($data,'email')!==$signature->getSignerEmail()){self::refused();}
      $locked=queryfx_one($conn,'SELECT id,phid,versions,creatorPHID,viewPolicy,editPolicy,signatureType FROM %R WHERE BINARY phid=BINARY %s FOR UPDATE',new LegalpadDocument(),$signature->getDocumentPHID());
      if(!$locked||self::version($signature->getDocumentVersion())!==self::version($locked['versions'])||$signature->getSignatureType()!==$locked['signatureType']){self::refused();}
      $doc=self::freshDocument($viewer,$signature->getDocumentPHID(),$locked);$already=$signature->isVerified();
      if($verify&&!$already){
        queryfx($conn,'UPDATE %R SET verified=%d,dateModified=UNIX_TIMESTAMP() WHERE id=%d AND secretKey=%s AND documentVersion=%d AND verified=%d',
          new LegalpadDocumentSignature(),LegalpadDocumentSignature::VERIFIED,$signature->getID(),$code,$signature->getDocumentVersion(),LegalpadDocumentSignature::UNVERIFIED);
        if($conn->getAffectedRows()!==1){self::refused();}$signature->setVerified(LegalpadDocumentSignature::VERIFIED);
      }
      // Native user/session and direct document policy are locked; query native
      // application/custom-policy dependencies again, without claiming closure.
      self::freshDocument($viewer,$signature->getDocumentPHID(),$locked);
      $current=self::contact($conn,$snapshot->getUserPHID(),$signature->getSignerEmail());if($current->getPHID()!==$email->getPHID()||$current->getAddress()!==$email->getAddress()){self::refused();}
      PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn);self::profile();
      $binding=$action==='mail'?PhorgePrivateMailPolicy::userBinding($viewer,$viewer,$email,null,$signature->getPHID(),self::sourceDigest($signature)):null;
      $signature->attachDocument($doc);self::finishTransaction($conn,true,$action==='read');$owned=false;
      return array('signature'=>$signature,'document'=>$doc,'already_verified'=>$already,'verified'=>$verify||$already,
        'viewer'=>$viewer,'email'=>$email,'mail_binding'=>$binding);
    }catch(Throwable$error){if($unguarded!==null){unset($unguarded);$unguarded=null;}if($owned){self::finishTransaction($conn,false,$action==='read');}throw$error;}
    finally{if($unguarded!==null){unset($unguarded);}}
  }
}
