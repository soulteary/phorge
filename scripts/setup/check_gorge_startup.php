#!/usr/bin/env php
<?php

require_once __DIR__.'/lib/gorge_startup.php';

if ($argc !== 3) {
  fwrite(STDERR, "Usage: check_gorge_startup.php <deployment> <local>\n");
  exit(64);
}
$config = array();
foreach (array($argv[2], $argv[1]) as $file) {
  $value = json_decode(file_get_contents($file), true);
  if (!is_array($value)) {
    throw new Exception('Invalid startup configuration.');
  }
  $config = $value + $config;
}
$timeout = getenv('PHORGE_GORGE_STARTUP_TIMEOUT');
if ($timeout === false || $timeout === '') {
  $timeout = '60';
}
if (!preg_match('/\A[1-9][0-9]*\z/', $timeout) || (int)$timeout > 600) {
  throw new Exception('PHORGE_GORGE_STARTUP_TIMEOUT must be 1..600 seconds.');
}
$worker_uri = getenv('GORGE_WORKER_URI');
$worker_token = getenv('GORGE_WORKER_TOKEN');
if ($worker_uri && !empty($config['gorge.conduit.uri']) &&
    empty($config['gorge.conduit.token'])) {
  fwrite(STDERR,
    "[startup] Set a nonempty GORGE_CONDUIT_TOKEN for PHP worker capabilities.\n");
  exit(64);
}
$report = gorge_startup_wait(gorge_startup_probes($config,
  $worker_uri, $worker_token), (int)$timeout, 'gorge_startup_request');
foreach ($report['optional'] as $name) {
  fwrite(STDERR, '[startup] Optional Gorge probe unavailable: '.$name."\n");
}
if ($report['failed']) {
  fwrite(STDERR, '[startup] Required Gorge probes timed out: '.
    implode(', ', $report['failed'])."\n");
  exit(1);
}
echo "[startup] Gorge readiness and execution capabilities verified.\n";
