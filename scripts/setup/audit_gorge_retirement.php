#!/usr/bin/env php
<?php

// Read-only deletion gates. Counts include old and chunk-member file records;
// no source bytes, business rows, queue entries or indexes are removed.
require_once dirname(__FILE__).'/../../scripts/__init_script__.php';
require_once dirname(__FILE__).'/lib/gorge_status_runtime.php';

$args = new PhutilArgumentParser($argv);
$args->parseStandardArguments();
$args->parse(array(
  array('name' => 'verify-integrity', 'help' => pht('Read and verify every file in this audit run.')),
  array('name' => 'writers-paused', 'help' => pht('Attest that all file writers and migration workers are stopped.')),
));
$integrity = array('state' => 'not_verified');
if ($args->getArg('verify-integrity')) {
  try {
    $integrity = array('state' => 'verified', 'checked' => 0, 'failures' => 0, 'partial' => 0);
    $table = new PhabricatorFile();
    foreach (new LiskMigrationIterator($table) as $file) {
      if ($file->getIsPartial()) { $integrity['partial']++; continue; }
      try {
        $expected = $file->getIntegrityHash();
        if (!$expected || !hash_equals($expected, $file->newIntegrityHash())) {
          $integrity['failures']++;
        }
      } catch (Throwable $ex) { $integrity['failures']++; }
      $integrity['checked']++;
    }
    $integrity['verifiedEpoch'] = time();
  } catch (Throwable $ex) { $integrity = array('state' => 'unavailable'); }
}
$takeover = gorge_status_runtime_report(true);
$report = array('takeover' => $takeover);
$file_inventory = idx($takeover['inventory']['files'], 'data');
$legacy_files = null;
$legacy_chunks = null;
$files = null;
if ($file_inventory !== null) {
  $files = $file_inventory['engines'];
  $legacy_chunks = (int)$file_inventory['legacyChunks'];
  $legacy_files = 0;
  foreach ($files as $row) {
    if (!in_array($row['storageEngine'], array('gorge', 'chunks'), true)) {
      $legacy_files += (int)$row['records'];
    }
  }
}
$report['files'] = array(
  'engines' => $files, 'legacyRecords' => $legacy_files,
  'legacyChunkRecords' => $legacy_chunks,
  'requiresIntegrityValidation' => true,
  'nextStep' => 'Run bin/files migrate --engine gorge --all --copy --dry-run, '.
    'migrate --all --copy, then bin/files integrity --all before deleting readers.',
);

$report['files'] += gorge_retirement_file_gate($legacy_files, $legacy_chunks,
  $integrity, $args->getArg('writers-paused'));

$search = PhabricatorEnv::getEnvConfig('cluster.search');
$legacy_search = array();
foreach ($search as $entry) {
  if (idx($entry, 'type') !== 'gorge') {
    $legacy_search[] = idx($entry, 'type');
  }
}
$report['search'] = array(
  'legacyConfiguredTypes' => $legacy_search,
  'hasGorgeConfiguration' => (bool)array_filter($search, function($entry) {
    return idx($entry, 'type') === 'gorge';
  }),
  // Configuration alone never proves a rebuilt replacement has parity.
  'requiresRebuildAndQueryValidation' => true,
  'ferretDomainIndexesRetained' => true,
);

$report['sqlQueue'] = idx(idx($takeover['inventory']['queue'], 'data', array()), 'classes');
$report['queueScope'] = 'SQL only; unavailable or zero SQL rows does not prove Redis drained.';
$report['outbox'] = idx($takeover['inventory']['feedOutbox'], 'data');

$report['workerBoundary'] = $report['takeover']['workerBoundary'];

echo phutil_json_encode($report)."\n";
