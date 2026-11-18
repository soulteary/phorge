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

}
