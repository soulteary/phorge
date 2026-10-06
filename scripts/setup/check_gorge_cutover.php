#!/usr/bin/env php
<?php
require_once __DIR__.'/lib/gorge_cutover.php';
if ($argc !== 3) {
  fwrite(STDERR, "Usage: check_gorge_cutover.php <deployment> <local>\n");
  exit(64);
}
try {
  $config = array();
  foreach (array($argv[2], $argv[1]) as $file) {
    $raw = file_get_contents($file);
    $value = json_decode($raw, true);
    if (!is_array($value) || substr(ltrim($raw), 0, 1) !== '{') {
      throw new Exception('Invalid cutover configuration.');
    }
    $config = $value + $config;
  }
  $timeout = getenv('PHORGE_GORGE_STARTUP_TIMEOUT') ?: '60';
  if (!preg_match('/\A[1-9][0-9]*\z/', $timeout) || (int)$timeout > 600) {
    throw new Exception('Cutover timeout must be 1..600 seconds.');
  }
  $probes = gorge_cutover_probes($config,
    getenv('GORGE_WORKER_URI'), getenv('GORGE_WORKER_TOKEN'),
    getenv('GORGE_MAINTENANCE_URI'), getenv('GORGE_MAINTENANCE_TOKEN'));
  $result = gorge_startup_wait($probes, (int)$timeout, 'gorge_startup_request');
  if ($result['failed']) {
    throw new Exception('Cutover probes failed: '.implode(', ', $result['failed']));
  }
  echo "Production cutover verified: live search index, native mail ledger, ".
    "image recipes, six Gorge-owned collectors and worker readiness.\n";
  echo "Search coverage, external delivery and image behavior require functional acceptance.\n";
} catch (Throwable $ex) {
  // Messages contain only gate names, never URLs, response bodies or tokens.
  fwrite(STDERR, '[cutover] '.$ex->getMessage()."\n");
  exit(1);
}
