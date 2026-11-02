<?php

final class PhorgeGovernanceMediaIssuerTestCase extends PhutilTestCase {
  public function testSecretDigestsAreKeyIncarnationAndPurposeSeparated() {
    $key=new PhutilOpaqueEnvelope(str_repeat('a',32));
    $other=new PhutilOpaqueEnvelope(str_repeat('b',32));
    $values=array();
    foreach (array('browser','transaction','code','credential') as $purpose) {
      $digest=PhorgeGovernanceMediaIssuer::digestValue($key,str_repeat('1',32),$purpose,str_repeat('c',64));
      $this->assertEqual(64,strlen($digest)); $values[]=$digest;
      $this->assertTrue($digest!==hash('sha256',str_repeat('c',64)));
      $this->assertTrue($digest!==PhorgeGovernanceMediaIssuer::digestValue($other,str_repeat('1',32),$purpose,str_repeat('c',64)));
      $this->assertTrue($digest!==PhorgeGovernanceMediaIssuer::digestValue($key,str_repeat('2',32),$purpose,str_repeat('c',64)));
    }
    $this->assertEqual(4,count(array_unique($values)));
  }

  public function testUnknownIssuerInputsCannotFallBackToUnkeyedHashes() {
    foreach (array(
      array('',str_repeat('1',32),'credential','token'),
      array(str_repeat('a',32),'legacy','credential','token'),
      array(str_repeat('a',32),str_repeat('1',32),'unknown','token'),
      array(str_repeat('a',32),str_repeat('1',32),'code',str_repeat('x',8193)),
    ) as $input) {
      $refused=false;
      try { PhorgeGovernanceMediaIssuer::digestValue(new PhutilOpaqueEnvelope($input[0]),$input[1],$input[2],$input[3]); }
      catch (InvalidArgumentException $error) { $refused=true; }
      $this->assertTrue($refused);
    }
  }

  public function testExternalKeyIsARegisteredHiddenLockedSetting() {
    $options=PhabricatorApplicationConfigOptions::loadAllOptions();
    $option=$options['governance.media-exchange.issuer-key'];
    $this->assertTrue($option->getDefault()===null);
    $this->assertTrue($option->getHidden()===true && $option->getLocked()===true);
    $this->assertEqual('string',$option->getType());
    $this->assertTrue($options['governance.media-exchange.incarnation']->getDefault()===null);
  }
}
