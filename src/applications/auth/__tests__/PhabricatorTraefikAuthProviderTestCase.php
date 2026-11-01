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
}
