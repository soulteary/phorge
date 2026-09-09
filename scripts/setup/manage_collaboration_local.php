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
    // can not be reconstructed, so remove the three collaboration-owned keys
    // on rollback instead of leaving the installation unable to exit.
    if (!isset($state['localSettings']) ||
        !is_array($state['localSettings'])) {
      $state['localSettings'] = array();
      foreach (array(
        'gitea.uri',
        'gorge.diff.enabled',
        'syntax-highlighter.engine',
      ) as $key) {
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
  if ($state === null) {
    $local_settings = array();
    $legacy_profile = isset($config['phorge.product-profile']) &&
      $config['phorge.product-profile'] === 'collaboration';
    foreach ($owned_keys as $key) {
      $present = !$legacy_profile && array_key_exists($key, $config);
      $local_settings[$key] = array(
        'present' => $present,
        'value' => $present ? $config[$key] : null,
      );
    }

    $state = array(
      'version' => 1,
      'localSettings' => $local_settings,
      // Filled after Phorge can read the effective database-backed value.
      'applicationsAdded' => null,
      'databaseSettings' => null,
      'uninstalledDatabase' => null,
      'customFieldsDatabase' => null,
    );

    // Install the rollback record before changing local.json.
    write_json_object($state_path, $state);
  }

  $config['phorge.product-profile'] = 'collaboration';
  $config['gorge.diff.enabled'] = false;

  $gitea_uri = getenv('GITEA_BASE_URI');
  if ($gitea_uri !== false && strlen($gitea_uri)) {
    $config['gitea.uri'] = rtrim($gitea_uri, '/').'/';
  }
  if (strlen((string)getenv('GORGE_RENDER_URI'))) {
    $config['syntax-highlighter.engine'] =
      'PhabricatorGorgeSyntaxHighlighterEngine';
  }
} else {
  if ($state !== null) {
    foreach ($owned_keys as $key) {
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
