<?php
final class PhorgePrivateMailPolicyTestCase extends PhutilTestCase {
  public function testSourcePendingHasABoundedCanonicalClockAndNeverGrantsPermission(){
    foreach(array(array(100,100),array('100','399'))as$pair){$this->assertTrue(PhorgePrivateMailPolicy::sourceCanStillCommit($pair[0],$pair[1]));}
    foreach(array(array(100,400),array(100,99),array('0100',100),array(100.0,100),array(true,100),array(null,100),array(0,100),array(100,4294967296))as$pair){
      $this->assertFalse(PhorgePrivateMailPolicy::sourceCanStillCommit($pair[0],$pair[1]));}
    $this->assertFalse(is_subclass_of(PhorgePrivateMailSourcePendingException::class,PhabricatorMetaMTAPermanentFailureException::class));
  }
  public function testGenericEnvelopeCannotCarryOriginalPrivateMetadata(){
    $source=(new PhabricatorMailEmailMessage())->setFromAddress(new PhutilEmailAddress('Private Actor <actor@example.invalid>'))
      ->setToAddresses(array(new PhutilEmailAddress('Private Team Member <member@example.invalid>')))
      ->setSubject('PRIVATE OBJECT CLOSED')->setTextBody('PRIVATE COMMENT')->setHTMLBody('<img src="private-capability">');
    $result=PhorgePrivateMailPolicy::newLinkOnlyMessage($source,'https://app.example.invalid/object/opaque/');
    $wire=(new PhabricatorGorgeMailerClient())->serializeMessage($result);
    $raw=phutil_json_encode($wire);
    foreach(array('Private Actor','Private Team Member','PRIVATE OBJECT','PRIVATE COMMENT','private-capability') as $private){
      $this->assertFalse(str_contains($raw,$private));
    }
    $this->assertEqual(array(),$result->getHeaders());$this->assertEqual(array(),$result->getAttachments());
    $this->assertEqual(null,$result->getHTMLBody());$this->assertEqual(null,$result->getReplyToAddress());
    $this->assertEqual('PRIVATE OBJECT CLOSED',$source->getSubject());
  }
  public function testSourceProvenanceDoesNotGuessSecurityFromForceOrNames(){
    $mail=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('ManiphestTransactionEditor');
    $this->assertEqual('business',PhorgePrivateMailPolicy::purpose($mail));
    $this->assertEqual(false,$mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false));
    $refused=false;try{$mail->getPrivateMailPolicyValue('gorge.delivery-snapshot');}
    catch(InvalidArgumentException $error){$refused=true;}
    $this->assertTrue($refused);
    $this->assertEqual('business',PhorgePrivateMailPolicy::classify('ManiphestTransactionEditor'));
    $this->assertEqual('security',PhorgePrivateMailPolicy::classify('PhabricatorPeopleEmailLoginMailEngine'));
    foreach(array(null,'security','forceDelivery','UnknownMailProducer','PhabricatorMailManagementSendTestWorkflow') as $unknown){
      $this->assertEqual('unknown',PhorgePrivateMailPolicy::classify($unknown));
    }
  }
  public function testWireCanonicalizationBindsContentWithoutOptionalEmptyRepresentation(){
    $a=array('subject'=>'Private notification','from'=>array('name'=>'','address'=>'synthetic@example.invalid'));
    $b=array('from'=>array('address'=>'synthetic@example.invalid'),'subject'=>'Private notification');
    $this->assertEqual(PhorgePrivateMailPolicy::canonicalMessage($a),PhorgePrivateMailPolicy::canonicalMessage($b));
    $b['subject']='Injected private title';
    $this->assertTrue(PhorgePrivateMailPolicy::canonicalMessage($a)!==PhorgePrivateMailPolicy::canonicalMessage($b));
  }
  public function testSecurityFlowRequiresExactProducerAndBoundDestination(){
    $user='PHID-USER-'.str_repeat('a',20);
    $binding=array('account_hash'=>str_repeat('a',64),'address_hash'=>str_repeat('b',64),
      'admin'=>null,'email'=>'PHID-UMAI-'.str_repeat('c',20),'recipient'=>$user,
      'source'=>null,'source_hash'=>null,'subject'=>$user);
    $mail=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('PhabricatorPeopleEmailLoginMailEngine');
    $mail->setPrivateMailFlow('account.login',$binding);
    $this->assertEqual($binding,$mail->getPrivateMailPolicyValue('governance.mail-binding'));
    $event_binding=$binding;$event_binding['source']=PhabricatorPHID::generateNewPHID('XACT','USER');
    $event_binding['source_hash']=str_repeat('c',64);
    (new PhabricatorMetaMTAMail())->setPrivateMailProducer('PhabricatorPeopleUsernameMailEngine')
      ->setPrivateMailFlow('account.username',$event_binding);
    foreach(array('wrong-producer','missing-destination','unknown-flow','missing-field')as$case){
      $bad=$binding;$flow='account.login';$producer='PhabricatorPeopleEmailLoginMailEngine';
      if($case==='wrong-producer'){$producer='PhabricatorUserEmail';}
      if($case==='missing-destination'){$bad['email']=null;}
      if($case==='unknown-flow'){$flow='security';}
      if($case==='missing-field'){unset($bad['source_hash']);}
      $refused=false;
      try{(new PhabricatorMetaMTAMail())->setPrivateMailProducer($producer)->setPrivateMailFlow($flow,$bad);}
      catch(InvalidArgumentException$error){$refused=true;}
      $this->assertTrue($refused,$case);
    }
  }
  public function testAdminNoticeCannotUseOwnerFlowOrBlessExistingMail(){
    $user='PHID-USER-'.str_repeat('a',20);$admin='PHID-USER-'.str_repeat('b',20);
    $binding=array('account_hash'=>str_repeat('a',64),'address_hash'=>str_repeat('b',64),
      'admin'=>$admin,'email'=>'PHID-UMAI-'.str_repeat('c',20),'recipient'=>$admin,
      'source'=>null,'source_hash'=>null,'subject'=>$user);
    $mail=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('PhabricatorAuthRegisterController');
    $mail->setPrivateMailFlow('account.approval.queue',$binding);
    $this->assertEqual('admin-notice',PhorgePrivateMailPolicy::purpose($mail));
    $bad=$binding;$bad['admin']=$user;$refused=false;
    try{PhorgePrivateMailPolicy::validateFlow($mail,'account.approval.queue',$bad);}
    catch(InvalidArgumentException$error){$refused=true;}
    $this->assertTrue($refused);
    $mail->setID(1);$refused=false;
    try{$mail->setPrivateMailFlow('account.approval.queue',$binding);}
    catch(InvalidArgumentException$error){$refused=true;}
    $this->assertTrue($refused);
    $signature=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('LegalpadDocumentSignController');
    $this->assertEqual('unsupported-signature',PhorgePrivateMailPolicy::purpose($signature));
  }
}
