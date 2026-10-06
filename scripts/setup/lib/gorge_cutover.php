<?php

require_once __DIR__.'/gorge_startup.php';

// Read-only acceptance gate. It never creates an index, sends a message,
// changes collector ownership or promotes a shadow search generation.
function gorge_cutover_probes(array $config, $worker_uri, $worker_token,
  $maintenance_uri, $maintenance_token) {
  if (($config['gorge.service-policy'] ?? null) !== 'required' ||
      ($config['gorge.image.mode'] ?? null) !== 'gorge' ||
      empty($config['gorge.image.uri']) || empty($config['gorge.image.token']) ||
      ($config['metamta.gorge-delivery-mode'] ?? null) !== 'native' ||
      ($config['gorge.mailer.exclusive'] ?? null) !== true ||
      ($config['phd.gorge-cleanup'] ?? null) !== true ||
      ($config['gorge.taskqueue.owner'] ?? null) !== 'gorge' ||
      !$worker_uri || !$worker_token || !$maintenance_uri || !$maintenance_token) {
    throw new Exception('Cutover requires required policy, native exclusive '.
      'mail, Gorge image, cleanup guard, queue owner and authenticated services.');
  }
  foreach ($config['gorge.service-policies'] ?? array() as $service => $mode) {
    if (in_array($service, array('mailer', 'search', 'image', 'taskqueue'), true) &&
        $mode !== 'required') {
      throw new Exception('Cutover service policy must be required.');
    }
  }
  $searches = $config['cluster.search'] ?? array();
  if (count($searches) !== 1 || ($searches[0]['type'] ?? null) !== 'gorge' ||
      empty($searches[0]['hosts']) || empty($config['gorge.search.token'])) {
    throw new Exception('Cutover requires exclusive authenticated Gorge search.');
  }
  foreach ($searches[0]['hosts'] as $host) {
    if (($host['roles']['read'] ?? null) !== true ||
        ($host['roles']['write'] ?? null) !== true) {
      throw new Exception('Cutover search requires read and write roles.');
    }
  }
  $outbound = array_filter($config['cluster.mailers'] ?? array(), function($m) {
    return ($m['type'] ?? null) === 'gorge' &&
      (!isset($m['outbound']) || $m['outbound']) &&
      !empty($m['options']['uri']) && !empty($m['options']['token']);
  });
  if (count($outbound) !== 1) {
    throw new Exception('Cutover requires one authenticated Gorge email mailer.');
  }
  $probes = gorge_startup_probes($config, $worker_uri, $worker_token);
  foreach ($probes as &$probe) {
    $probe[5] = true;
    if ($probe[0] === 'search backend') { $probe[4] = 'live-index'; }
  }
  unset($probe);
  $probes[] = array('worker execution readiness', rtrim($worker_uri, '/').
    '/readyz', $worker_token, null, 'ready', true);
  $probes[] = array('cleanup schema readiness', rtrim($maintenance_uri, '/').
    '/readyz', $maintenance_token, null, 'ready', true);
  $probes[] = array('cleanup ownership', rtrim($maintenance_uri, '/').
    '/api/maintenance/collectors', $maintenance_token, null,
    'cleanup-owners', true);
  return $probes;
}
