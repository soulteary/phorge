#!/usr/bin/env php
<?php

// Read-only deletion gates. Counts include old and chunk-member file records;
// no source bytes, business rows, queue entries or indexes are removed.
require_once dirname(__FILE__).'/../../scripts/__init_script__.php';

$report = array();
$conn = id(new PhabricatorFile())->establishConnection('r');
$files = queryfx_all($conn,
  'SELECT storageEngine, COUNT(*) AS records, SUM(byteSize) AS bytes '.
  'FROM %T GROUP BY storageEngine', id(new PhabricatorFile())->getTableName());
$legacy_files = 0;
foreach ($files as $row) {
  if (!in_array($row['storageEngine'], array('gorge', 'chunks'), true)) {
    $legacy_files += (int)$row['records'];
  }
}
$report['files'] = array(
  'engines' => $files,
  'legacyRecords' => $legacy_files,
  'canRemoveLegacyReaders' => ($legacy_files === 0),
  'nextStep' => 'Run bin/files migrate --engine gorge --all --copy --dry-run, then '.
    'migrate --all --copy and verify bin/files integrity --all before deleting readers.',
);

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

$conn = id(new PhabricatorWorkerActiveTask())->establishConnection('r');
$report['sqlQueue'] = queryfx_all($conn,
  'SELECT taskClass, COUNT(*) AS records FROM %T GROUP BY taskClass',
  id(new PhabricatorWorkerActiveTask())->getTableName());
$report['queueScope'] = 'SQL queue only. Audit Redis separately when it owns '.
  'the active queue; zero SQL rows does not prove Redis is drained.';

$conn = id(new PhabricatorFeedStoryData())->establishConnection('r');
$report['outbox'] = queryfx_one($conn,
  'SELECT COUNT(*) AS pending, MAX(attempts) AS maximumAttempts '.
  'FROM %T WHERE deliveredEpoch IS NULL', 'feed_gorgeoutbox');

$report['workerBoundary'] = array(
  'native' => array('FeedPublisherHTTPWorker (deliveryVersion=1 with policy file)'),
  'delegated' => array('FeedPublisherWorker', 'PhabricatorSearchWorker',
    'PhabricatorMetaMTAWorker', 'PhabricatorApplicationTransactionPublishWorker',
    'remaining registered PHP worker classes'),
  'daemonGate' => 'Keep Trigger and Fact until their domain work is migrated '.
    'or retired. Retired repository/build applications need no new Go ports; '.
    'verify their persisted tasks are drained or explicitly retired.',
);

echo phutil_json_encode($report)."\n";
