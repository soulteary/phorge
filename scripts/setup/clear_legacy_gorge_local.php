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
// The predicate is the entry KEY, not its type. "cluster.mailers" is a shared
// list and DOCKER.md documents matching on the key precisely so that an
// administrator can add a second `type: gorge` mailer pointing at another
// instance under a different key without the managed entry overwriting it --
// so a type-wide sweep here would silently delete their mail routing.
//
// The managed key can only be claimed from a source that actually records
// ownership. There are two:
//
//   1. GORGE_MAILER_KEY, when the operator supplies it.
//   2. `gorge-mailer`, the key an installation which never set the variable
//      was managed under.
//
// The deployment document is deliberately NOT a third source. It looks like
// one -- the managed entry is written there with its key -- but
// build_deployment_config.php also copies every entry it preserved from
// local.json into it (see the loop around its `$mailers[] = $mailer`), so an
// administrator's own second `type: gorge` mailer appears there too and is
// indistinguishable from the managed one. Harvesting keys from it would make
// this migration delete their mail routing.
//
// A managed entry renamed by a deployment whose environment is gone is
// therefore not claimed automatically. It is reported instead, below: leaving
// a stale mailer in place is recoverable, deleting an administrator's is not.
$managed_keys = array();

$mailer_key = getenv('GORGE_MAILER_KEY');
if ($mailer_key !== false && strlen($mailer_key)) {
  $managed_keys[$mailer_key] = true;
}

$managed_keys['gorge-mailer'] = true;

if (array_key_exists('cluster.mailers', $config) &&
    is_array($config['cluster.mailers'])) {
  $mailers = array();
  $dropped_mailers = array();
  $unclaimed = array();
  foreach ($config['cluster.mailers'] as $mailer) {
    if (is_array($mailer)) {
      // Plain PHP only: like build_deployment_config.php, this script runs
      // without booting Phorge, so libphutil helpers are not available.
      $type = array_key_exists('type', $mailer) ? $mailer['type'] : null;
      $key = array_key_exists('key', $mailer) ? $mailer['key'] : null;

      if ($key !== null && isset($managed_keys[$key])) {
        $dropped_mailers[] = $key;
        continue;
      }

      if ($type === 'gorge') {
        $unclaimed[] = ($key === null) ? '<no key>' : $key;
      }
    }
    $mailers[] = $mailer;
  }

  if ($dropped_mailers) {
    if ($mailers) {
      $config['cluster.mailers'] = array_values($mailers);
    } else {
      // An empty list is not the same as no list: unset it so the built-in
      // mailer default applies again, which is where a pre-Gorge install was.
      unset($config['cluster.mailers']);
    }
    $removed[] = 'cluster.mailers['.implode(', ', $dropped_mailers).']';
  }

  // A Gorge mailer under a key this deployment never claimed is either an
  // administrator's own second instance or a managed entry renamed by a
  // deployment whose environment is gone. Nothing here can tell those apart,
  // and deleting the first would lose configuration this migration has no
  // business touching, so report instead of guessing.
  if ($unclaimed) {
    fwrite(
      STDERR,
      "[migration] Warning: left ".count($unclaimed)." Gorge mailer(s) in ".
      "place under unrecognized key(s): ".implode(', ', $unclaimed).". ".
      "If one of these was this deployment's managed mailer, rerun with ".
      "GORGE_MAILER_KEY set to its key, or remove it by hand -- it still ".
      "points at the retired service.\n");
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

// An empty result must still be a JSON object: json_encode() renders an empty
// PHP array as "[]", which is not a configuration document.
if (!$config) {
  $json = '{}';
} else {
  $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
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
