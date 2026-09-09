<?php

/**
 * One Gorge capability and the Phorge configuration which selects it.
 */
final class PhabricatorGorgeServiceSpec extends Phobject {

  private $key;
  private $name;
  private $uriKey;
  private $tokenKey;
  private $ownerKey;
  private $configurationKeys = array();

  public function setKey($key) {
    $this->key = $key;
    return $this;
  }

  public function getKey() {
    return $this->key;
  }

  public function setName($name) {
    $this->name = $name;
    return $this;
  }

  public function getName() {
    return $this->name;
  }

  public function setURIKey($key) {
    $this->uriKey = $key;
    return $this;
  }

  public function getURIKey() {
    return $this->uriKey;
  }

  public function setTokenKey($key) {
    $this->tokenKey = $key;
    return $this;
  }

  public function getTokenKey() {
    return $this->tokenKey;
  }

  public function setOwnerKey($key) {
    $this->ownerKey = $key;
    return $this;
  }

  public function getOwnerKey() {
    return $this->ownerKey;
  }

  public function setConfigurationKeys(array $keys) {
    $this->configurationKeys = $keys;
    return $this;
  }

  public function getConfigurationKeys() {
    return $this->configurationKeys;
  }

  public function getConfiguredURI() {
    if ($this->uriKey === null) {
      return null;
    }

    $uri = PhabricatorEnv::getEnvConfigIfExists($this->uriKey);
    if (!phutil_nonempty_string($uri)) {
      return null;
    }

    return rtrim($uri, '/');
  }

  public function getConfiguredToken() {
    if ($this->tokenKey === null) {
      return null;
    }

    return PhabricatorEnv::getEnvConfigIfExists($this->tokenKey);
  }

  /**
   * Resolve the owner of a capability with a native Phorge implementation.
   *
   * "auto" preserves the pre-control-plane behavior for source and legacy
   * installs: a configured endpoint selects Gorge. Deployment configuration
   * writes an explicit owner, so endpoint rotation can no longer transfer
   * consumer ownership as a side effect.
   */
  public function getOwner() {
    if ($this->ownerKey === null) {
      return null;
    }

    $owner = PhabricatorEnv::getEnvConfigIfExists($this->ownerKey);
    if ($owner === null || $owner === 'auto') {
      return ($this->getConfiguredURI() === null) ? 'phorge' : 'gorge';
    }

    if ($owner !== 'phorge' && $owner !== 'gorge') {
      throw new Exception(
        pht(
          'Configuration option "%s" has unknown owner "%s".',
          $this->ownerKey,
          $owner));
    }

    return $owner;
  }

  public function isOwnedBy($owner) {
    return ($this->getOwner() === $owner);
  }

}
