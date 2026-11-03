<?php
final class PhorgePrivateMailPolicyTestCase extends PhutilTestCase {
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
}
