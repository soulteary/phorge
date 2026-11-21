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


  public function testMediaIdentityRequiresAnExactCurrentProxyLink() {
    $server = $_SERVER;
    try {
      $first = $this->generateNewTestUser();
      $second = $this->generateNewTestUser();
      $unlinked = $this->generateNewTestUser();
      unset($_SERVER['HTTP_X_AUTH_USER']);
      $this->assertTrue(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($first));
      $_SERVER['HTTP_X_AUTH_USER'] = 'ignored-without-proxy-provider';
      $this->assertTrue(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($first));

      $provider = $this->newProvider();
      $prefix = Filesystem::readRandomCharacters(24);
      $first_identity = $prefix.'-a';
      $second_identity = $prefix.'-b';
      $first_link = $this->linkUser($provider, $first, $first_identity);
      $this->linkUser($provider, $second, $second_identity);
      foreach (array(null, '', $second_identity, $prefix.'-unknown') as $identity) {
        if ($identity === null) {
          unset($_SERVER['HTTP_X_AUTH_USER']);
        } else {
          $_SERVER['HTTP_X_AUTH_USER'] = $identity;
        }
        $this->assertFalse(
          PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($first));
      }
      $_SERVER['HTTP_X_AUTH_USER'] = $first_identity;
      $this->assertTrue(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($first));
      $this->assertFalse(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($second));
      $_SERVER['HTTP_X_AUTH_USER'] = $second_identity;
      $this->assertTrue(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($second));
      $_SERVER['HTTP_X_AUTH_EMAIL'] = $first->getUsername().'@example.com';
      $this->assertFalse(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($first));

      $unlinked_identity = $prefix.'-unlinked';
      $_SERVER['HTTP_X_AUTH_USER'] = $unlinked_identity;
      $link = $this->newController(PhabricatorAuthLinkController::class, array(
        'action' => 'link', 'id' => $provider->getProviderConfig()->getID()));
      $this->assertTrue(
        $provider->canUseSessionForAccountLink($unlinked, $unlinked_identity, $link));
      $this->assertFalse(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($unlinked));

      $first_link->unlinkAccount();
      $_SERVER['HTTP_X_AUTH_USER'] = $first_identity;
      $this->assertFalse(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($first));
      $provider->getProviderConfig()->setIsEnabled(0)->save();
      unset($_SERVER['HTTP_X_AUTH_USER']);
      $this->assertTrue(
        PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($first));
    } finally {
      $_SERVER = $server;
    }
  }

  public function testAmbiguousProxyProviderConfigurationFailsClosed() {
    $server = $_SERVER;
    try {
      $provider = $this->newProvider();
      (clone $provider->getProviderConfig())
        ->setID(null)->setPHID(null)
        ->setProviderDomain('unit-duplicate-adapter-domain')
        ->save();
      $user = $this->generateNewTestUser();
      $_SERVER['HTTP_X_AUTH_USER'] = 'unit-ambiguous-identity';
      foreach (array('app', 'media') as $scope) {
        try {
          if ($scope === 'media') {
            PhabricatorTraefikAuthProvider::canUseMediaSessionForProxyIdentity($user);
          } else {
            PhabricatorTraefikAuthProvider::canReuseSessionForProxyIdentity(
              $user, $this->newController(PhabricatorAuthListController::class));
          }
          $this->assertFailure('Duplicate adapter keys were accepted.');
        } catch (RuntimeException $error) {
          $this->assertEqual('proxy_identity_provider_ambiguous', $error->getMessage());
        }
      }
    } finally {
      $_SERVER = $server;
    }
  }

  private function executeSessionAdmission($token, $identity) {
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION] = $token;
    $_SERVER['HTTP_X_AUTH_USER'] = $identity;
    // Stop the actual controller only after it has performed native session
    // admission. No request user, authentication query or session is replaced.
    $request = (new AphrontRequest('phabricator.example.com', '/auth/'))
      ->setRequestData(array('code' => 'unit-stop-after-native-session'));
    $controller = (new PhabricatorAuthListController())->setRequest($request);
    try {
      $controller->willBeginExecution();
      $this->assertFailure('The post-admission restricted parameter was accepted.');
    } catch (Exception $error) {
      $this->assertTrue(
        strpos($error->getMessage(), 'Request includes restricted parameter') === 0);
    }
    return $request;
  }

  public function testProxyMismatchRevokesOnlyPresentedSessionAndNativeBinding() {
    $server = $_SERVER;
    $cookies = $_COOKIE;
    try {
      $provider = $this->newProvider();
      $first = $this->generateNewTestUser();
      $second = $this->generateNewTestUser();
      $prefix = Filesystem::readRandomCharacters(24);
      $this->linkUser($provider, $first, $prefix.'-a');
      $this->linkUser($provider, $second, $prefix.'-b');
      $engine = new PhabricatorAuthSessionEngine();
      $presented = $engine->establishSession(
        PhabricatorAuthSession::TYPE_WEB, $first->getPHID(), false);
      $unrelated = $engine->establishSession(
        PhabricatorAuthSession::TYPE_WEB, $first->getPHID(), false);
      $presented_user = $engine->loadUserForSession(
        PhabricatorAuthSession::TYPE_WEB, $presented);
      $unrelated_user = $engine->loadUserForSession(
        PhabricatorAuthSession::TYPE_WEB, $unrelated);
      $presented_session = $presented_user->getSession();
      $unrelated_session = $unrelated_user->getSession();
      $connection = $first->establishConnection('w');
      // This is the real native binding source used by media authentication.
      // It is not an issued media secret or HTTP/browser acceptance receipt.
      $binding = PhorgeGovernanceNativeIdentitySource::lockMediaBinding(
        $first->getPHID(), $presented_session->getPHID(),
        hash('sha256', $presented_session->getSessionKey()), null, $connection);
      $this->assertEqual($presented_session->getPHID(), $binding->getSessionPHID());

      $request = $this->executeSessionAdmission($presented, $prefix.'-b');
      $this->assertFalse($request->getUser()->isLoggedIn());
      $this->assertTrue(strpos(
        $_COOKIE[PhabricatorCookies::COOKIE_SESSION],
        PhabricatorAuthSessionEngine::KIND_ANONYMOUS.'/') === 0);
      $this->assertEqual(null,
        (new PhabricatorAuthSession())->loadOneWhere('id=%d', $presented_session->getID()));
      $this->assertTrue(
        (new PhabricatorAuthSession())->loadOneWhere('id=%d', $unrelated_session->getID()) !== null);
      $this->assertEqual(null, $engine->loadUserForSession(
        PhabricatorAuthSession::TYPE_WEB, $presented));
      $logs = (new PhabricatorUserLog())->loadAllWhere(
        'userPHID=%s AND action=%s AND session=%s', $first->getPHID(),
        PhabricatorLogoutUserLogType::LOGTYPE, $presented_session->getSessionKey());
      $this->assertEqual(1, count($logs));
      try {
        PhorgeGovernanceNativeIdentitySource::revalidate($binding, $connection);
        $this->assertFailure('A revoked native media binding was accepted.');
      } catch (RuntimeException $error) {
        $this->assertEqual('governance_native_credential_ineligible', $error->getMessage());
      }
      $remaining = PhorgeGovernanceNativeIdentitySource::lockMediaBinding(
        $first->getPHID(), $unrelated_session->getPHID(),
        hash('sha256', $unrelated_session->getSessionKey()),
        $binding->getAccountEpoch(), $connection);
      $this->assertEqual($unrelated_session->getPHID(), $remaining->getSessionPHID());
      $same = $this->executeSessionAdmission($unrelated, $prefix.'-a');
      $this->assertEqual($first->getPHID(), $same->getUser()->getPHID());
      $this->assertTrue(
        (new PhabricatorAuthSession())->loadOneWhere('id=%d', $unrelated_session->getID()) !== null);
    } finally {
      $_SERVER = $server;
      $_COOKIE = $cookies;
    }
  }

}
