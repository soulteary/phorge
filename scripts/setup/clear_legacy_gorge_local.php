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

// Only scalar, machine-owned keys. The list deliberately excludes
// "notification.servers", "cluster.mailers" and "cluster.search": those are
// shared lists an administrator may also have entries in, and the one-way
// migration in manage_collaboration_local.php already restores them.
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
