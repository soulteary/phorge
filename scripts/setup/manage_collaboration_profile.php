#!/usr/bin/env php
<?php

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/init/init-script.php';

if ($argc !== 3) {
  throw new PhutilArgumentUsageException(
    pht(
      'Usage: %s <collaboration|full> <state-file>',
      basename($argv[0])));
}

$mode = $argv[1];
$state_path = $argv[2];

if ($mode !== 'collaboration' && $mode !== 'full') {
  throw new PhutilArgumentUsageException(
    pht('Unknown product profile "%s".', $mode));
}

$managed_applications = array(
  'PhabricatorDiffusionApplication',
  'PhabricatorDifferentialApplication',
  'PhabricatorAuditApplication',
  'PhabricatorOwnersApplication',
  'PhabricatorHarbormasterApplication',
  'PhabricatorDrydockApplication',
  'PhabricatorDivinerApplication',
  'PhabricatorPasteApplication',
);

$field_defaults = array(
  'gitea.repository' => array(
    'name' => 'Gitea Repository',
    'type' => 'link',
    'caption' => 'Canonical repository in Gitea.',
  ),
  'gitea.issue' => array(
    'name' => 'Gitea Issue',
    'type' => 'link',
    'caption' => 'Related issue in Gitea.',
  ),
  'gitea.pull-request' => array(
    'name' => 'Gitea Pull Request',
    'type' => 'link',
    'caption' => 'Related pull request in Gitea.',
  ),
  'gitea.commit' => array(
    'name' => 'Gitea Commit',
    'type' => 'link',
    'caption' => 'Related commit in Gitea.',
  ),
);

function normalize_application_set($value) {
  $result = array();
  foreach ((array)$value as $key => $item) {
    if (is_string($key)) {
      if ($item) {
        $result[$key] = true;
      }
    } else if (is_string($item) && strlen($item)) {
      // Accept the numeric list emitted by the initial collaboration-profile
      // implementation, then immediately rewrite it as Phorge's keyed set.
      $result[$item] = true;
    }
  }
  return $result;
}

function load_profile_state($path) {
  if (!Filesystem::pathExists($path)) {
    return null;
  }

  $state = json_decode(Filesystem::readFile($path), true);
  if (!is_array($state) ||
      idx($state, 'version') !== 1 ||
      !is_array(idx($state, 'localSettings')) ||
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
    throw new Exception(
      pht('Collaboration profile state file "%s" is invalid.', $path));
  }

  return $state;
}

function write_profile_state($path, array $state) {
  $directory = dirname($path);
  if (!Filesystem::pathExists($directory)) {
    Filesystem::createDirectory($directory, 0750, true);
  }

  $temporary = $path.'.tmp.'.getmypid();
  $json = json_encode(
    $state,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
  if (file_put_contents($temporary, $json, LOCK_EX) === false) {
    throw new Exception(pht('Unable to write profile state "%s".', $path));
  }

  chmod($temporary, 0640);
  if (!rename($temporary, $path)) {
    @unlink($temporary);
    throw new Exception(pht('Unable to install profile state "%s".', $path));
  }
}

function store_effective_config($key, $value) {
  $entry = PhabricatorConfigEntry::loadConfigEntry($key);
  $source = PhabricatorContentSource::newForSource(
    PhabricatorConsoleContentSource::SOURCECONST);

  PhabricatorConfigEditor::storeNewValue(
    PhabricatorUser::getOmnipotentUser(),
    $entry,
    $value,
    $source);
}

function store_local_config($key, $value) {
  $source = new PhabricatorConfigLocalSource();
  $source->setKeys(array($key => $value));
}

function delete_database_config($key) {
  $entry = PhabricatorConfigEntry::loadConfigEntry($key);
  if (!$entry->getID() || $entry->getIsDeleted()) {
    return;
  }

  $source = PhabricatorContentSource::newForSource(
    PhabricatorConsoleContentSource::SOURCECONST);
  PhabricatorConfigEditor::deleteConfig(
    PhabricatorUser::getOmnipotentUser(),
    $entry,
    $source);
}

function capture_database_config($key) {
  $entry = PhabricatorConfigEntry::loadConfigEntry($key);
  $present = (bool)$entry->getID() && !$entry->getIsDeleted();
  return array(
    'present' => $present,
    'value' => $present ? $entry->getValue() : null,
  );
}

function restore_database_config($key, array $baseline) {
  if (!isset($baseline['present'])) {
    throw new Exception(pht('Database configuration baseline is invalid.'));
  }
  if ($baseline['present']) {
    store_effective_config($key, $baseline['value']);
  } else {
    delete_database_config($key);
  }
}

function store_at_original_source($key, $value, array $database_baseline) {
  if (!isset($database_baseline['present'])) {
    throw new Exception(pht('Configuration source baseline is invalid.'));
  }
  if ($database_baseline['present']) {
    store_effective_config($key, $value);
  } else {
    // Keep local configuration authoritative if the profile did not inherit a
    // database entry. This also preserves administrator changes made while the
    // profile was active without leaving a database source shadow behind.
    store_local_config($key, $value);
    delete_database_config($key);
  }
}

function keyed_application_set($value) {
  $result = array();
  foreach ((array)$value as $key => $item) {
    if (is_string($key) && $item) {
      $result[$key] = true;
    }
  }
  return $result;
}

$uninstalled_key = 'phabricator.uninstalled-applications';
$fields_key = 'maniphest.custom-field-definitions';
$raw_uninstalled = PhabricatorEnv::getEnvConfig($uninstalled_key);
$keyed_uninstalled = keyed_application_set($raw_uninstalled);
$uninstalled = normalize_application_set(
  $raw_uninstalled);

if ($mode === 'collaboration') {
  $state = load_profile_state($state_path);
  if ($state === null) {
    throw new Exception(
      pht('Collaboration local baseline "%s" does not exist.', $state_path));
  }

  $state_changed = false;
  if ($state['applicationsAdded'] === null) {
    $added = array();
    foreach ($managed_applications as $application) {
      // Only a keyed entry is a valid administrator-defined uninstall. A
      // numeric entry came from the initial, broken profile implementation
      // and must be treated as something this profile added.
      if (!isset($keyed_uninstalled[$application])) {
        $added[$application] = true;
      }
    }

    // Record the baseline before changing database configuration. A failed
    // config write is safe to retry; a successful write without this record
    // would make a later rollback unable to distinguish administrator choices.
    $state['applicationsAdded'] = $added;
    $state_changed = true;
  }

  if ($state['databaseSettings'] === null) {
    $state['databaseSettings'] = array();
    $state_changed = true;
  }

  if ($state['uninstalledDatabase'] === null) {
    $state['uninstalledDatabase'] = capture_database_config($uninstalled_key);
    $state_changed = true;
  }

  if ($state['customFieldsDatabase'] === null) {
    $state['customFieldsDatabase'] = capture_database_config($fields_key);
    $state_changed = true;
  }

  $database_values = array(
    'gorge.diff.enabled' => false,
  );
  if (strlen((string)getenv('GORGE_RENDER_URI'))) {
    $database_values['syntax-highlighter.engine'] =
      'PhabricatorGorgeSyntaxHighlighterEngine';
  }
  foreach ($database_values as $key => $value) {
    if (!array_key_exists($key, $state['databaseSettings'])) {
      $state['databaseSettings'][$key] = capture_database_config($key);
      $state_changed = true;
    }
  }

  // Persist every database baseline before changing any database value.
  if ($state_changed) {
    write_profile_state($state_path, $state);
  }

  foreach ($managed_applications as $application) {
    $uninstalled[$application] = true;
  }

  $fields = (array)PhabricatorEnv::getEnvConfig($fields_key);
  foreach ($field_defaults as $key => $spec) {
    if (!array_key_exists($key, $fields)) {
      $fields[$key] = $spec;
    }
  }

  // Update the source which was authoritative before the profile was enabled.
  // Existing database configuration stays in the database; otherwise local
  // configuration remains authoritative and no new shadow is introduced.
  $uninstalled_database = $state['uninstalledDatabase'];
  if (!is_array($uninstalled_database)) {
    throw new Exception(pht('Uninstalled application baseline is invalid.'));
  }
  store_at_original_source(
    $uninstalled_key,
    $uninstalled,
    $uninstalled_database);
  $custom_fields_database = $state['customFieldsDatabase'];
  if (!is_array($custom_fields_database)) {
    throw new Exception(pht('Custom-field source baseline is invalid.'));
  }
  store_at_original_source(
    $fields_key,
    $fields,
    $custom_fields_database);
  foreach ($database_values as $key => $value) {
    store_effective_config($key, $value);
  }

  echo pht('Applied the collaboration profile to effective configuration.')."\n";
} else {
  $state = load_profile_state($state_path);
  if ($state === null) {
    if ($uninstalled !== $keyed_uninstalled) {
      // The initial profile wrote a numeric list and had no rollback state.
      // Numeric managed entries belong to that profile; valid keyed entries
      // remain administrator choices. Normalize any other legacy values too.
      foreach ($managed_applications as $application) {
        if (!isset($keyed_uninstalled[$application])) {
          unset($uninstalled[$application]);
        }
      }
      $database_baseline = capture_database_config($uninstalled_key);
      store_at_original_source(
        $uninstalled_key,
        $uninstalled,
        $database_baseline);
      echo pht('Normalized the legacy uninstalled application set.')."\n";
    } else {
      echo pht(
        'No collaboration profile state exists; no applications changed.')."\n";
    }
    exit(0);
  }

  $added = normalize_application_set($state['applicationsAdded']);
  foreach ($added as $application => $ignored) {
    unset($uninstalled[$application]);
  }

  $uninstalled_database = $state['uninstalledDatabase'];
  if ($uninstalled_database === null) {
    // The local phase completed but the collaboration database phase never
    // ran, so no database override was inherited or created.
    $uninstalled_database = array(
      'present' => false,
      'value' => null,
    );
  }
  if (!is_array($uninstalled_database)) {
    throw new Exception(pht('Uninstalled application baseline is invalid.'));
  }
  store_at_original_source(
    $uninstalled_key,
    $uninstalled,
    $uninstalled_database);
  $fields = (array)PhabricatorEnv::getEnvConfig($fields_key);
  $custom_fields_database = $state['customFieldsDatabase'];
  if ($custom_fields_database === null) {
    $custom_fields_database = array(
      'present' => false,
      'value' => null,
    );
  }
  if (!is_array($custom_fields_database)) {
    throw new Exception(pht('Custom-field source baseline is invalid.'));
  }
  store_at_original_source(
    $fields_key,
    $fields,
    $custom_fields_database);
  foreach ((array)$state['databaseSettings'] as $key => $baseline) {
    if (!is_array($baseline)) {
      throw new Exception(pht('Database configuration baseline is invalid.'));
    }
    restore_database_config($key, $baseline);
  }

  // Delete state only after the database update succeeds. If deletion fails,
  // the next run repeats the same idempotent removal instead of losing the
  // rollback record early.
  if (!unlink($state_path)) {
    throw new Exception(
      pht('Unable to remove profile state "%s".', $state_path));
  }

  echo pht('Restored applications managed by the collaboration profile.')."\n";
}
