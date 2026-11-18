<?php

final class PhabricatorTraefikSessionIdentityTestCase
  extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  private function newProvider() {
    $provider = new PhabricatorTraefikAuthProvider();
    $config = $provider->getDefaultProviderConfig()
      ->setProviderType($provider->getProviderType())
      ->setProviderDomain($provider->getProviderDomain())
      ->setShouldAllowLink(true)
      ->save();
    return $provider->attachProviderConfig($config);
  }

  private function linkUser($provider, $user, $identity) {
    $identifiers = (new PhutilTraefikAuthAdapter())
      ->setAccountID($identity)
      ->getAccountIdentifiers();
    $method = new ReflectionMethod(
      PhabricatorAuthProvider::class, 'newExternalAccountForIdentifiers');
    $account = $method->invoke($provider, $identifiers);
    $account->setUserPHID($user->getPHID())->save();
    return $account;
  }

  public function testSessionMustMatchPersistedProviderIdentity() {
    $provider = $this->newProvider();
    $first = $this->generateNewTestUser();
    $second = $this->generateNewTestUser();
    $key = Filesystem::readRandomCharacters(24);
    $first_id = $key.'-first';
    $second_id = $key.'-second';
    $this->linkUser($provider, $first, $first_id);
    $this->linkUser($provider, $second, $second_id);

    $this->assertTrue($provider->isSessionIdentityCompatible($first, $first_id));
    $this->assertFalse($provider->isSessionIdentityCompatible($first, $second_id));
    $this->assertTrue($provider->isSessionIdentityCompatible($second, $second_id));
    $this->assertFalse($provider->isSessionIdentityCompatible($second, $first_id));
    $this->assertFalse($provider->isSessionIdentityCompatible($first, $key.'-unknown'));
    $this->assertFalse($provider->isSessionIdentityCompatible($first, ''));

  }

  public function testUnlinkingRemovesSessionCompatibility() {
    $provider = $this->newProvider();
    $user = $this->generateNewTestUser();
    $identity = Filesystem::readRandomCharacters(24);
    $account = $this->linkUser($provider, $user, $identity);
    $this->assertTrue($provider->isSessionIdentityCompatible($user, $identity));
    $account->unlinkAccount();
    $this->assertFalse($provider->isSessionIdentityCompatible($user, $identity));
  }

  private function newController($class, array $data = array()) {
    $request = id(new AphrontRequest('example.test', '/'))
      ->setURIMap($data);
    return id(newv($class, array()))->setRequest($request);
  }

  public function testLinkExceptionDoesNotPermitOrdinaryRequests() {
    $provider = $this->newProvider();
    $user = $this->generateNewTestUser();
    $identity = Filesystem::readRandomCharacters(24);
    $link = $this->newController(PhabricatorAuthLinkController::class, array(
      'action' => 'link', 'id' => $provider->getProviderConfig()->getID()));
    $login = $this->newController(PhabricatorAuthLoginController::class, array(
      'pkey' => $provider->getProviderKey()));
    $ordinary = $this->newController(PhabricatorAuthListController::class);
    $this->assertTrue($provider->canUseSessionForAccountLink($user, $identity, $link));
    $this->assertTrue($provider->canUseSessionForAccountLink($user, $identity, $login));
    $this->assertFalse($provider->canUseSessionForAccountLink($user, $identity, $ordinary));
    $provider->getProviderConfig()->setShouldAllowLink(false);
    $this->assertFalse($provider->canUseSessionForAccountLink($user, $identity, $link));
  }

  public function testLinkExceptionRejectsExistingBindings() {
    $provider = $this->newProvider();
    $first = $this->generateNewTestUser();
    $second = $this->generateNewTestUser();
    $identity = Filesystem::readRandomCharacters(24);
    $this->linkUser($provider, $first, $identity);
    $link = $this->newController(PhabricatorAuthLinkController::class, array(
      'action' => 'link', 'id' => $provider->getProviderConfig()->getID()));
    $this->assertFalse($provider->canUseSessionForAccountLink($first, 'other', $link));
    $this->assertFalse($provider->canUseSessionForAccountLink($second, $identity, $link));
  }

  public function testLinkValidationRequiresExactContinuation() {
    $provider = $this->newProvider();
    $user = $this->generateNewTestUser();
    $identity = Filesystem::readRandomCharacters(24);
    $original = $_COOKIE;
    try {
      foreach (array(PhabricatorAuthValidateController::class,
        PhabricatorAuthFinishController::class) as $class) {
        $controller = $this->newController($class);
        $_COOKIE[PhabricatorCookies::COOKIE_NEXTURI] = '/settings/';
        $this->assertFalse(
          $provider->canUseSessionForAccountLink($user, $identity, $controller));
        $_COOKIE[PhabricatorCookies::COOKIE_NEXTURI] =
          '/auth/link/'.$provider->getProviderConfig()->getID().'/';
        $this->assertTrue(
          $provider->canUseSessionForAccountLink($user, $identity, $controller));
      }
    } finally {
      $_COOKIE = $original;
    }
  }

  public function testLinkConfirmationRequiresExactUnlinkedAccount() {
    $provider = $this->newProvider();
    $user = $this->generateNewTestUser();
    $identity = Filesystem::readRandomCharacters(24);
    $method = new ReflectionMethod(
      PhabricatorAuthProvider::class, 'newExternalAccountForIdentifiers');
    $account = $method->invoke($provider,
      (new PhutilTraefikAuthAdapter())->setAccountID($identity)->getAccountIdentifiers());
    $account->save();
    $right = $this->newController(PhabricatorAuthConfirmLinkController::class,
      array('akey' => $account->getAccountSecret()));
    $wrong = $this->newController(PhabricatorAuthConfirmLinkController::class,
      array('akey' => 'wrong'));
    $this->assertTrue($provider->canUseSessionForAccountLink($user, $identity, $right));
    $this->assertFalse($provider->canUseSessionForAccountLink($user, $identity, $wrong));
  }

}
