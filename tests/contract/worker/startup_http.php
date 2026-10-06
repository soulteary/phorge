<?php

require_once __DIR__.'/../../../scripts/setup/lib/gorge_startup.php';
$directory = sys_get_temp_dir().'/gorge-startup-http-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$process = null;
try {
  file_put_contents($directory.'/router.php', <<<'PHP'
<?php
if (($_SERVER['HTTP_X_SERVICE_TOKEN'] ?? '') !== 'test-token') {
  http_response_code(401); exit;
}
switch ($_SERVER['REQUEST_URI']) {
  case '/api/mailer/delivery-capabilities':
    echo '{"data":{"schemaVersion":1,"recovery":true}}'; break;
  case '/api/search/exists': echo '{"data":{"exists":true}}'; break;
  case '/api/image/capabilities':
    echo json_encode(array('data' => array('protocolVersion' => 1,
      'recipeRevision' => 'phorge-v1', 'backendRevision' => 'fixture',
      'recipes' => array_fill_keys(array('profile', 'pinboard', 'thumbgrid',
        'preview', 'workcard'), array('width' => 1)),
      'inputFormats' => array('image/jpeg', 'image/png', 'image/gif', 'image/webp'),
      'animationPolicies' => array('legacy-static', 'legacy-preserve')))); break;
  case '/api/maintenance/collectors':
    $owner = file_exists(__DIR__.'/paused') ? 'paused' : 'gorge';
    echo json_encode(array('data' => array_map(function($id) use ($owner) {
      return array('id' => $id, 'owner' => $owner, 'policyHash' => 'fixture');
    }, array('cache.general.ttl', 'cache.general', 'cache.markup',
      'conduit.logs', 'daemon.processes', 'daemon.lock-log')))); break;
  case '/readyz': echo '{"status":"ok"}'; break;
  case '/login': echo '<html>login</html>'; break;
  case '/redirect': header('Location: /readyz'); break;
  case '/down': http_response_code(503); echo '{"status":"unavailable"}'; break;
  case '/old': echo '{"data":{"executionVersion":0,"leaseOutcomes":true}}'; break;
  case '/api/file/fetch/meta':
    echo '{"data":{"protocolVersion":1,"maxBytes":16777216,"publicOnly":true,"headerTokenOnly":true,"pinnedDNS":true}}'; break;
  case '/meta':
  case '/api/queue/meta':
  case '/api/worker/meta':
    echo '{"data":{"executionVersion":1,"leaseOutcomes":true}}'; break;
  case '/big': echo str_repeat('x', 65537); break;
  case '/api/diff/prose': echo '{"data":{"parts":[]}}'; break;
  case '/api/diff/generate':
  case '/diff':
    $input = json_decode(file_get_contents('php://input'), true);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $input !== array('old'=>'', 'new'=>'')) {
      http_response_code(400); exit;
    }
    echo '{"data":{"diff":""},"error":null}'; break;
  default: http_response_code(404);
}
PHP
  );
  $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
  if (!$socket) { throw new Exception('Unable to allocate local test port.'); }
  $address = stream_socket_get_name($socket, false);
  fclose($socket);
  $process = proc_open(array(PHP_BINARY, '-S', $address, $directory.'/router.php'),
    array(0 => array('pipe', 'r'), 1 => array('file', $directory.'/server.log', 'a'),
      2 => array('file', $directory.'/server.log', 'a')), $pipes);
  fclose($pipes[0]);
  $base = 'http://'.$address;
  $ready = array('test', $base.'/readyz', 'test-token', null, 'ready', true);
  $report = gorge_startup_wait(array($ready), 5, 'gorge_startup_request');
  if ($report['failed']) { throw new Exception('Local fixture did not start.'); }
  foreach (array(
    array('/readyz', 'ready', 'test-token', true),
    array('/readyz', 'ready', 'wrong-token', false),
    array('/login', 'ready', 'test-token', false),
    array('/redirect', 'ready', 'test-token', false),
    array('/down', 'ready', 'test-token', false),
    array('/old', 'execution', 'test-token', false),
    array('/meta', 'execution', 'test-token', true),
    array('/big', 'ready', 'test-token', false),
    array('/api/file/fetch/meta', 'file-fetch', 'test-token', true),
    array('/old', 'file-fetch', 'test-token', false),
    array('/diff', 'diff', 'test-token', true),
  ) as $case) {
    $payload = $case[1] === 'diff' ? array('old' => '', 'new' => '') : null;
    $probe = array('test', $base.$case[0], $case[2], $payload, $case[1], true);
    if (gorge_startup_request($probe, 2) !== $case[3]) {
      throw new Exception('Unexpected HTTP probe result for '.$case[0]);
    }
  }
  file_put_contents($directory.'/local.json', '{}');
  $config = array('gorge.service-policy' => 'required',
    'gorge.render.uri' => $base, 'gorge.render.token' => 'test-token',
    'gorge.file.uri' => $base, 'gorge.file.token' => 'test-token',
    'gorge.taskqueue.uri' => $base, 'gorge.taskqueue.token' => 'test-token');
  file_put_contents($directory.'/deployment.json', json_encode($config));
  foreach (array('test-token' => 0, 'wrong-token' => 1) as $token => $expected) {
    $child = proc_open(array(PHP_BINARY,
      __DIR__.'/../../../scripts/setup/check_gorge_startup.php',
      $directory.'/deployment.json', $directory.'/local.json'),
      array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $output, null,
      array('GORGE_WORKER_URI' => $base, 'GORGE_WORKER_TOKEN' => $token,
        'PHORGE_GORGE_STARTUP_TIMEOUT' => '1'));
    $stdout = stream_get_contents($output[1]);
    $stderr = stream_get_contents($output[2]);
    fclose($output[1]); fclose($output[2]);
    if (proc_close($child) !== $expected || strpos($stderr, $token) !== false) {
      throw new Exception('Startup CLI exit or credential isolation failed.');
    }
  }
  $config = array('gorge.service-policy' => 'required',
    'gorge.image.mode' => 'gorge', 'gorge.image.uri' => $base,
    'gorge.image.token' => 'test-token', 'phd.gorge-cleanup' => true,
    'metamta.gorge-delivery-mode' => 'native', 'gorge.mailer.exclusive' => true,
    'gorge.taskqueue.owner' => 'gorge', 'gorge.search.token' => 'test-token',
    'cluster.mailers' => array(array('type' => 'gorge', 'options' => array(
      'uri' => $base, 'token' => 'test-token'))),
    'cluster.search' => array(array('type' => 'gorge', 'hosts' => array(array(
      'host' => '127.0.0.1', 'port' => (int)parse_url($base, PHP_URL_PORT),
      'protocol' => 'http', 'roles' => array('read' => true, 'write' => true))))));
  file_put_contents($directory.'/deployment.json', json_encode($config));
  foreach (array(false => 0, true => 1) as $paused => $expected) {
    if ($paused) { file_put_contents($directory.'/paused', '1'); }
    $child = proc_open(array(PHP_BINARY,
      __DIR__.'/../../../scripts/setup/check_gorge_cutover.php',
      $directory.'/deployment.json', $directory.'/local.json'),
      array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $output, null,
      array('GORGE_WORKER_URI' => $base, 'GORGE_WORKER_TOKEN' => 'test-token',
        'GORGE_MAINTENANCE_URI' => $base, 'GORGE_MAINTENANCE_TOKEN' => 'test-token',
        'PHORGE_GORGE_STARTUP_TIMEOUT' => '1'));
    $stdout = stream_get_contents($output[1]);
    $stderr = stream_get_contents($output[2]);
    fclose($output[1]); fclose($output[2]);
    if (proc_close($child) !== $expected || strpos($stderr, 'test-token') !== false) {
      throw new Exception('Cutover CLI ownership or credential isolation failed.');
    }
  }
  echo "Startup HTTP contracts passed: auth, errors, redirects, bounds, diff, protocol, cutover CLI.\n";
} finally {
  if (is_resource($process)) { proc_terminate($process); proc_close($process); }
  foreach (glob($directory.'/*') as $file) { unlink($file); }
  rmdir($directory);
}
