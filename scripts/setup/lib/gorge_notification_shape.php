<?php

// Shared by manage_collaboration_local.php and clear_legacy_gorge_local.php.
//
// Both scripts run standalone, without booting Phorge, so this is plain PHP
// with no libphutil. It lives here rather than being copied into each script
// because the two must agree: they decide the same question about the same
// key on the same run, and a divergence between them would either delete an
// administrator's configuration or leave a retired endpoint in place.

/**
 * Resolve what build_deployment_config.php will do with "notification.servers".
 *
 * Mirrors env_mode('GORGE_NOTIFICATION_MODE', 'GORGE_NOTIFICATION_ADMIN_HOST').
 * "preserve" means the builder writes nothing, so whatever is in local.json is
 * the effective value.
 */
function gorge_notification_selector_mode() {
  $mode = getenv('GORGE_NOTIFICATION_MODE');
  if ($mode === false || $mode === '' || $mode === 'auto') {
    $host = getenv('GORGE_NOTIFICATION_ADMIN_HOST');
    return (is_string($host) && strlen($host)) ? 'enable' : 'preserve';
  }
  return $mode;
}

/**
 * Recognize the shape the Gorge notification integration wrote.
 *
 * Exactly two entries: an "admin" entry pinned to plain HTTP followed by a
 * "client" entry, with no keys beyond the ones that writer emitted. Anything
 * else -- a single entry, a third server, an extra key, an HTTPS admin entry
 * -- cannot have come from it.
 *
 * This can also match an administrator's own Aphlict configuration, because
 * that writer emitted nothing distinctive. Callers must only act on it where
 * the alternative is leaving an install pointed at a retired host, and must
 * say what they removed.
 */
function is_gorge_notification_shape($value) {
  if (!is_array($value) || count($value) !== 2) {
    return false;
  }
  if (!array_key_exists(0, $value) || !array_key_exists(1, $value)) {
    return false;
  }

  $admin = $value[0];
  $client = $value[1];
  if (!is_array($admin) || !is_array($client)) {
    return false;
  }

  $admin_keys = array_keys($admin);
  sort($admin_keys);
  if ($admin_keys !== array('host', 'port', 'protocol', 'type')) {
    return false;
  }
  if ($admin['type'] !== 'admin' || $admin['protocol'] !== 'http') {
    return false;
  }

  $client_keys = array_keys($client);
  sort($client_keys);
  if ($client_keys !== array('host', 'port', 'protocol', 'type') &&
      $client_keys !== array('host', 'path', 'port', 'protocol', 'type')) {
    return false;
  }
  if ($client['type'] !== 'client') {
    return false;
  }

  return true;
}
