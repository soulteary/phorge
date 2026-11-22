<?php

// Keep the supported branch separate from the minimum security patch.
// Later 8.5 security releases are accepted; another minor branch is not.
function phorge_php_version_is_supported($version) {
  return is_string($version) &&
    preg_match('/\A8\.5\.[0-9]+\z/', $version) &&
    version_compare($version, '8.5.13', '>=');
}

function phorge_assert_supported_php_version($version) {
  $profile = getenv('PHORGE_PHP_COMPATIBILITY_PROFILE');
  if ($profile !== false && $profile !== '') {
    if ($profile !== 'php86-isolated-v1' ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
        !is_string($version) || $version !== PHP_VERSION ||
        !preg_match('/\A8\.6\.[0-9]+\z/', $version)) {
      throw new RuntimeException('Invalid isolated PHP compatibility profile.');
    }
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
                   'PHORGE_CONTROL_PLANE') as $name) {
      $selected = getenv($name);
      if ($selected !== false && $selected !== '') {
        throw new RuntimeException('Deployment selectors refused by PHP candidate.');
      }
    }
    foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $name) {
      $path = dirname(__DIR__, 2).'/conf/local/'.$name;
      if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('Deployment configuration refused by PHP candidate.');
      }
    }
    return;
  }
  if (!phorge_php_version_is_supported($version)) {
    throw new RuntimeException(
      'Unsupported PHP runtime "'.$version.'". '.
      'Phorge requires PHP 8.5.x, version 8.5.13 or newer.');
  }
}
