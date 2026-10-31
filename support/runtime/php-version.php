<?php

// Keep the supported branch separate from the minimum security patch.
// Later 8.5 security releases are accepted; another minor branch is not.
function phorge_php_version_is_supported($version) {
  return is_string($version) &&
    preg_match('/\A8\.5\.[0-9]+\z/', $version) &&
    version_compare($version, '8.5.12', '>=');
}

function phorge_assert_supported_php_version($version) {
  if (!phorge_php_version_is_supported($version)) {
    throw new RuntimeException(
      'Unsupported PHP runtime "'.$version.'". '.
      'Phorge requires PHP 8.5.x, version 8.5.12 or newer.');
  }
}
