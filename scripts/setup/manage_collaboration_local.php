#!/usr/bin/env php
<?php

if ($argc !== 4) {
  fwrite(
    STDERR,
    "Usage: manage_collaboration_local.php ".
    "<collaboration|full> <local-config> <state-file>\n");
  exit(1);
}

$mode = $argv[1];
$config_path = $argv[2];
$state_path = $argv[3];
if ($mode !== 'collaboration' && $mode !== 'full') {
  fwrite(STDERR, "Unknown product profile.\n");
  exit(1);
}

function read_json_object($path, $required) {
  if (!file_exists($path)) {
    if ($required) {
      throw new Exception('Required JSON file does not exist: '.$path);
    }
    return null;
  }

  $value = json_decode(file_get_contents($path), true);
  if (!is_array($value)) {
    throw new Exception('JSON file is not an object: '.$path);
  }
  return $value;
}

function write_json_object($path, array $value) {
  $temporary = $path.'.tmp.'.getmypid();
  $json = json_encode(
    $value,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
  if (file_put_contents($temporary, $json, LOCK_EX) === false) {
    throw new Exception('Unable to write JSON file: '.$path);
  }
  chmod($temporary, 0640);
  if (!rename($temporary, $path)) {
    @unlink($temporary);
    throw new Exception('Unable to install JSON file: '.$path);
  }
}

function validate_profile_state(array $state) {
  if (!isset($state['version']) || $state['version'] !== 1 ||
      !isset($state['localSettings']) ||
      !is_array($state['localSettings']) ||
      !array_key_exists('applicationsAdded', $state) ||
      ($state['applicationsAdded'] !== null &&
       !is_array($state['applicationsAdded'])) ||
      !array_key_exists('databaseSettings', $state) ||
      ($state['databaseSettings'] !== null &&
       !is_array($state['databaseSettings'])) ||
      !array_key_exists('uninstalledDatabase', $state) ||
      ($state['uninstalledDatabase'] !== null &&
       !is_array($state['uninstalledDatabase'])) ||
      !array_key_exists('customFieldsDatabase', $state) ||
      ($state['customFieldsDatabase'] !== null &&
       !is_array($state['customFieldsDatabase']))) {
    throw new Exception('Collaboration profile state is invalid.');
  }
}

$config = read_json_object($config_path, true);
$state = read_json_object($state_path, false);
if ($state !== null) {
  $state_upgraded = false;
  if (isset($state['version']) && $state['version'] === 1 &&
      array_key_exists('applicationsAdded', $state)) {
    // The first state format recorded applications only. Its local baseline
    // can not be reconstructed, so synthesize absent baselines only for the
    // integrations that legacy configuration shows as active.
    if (!isset($state['localSettings']) ||
        !is_array($state['localSettings'])) {
      $state['localSettings'] = array();
      $legacy_local_keys = array(
        'gorge.diff.enabled',
      );
      if (strlen((string)getenv('GITEA_BASE_URI')) ||
          (array_key_exists('gitea.uri', $config) &&
           strlen((string)$config['gitea.uri']))) {
        $legacy_local_keys[] = 'gitea.uri';
      }
      if (strlen((string)getenv('GORGE_RENDER_URI')) ||
          (array_key_exists('syntax-highlighter.engine', $config) &&
           $config['syntax-highlighter.engine'] ===
            'PhabricatorGorgeSyntaxHighlighterEngine')) {
        $legacy_local_keys[] = 'syntax-highlighter.engine';
      }
      foreach ($legacy_local_keys as $key) {
        $state['localSettings'][$key] = array(
          'present' => false,
          'value' => null,
        );
      }
      $state_upgraded = true;
    }
    if (!array_key_exists('databaseSettings', $state)) {
      $state['databaseSettings'] = null;
      $state_upgraded = true;
    }
    if (!array_key_exists('uninstalledDatabase', $state)) {
      // Older helpers had already created this database override. Preserve its
      // effective value in local.json during rollback, then remove it.
      $state['uninstalledDatabase'] = array(
        'present' => false,
        'value' => null,
      );
      $state_upgraded = true;
    }
    if (!array_key_exists('customFieldsDatabase', $state)) {
      // Older helpers also wrote the merged custom-field definitions to the
      // database unconditionally. Move that effective value back to local
      // configuration when the profile is later removed.
      $state['customFieldsDatabase'] = array(
        'present' => false,
        'value' => null,
      );
      $state_upgraded = true;
    }
  }
  if ($state_upgraded) {
    write_json_object($state_path, $state);
  }
  validate_profile_state($state);
}

$owned_keys = array(
  'gitea.uri',
  'gorge.diff.enabled',
  'syntax-highlighter.engine',
);

if ($mode === 'collaboration') {
  $legacy_profile = $state === null &&
    isset($config['phorge.product-profile']) &&
    $config['phorge.product-profile'] === 'collaboration';
  $profile_values = array(
    'gorge.diff.enabled' => false,
  );
  $gitea_uri = getenv('GITEA_BASE_URI');
  if ($gitea_uri !== false && strlen($gitea_uri)) {
    $profile_values['gitea.uri'] = rtrim($gitea_uri, '/').'/';
  }
  if (strlen((string)getenv('GORGE_RENDER_URI'))) {
    $profile_values['syntax-highlighter.engine'] =
      'PhabricatorGorgeSyntaxHighlighterEngine';
  }
  if ($legacy_profile) {
    // The stateless implementation can not identify which optional values it
    // wrote on an earlier boot. Keep existing profile keys active and treat
    // them as profile-owned so a later rollback removes them.
    foreach ($owned_keys as $key) {
      if (!array_key_exists($key, $profile_values) &&
          array_key_exists($key, $config)) {
        $profile_values[$key] = $config[$key];
      }
    }
  }

  $state_changed = false;
  if ($state === null) {
    $state = array(
      'version' => 1,
      'localSettings' => array(),
      // Filled after Phorge can read the effective database-backed value.
      'applicationsAdded' => null,
      'databaseSettings' => null,
      'uninstalledDatabase' => null,
      'customFieldsDatabase' => null,
    );
    $state_changed = true;
  }

  foreach ($profile_values as $key => $value) {
    if (!array_key_exists($key, $state['localSettings'])) {
      $present = !$legacy_profile && array_key_exists($key, $config);
      $state['localSettings'][$key] = array(
        'present' => $present,
        'value' => $present ? $config[$key] : null,
      );
      $state_changed = true;
    }
  }

  // Install every rollback record before changing local.json. If an optional
  // integration is enabled later, its baseline is appended at that point.
  if ($state_changed) {
    write_json_object($state_path, $state);
  }

  $config['phorge.product-profile'] = 'collaboration';
  foreach ($profile_values as $key => $value) {
    $config[$key] = $value;
  }
} else {
  if ($state !== null) {
    foreach ($owned_keys as $key) {
      if (!array_key_exists($key, $state['localSettings'])) {
        continue;
      }
      $baseline = $state['localSettings'][$key];
      if (!is_array($baseline) || !isset($baseline['present'])) {
        throw new Exception('Collaboration local baseline is invalid.');
      }
      if ($baseline['present']) {
        $config[$key] = $baseline['value'];
      } else {
        unset($config[$key]);
      }
    }
  } else if (isset($config['phorge.product-profile']) &&
             $config['phorge.product-profile'] === 'collaboration') {
    // Legacy collaboration mode predates the state file. These keys were
    // written solely by that profile, so remove them on direct upgrade to full.
    foreach ($owned_keys as $key) {
      unset($config[$key]);
    }
  }
  $config['phorge.product-profile'] = 'full';
}

write_json_object($config_path, $config);
