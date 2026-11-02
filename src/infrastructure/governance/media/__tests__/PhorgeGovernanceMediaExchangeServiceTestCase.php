<?php

final class PhorgeGovernanceMediaExchangeServiceTestCase extends PhutilTestCase {

  public function testConfigurationIsRegisteredDisabledAndLocked() {
    $options=PhabricatorApplicationConfigOptions::loadAllOptions();
    $key='governance.media-exchange.enabled';
    $this->assertTrue(isset($options[$key]));
    $option=$options[$key];
    $this->assertEqual('bool',$option->getType());
    $this->assertTrue($option->getDefault()===false);
    $this->assertTrue($option->getLocked()===true);
    $this->assertEqual(PhorgeGovernanceMediaConfigOptions::class,get_class($option->getGroup()));
    $defaults=(new PhabricatorConfigDefaultSource())->getKeys(array($key));
    $this->assertTrue(array_key_exists($key,$defaults) && $defaults[$key]===false);
  }

  public function testOpaqueSecretCanonicalShape() {
    $secret = str_repeat('a', 64);
    $this->assertEqual($secret, PhorgeGovernanceMediaExchangeService::validateSecret($secret));
    foreach (array('', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65),
      123, null, array(), $secret."\n") as $invalid) {
      $refused = false;
      try { PhorgeGovernanceMediaExchangeService::validateSecret($invalid); }
      catch (InvalidArgumentException $error) { $refused = true; }
      $this->assertTrue($refused);
    }
  }

  public function testBrowserGenerationIsCanonicalAndCannotWrap() {
    $this->assertEqual(2, PhorgeGovernanceMediaExchangeService::nextBrowserGeneration('1'));
    $this->assertEqual(3, PhorgeGovernanceMediaExchangeService::nextBrowserGeneration(2));
    foreach (array(0, '0', '01', '-1', 1.0, true, null, array(), '1e2',
      (string)PHP_INT_MAX, (string)PHP_INT_MAX.'0') as $value) {
      $refused=false;
      try { PhorgeGovernanceMediaExchangeService::nextBrowserGeneration($value); }
      catch (InvalidArgumentException $error) { $refused=true; }
      $this->assertTrue($refused);
    }
  }

  public function testTargetCannotBecomeAnOpenRedirectOrCredentialURL() {
    foreach (array('/file/data/key/PHID-FILE-a/name.png', '/file/xform/PHID-FILE-a/thumbnail/') as $path) {
      $this->assertEqual($path, PhorgeGovernanceMediaExchangeService::validateTarget(
        'phorge-files', 'private-media', $path));
    }
    foreach (array('//elsewhere/file/data/x', 'https://elsewhere/file/data/x',
      '/file/data/../secret', '/file/data/./secret', '/file/data/%2e%2e/secret', '/file/data/a?token=secret',
      '/file/data/a#fragment', '/file/data/a\\b', '/file/data//x', '/file/data/a'."\n",
      '/auth/login/', '/file/data/'.str_repeat('a', 1024)) as $path) {
      $refused = false;
      try { PhorgeGovernanceMediaExchangeService::validateTarget('phorge-files', 'private-media', $path); }
      catch (InvalidArgumentException $error) { $refused = true; }
      $this->assertTrue($refused);
    }
    foreach (array(array('other-files', 'private-media'), array('phorge-files', 'login')) as $pair) {
      $refused = false;
      try { PhorgeGovernanceMediaExchangeService::validateTarget($pair[0], $pair[1], '/file/data/x'); }
      catch (InvalidArgumentException $error) { $refused = true; }
      $this->assertTrue($refused);
    }
  }
}
