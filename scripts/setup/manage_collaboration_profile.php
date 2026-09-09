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
       !is_array($state['applicationsAdded']))) {
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

  // Database configuration is the highest-priority source in Phorge. Write
  // the merged effective values there so an existing Web UI configuration can
  // not silently replace the profile's local.json values wholesale.
  store_effective_config($uninstalled_key, $uninstalled);
  store_effective_config($fields_key, $fields);

  echo pht('Applied the collaboration profile to effective configuration.')."\n";
} else {
  $state = load_profile_state($state_path);
  if ($state === null) {
    echo pht('No collaboration profile state exists; no applications changed.')."\n";
    exit(0);
  }

  $added = normalize_application_set($state['applicationsAdded']);
  foreach ($added as $application => $ignored) {
    unset($uninstalled[$application]);
  }

  store_effective_config($uninstalled_key, $uninstalled);

  // Delete state only after the database update succeeds. If deletion fails,
  // the next run repeats the same idempotent removal instead of losing the
  // rollback record early.
  if (!unlink($state_path)) {
    throw new Exception(
      pht('Unable to remove profile state "%s".', $state_path));
  }

  echo pht('Restored applications managed by the collaboration profile.')."\n";
}
