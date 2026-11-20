<?php

final class PhabricatorSetupCheckTestCase extends PhabricatorTestCase {

  public function testLoadAllChecks() {
    PhabricatorSetupCheck::loadAllChecks();
    $this->assertTrue(true);
  }

  public function testAnonymousWebserverChecksRespectEnabledForwardAuth() {
    $proxy = id(new PhabricatorAuthProviderConfig())
      ->setProviderClass('PhabricatorTraefikAuthProvider')
      ->setIsEnabled(true);
    $password = id(new PhabricatorAuthProviderConfig())
      ->setProviderClass('PhabricatorPasswordAuthProvider')
      ->setIsEnabled(true);
    $this->assertFalse(
      PhabricatorWebServerSetupCheck::shouldSkipAnonymousChecks(array()));
    $this->assertFalse(
      PhabricatorWebServerSetupCheck::shouldSkipAnonymousChecks(array($password)));
    $this->assertTrue(
      PhabricatorWebServerSetupCheck::shouldSkipAnonymousChecks(array($proxy)));
    $this->assertTrue(
      PhabricatorWebServerSetupCheck::shouldSkipAnonymousChecks(array($password, $proxy)));
    $proxy->setIsEnabled(false);
    $this->assertFalse(
      PhabricatorWebServerSetupCheck::shouldSkipAnonymousChecks(array($proxy)));
  }

}
