<?php

// Standalone bootstrap probes: no Phorge bootstrap, database queries or writes.
function gorge_startup_probes(array $config, $worker_uri, $worker_token) {
  $probes = array();
  $policy = isset($config['gorge.service-policy'])
    ? $config['gorge.service-policy'] : 'required';
  $overrides = isset($config['gorge.service-policies'])
    ? $config['gorge.service-policies'] : array();
  foreach (array('render', 'file', 'conduit', 'webhook', 'taskqueue', 'db')
    as $service) {
    $mode = isset($overrides[$service]) ? $overrides[$service] : $policy;
    if ($service === 'db') {
      $mode = 'required';
    }
    $uri = isset($config['gorge.'.$service.'.uri'])
      ? $config['gorge.'.$service.'.uri'] : null;
    if ($mode === 'off' || !$uri) {
      continue;
    }
    $token = isset($config['gorge.'.$service.'.token'])
      ? $config['gorge.'.$service.'.token'] : null;
    $probes[] = array($service, rtrim($uri, '/').'/readyz', $token, null,
      'ready', $mode === 'required');
    if ($service === 'render') {
      foreach (array('generate' => 'diff', 'prose' => 'parts') as $route => $shape) {
        $probes[] = array('diff/'.$route,
          rtrim($uri, '/').'/api/diff/'.$route, $token,
          array('old' => 'startup old', 'new' => 'startup new'),
          $shape, true);
      }
    }
    if ($service === 'file') {
      $probes[] = array('file download protocol',
        rtrim($uri, '/').'/api/file/fetch/meta', $token,
        null, 'file-fetch', true);
    }
    if ($service === 'taskqueue') {
      $probes[] = array('queue protocol', rtrim($uri, '/').'/api/queue/meta',
        $token, null, 'execution', true);
    }
  }
  $image_mode = isset($config['gorge.image.mode'])
    ? $config['gorge.image.mode'] : 'legacy';
  if ($image_mode !== 'legacy') {
    $uri = isset($config['gorge.image.uri']) ? $config['gorge.image.uri'] : '';
    $token = isset($config['gorge.image.token'])
      ? $config['gorge.image.token'] : null;
    $probes[] = array('image readiness', rtrim($uri, '/').'/readyz',
      $token, null, 'ready', $image_mode === 'gorge');
    $probes[] = array('image capabilities', rtrim($uri, '/').
      '/api/image/capabilities', $token, null, 'image',
      $image_mode === 'gorge');
  }
  if ($worker_uri) {
    // Worker readiness includes a callback to PHP, which has not started yet.
    // Static capabilities are safe here; /readyz gates daemon startup later.
    $probes[] = array('worker protocol', rtrim($worker_uri, '/').'/api/worker/meta',
      $worker_token, null, 'execution', true);
  }
  foreach (array('gorge.file.uploads' => 'file-uploads',
    'gorge.file.deletion-outbox' => 'file-deletions') as $key => $shape) {
    if (!empty($config[$key])) {
      $file_uri = isset($config['gorge.file.uri']) ? $config['gorge.file.uri'] : '';
      $probes[] = array($shape, rtrim($file_uri, '/').'/api/file/lifecycle/meta',
        isset($config['gorge.file.token']) ? $config['gorge.file.token'] : null,
        null, $shape, true);
    }
  }
  if (!empty($config['gorge.file.uploads'])) {
    $file_uri = isset($config['gorge.file.uri']) ? $config['gorge.file.uri'] : '';
    $probes[] = array('file upload protocol',
      rtrim($file_uri, '/').'/api/file/uploads/meta',
      isset($config['gorge.file.token']) ? $config['gorge.file.token'] : null,
      null, 'file-upload-protocol', true);
  }
  foreach (array('gorge.image.meme-mode' => 'image-meme',
    'gorge.image.builtin-mode' => 'image-compose') as $key => $shape) {
    if (isset($config[$key]) && $config[$key] !== 'legacy') {
      $image_uri = isset($config['gorge.image.uri']) ? $config['gorge.image.uri'] : '';
      $probes[] = array($shape, rtrim($image_uri, '/').'/api/image/capabilities',
        isset($config['gorge.image.token']) ? $config['gorge.image.token'] : null,
        null, $shape, $config[$key] === 'gorge');
    }
  }
  foreach (isset($config['cluster.mailers']) ? $config['cluster.mailers'] : array()
    as $mailer) {
    if ($mailer['type'] !== 'gorge' ||
        (isset($mailer['outbound']) && !$mailer['outbound'])) {
      continue;
    }
    $mode = isset($overrides['mailer']) ? $overrides['mailer'] : $policy;
    if ($mode !== 'off') {
      $options = $mailer['options'];
      $probes[] = array('mailer', rtrim($options['uri'], '/').'/readyz',
        isset($options['token']) ? $options['token'] : null, null, 'ready',
        $mode === 'required');
      if (isset($config['metamta.gorge-delivery-mode']) &&
          $config['metamta.gorge-delivery-mode'] === 'native') {
        $probes[] = array('native mail ledger', rtrim($options['uri'], '/').
          '/api/mailer/delivery-capabilities',
          isset($options['token']) ? $options['token'] : null,
          null, 'mail-delivery', true);
      }
    }
  }
  foreach (isset($config['cluster.search']) ? $config['cluster.search'] : array()
    as $search) {
    $mode = isset($overrides['search']) ? $overrides['search'] : $policy;
    if ($search['type'] !== 'gorge' || $mode === 'off') {
      continue;
    }
    foreach ($search['hosts'] as $host) {
      $name = $host['host'];
      if (strpos($name, ':') !== false && $name[0] !== '[') {
        $name = '['.$name.']';
      }
      $probes[] = array('search', $host['protocol'].'://'.$name.':'.
        $host['port'].'/readyz',
        isset($config['gorge.search.token']) ? $config['gorge.search.token'] : null,
        null, 'ready', $mode === 'required');
      $probes[] = array('search backend', $host['protocol'].'://'.$name.':'.
        $host['port'].'/api/search/exists',
        isset($config['gorge.search.token']) ? $config['gorge.search.token'] : null,
        null, 'exists', $mode === 'required');
    }
  }
  foreach (isset($config['notification.servers'])
    ? $config['notification.servers'] : array() as $server) {
    if ($server['type'] !== 'admin' || !empty($server['disabled'])) {
      continue;
    }
    $mode = isset($overrides['notification'])
      ? $overrides['notification'] : $policy;
    if ($mode === 'off') {
      continue;
    }
    $host = $server['host'];
    if (strpos($host, ':') !== false && $host[0] !== '[') {
      $host = '['.$host.']';
    }
    $path = isset($server['path']) ? trim($server['path'], '/') : '';
    $probes[] = array('notification', $server['protocol'].'://'.$host.':'.
      $server['port'].'/'.(strlen($path) ? $path.'/' : '').'readyz',
      null, null, 'ready', $mode === 'required');
  }
  return $probes;
}

function gorge_startup_valid($shape, $json) {
  if (!is_array($json)) {
    return false;
  }
  if ($shape === 'ready') {
    return isset($json['status']) && $json['status'] === 'ok';
  }
  if (!isset($json['data']) || !is_array($json['data']) ||
      !empty($json['error'])) {
    return false;
  }
  $data = $json['data'];
  switch ($shape) {
    case 'file-upload-protocol':
      return isset($data['protocolVersion'], $data['enabled'],
        $data['chunkSize'], $data['maxSize'], $data['storageFormat'],
        $data['durability']) && $data['protocolVersion'] === 1 &&
        $data['enabled'] === true && ($data['integrityVersion'] ?? null) === 1 &&
        $data['chunkSize'] === 4 * 1024 * 1024 &&
        is_int($data['maxSize']) && $data['maxSize'] > 0 &&
        $data['maxSize'] <= 64 * 1024 * 1024 * 1024 &&
        $data['storageFormat'] === 'raw' && $data['durability'] === 'posix-volume';
    case 'file-uploads':
      return isset($data['protocolVersion'], $data['uploads']) &&
        $data['protocolVersion'] === 1 && $data['uploads'] === true;
    case 'file-deletions':
      return isset($data['protocolVersion'], $data['deletionOutbox']) &&
        $data['protocolVersion'] === 1 && $data['deletionOutbox'] === true;
    case 'image-meme':
      return isset($data['meme']['revision'], $data['meme']['fontRevision']) &&
        $data['meme']['revision'] === 'meme-v1' &&
        is_string($data['meme']['fontRevision']) &&
        preg_match('/^[a-f0-9]{64}$/D', $data['meme']['fontRevision']);
    case 'image-compose':
      return isset($data['compose']['revision'], $data['compose']['recipes']) &&
        $data['compose']['revision'] === 'compose-v1' &&
        is_array($data['compose']['recipes']) &&
        !array_diff(array('avatar', 'icon', 'favicon'), $data['compose']['recipes']);
    case 'file-fetch':
      return isset($data['protocolVersion'], $data['maxBytes'],
        $data['publicOnly'], $data['headerTokenOnly'], $data['pinnedDNS']) &&
        $data['protocolVersion'] === 1 &&
        is_int($data['maxBytes']) && $data['maxBytes'] >= 16 * 1024 * 1024 &&
        $data['publicOnly'] === true && $data['headerTokenOnly'] === true &&
        $data['pinnedDNS'] === true;
    case 'mail-delivery':
      return isset($data['schemaVersion'], $data['recovery']) &&
        $data['schemaVersion'] === 1 && $data['recovery'] === true;
    case 'image':
      if (!isset($data['protocolVersion'], $data['recipeRevision'],
          $data['backendRevision'], $data['recipes'], $data['inputFormats'],
          $data['animationPolicies']) || $data['protocolVersion'] !== 1 ||
          $data['recipeRevision'] !== 'phorge-v1' ||
          !is_string($data['backendRevision']) || !$data['backendRevision'] ||
          !is_array($data['recipes']) || !is_array($data['inputFormats']) ||
          !is_array($data['animationPolicies'])) {
        return false;
      }
      foreach (array('profile', 'pinboard', 'thumbgrid', 'preview', 'workcard')
        as $recipe) {
        if (!isset($data['recipes'][$recipe])) { return false; }
      }
      return !array_diff(array('image/jpeg', 'image/png', 'image/gif',
        'image/webp'), $data['inputFormats']) &&
        !array_diff(array('legacy-static', 'legacy-preserve'),
          $data['animationPolicies']);
    case 'live-index':
      return isset($data['exists']) && $data['exists'] === true;
    case 'cleanup-owners':
      $expected = array('cache.general.ttl', 'cache.general', 'cache.markup',
        'conduit.logs', 'daemon.processes', 'daemon.lock-log',
        'differential.parse', 'differential.viewstate', 'multimeter.events');
      $seen = array();
      foreach ($data as $state) {
        if (!is_array($state) || !isset($state['id'], $state['owner'],
            $state['policyHash']) || $state['owner'] !== 'gorge' ||
            !is_string($state['policyHash']) || !$state['policyHash'] ||
            !in_array($state['id'], $expected, true) ||
            isset($seen[$state['id']])) { return false; }
        $seen[$state['id']] = true;
      }
      return count($seen) === count($expected);
    case 'execution':
      return isset($data['executionVersion'], $data['leaseOutcomes']) &&
        $data['executionVersion'] === 1 && $data['leaseOutcomes'] === true;
    case 'diff':
      return isset($data['diff']) && is_string($data['diff']);
    case 'parts':
      return isset($data['parts']) && is_array($data['parts']);
    case 'exists':
      // false means a reachable backend whose index still needs init/rebuild.
      return isset($data['exists']) && is_bool($data['exists']);
  }
  return false;
}

function gorge_startup_request(array $probe, $timeout) {
  $curl = curl_init($probe[1]);
  $body = '';
  curl_setopt_array($curl, array(
    CURLOPT_WRITEFUNCTION => function($curl, $chunk) use (&$body) {
      if (strlen($body) + strlen($chunk) > 65536) {
        return 0;
      }
      $body .= $chunk;
      return strlen($chunk);
    },
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json',
      'X-Service-Token: '.(string)$probe[2]),
  ));
  if ($probe[3] !== null) {
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($probe[3]));
  }
  $success = curl_exec($curl);
  $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  unset($curl);
  // Never include response bodies, URLs or tokens in startup diagnostics.
  return $success !== false && $status === 200 &&
    gorge_startup_valid($probe[4], json_decode($body, true));
}

function gorge_startup_wait(array $probes, $timeout, callable $request) {
  $deadline = microtime(true) + $timeout;
  $optional = array();
  do {
    foreach ($probes as $key => $probe) {
      $remaining = $deadline - microtime(true);
      if ($remaining <= 0) {
        break;
      }
      if ($request($probe, min(2, $remaining))) {
        unset($probes[$key]);
      } else if (!$probe[5]) {
        $optional[] = $probe[0];
        unset($probes[$key]);
      }
    }
    if (!$probes || microtime(true) >= $deadline) {
      break;
    }
    usleep((int)(max(0, min(1, $deadline - microtime(true))) * 1000000));
  } while (true);
  return array(
    'failed' => array_values(array_map(function($probe) {
      return $probe[0];
    }, $probes)),
    'optional' => $optional,
  );
}
