<?php

// Exercise the real PHP client over HTTP without a production database.
require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) { throw new Exception($error); }
$address = stream_socket_get_name($listener, false);
fclose($listener);
$directory = sys_get_temp_dir().'/gorge-download-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$router = $directory.'/router.php';
file_put_contents($router, <<<'ROUTER'
<?php
if ($_SERVER['REQUEST_URI'] === '/api/file/fetch/meta') {
  header('Content-Type: application/json');
  echo json_encode(array('data'=>array('protocolVersion'=>1, 'maxBytes'=>16777216,
    'publicOnly'=>true, 'headerTokenOnly'=>true, 'pinnedDNS'=>true)));
  return;
}
$request = json_decode(file_get_contents('php://input'), true);
file_put_contents(__DIR__.'/request.json', json_encode(array(
  'path' => $_SERVER['REQUEST_URI'],
  'token' => $_SERVER['HTTP_X_SERVICE_TOKEN'] ?? null,
  'body' => $request,
)));
if (($request['uri'] ?? '') === 'https://example.test/error') {
  http_response_code(413);
  header('Content-Type: application/json');
  echo json_encode(array('error'=>array('code'=>'ERR_TOO_LARGE', 'message'=>'download exceeds size limit')));
} else {
  header('Content-Type: application/octet-stream');
  echo ($request['uri'] ?? '') === 'https://example.test/empty' ? '' : "fixture\0bytes";
}
ROUTER
);
$process = proc_open(
  array(PHP_BINARY, '-S', $address, $router),
  array(0=>array('file', '/dev/null', 'r'),
    1=>array('file', '/dev/null', 'w'), 2=>array('file', '/dev/null', 'w')),
  $pipes);
function checkDownload($condition, $message) {
  if (!$condition) { throw new Exception($message); }
}
try {
  $ready = false;
  for ($attempt = 0; $attempt < 100; $attempt++) {
    $connection = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
    if ($connection) { fclose($connection); $ready = true; break; }
    usleep(20000);
  }
  checkDownload($ready, 'Fixture did not start.');
  $env = PhabricatorEnv::beginScopedEnv();
  $env->overrideEnvConfig('gorge.file.uri', 'http://'.$address);
  $env->overrideEnvConfig('gorge.file.token', 'download-contract-only');
  $env->overrideEnvConfig('security.outbound-blacklist', array('8.8.0.0/16'));
  $client = new PhabricatorGorgeFileStorageClient();
  checkDownload(PhabricatorGorgeFileStorageClient::hasDownloadCapabilities(
    $client->getDownloadCapabilities()), 'Download capabilities missing.');
  checkDownload(!PhabricatorGorgeFileStorageClient::hasDownloadCapabilities(
    array('protocolVersion'=>1)), 'Old capabilities accepted.');
  $data = $client->downloadFile('https://example.test/file?secret=fixture');
  checkDownload($data === "fixture\0bytes", 'Binary response changed.');
  $request = json_decode(file_get_contents($directory.'/request.json'), true);
  checkDownload($request['path'] === '/api/file/fetch', 'Wrong route or token in URL.');
  checkDownload($request['token'] === 'download-contract-only', 'Missing token header.');
  checkDownload($request['body']['denyCIDRs'] === array('8.8.0.0/16'), 'Blacklist lost.');
  checkDownload($request['body']['uri'] === 'https://example.test/file?secret=fixture', 'URI changed.');
  checkDownload($client->downloadFile('https://example.test/empty') === '', 'Empty file rejected.');
  $failed = false;
  try { $client->downloadFile('https://example.test/error'); }
  catch (Exception $ex) { $failed = strpos($ex->getMessage(), 'ERR_TOO_LARGE') !== false; }
  checkDownload($failed, 'Error envelope ignored.');
  echo "Download PHP HTTP contract passed.\n";
} finally {
  if (is_resource($process)) { proc_terminate($process); proc_close($process); }
  foreach (array('router.php', 'request.json') as $file) {
    if (file_exists($directory.'/'.$file)) { unlink($directory.'/'.$file); }
  }
  rmdir($directory);
}
