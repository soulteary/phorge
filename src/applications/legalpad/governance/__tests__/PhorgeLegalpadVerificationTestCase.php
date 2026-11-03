<?php
final class PhorgeLegalpadVerificationTestCase extends PhutilTestCase {
  public function testSignerAccountComesFromNativeIndividualOrCorporateActorOnly(){
    $user='PHID-USER-'.str_repeat('a',20);$other='PHID-USER-'.str_repeat('b',20);
    $this->assertEqual($user,PhorgeLegalpadVerification::signerIdentity('user',$user,array('email'=>'alias@example.invalid')));
    $this->assertEqual($user,PhorgeLegalpadVerification::signerIdentity('corp',null,array('actorPHID'=>$user,'email'=>'alias@example.invalid')));
    foreach(array(array('user',null,array('email'=>'alias@example.invalid')),array('corp',null,array('email'=>'alias@example.invalid')),
      array('corp',$user,array('actorPHID'=>$user)),array('user',$user,array('actorPHID'=>$other)),array('unknown',$user,array()))as$case){
      $refused=false;try{PhorgeLegalpadVerification::signerIdentity($case[0],$case[1],$case[2]);}catch(PhorgeLegalpadVerificationException$error){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testNativeBooleanFlagsAndCanonicalDatabaseBitsHaveTheSameMeaning(){
    foreach(array(false,0,'0')as$value){$this->assertFalse(PhorgeLegalpadVerification::securityFlag($value));}
    foreach(array(true,1,'1')as$value){$this->assertTrue(PhorgeLegalpadVerification::securityFlag($value));}
    foreach(array(null,'',2,'01',0.0,array())as$value){$refused=false;try{PhorgeLegalpadVerification::securityFlag($value);}
      catch(PhorgeLegalpadVerificationException$error){$refused=true;}$this->assertTrue($refused);}
  }
  public function testDocumentVersionMustBeCanonicalPositiveUint32(){
    foreach(array(1,'1',4294967295,'4294967295')as$value){$this->assertEqual((int)$value,PhorgeLegalpadVerification::version($value));}
    foreach(array(null,true,1.0,0,-1,'01','1.0',4294967296)as$value){$refused=false;try{PhorgeLegalpadVerification::version($value);}
      catch(PhorgeLegalpadVerificationException$error){$refused=true;}$this->assertTrue($refused);}
  }
  public function testNativeVerificationBindingIncludesSecretVersionActorAndContact(){
    $user='PHID-USER-'.str_repeat('a',20);$signature=(new LegalpadDocumentSignature())->setPHID('PHID-LEGS-'.str_repeat('b',20))
      ->setDocumentPHID('PHID-LEGD-'.str_repeat('c',20))->setDocumentVersion(1)->setSignatureType('corp')->setSignerPHID(null)
      ->setSignatureData(array('actorPHID'=>$user,'email'=>'alias@example.invalid'))->setSignerEmail('alias@example.invalid')
      ->setVerified(LegalpadDocumentSignature::UNVERIFIED)->setSecretKey(str_repeat('a',20));
    $digest=PhorgeLegalpadVerification::sourceDigest($signature);
    foreach(array('setSecretKey'=>str_repeat('b',20),'setDocumentVersion'=>2,'setSignerEmail'=>'another@example.invalid')as$method=>$value){
      $copy=clone$signature;$copy->$method($value);$this->assertTrue(PhorgeLegalpadVerification::sourceDigest($copy)!==$digest);
    }
    foreach(array(0,null,2)as$verified){$copy=clone$signature;$copy->setVerified($verified);$refused=false;
      try{PhorgeLegalpadVerification::sourceDigest($copy);}catch(PhorgeLegalpadVerificationException$error){$refused=true;}$this->assertTrue($refused);}
    $this->assertEqual(null,$signature->getSignerPHID());
  }
  public function testRawLegacySignatureIsNotReclassifiedAsASafeChallenge(){
    $raw=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('LegalpadDocumentSignController');
    $this->assertEqual('unsupported-signature',PhorgePrivateMailPolicy::purpose($raw));
    $user='PHID-USER-'.str_repeat('a',20);$binding=array('account_hash'=>str_repeat('a',64),'address_hash'=>str_repeat('b',64),
      'subject'=>$user,'recipient'=>$user,'admin'=>null,'email'=>'PHID-UMAI-'.str_repeat('c',20),
      'source'=>'PHID-LEGS-'.str_repeat('d',20),'source_hash'=>str_repeat('e',64));
    $raw->setPrivateMailFlow('legalpad.signature',$binding);$this->assertEqual('security',PhorgePrivateMailPolicy::purpose($raw));
    $binding['source']=null;$refused=false;try{$raw->setPrivateMailFlow('legalpad.signature',$binding);}
    catch(InvalidArgumentException$error){$refused=true;}$this->assertTrue($refused);
  }
}
