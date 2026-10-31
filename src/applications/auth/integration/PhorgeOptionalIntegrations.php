<?php

/** Validate opt-in gateway/RPC settings before trusting any request identity. */
final class PhorgeOptionalIntegrations extends Phobject {

  public static function getEntryConfiguration() {
    $prefix = 'integrations.entry-session.';
    $proxies = PhabricatorEnv::getEnvConfig($prefix.'trusted-proxies');
    PhutilTypeSpec::newFromString('list<string>')->check($proxies);
    if (!$proxies) {
      throw new PhutilArgumentUsageException('Configure explicit trusted proxy addresses.');
    }
    foreach ($proxies as $proxy) {
      if (!filter_var($proxy, FILTER_VALIDATE_IP) ||
          in_array($proxy, array('0.0.0.0', '::'), true)) {
        throw new PhutilArgumentUsageException('Trusted proxies must be exact IP addresses.');
      }
    }
    $cookie = PhabricatorEnv::getEnvConfig($prefix.'cookie');
    $header = PhabricatorEnv::getEnvConfig($prefix.'principal-header');
    if (!is_string($cookie) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $cookie) ||
        in_array($cookie, array('phsid', 'phusr', 'phreg', 'phcid', 'phfrm',
          'next_uri', 'jump_to_hisec', 'invite'), true) ||
        !is_string($header) || !preg_match('/^[A-Za-z][A-Za-z0-9-]{0,79}$/D', $header)) {
      throw new PhutilArgumentUsageException('Invalid entry cookie or principal header name.');
    }
    $provider = PhabricatorEnv::getEnvConfig($prefix.'provider');
    if (!is_string($provider) ||
        !preg_match('/^[A-Za-z0-9_-]+:[A-Za-z0-9._-]+$/D', $provider)) {
      throw new PhutilArgumentUsageException('Configure an explicit native authentication provider key.');
    }
    $provider_uri = PhabricatorEnv::getEnvConfig($prefix.'provider-login-uri');
    $expected = '/auth/login/'.$provider.'/';
    if ($provider_uri === '') {
      $provider_uri = $expected;
    }
    // Recovery always goes through the selected provider, never an arbitrary
    // destination. The original document URI is separately native-validated.
    if ($provider_uri !== $expected || !self::isLocalPath($provider_uri)) {
      throw new PhutilArgumentUsageException('Provider recovery URI must match its native provider.');
    }
    $entry_uri = PhabricatorEnv::getEnvConfig($prefix.'entry-login-uri');
    if (!self::isLocalPath($entry_uri)) {
      throw new PhutilArgumentUsageException('Entry login URI must be a local path.');
    }
    return array('trusted-proxies' => array_values(array_unique($proxies)),
      'cookie' => $cookie, 'principal-header' => $header, 'provider' => $provider,
      'provider-login-uri' => $provider_uri, 'entry-login-uri' => $entry_uri);
  }

  public static function isLocalPath($value) {
    if (!is_string($value) || !strlen($value) || $value[0] !== '/' ||
        preg_match('/[\x00-\x20\x7f\\\\]/', $value) ||
        substr($value, 0, 2) === '//') {
      return false;
    }
    $uri = new PhutilURI($value);
    return !$uri->getDomain() && !$uri->getProtocol() && !$uri->getFragment();
  }

  public static function getMaintenanceConfiguration() {
    if (!PhabricatorEnv::getEnvConfig('integrations.entry-session.enabled')) {
      throw new PhutilArgumentUsageException('Maintenance requires entry-session verification.');
    }
    self::getEntryConfiguration();
    $prefix = 'integrations.maintenance.';
    $endpoint = PhabricatorEnv::getEnvConfig($prefix.'endpoint');
    $key_file = PhabricatorEnv::getEnvConfig($prefix.'key-file');
    if (!is_string($endpoint) || preg_match('/[\x00-\x20\x7f]/', $endpoint)) {
      throw new PhutilArgumentUsageException('Invalid maintenance endpoint.');
    }
    $uri = new PhutilURI($endpoint);
    $protocol = $uri->getProtocol();
    if (!in_array($protocol, array('https', 'http'), true) ||
        !$uri->getDomain() || $uri->getUser() || $uri->getPass() ||
        $uri->getPath() !== '/rpc' || $uri->getQueryParams() || $uri->getFragment() ||
        ($protocol === 'http' &&
         !PhabricatorEnv::getEnvConfig($prefix.'allow-http'))) {
      throw new PhutilArgumentUsageException('Maintenance requires an HTTPS /rpc endpoint without credentials or query.');
    }
    if (!is_string($key_file) || !strlen($key_file) || $key_file[0] !== '/' ||
        preg_match('/[\x00-\x1f\x7f]/', $key_file) || !is_file($key_file) ||
        !is_readable($key_file) || filesize($key_file) !== 32) {
      throw new PhutilArgumentUsageException('Configure a readable absolute maintenance key file.');
    }
    return array('endpoint' => $endpoint, 'key-file' => $key_file);
  }

  public static function isMaintenanceEnabled() {
    if (!PhabricatorEnv::getEnvConfig('integrations.maintenance.enabled')) {
      return false;
    }
    try {
      self::getMaintenanceConfiguration();
      return true;
    } catch (Exception $ex) {
      return false;
    }
  }
}
