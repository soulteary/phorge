<?php

final class PhabricatorTraefikAuthProviderTestCase extends PhutilTestCase {

  private function newProvider($registration = false) {
    $provider = new PhabricatorTraefikAuthProvider();
    $config = $provider->getDefaultProviderConfig()
      ->setProviderType($provider->getProviderType())
      ->setProviderDomain($provider->getProviderDomain())
      ->setShouldAllowRegistration($registration);
    return $provider->attachProviderConfig($config);
  }

  private function newRequest() {
    return id(new AphrontRequest('phorge.example.test', '/auth/start/'))
      // Render-only tests do not need a database-backed CSRF secret.
      ->setUser(PhabricatorUser::getOmnipotentUser());
  }

  private function parseHTML($html) {
    $dom = new DOMDocument();
    $dom->loadHTML((string)$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return $dom;
  }

  public function testForwardAuthUsesTopLevelNavigation() {
    $provider = $this->newProvider();
    $method = new ReflectionMethod($provider, 'renderLoginForm');
    $dom = $this->parseHTML(
      $method->invoke($provider, $this->newRequest(), 'start'));
    $links = $dom->getElementsByTagName('a');
    $this->assertEqual(1, $links->length);
    $this->assertEqual(0, $dom->getElementsByTagName('form')->length);
    $this->assertEqual(
      $provider->getLoginURI(), $links->item(0)->getAttribute('href'));
    $this->assertEqual('', $links->item(0)->getAttribute('data-sigil'));
    $this->assertTrue(strpos($links->item(0)->textContent, 'Traefik Auth') !== false);
  }

  public function testNavigationPreservesLocalizedModeLabels() {
    $provider = $this->newProvider();
    $method = new ReflectionMethod($provider, 'renderLoginForm');
    foreach (array('start' => pht('Log In'),
      'link' => pht('Link External Account'),
      'refresh' => pht('Refresh Account Link'),
      'invite' => pht('Register Account')) as $mode => $label) {
      $dom = $this->parseHTML(
        $method->invoke($provider, $this->newRequest(), $mode));
      $this->assertTrue(strpos($dom->textContent, $label) !== false);
    }
    $provider = $this->newProvider(true);
    $dom = $this->parseHTML(
      $method->invoke($provider, $this->newRequest(), 'start'));
    $this->assertTrue(strpos($dom->textContent, pht('Log In or Register')) !== false);
  }

  public function testNavigationPreservesQueryAndEscapesURI() {
    $provider = $this->newProvider();
    $method = new ReflectionMethod($provider, 'renderStandardLoginButton');
    $uri = '/auth/login/traefik:traefik/?state=one&invite=two%20words';
    $dom = $this->parseHTML($method->invoke($provider, $this->newRequest(),
      'start', array('uri' => $uri, 'navigation' => true)));
    $this->assertEqual($uri,
      $dom->getElementsByTagName('a')->item(0)->getAttribute('href'));
    $this->assertEqual(0, $dom->getElementsByTagName('input')->length);
  }

  public function testNavigationRejectsStateChangingMethods() {
    $provider = $this->newProvider();
    $method = new ReflectionMethod($provider, 'renderStandardLoginButton');
    $rejected = false;
    try {
      $method->invoke($provider, $this->newRequest(), 'start', array(
        'uri' => '/auth/login/traefik:traefik/', 'method' => 'POST',
        'navigation' => true));
    } catch (InvalidArgumentException $ex) {
      $rejected = true;
    }
    $this->assertTrue($rejected);
  }

  public function testExistingProviderFormContractIsUnchanged() {
    $provider = $this->newProvider();
    $method = new ReflectionMethod($provider, 'renderStandardLoginButton');
    $dom = $this->parseHTML($method->invoke($provider, $this->newRequest(),
      'start', array('uri' => '/auth/login/example/?state=one&next=two',
        'method' => 'POST')));
    $forms = $dom->getElementsByTagName('form');
    $this->assertEqual(1, $forms->length);
    $this->assertEqual('POST', strtoupper($forms->item(0)->getAttribute('method')));
    $this->assertEqual('/auth/login/example/', $forms->item(0)->getAttribute('action'));
    $this->assertEqual(0, $dom->getElementsByTagName('a')->length);
  }

  public function testStableIdentityDoesNotFollowDisplayFields() {
    $adapter = (new PhutilTraefikAuthAdapter())
      ->setAccountID('directory-user-101')
      ->setAccountEmail('first@example.test')
      ->setAccountName('first')
      ->setAccountRealName('First Name');
    $before = mpull($adapter->getAccountIdentifiers(), 'getIdentifierRaw');
    $adapter->setAccountEmail('changed@example.test')
      ->setAccountName('changed')->setAccountRealName('Changed Name');
    $this->assertEqual(array('directory-user-101'), $before);
    $this->assertEqual($before,
      mpull($adapter->getAccountIdentifiers(), 'getIdentifierRaw'));
  }

  public function testSameEmailDoesNotMergeDistinctDirectoryIdentities() {
    $first = (new PhutilTraefikAuthAdapter())
      ->setAccountID('directory-user-101')->setAccountEmail('same@example.test');
    $second = (new PhutilTraefikAuthAdapter())
      ->setAccountID('directory-user-102')->setAccountEmail('same@example.test');
    $this->assertEqual(array('directory-user-101'),
      mpull($first->getAccountIdentifiers(), 'getIdentifierRaw'));
    $this->assertEqual(array('directory-user-102'),
      mpull($second->getAccountIdentifiers(), 'getIdentifierRaw'));
  }

  public function testIdentityIsNotInventedFromEmail() {
    $adapter = (new PhutilTraefikAuthAdapter())
      ->setAccountEmail('someone@example.test')->setAccountName('someone');
    $this->assertEqual(array(), $adapter->getAccountIdentifiers());
  }

  public function testUsernameSuggestionDoesNotChangeIdentity() {
    $provider = $this->newProvider();
    $method = new ReflectionMethod($provider, 'deriveUsernameFromEmail');
    $this->assertEqual('alice',
      $method->invoke($provider, 'alice@example.test', 'directory-user-101'));
    $this->assertEqual('directory-user-101',
      $method->invoke($provider, null, 'directory-user-101'));
    $adapter = (new PhutilTraefikAuthAdapter())
      ->setAccountID('directory-user-101')
      ->setAccountName($method->invoke(
        $provider, 'alice@example.test', 'directory-user-101'));
    $this->assertEqual(array('directory-user-101'),
      mpull($adapter->getAccountIdentifiers(), 'getIdentifierRaw'));
  }

}
