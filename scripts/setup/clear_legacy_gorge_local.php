#!/usr/bin/env php
<?php

// Remove deployment-owned Gorge keys from conf/local/local.json.
//
// The legacy control plane wrote service endpoints, tokens and consumer
// ownership straight into local.json with `bin/config set`. Under the
// deployment control plane those keys belong exclusively to deployment.json,
// which is a higher-priority source -- but only for the keys it actually
// contains. build_deployment_config.php omits a scalar service when no
// selector variable is present, and leaves mode-controlled services alone in
// "preserve" mode, so a value left behind in local.json is not masked: it
// stays effective and, with the default "required" policy, keeps routing
// requests at a host which was retired with the legacy stack.
//
// This script therefore runs unconditionally in the migrate role. A copy of
// any of these keys in local.json is always stale under this control plane,
// so removing them is idempotent: on a deployment which never used the legacy
// control plane there is nothing to remove.

if ($argc !== 2) {
  fwrite(STDERR, "Usage: clear_legacy_gorge_local.php <local-config>\n");
  exit(64);
}

$local_path = $argv[1];

if (!is_file($local_path)) {
  exit(0);
}

$raw = file_get_contents($local_path);
if ($raw === false) {
  fwrite(STDERR, "Unable to read: ".$local_path."\n");
  exit(1);
}

$config = json_decode($raw, true);
if (!is_array($config)) {
  // A malformed local.json is the bootstrap writer's problem, not this
  // script's. Do not destroy it here.
  exit(0);
}

// Scalar, machine-owned keys. "notification.servers" is excluded because
// manage_collaboration_local.php already restores it from the recorded
// baseline. "cluster.mailers" and "cluster.search" are shared lists which an
// administrator may also have entries in, so they are filtered entry by entry
// below rather than removed wholesale.
$owned_keys = array(
  'gorge.render.uri',
  'gorge.render.token',
  'gorge.conduit.uri',
  'gorge.conduit.token',
  'gorge.file.uri',
  'gorge.file.token',
  'gorge.webhook.uri',
  'gorge.webhook.token',
  'gorge.webhook.owner',
  'gorge.taskqueue.uri',
  'gorge.taskqueue.token',
  'gorge.taskqueue.owner',
  'gorge.db.uri',
  'gorge.db.token',
  'gorge.search.token',
);

$removed = array();
foreach ($owned_keys as $key) {
  if (array_key_exists($key, $config)) {
    unset($config[$key]);
    $removed[] = $key;
  }
}

// Drop the managed Gorge mailer, keeping every administrator-configured
// entry.
//
// The predicate is the entry type, not its key. build_deployment_config.php
// matches on GORGE_MAILER_KEY because it is removing the entry it wrote
// moments earlier in the same process, with that variable in hand. This is a
// one-way migration and runs without the retired deployment's environment --
// the documented reused-volume `docker run` recipe passes no GORGE_MAILER_KEY
// at all -- so keying off it would miss the managed entry of any install
// which renamed it, and leave mail pointed at the retired endpoint.
//
// `type: gorge` identifies a mailer served by the Gorge mailer service under
// any key, which is exactly the set being retired, and administrator-owned
// entries are other types (smtp, sendmail, ses, ...). The key is still
// honored when it is available, so an entry which was renamed to a
// non-`gorge` type by hand is matched too.
$mailer_key = getenv('GORGE_MAILER_KEY');
if ($mailer_key === false || !strlen($mailer_key)) {
  $mailer_key = null;
}

if (array_key_exists('cluster.mailers', $config) &&
    is_array($config['cluster.mailers'])) {
  $mailers = array();
  $dropped = 0;
  foreach ($config['cluster.mailers'] as $mailer) {
    if (is_array($mailer)) {
      // Plain PHP only: like build_deployment_config.php, this script runs
      // without booting Phorge, so libphutil helpers are not available.
      $type = array_key_exists('type', $mailer) ? $mailer['type'] : null;
      $key = array_key_exists('key', $mailer) ? $mailer['key'] : null;

      $is_gorge_type = ($type === 'gorge');
      $is_managed_key = ($mailer_key !== null) && ($key === $mailer_key);

      if ($is_gorge_type || $is_managed_key) {
        $dropped++;
        continue;
      }
    }
    $mailers[] = $mailer;
  }

  if ($dropped) {
    if ($mailers) {
      $config['cluster.mailers'] = array_values($mailers);
    } else {
      // An empty list is not the same as no list: unset it so the built-in
      // mailer default applies again, which is where a pre-Gorge install was.
      unset($config['cluster.mailers']);
    }
    $removed[] = 'cluster.mailers[type=gorge]';
  }
}

// Drop Gorge search engines the same way. build_deployment_config.php restores
// MySQL/Ferret when removing the Gorge entry empties the list, so do that here
// as well: an empty cluster.search leaves the install with no search engine at
// all. This is also why gorge.search.token is safe to remove above -- the
// entry which needed it is gone by the time this finishes.
if (array_key_exists('cluster.search', $config) &&
    is_array($config['cluster.search'])) {
  $search = array();
  $dropped = 0;
  foreach ($config['cluster.search'] as $engine) {
    if (is_array($engine) &&
        isset($engine['type']) &&
        $engine['type'] === 'gorge') {
      $dropped++;
      continue;
    }
    $search[] = $engine;
  }

  if ($dropped) {
    if (!$search) {
      $search[] = array(
        'type' => 'mysql',
        'roles' => array('read' => true, 'write' => true),
      );
    }
    $config['cluster.search'] = array_values($search);
    $removed[] = 'cluster.search[type=gorge]';
  }
}

if (!$removed) {
  exit(0);
}

$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
  fwrite(STDERR, "Unable to encode: ".$local_path."\n");
  exit(1);
}
$json .= "\n";

$temporary = $local_path.'.tmp.'.getmypid();
if (file_put_contents($temporary, $json, LOCK_EX) === false) {
  fwrite(STDERR, "Unable to write: ".$temporary."\n");
  exit(1);
}

// Preserve the ownership and mode the entrypoint established, the same way
// build_deployment_config.php does when it republishes its own file.
$owner = fileowner($local_path);
$group = filegroup($local_path);
if ($owner !== false) {
  @chown($temporary, $owner);
}
if ($group !== false) {
  @chgrp($temporary, $group);
}
@chmod($temporary, 0640);

if (!rename($temporary, $local_path)) {
  @unlink($temporary);
  fwrite(STDERR, "Unable to install: ".$local_path."\n");
  exit(1);
}

printf(
  "Removed %d legacy Gorge key(s) from %s: %s\n",
  count($removed),
  $local_path,
  implode(', ', $removed));
