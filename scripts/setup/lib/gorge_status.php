<?php

// Read-only report helpers. Never include endpoint bodies, credentials, task
// data, phone numbers, provider results or exception messages in the output.
function gorge_status_value($config, $key, $default = null) {
  return array_key_exists($key, $config) ? $config[$key] : $default;
}

function gorge_status_identity($uri) {
  $parts = parse_url($uri);
  if (!$parts || empty($parts['host'])) { return null; }
  $endpoint = strtolower(idx($parts, 'scheme', '').'://'.$parts['host']).
    ':'.idx($parts, 'port', '').idx($parts, 'path', '');
  return hash('sha256', $endpoint);
}

function gorge_status_capabilities(array $data) {
  $out = array();
  foreach (array('protocolVersion', 'version', 'schemaVersion', 'recovery', 'executionVersion',
    'leaseOutcomes', 'schedulerProtocol', 'schedulerAtomicEnqueue',
    'schedulerDatabaseID', 'enabled', 'integrityVersion', 'chunkSize',
    'maxSize', 'storageFormat', 'durability', 'deletionOutbox',
    'uploads', 'pinnedDNS', 'maxBytes', 'publicOnly', 'headerTokenOnly',
    'recipeRevision', 'backendRevision', 'processed', 'failed', 'active', 'fact') as $key) {
    if (isset($data[$key]) && is_scalar($data[$key])) { $out[$key] = $data[$key]; }
  }
  if (isset($data['supported']) && is_array($data['supported'])) {
    $out['supported'] = array_values(array_filter($data['supported'],'is_string'));
  }
  if (isset($data['backendIdentities']) && is_array($data['backendIdentities'])) {
    $out['backendIdentities'] = array();
    foreach ($data['backendIdentities'] as $backend => $identity) {
      if (!is_array($identity)) { continue; }
      $out['backendIdentities'][$backend] = array_intersect_key($identity,
        array_fill_keys(array('state', 'volumeIdentity'), true));
    }
  }
  if (array_is_list($data)) {
    $out['items'] = array();
    foreach ($data as $item) {
      if (!is_array($item)) { continue; }
      $safe = array_intersect_key($item, array_fill_keys(array(
        'id', 'owner', 'ownerEpoch', 'fence', 'leaseExpires', 'lastState', 'type', 'roles'), true));
      if (isset($item['host'])) {
        $safe['backendIdentity'] = hash('sha256', $item['host'].'/'.idx($item, 'index', ''));
      }
      $out['items'][] = $safe;
    }
  }
  foreach (array('meme', 'compose') as $key) {
    if (isset($data[$key]) && is_array($data[$key])) {
      $out[$key] = array_intersect_key($data[$key],
        array_fill_keys(array('revision', 'fontRevision'), true));
    }
  }
  return $out;
}

// Whitelist operational inventory independently of remote response extensions.
function gorge_status_operations(array $data) {
  $out = array();
  $fields = array('backend', 'snapshot', 'generatedEpoch',
    'physicalDatabaseIdentity', 'physicalIdentityState', 'configuredBackendIdentity',
    'examinedKeys', 'approximateMemoryBytes', 'inboxRecords', 'truncated',
    'memoryIncomplete', 'archivedIndexRecords', 'finalizeDeduplication',
    'activeCount', 'archivedCount', 'leasedCount', 'failedCount');
  foreach ($fields as $key) {
    if (isset($data[$key]) && is_scalar($data[$key])) { $out[$key] = $data[$key]; }
  }
  if (isset($data['stats']) && is_array($data['stats'])) {
    $out['stats'] = gorge_status_operations($data['stats']);
  }
  if (isset($data['tables']) && is_array($data['tables'])) {
    $out['tables'] = array();
    foreach ($data['tables'] as $record) {
      if (!is_array($record)) { continue; }
      $safe = array();
      foreach (array('table', 'state', 'records', 'approximateDataBytes',
        'approximateIndexBytes') as $key) {
        if (isset($record[$key]) && is_scalar($record[$key])) { $safe[$key] = $record[$key]; }
      }
      if (isset($record['states']) && is_array($record['states'])) {
        $safe['states'] = array_filter($record['states'], 'is_int');
      }
      $out['tables'][] = $safe;
    }
  }
  return $out;
}

function gorge_status_observe($uri, $token, $path, $probe) {
  if (!$uri) { return array('state' => 'not_configured'); }
  try {
    $data = call_user_func($probe, $uri, $token, $path);
    if (!is_array($data)) { throw new Exception('Invalid capabilities.'); }
    return array('state' => 'observed',
      'endpointIdentity' => gorge_status_identity($uri),
      'capabilities' => gorge_status_capabilities($data));
  } catch (Throwable $ex) {
    return array('state' => 'unavailable',
      'endpointIdentity' => gorge_status_identity($uri));
  }
}

function gorge_status_worker_boundary(array $config) {
  return array(
    'native' => array(
      array('taskClass' => 'FeedPublisherHTTPWorker',
        'condition' => 'deliveryVersion=1; worker policy or PHP preparation'),
      array('taskClass' => 'PhabricatorNotificationPublishWorker',
        'condition' => 'worker notification mode native/auto; policy configured'),
      array('taskClass' => 'GorgeMailDeliveryWorker',
        'condition' => 'native mail enabled; PHP prepare/authorize/apply retained'),
      array('taskClass' => 'GorgeMailSubmitWorker',
        'condition' => 'native mail enabled; durable mailer ledger'),
    ),
    'delegated' => array('legacy FeedPublisherHTTPWorker payloads',
      'PhabricatorSearchWorker', 'PhabricatorMetaMTAWorker',
      'PhabricatorApplicationTransactionPublishWorker', 'HeraldWebhookWorker',
      'other classes registered through the PHP fallback'),
    'mailMode' => gorge_status_value($config, 'metamta.gorge-delivery-mode', 'legacy'),
    'notificationOutbox' => gorge_status_value($config, 'gorge.notification.outbox', false),
    'triggerDaemon' => 'Retain GC, Nuance, calendar and deletion recovery loops; '.
      'only supported scheduling actions can transfer through database owner.',
    'factDaemon' => gorge_status_value($config, 'gorge.integrations', array()) &&
      idx($config['gorge.integrations'], 'fact', false) === true
      ? 'Stop PHP Fact writers before enabling Go; running old processes are not revoked.'
      : 'PHP retains Fact execution until explicitly enabled.',
    'removalGate' => 'Native availability is not proof that persisted tasks or '.
      'historical formats have drained. Audit Redis independently of SQL.',
  );
}

function gorge_status_build(array $config, $probe, $inventory) {
  $report = array('reportVersion' => 1, 'generatedEpoch' => time(),
    'scope' => 'Read-only snapshot; configured modes and observations are separate. '.
      'Unavailable is unknown, never zero or proof of retirement.', 'services' => array());
  $specs = array(
    'render' => array('gorge.render', '/api/highlight/languages'),
    'conduit' => array('gorge.conduit', '/readyz'),
    'image' => array('gorge.image', '/api/image/capabilities'),
    'file' => array('gorge.file', '/api/file/lifecycle/meta'),
    'taskqueue' => array('gorge.taskqueue', '/api/queue/meta'),
    'webhook' => array('gorge.webhook', '/readyz'),
    'db' => array('gorge.db', '/readyz'),
  );
  foreach ($specs as $key => $spec) {
    $uri = gorge_status_value($config, $spec[0].'.uri');
    $report['services'][$key] = array('configured' => (bool)$uri,
      'configuredOwner' => gorge_status_value($config, $spec[0].'.owner'),
      'observation' => gorge_status_observe($uri,
        gorge_status_value($config, $spec[0].'.token'), $spec[1], $probe));
  }
  $report['services']['image']['modes'] = array(
    'transform' => gorge_status_value($config, 'gorge.image.mode', 'legacy'),
    'meme' => gorge_status_value($config, 'gorge.image.meme-mode', 'legacy'),
    'builtin' => gorge_status_value($config, 'gorge.image.builtin-mode', 'legacy'));
  $report['services']['file']['modes'] = array(
    'uploads' => gorge_status_value($config, 'gorge.file.uploads', false),
    'deletionOutbox' => gorge_status_value($config, 'gorge.file.deletion-outbox', false));
  $report['services']['file']['uploadObservation'] = gorge_status_observe(
    gorge_status_value($config, 'gorge.file.uri'),
    gorge_status_value($config, 'gorge.file.token'), '/api/file/uploads/meta', $probe);
  foreach (array('mailer' => 'cluster.mailers', 'search' => 'cluster.search') as $key => $option) {
    $entries = array();
    foreach (gorge_status_value($config, $option, array()) as $entry) {
      $options = idx($entry, 'options', array());
      $uri = idx($options, 'uri', idx($entry, 'uri'));
      $hosts = idx($entry, 'hosts', array());
      if ($key === 'search' && $hosts) {
        foreach ($hosts as $host) {
          $hostname = $host['host'];
          if (strpos($hostname, ':') !== false && $hostname[0] !== '[') { $hostname = '['.$hostname.']'; }
          $host_uri = idx($host, 'protocol', 'http').'://'.$hostname.':'.
            idx($host, 'port', 8120).idx($host, 'path', '');
          $entries[] = array('type' => idx($entry, 'type'),
            'roles' => idx($host, 'roles', array()),
            'endpointIdentity' => gorge_status_identity($host_uri),
            'observation' => idx($entry, 'type') === 'gorge'
              ? gorge_status_observe($host_uri, gorge_status_value($config, 'gorge.search.token'),
                  '/api/search/backends', $probe) : array('state' => 'legacy_configured'));
        }
        continue;
      }
      $entries[] = array('type' => idx($entry, 'type'),
        'backendIdentity' => $uri ? gorge_status_identity($uri) : null,
        'observation' => idx($entry, 'type') === 'gorge'
          ? gorge_status_observe($uri, idx($options, 'token', idx($entry, 'token')),
              $key === 'mailer' ? '/api/mailer/delivery-capabilities' : '/readyz', $probe)
          : array('state' => 'legacy_configured'));
    }
    $report['services'][$key] = array('backends' => $entries);
  }
  $report['services']['search']['projectionMode'] = gorge_status_value(
    $config, 'gorge.search.projection-shadow', false) ? 'shadow' : 'off';
  $report['services']['search']['liveGenerationCutover'] = 'not_proven';
  $integration = gorge_status_value($config, 'gorge.integrations', array());
  $report['services']['integrations'] = array(
    'enabledDomains' => array('inbound' => idx($integration, 'inbound', array()),
      'smsAdapters' => array_keys(idx($integration, 'sms', array())),
      'connectors' => array_keys(idx($integration, 'connectors', array())),
      'fact' => idx($integration, 'fact', false) === true),
    'observation' => gorge_status_observe(idx($integration, 'uri'),
      idx($integration, 'token'), '/api/integrations/capabilities', $probe));
  $report['workerBoundary'] = gorge_status_worker_boundary($config);
  foreach (array('scheduler', 'cleanup', 'files', 'deletions', 'queue',
    'feedOutbox', 'mailOutbox', 'inboundReceipts', 'persistentCapacity', 'fact') as $domain) {
    try {
      $report['inventory'][$domain] = array('state' => 'observed',
        'data' => call_user_func($inventory, $domain));
    } catch (Throwable $ex) {
      $report['inventory'][$domain] = array('state' => 'unavailable');
    }
  }
  return $report;
}

function gorge_retirement_file_gate($legacy, $chunks, array $integrity, $paused) {
  $known = $legacy !== null && $chunks !== null;
  $verified = idx($integrity, 'state') === 'verified' &&
    idx($integrity, 'failures') === 0 && idx($integrity, 'partial') === 0;
  return array('historyDrained' => $known ? ($legacy === 0 && $chunks === 0) : null,
    'integrity' => $integrity, 'writersPausedAttestation' => (bool)$paused,
    'canRemoveLegacyReaders' => $known && $legacy === 0 && $chunks === 0 && $verified && $paused,
    'canRemoveLegacyChunkReaders' => $known && $chunks === 0 && $verified && $paused);
}

function gorge_status_capacity_points(array $report) {
  $points = array();
  $local = idx(idx(idx($report, 'inventory', array()), 'persistentCapacity', array()), 'data', array());
  foreach ($local as $name => $item) {
    if (idx($item,'state') === 'observed' && isset($item['physicalDatabaseIdentity'])) {
      $points['php/'.$name] = array('identity'=>$item['physicalDatabaseIdentity'],'data'=>$item['data']);
    }
  }
  $remote = idx($report,'runtimeOperations',array());
  $integration = idx(idx($report,'integrationCapacity',array()),'data',array());
  if (isset($integration['inventory'])) { $remote['integrations'] = array('state'=>'observed','data'=>$integration['inventory']); }
  foreach ($remote as $domain=>$item) {
    $data=idx($item,'data',array());
    if (idx($item,'state') !== 'observed' || !isset($data['physicalDatabaseIdentity'])) { continue; }
    foreach (idx($data,'tables',array()) as $table) {
      if (idx($table,'state') === 'observed') {
        $points[$domain.'/'.$table['table']] = array('identity'=>$data['physicalDatabaseIdentity'],'data'=>$table);
      }
    }
  }
  return $points;
}

function gorge_status_growth(array $current, array $previous) {
  $seconds=idx($current,'generatedEpoch',0)-idx($previous,'generatedEpoch',0);
  if ($seconds<=0 || idx($current,'reportVersion') !== idx($previous,'reportVersion')) {
    return array('state'=>'unavailable','reason'=>'incompatible report or timestamp');
  }
  $before=gorge_status_capacity_points($previous);$out=array();
  foreach (gorge_status_capacity_points($current) as $key=>$point) {
    $old=idx($before,$key);
    if (!$old || $old['identity'] !== $point['identity']) { $out[$key]=array('state'=>'not_comparable');continue; }
    $metrics=array();
    foreach (array('records','approximateDataBytes','approximateIndexBytes') as $metric) {
      if (isset($old['data'][$metric]) && isset($point['data'][$metric])) {
        $delta=$point['data'][$metric]-$old['data'][$metric];
        $metrics[$metric]=array('delta'=>$delta,'netPerDay'=>$delta*86400/$seconds);
      }
    }
    $out[$key]=array('state'=>'observed','metrics'=>$metrics);
  }
  return array('state'=>'observed','elapsedSeconds'=>$seconds,'scope'=>'net growth between observational snapshots; not insertion rate','tables'=>$out);
}

// A disabled upload feature is not a failed capacity observation.
function gorge_status_upload_usage($uri, $token, $probe) {
  if (!$uri) { return array('state' => 'not_configured'); }
  try {
    $meta = $probe($uri, $token, '/api/file/uploads/meta');
    if (!is_array($meta) || !isset($meta['enabled']) || !is_bool($meta['enabled'])) {
      return array('state' => 'unavailable');
    }
    if (!$meta['enabled']) { return array('state' => 'not_enabled'); }
    return array('state' => 'observed',
      'data' => $probe($uri, $token, '/api/file/uploads/usage'));
  } catch (Throwable $ex) { return array('state' => 'unavailable'); }
}
