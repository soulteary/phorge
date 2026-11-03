<?php
/** Genuine Legalpad editors, sign controller, native sessions and verification route. */
function governance_private_mail_legalpad_cases($fixture,callable $check){
  $fixture->assertOwned();$suffix=substr($fixture->getNamespace(),-10);$cookie=$_COOKIE;$server=$_SERVER;$post=$_POST;
  $cache=(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->getValue();
  try{
    $users=array();$auth=array();$sessions=new PhabricatorAuthSessionEngine();
    foreach(array('author','signer','other')as$role){$users[$role]=(new PhabricatorUser())->setUsername('pmlegal'.$role.$suffix)
      ->setRealName('Synthetic Legalpad '.$role)->setIsApproved(1)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($users[$role]->getPHID())->setAddress($users[$role]->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      $auth[$role]=governance_native_request($users[$role],$sessions);
    }
    $alias=(new PhabricatorUserEmail())->setUserPHID($users['signer']->getPHID())->setAddress('verifiedalias'.$suffix.'@example.invalid')
      ->setIsPrimary(0)->setIsVerified(1)->save();
    $doc=LegalpadDocument::initializeNewDocument($auth['author']['viewer'])->attachDocumentBody((new LegalpadDocumentBody())->setCreatorPHID($users['author']->getPHID())->setTitle('')->setText(''))
      ->setDocumentBodyPHID(PhabricatorPHIDConstants::PHID_VOID)
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    (new LegalpadDocumentEditor())->setActor($auth['author']['viewer'])->setContentSourceFromRequest($auth['author']['request'])->setIsSilent(true)
      ->applyTransactions($doc,array((new LegalpadTransaction())->setTransactionType(LegalpadDocumentTitleTransaction::TRANSACTIONTYPE)->setNewValue('PRIVATE LEGALPAD TITLE'),
        (new LegalpadTransaction())->setTransactionType(LegalpadDocumentTextTransaction::TRANSACTIONTYPE)->setNewValue('PRIVATE LEGALPAD TERMS'),
        (new LegalpadTransaction())->setTransactionType(LegalpadDocumentSignatureTypeTransaction::TRANSACTIONTYPE)->setNewValue(LegalpadDocument::SIGNATURE_TYPE_CORPORATION)));
    $last_html='';
    $call=static function($session,$path,$method,array $data=array())use(&$last_html){
      $celerity=new ReflectionProperty(CelerityAPI::class,'response');$saved=$celerity->getValue();$celerity->setValue(null,null);
      try{
      $_SERVER['REQUEST_METHOD']=$method;$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_HOST']='fixture.invalid';unset($_SERVER['HTTPS']);
      $_SERVER['HTTP_ORIGIN']='http://fixture.invalid';$_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';$_SERVER['QUERY_STRING']=$method==='GET'?http_build_query($data):'';
      $_POST=$method==='POST'?$data:array();$_COOKIE=array();if($session!==null){$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];}
      (new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,null);
      $configuration=(new AphrontApplicationConfiguration())->setHost('fixture.invalid')->setPath($path);$request=$configuration->buildRequest();$configuration->setRequest($request);
      $site=(new PhabricatorPlatformSite())->newSiteForRequest($request);if(!$site){throw new RuntimeException('legalpad_fixture_site_missing');}
      $matched=null;foreach($site->getRoutingMaps()as$map){$matched=$map->routePath($path);if($matched){break;}}
      if(!$matched){throw new RuntimeException('legalpad_fixture_route_missing');}$request->setURIMap($matched->getURIData());$controller=$matched->getController()->setRequest($request);
      return governance_native_with_request_write_guard($request,static function()use($controller,$request,$configuration,&$last_html){$response=$controller->willBeginExecution();
        if($response===null){$response=$controller->handleRequest($request);}
        $response=(new ReflectionMethod(AphrontApplicationConfiguration::class,'produceResponse'))->invoke($configuration,$request,$response);
        $last_html=(string)$response->buildResponseString();return$response;});
      }finally{$celerity->setValue(null,$saved);}
    };
    $mail_table=new PhabricatorMetaMTAMail();$before=(int)queryfx_one($mail_table->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$mail_table)['n'];
    $form=array('__form__'=>true,'agree'=>true,'name'=>'Synthetic company','address'=>'Synthetic address','contact.name'=>'Synthetic contact',
      'email'=>$alias->getAddress(),AphrontRequest::getCSRFTokenName()=>$auth['signer']['viewer']->getCSRFToken());
    $signed=$call($auth['signer'],'/L'.$doc->getID(),'POST',$form);
    $signatures=(new LegalpadDocumentSignature())->loadAllWhere('documentPHID=%s',$doc->getPHID());
    $check($signed instanceof AphrontRedirectResponse&&count($signatures)===1,'private-mail-legalpad-actual-corporate-sign-controller-and-native-high-security-path-commits');
    $signature=head($signatures);$code=$signature->getSecretKey();$path='/legalpad/verify/'.$code.'/';
    $check($signature->getSignerPHID()===null&&idx($signature->getSignatureData(),'actorPHID')===$users['signer']->getPHID()&&
      !$signature->isVerified()&&$signature->getSignerEmail()===$alias->getAddress(),
      'private-mail-legalpad-corporate-native-actor-remains-distinct-from-null-signer-and-verified-secondary-contact');
    $mails=$mail_table->loadAllWhere('id>%d ORDER BY id',$before);$messages=array();foreach($mails as$mail){if($mail->getPrivateMailPolicyValue('governance.mail-flow')==='legalpad.signature'){$messages[]=$mail;}}
    $check(count($messages)===1&&PhorgePrivateMailPolicy::authorize(head($messages))&&
      !str_contains(head($messages)->getBody(),'PRIVATE LEGALPAD TITLE')&&!str_contains(head($messages)->getBody(),'Synthetic company')&&
      head($messages)->getPrivateMailPolicyValue('governance.mail-binding')['email']===$alias->getPHID(),
      'private-mail-legalpad-genuine-producer-binds-owned-verified-secondary-and-generic-challenge-without-private-document-content');
    $anonymous=$call(null,$path,'GET');$anonymous_html=$last_html;$other=$call($auth['other'],$path,'GET');$other_html=$last_html;
    $check(!str_contains($anonymous_html,'PRIVATE LEGALPAD TITLE')&&!str_contains($anonymous_html,$alias->getAddress())&&$other instanceof Aphront403Response&&
      !str_contains($other_html,'PRIVATE LEGALPAD TITLE')&&!str_contains($other_html,$alias->getAddress()),
      'private-mail-legalpad-anonymous-and-switched-account-get-cannot-read-title-signer-or-email');
    $get=$call($auth['signer'],$path,'GET');
    $check($get instanceof AphrontDialogResponse&&!((new LegalpadDocumentSignature())->load($signature->getID()))->isVerified(),
      'private-mail-legalpad-matching-native-session-and-current-view-can-read-verification-without-consuming');
    $csrf_denied=false;try{$call($auth['signer'],$path,'POST',array('__form__'=>true));}
    catch(AphrontMalformedRequestException$error){$csrf_denied=true;}
    $check($csrf_denied&&!((new LegalpadDocumentSignature())->load($signature->getID()))->isVerified(),
      'private-mail-legalpad-real-csrf-failure-does-not-consume-signature');
    $doc->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $denied=$call($auth['signer'],$path,'GET');
    $check($denied instanceof Aphront403Response&&!PhorgePrivateMailPolicy::authorize(head($messages))&&
      !((new LegalpadDocumentSignature())->load($signature->getID()))->isVerified(),
      'private-mail-legalpad-current-document-view-revocation-denies-endpoint-and-queued-mail');
    $doc->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();$users['signer']->setIsDisabled(1)->save();
    $disabled=$call($auth['signer'],$path,'GET');$disabled_html=$last_html;
    $disabled_post=$call($auth['signer'],$path,'POST',array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$auth['signer']['viewer']->getCSRFToken()));
    $check(!str_contains($disabled_html,'PRIVATE LEGALPAD TITLE')&&!str_contains($last_html,'PRIVATE LEGALPAD TITLE')&&
      !str_contains($disabled_html,$alias->getAddress())&&!str_contains($last_html,$alias->getAddress())&&
      !((new LegalpadDocumentSignature())->load($signature->getID()))->isVerified()&&!PhorgePrivateMailPolicy::authorize(head($messages)),
      'private-mail-legalpad-disabled-account-cannot-verify-or-authorize-queued-challenge');
    $users['signer']->setIsDisabled(0)->save();$auth['signer']=governance_native_request($users['signer'],$sessions);
    $old_code=$code;$signature->setSecretKey(Filesystem::readRandomCharacters(20))->save();$code=$signature->getSecretKey();$path='/legalpad/verify/'.$code.'/';
    $old=$call($auth['signer'],'/legalpad/verify/'.$old_code.'/','GET');
    $check($old instanceof Aphront403Response&&!PhorgePrivateMailPolicy::authorize(head($messages)),
      'private-mail-legalpad-native-secret-rotation-invalidates-old-link-and-immutable-old-mail');
    require_once __DIR__.'/legalpad-boundary-cases.php';
    $auth['signer']=governance_private_mail_legalpad_boundary_cases($fixture,$check,array('document'=>$doc,'signature'=>$signature,
      'alias'=>$alias,'signer_auth'=>$auth['signer'],'sessions'=>$sessions,'call'=>$call,'signer'=>$users['signer'],
      'last_html'=>static function()use(&$last_html){return$last_html;}));
    require_once __DIR__.'/legalpad-policy-cases.php';
    $policy_tests=governance_private_mail_legalpad_policy_cases($fixture,$check,array('document'=>$doc,'signature'=>$signature,
      'signer_auth'=>$auth['signer'],'author_auth'=>$auth['author'],'call'=>$call));
    require_once __DIR__.'/legalpad-inflight-cases.php';
    $inflight=governance_private_mail_legalpad_inflight_cases($fixture,$check,array('document'=>$doc,'signature'=>$signature,'signer_auth'=>$auth['signer'],'call'=>$call));
    require_once __DIR__.'/legalpad-concurrency-cases.php';
    $concurrency=governance_private_mail_legalpad_concurrency_cases($fixture,$check,array('signature'=>$signature,'auth'=>$auth['signer']));
    $csrf=AphrontRequest::getCSRFTokenName();$confirmed=$call($auth['signer'],$path,'POST',array('__form__'=>true,$csrf=>$auth['signer']['viewer']->getCSRFToken()));
    $stored=(new LegalpadDocumentSignature())->load($signature->getID());$repeat=$call($auth['signer'],$path,'POST',array('__form__'=>true,$csrf=>$auth['signer']['viewer']->getCSRFToken()));
    $check($confirmed instanceof AphrontDialogResponse&&$repeat instanceof AphrontDialogResponse&&$stored->isVerified()&&
      ((new LegalpadDocumentSignature())->load($signature->getID()))->getVerified()===$stored->getVerified()&&$stored->getSignerPHID()===null,
      'private-mail-legalpad-after-concurrent-cas-sequential-repeat-keeps-state-and-corporate-null-signer');
    return array('actual_corporate_sign_and_verification_routes'=>true,'verified_secondary_contact_supported'=>true,
      'anonymous_raw_or_third_party_adoption_supported'=>false,'current_view_and_identity_rechecked'=>true,
      'configured_mfa_factor_proved'=>false,'concurrent_process_verification_proved'=>true,'policy_source_tests'=>$policy_tests,'inflight_source_tests'=>$inflight,'concurrency'=>$concurrency,'physical_smtp_legalpad_proved'=>false);
  }finally{$_COOKIE=$cookie;$_SERVER=$server;$_POST=$post;(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,$cache);}
}
