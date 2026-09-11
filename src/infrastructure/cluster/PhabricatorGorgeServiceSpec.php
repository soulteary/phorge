<?php

/**
 * One Gorge capability and the Phorge configuration which selects it.
 */
final class PhabricatorGorgeServiceSpec extends Phobject {

  const POLICY_REQUIRED = 'required';
  const POLICY_FALLBACK = 'fallback';
  const POLICY_OFF = 'off';

  private static $fallbackCounts = array();

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

  /**
   * Resolve the failure policy for this service.
   *
   * A per-service entry overrides the deployment-wide policy. The default is
   * deliberately "required": once a service is configured, an outage must be
   * visible instead of silently selecting a second implementation.
   *
   * Database diagnostics are past their migration window and are now always
   * Gorge-owned. Keeping fallback/off selectable for the `db` capability
   * would continue exercising the large direct-management-SQL implementation
   * we are retiring, so reject those policies rather than silently re-enable
   * an obsolete diagnostic path.
   */
  public function getPolicy() {
    $policy = null;
    $policies = PhabricatorEnv::getEnvConfigIfExists(
      'gorge.service-policies');
    if (is_array($policies)) {
      $policy = idx($policies, $this->getKey());
    }

    if ($policy === null) {
      $policy = PhabricatorEnv::getEnvConfigIfExists(
        'gorge.service-policy');
    }
    if ($policy === null) {
      $policy = self::POLICY_REQUIRED;
    }

    $valid = array(
      self::POLICY_REQUIRED,
      self::POLICY_FALLBACK,
      self::POLICY_OFF,
    );
    if (!in_array($policy, $valid, true)) {
      throw new Exception(
        pht(
          'Gorge service "%s" has unknown failure policy "%s".',
          $this->getKey(),
          phutil_string_cast($policy)));
    }

    if ($this->getKey() === 'db' && $policy !== self::POLICY_REQUIRED) {
      // The native PHP diagnostic fallback has been retired, so an override
      // which selects it no longer names an implementation. Ignore it rather
      // than throwing: getPolicy() is reached from setup checks, the database
      // console and management queries -- including on installations which
      // never configured this service and only set the global policy for
      // another one -- so throwing here breaks unrelated pages, and it breaks
      // the setup check which would otherwise explain the problem.
      //
      // PhabricatorGorgeDBSetupCheck reports the ignored override instead,
      // where the operator can act on it.
      return self::POLICY_REQUIRED;
    }

    return $policy;
  }

  public function isRequired() {
    return ($this->getPolicy() === self::POLICY_REQUIRED);
  }

  public function isFallbackAllowed() {
    return ($this->getPolicy() === self::POLICY_FALLBACK);
  }

  public function isDisabled() {
    return ($this->getPolicy() === self::POLICY_OFF);
  }

  /**
   * Record an intentional transition from Gorge to a native implementation.
   */
  public function recordFallback($operation) {
    $key = $this->getKey().'.'.$operation;
    $count = idx(self::$fallbackCounts, $key, 0) + 1;
    self::$fallbackCounts[$key] = $count;

    phlog(
      sprintf(
        '[gorge-fallback] service=%s operation=%s process_count=%d',
        $this->getKey(),
        $operation,
        $count));

    return $this;
  }

  public static function getFallbackCounts() {
    return self::$fallbackCounts;
  }

  public static function resetFallbackCounts() {
    self::$fallbackCounts = array();
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
