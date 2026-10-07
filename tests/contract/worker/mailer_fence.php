<?php

// Requires the explicit disposable MySQL server used by paired acceptance.
// Creates and removes a random test namespace; no provider requests or queues.
$port = getenv('GORGE_TEST_INTEGRATIONS_MYSQL_PORT');
if (!$port) {
  throw new Exception('Set GORGE_TEST_INTEGRATIONS_MYSQL_PORT.');
}
require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);
$password = getenv('GORGE_TEST_INTEGRATIONS_MYSQL_PASSWORD') ?: '';
$namespace = 'gorge_mailer_fence_test_'.bin2hex(random_bytes(6));
$database = $namespace.'_metamta';
$mysql = new mysqli('127.0.0.1', 'root', $password, null, (int)$port);
$mysql->query('CREATE DATABASE '.$database);
$mysql->select_db($database);
try {
  $quickstart = file_get_contents(dirname(__DIR__, 3).'/resources/sql/quickstart.sql');
  if (!preg_match('/CREATE TABLE `metamta_mail` \(.*?;\n/s', $quickstart, $matches)) {
    throw new Exception('Mail fixture schema unavailable.');
  }
  $mysql->query(str_replace(array('{$CHARSET}', '{$COLLATE_TEXT}'),
    array('utf8mb4', 'utf8mb4_bin'), $matches[0]));
  $env = PhabricatorEnv::beginScopedEnv();
  foreach (array('mysql.host' => '127.0.0.1', 'mysql.port' => (string)$port,
    'mysql.user' => 'root', 'mysql.pass' => $password,
    'storage.default-namespace' => $namespace, 'cluster.databases' => array(),
    'cluster.instance' => null) as $key => $value) {
    $env->overrideEnvConfig($key, $value);
  }
  PhabricatorEnv::setReadOnly(false, null);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
  $save = new ReflectionMethod('PhabricatorLiskDAO', 'save');
  $fence = new ReflectionMethod('PhabricatorMetaMTAMail', 'beginGorgeLegacySubmission');
  $mail = new PhabricatorMetaMTAMail();
  $save->invoke($mail);
  $stale = id(new PhabricatorMetaMTAMail())->load($mail->getID());
  $fence->invoke($mail);
  $observe = function($id) use ($mysql) {
    return $mysql->query('SELECT status FROM metamta_mail WHERE id='.(int)$id)
      ->fetch_assoc()['status'];
  };
  if ($observe($mail->getID()) !== PhabricatorMailOutboundStatus::STATUS_UNKNOWN) {
    throw new Exception('Submission fence was not committed before network I/O.');
  }
  $rejected = false;
  try { $fence->invoke($stale); } catch (Exception $ex) { $rejected = true; }
  if (!$rejected || $observe($mail->getID()) !== PhabricatorMailOutboundStatus::STATUS_UNKNOWN) {
    throw new Exception('A stale queued snapshot acquired the submission twice.');
  }
  LiskDAO::closeAllConnections();
  $reloaded = id(new PhabricatorMetaMTAMail())->load($mail->getID());
  if ($reloaded->getStatus() !== PhabricatorMailOutboundStatus::STATUS_UNKNOWN) {
    throw new Exception('Submission fence was lost after connection termination.');
  }
  $transactional = new PhabricatorMetaMTAMail();
  $save->invoke($transactional);
  $conn = $transactional->establishConnection('w');
  $conn->openTransaction();
  try {
    $rejected = false;
    try { $fence->invoke($transactional); } catch (Exception $ex) { $rejected = true; }
    if (!$rejected || $observe($transactional->getID()) !== PhabricatorMailOutboundStatus::STATUS_QUEUE) {
      throw new Exception('Uncommitted outer transaction allowed submission.');
    }
  } finally {
    $conn->killTransaction();
  }
  echo "Committed mail submission fence and stale/transaction rejection passed.\n";
} finally {
  LiskDAO::closeAllConnections();
  $mysql->query('DROP DATABASE '.$database);
  $mysql->close();
}
