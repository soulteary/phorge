<?php
// Only the owned, loopback retirement fixture may reach the native dispatcher.
$level = ob_get_level();
ob_start();
try {
  if (PHP_SAPI !== 'cli-server' ||
      getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
      ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    throw new RuntimeException('fixture_scope');
  }
  $private = getenv('WIKI_RETIREMENT_HTTP_FIXTURE');
  if (!is_string($private) || is_link($private) || realpath(dirname($private)) !== realpath(sys_get_temp_dir()) ||
      !preg_match('/\Awiki-http-[a-f0-9]{24}\z/', basename($private)) ||
      (fileperms($private) & 0777) !== 0700 || is_link($private.'/config.json') ||
      (fileperms($private.'/config.json') & 0777) !== 0600) {
    throw new RuntimeException('fixture_private_storage');
  }
  $config = json_decode(file_get_contents($private.'/config.json'), true);
  if (($_SERVER['HTTP_HOST'] ?? '') !== $config['host'] ||
      ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' ||
      !in_array(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $config['paths'], true)) {
    throw new RuntimeException('fixture_request');
  }
  $root = dirname(__DIR__, 2);
  require $root.'/support/runtime/bootstrap.php';
  phutil_load_library($root.'/src/');
  $stack = new PhabricatorConfigStackSource();
  $stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource($config['dictionary']));
  (new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))->setValue(null, $stack);
  (new ReflectionProperty(PhabricatorEnv::class, 'cache'))->setValue(null, array());
  $db = $config['dictionary'];
  if (!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/', $db['storage.default-namespace'])) {
    throw new RuntimeException('fixture_namespace');
  }
  $proof = new mysqli($db['mysql.host'], $db['mysql.user'], $db['mysql.pass'],
    $db['storage.default-namespace'].'_governance', $db['mysql.port']);
  $row = $proof->query('SELECT owner_nonce FROM fixture_guard WHERE id=1')->fetch_assoc();
  if (!$row || !hash_equals($config['nonce'], $row['owner_nonce'])) {
    throw new RuntimeException('fixture_owner');
  }
  $guard = new AphrontWriteGuard(static function() use ($proof, $config) {
    $row = $proof->query('SELECT owner_nonce FROM fixture_guard WHERE id=1')->fetch_assoc();
    if (!$row || !hash_equals($config['nonce'], $row['owner_nonce'])) {
      throw new RuntimeException('fixture_owner');
    }
  });
  ob_end_clean();
  $app = (new AphrontApplicationConfiguration())->setHost($config['host'])
    ->setPath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
  $request = $app->buildRequest();
  require_once $root.'/support/startup/PhabricatorStartup.php';
  PhabricatorStartup::beginOutputCapture();
  $meter = MultimeterControl::newInstance();
  $meter->setSampleRate(0);
  $app->processRequest($request, new PhutilDeferredLog(null, ''),
    new AphrontPHPHTTPSink(), $meter);
} catch (Throwable $error) {
  if (isset($private) && is_dir($private)) {
    file_put_contents($private.'/failure.json', json_encode(array('class'=>get_class($error), 'file'=>basename($error->getFile()), 'line'=>$error->getLine())));
  }
  while (ob_get_level() > $level) { ob_end_clean(); }
  http_response_code(500);
  echo 'isolated_http_fixture_failed';
} finally {
  if (isset($guard)) { $guard->dispose(); }
  if (isset($proof)) { $proof->close(); }
}
