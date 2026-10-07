<?php

require_once dirname(__FILE__).'/gorge_status.php';

function gorge_status_runtime_inventory($domain) {
  switch ($domain) {
    case 'scheduler':
      $conn = id(new PhabricatorWorkerTrigger())->establishConnection('r');
      return array('control' => queryfx_one($conn,
        'SELECT owner,epoch,databaseID FROM worker_gorgeschedulercontrol WHERE id=1'),
        'triggers' => queryfx_all($conn,
          'SELECT actionClass,COUNT(*) AS records FROM worker_trigger GROUP BY actionClass'));
    case 'cleanup':
      $out = array();
      foreach (PhabricatorGorgeCleanup::getRegistry() as $key => $spec) {
        try {
          $out[$key] = queryfx_one($spec[0]->establishConnection('r'),
            'SELECT owner,ownerEpoch,fence,leaseExpires,lastState FROM '.
            'gorge_gc_control WHERE collectorID=%s', $key);
          if (!$out[$key]) { $out[$key] = array('state' => 'not_imported'); }
        } catch (Throwable $ex) { $out[$key] = array('state' => 'unavailable'); }
      }
      return $out;
    case 'files':
      $table = new PhabricatorFile();
      return array('engines' => queryfx_all($table->establishConnection('r'),
        'SELECT storageEngine,COUNT(*) AS records,COALESCE(SUM(byteSize),0) AS bytes '.
        'FROM %T GROUP BY storageEngine', $table->getTableName()),
        'legacyChunks' => PhabricatorChunkedFileStorageEngine::countLegacyFileRecords());
    case 'deletions':
      return queryfx_all(id(new PhabricatorFile())->establishConnection('r'),
        'SELECT state,COUNT(*) AS records,MAX(attempts) AS maximumAttempts '.
        'FROM file_gorgedeletion GROUP BY state');
    case 'queue':
      $table = new PhabricatorWorkerActiveTask();
      return array('scope' => 'SQL only; inspect Redis independently',
        'classes' => queryfx_all($table->establishConnection('r'),
          'SELECT taskClass,COUNT(*) AS records FROM %T GROUP BY taskClass', $table->getTableName()));
    case 'feedOutbox':
      return queryfx_one(id(new PhabricatorFeedStoryData())->establishConnection('r'),
        'SELECT COUNT(*) AS pending,MAX(attempts) AS maximumAttempts '.
        'FROM feed_gorgeoutbox WHERE deliveredEpoch IS NULL');
    case 'mailOutbox':
      return queryfx_one(id(new PhabricatorMetaMTAMail())->establishConnection('r'),
        'SELECT COUNT(*) AS pending FROM metamta_gorgeoutbox WHERE deliveredEpoch IS NULL');
    case 'inboundReceipts':
      $table = new PhabricatorGorgeInboundReceipt();
      return queryfx_all($table->establishConnection('r'),
        'SELECT state,COUNT(*) AS records FROM %T GROUP BY state', $table->getTableName());
    case 'persistentCapacity':
      $out = array();
      foreach (array(
        array(new PhabricatorFile(), array('file_gorgedeletion')),
        array(new PhabricatorMetaMTAMail(), array('metamta_gorgeinboundreceipt', 'metamta_gorgeoutbox')),
        array(new PhabricatorWorkerActiveTask(), array('worker_activetask', 'worker_archivetask', 'worker_gorgeinbox', 'worker_taskdata')),
        array(new PhabricatorFeedStoryData(), array('feed_gorgeoutbox')),
        array(new PhabricatorSearchGorgeProjection(), array('search_gorgeprojection', 'search_gorgedeletion')),
      ) as $spec) {
        foreach ($spec[1] as $name) {
          try {
            $conn = $spec[0]->establishConnection('r');
            $space = queryfx_one($conn, 'SELECT COALESCE(DATA_LENGTH,0) AS approximateDataBytes, '.
              'COALESCE(INDEX_LENGTH,0) AS approximateIndexBytes FROM information_schema.TABLES '.
              'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $name);
            if (!$space) { throw new Exception('Missing table.'); }
            $count = queryfx_one($conn, 'SELECT COUNT(*) AS records FROM %T', $name);
            $identity = queryfx_one($conn, 'SELECT @@server_uuid AS uuid,DATABASE() AS name');
            $out[$name] = array('state'=>'observed', 'data'=>$space + $count,
              'physicalDatabaseIdentity'=>hash('sha256', $identity['uuid'].'/'.$identity['name']));
          } catch (Throwable $ex) { $out[$name] = array('state'=>'unavailable'); }
        }
      }
      return $out;
    case 'fact':
      $table = new PhabricatorFactCursor();
      return queryfx_all($table->establishConnection('r'),
        'SELECT name,position FROM %T', $table->getTableName());
  }
  throw new Exception('Unknown inventory.');
}

function gorge_status_runtime_probe($uri, $token, $path) {
  $parts = parse_url($uri);
  if (!$parts || !in_array(idx($parts, 'scheme'), array('http', 'https'), true) ||
      isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) ||
      isset($parts['fragment'])) { throw new Exception('Invalid service URI.'); }
  $future = id(new HTTPSFuture(rtrim($uri, '/').$path))
    ->setTimeout(5)->setFollowLocation(false);
  if ($token) { $future->addHeader('X-Service-Token', $token); }
  list($status, $body) = $future->resolve();
  if (!($status instanceof HTTPFutureHTTPResponseStatus) ||
      $status->getStatusCode() !== 200 || strlen($body) > 1024 * 1024) {
    throw new Exception('Service unavailable.');
  }
  $data = phutil_json_decode($body);
  return idx($data, 'data', $data);
}

function gorge_status_runtime_report($local_only = false, array $runtime = array()) {
  $config = array();
  foreach (array('gorge.render', 'gorge.conduit', 'gorge.image', 'gorge.file',
    'gorge.taskqueue', 'gorge.webhook', 'gorge.db') as $prefix) {
    foreach (array('uri', 'token', 'owner') as $suffix) {
      $config[$prefix.'.'.$suffix] = PhabricatorEnv::getEnvConfigIfExists($prefix.'.'.$suffix);
    }
  }
  foreach (array('gorge.image.mode', 'gorge.image.meme-mode', 'gorge.image.builtin-mode',
    'gorge.file.uploads', 'gorge.file.deletion-outbox', 'gorge.integrations',
    'cluster.mailers', 'cluster.search', 'gorge.search.projection-shadow',
    'metamta.gorge-delivery-mode', 'gorge.notification.outbox') as $key) {
    $value = PhabricatorEnv::getEnvConfigIfExists($key);
    if ($value !== null) { $config[$key] = $value; }
  }
  $config['gorge.search.token'] = PhabricatorEnv::getEnvConfigIfExists('gorge.search.token');
  $probe = $local_only ? function() { throw new Exception('Not probed.'); }
    : 'gorge_status_runtime_probe';
  $report = gorge_status_build($config, $probe, 'gorge_status_runtime_inventory');
  $report['probeMode'] = $local_only ? 'local_only' : 'configured_endpoints';
  // These services do not have a PHP URI option. An optional restricted local
  // descriptor supplies runtime endpoints, never deployment environment dumps.
  foreach (array('worker' => '/api/worker/meta', 'maintenance' => '/api/maintenance/collectors') as $key => $path) {
    $entry = idx($runtime, $key, array());
    $report['services'][$key] = array('observation' => gorge_status_observe(
      idx($entry, 'uri'), idx($entry, 'token'), $path, $probe));
  }
  $report['runtimeOperations'] = array();
  $endpoints = array('taskqueue' => array(idx($config, 'gorge.taskqueue.uri'),
    idx($config, 'gorge.taskqueue.token'), '/api/queue/operations'));
  foreach (array('mailer' => '/api/mailer/operations', 'search' => '/api/search/projection/operations') as $key => $path) {
    $entry = idx($runtime, $key, array());
    if (idx($entry,'uri')) { $endpoints[$key] = array($entry['uri'],idx($entry,'token'),$path);continue; }
    $option = $key === 'mailer' ? 'cluster.mailers' : 'cluster.search';
    $found = false;
    foreach (idx($config,$option,array()) as $backend) {
      if (idx($backend,'type') !== 'gorge') { continue; }
      if ($key === 'mailer') {
        $opts=idx($backend,'options',array());$uri=idx($opts,'uri',idx($backend,'uri'));
        if (!$uri) {continue;}
        $endpoints[$key.'/'.substr(gorge_status_identity($uri),0,12)] = array($uri,idx($opts,'token',idx($backend,'token')),$path);
        $found=true;
      } else {
        foreach (idx($backend,'hosts',array()) as $host) {
          $hostname=$host['host'];
          if (strpos($hostname,':')!==false && $hostname[0]!=='[') {$hostname='['.$hostname.']';}
          $uri=idx($host,'protocol','http').'://'.$hostname.':'.idx($host,'port',8120).idx($host,'path','');
          $endpoints[$key.'/'.substr(gorge_status_identity($uri),0,12)] = array($uri,idx($config,'gorge.search.token'),$path);
          $found=true;
        }
      }
    }
    if (!$found) { $endpoints[$key] = array(null,null,$path); }
  }
  foreach ($endpoints as $key => $entry) {
    if (!$entry[0]) { $report['runtimeOperations'][$key] = array('state'=>'not_configured'); continue; }
    try {
      $data = call_user_func($probe, $entry[0], $entry[1], $entry[2]);
      $report['runtimeOperations'][$key] = array('state'=>'observed', 'data'=>gorge_status_operations($data));
    } catch (Throwable $ex) { $report['runtimeOperations'][$key] = array('state'=>'unavailable'); }
  }
  $worker = idx($runtime,'worker',array());
  $report['services']['worker']['stats'] = gorge_status_observe(idx($worker,'uri'),
    idx($worker,'token'),'/api/worker/stats',$probe);
  $localQueue = idx(idx(idx($report['inventory'],'persistentCapacity',array()),'data',array()),'worker_activetask',array());
  $remoteQueue = idx(idx($report['runtimeOperations'],'taskqueue',array()),'data',array());
  $report['queuePhysicalIdentityMatch'] = isset($localQueue['physicalDatabaseIdentity']) && isset($remoteQueue['physicalDatabaseIdentity'])
    ? $localQueue['physicalDatabaseIdentity'] === $remoteQueue['physicalDatabaseIdentity'] : null;
  $report['services']['notification'] = array(
    'configuredEndpoints' => count(PhabricatorEnv::getEnvConfig('notification.servers')),
    'deliveryAcknowledgement' => 'transport acceptance; browser receipt not proven');
  $control = idx(idx(idx($report['inventory'], 'scheduler'), 'data', array()), 'control');
  $queue_caps = idx($report['services']['taskqueue']['observation'], 'capabilities', array());
  $report['schedulerIdentityMatch'] = $control && isset($queue_caps['schedulerDatabaseID'])
    ? $control['databaseID'] === $queue_caps['schedulerDatabaseID'] : null;
  if (!empty($config['gorge.integrations']['uri']) && !$local_only) {
    foreach (array('health', 'usage', 'capacity') as $action) {
      $key = 'integration'.ucfirst($action);
      try {
        $client = PhabricatorGorgeIntegrationClient::newConfiguredClient();
        $report[$key] = array('state' => 'observed',
          'data' => $client->inspectIntegration($action, null));
      } catch (Throwable $ex) { $report[$key] = array('state' => 'unavailable'); }
    }
  }
  if (!$local_only) {
    $report['uploadUsage'] = gorge_status_upload_usage(
      idx($config, 'gorge.file.uri'), idx($config, 'gorge.file.token'),
      'gorge_status_runtime_probe');
  }
  return $report;
}
