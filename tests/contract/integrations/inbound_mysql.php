<?php

// Uses only a disposable, fixed test namespace. Never call against a production host.
$port = getenv('GORGE_TEST_INTEGRATIONS_MYSQL_PORT');
if (!$port) { throw new Exception('Set GORGE_TEST_INTEGRATIONS_MYSQL_PORT.'); }
require_once dirname(__DIR__).'/bootstrap.php';
$root = dirname(__FILE__).'/../../..';
PhabricatorEnv::initializeScriptEnvironment(true, true);
$password = getenv('GORGE_TEST_INTEGRATIONS_MYSQL_PASSWORD') ?: '';
$mysql = new mysqli('127.0.0.1', 'root', $password, null, (int)$port);
$database = 'gorge_integrations_test_metamta';
$mysql->query('CREATE DATABASE '.$database);
$mysql->select_db($database);
try {
  $patch = file_get_contents($root.'/resources/sql/autopatches/20261007.metamta.01.gorgeinbound.sql');
  $patch = str_replace('{$NAMESPACE}_metamta.', '', $patch);
  $mysql->query($patch);
  $quickstart = file_get_contents($root.'/resources/sql/quickstart.sql');
  preg_match('/CREATE TABLE `metamta_receivedmail` \(.*?;\n/s', $quickstart, $matches);
  $mysql->query(str_replace(array('{$CHARSET}', '{$COLLATE_TEXT}'),
    array('utf8mb4', 'utf8mb4_bin'), $matches[0]));
  $env = PhabricatorEnv::beginScopedEnv();
  $env->overrideEnvConfig('mysql.host', '127.0.0.1');
  $env->overrideEnvConfig('mysql.port', (string)$port);
  $env->overrideEnvConfig('mysql.user', 'root');
  $env->overrideEnvConfig('mysql.pass', $password);
  $env->overrideEnvConfig('storage.default-namespace', 'gorge_integrations_test');
  $env->overrideEnvConfig('cluster.databases', array());
  $env->overrideEnvConfig('cluster.instance', null);
  PhabricatorEnv::setReadOnly(false, null);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
  $env->overrideEnvConfig('gorge.conduit.token', 'source-contract');
  $env->overrideEnvConfig('gorge.integrations', array('inbound'=>array('mailgun')));
  $_SERVER['HTTP_X_SERVICE_TOKEN'] = 'source-contract';
  $method = new PhabricatorIntegrationInboundConduitAPIMethod();
  $execute = new ReflectionMethod($method, 'execute');
  $message = array('provider'=>'mailgun','headers'=>array(
    'from'=>'sender@example.test','to'=>'receiver@example.test','message-id'=>'<fixture>',
    'x-phabricator-sent-this-message'=>'yes'), 'text'=>'fixture', 'html'=>'', 'attachments'=>null);
  $id = hash('sha256', 'receipt-fixture');
  $call = function($event, $body) use ($method, $execute) {
    return $execute->invoke($method, new ConduitAPIRequest(array('eventID'=>$event,'message'=>$body), true));
  };
  $first = $call($id, $message);
  if ($first['state'] !== 'done' || !$first['mailID']) { throw new Exception('Initial receipt not completed.'); }
  $second = $call($id, $message);
  if ($second !== $first) { throw new Exception('Replay created another email.'); }
  $count = $mysql->query('SELECT COUNT(*) AS n FROM metamta_receivedmail')->fetch_assoc();
  if ((int)$count['n'] !== 1) { throw new Exception('Duplicate inbound mail.'); }
  $message['text'] = 'conflict'; $conflict = false;
  try { $call($id, $message); } catch (Exception $ex) { $conflict = strpos($ex->getMessage(), 'conflict') !== false; }
  if (!$conflict) { throw new Exception('Identity collision accepted.'); }
  $message['text'] = 'fixture';
  $mysql->query("UPDATE metamta_gorgeinboundreceipt SET state='processing'");
  $unknown = $call($id, $message);
  if ($unknown['state'] !== 'unknown') { throw new Exception('Interrupted business command repeated.'); }
  $resolve = new ConduitAPIRequest(array('eventID'=>$id, 'message'=>$message,
    'phase'=>'reconcile', 'evidence'=>'Verified existing mail business transaction'), true);
  $blocked = false;
  try { $execute->invoke($method, $resolve); } catch (Exception $ex) { $blocked = true; }
  if (!$blocked) { throw new Exception('Active receipt reconciled prematurely.'); }
  $mysql->query("UPDATE metamta_gorgeinboundreceipt SET dateModified=1");
  $reconciled = $execute->invoke($method, $resolve);
  if ($reconciled !== $first || $call($id, $message) !== $first) {
    throw new Exception('Verified receipt reconciliation changed business identity.');
  }
  $count = $mysql->query('SELECT COUNT(*) AS n FROM metamta_receivedmail')->fetch_assoc();
  if ((int)$count['n'] !== 1) { throw new Exception('Reconciliation repeated mail processing.'); }
  $_SERVER['HTTP_X_SERVICE_TOKEN'] = 'wrong'; $denied = false;
  try { $call($id, $message); } catch (Exception $ex) { $denied = true; }
  if (!$denied) { throw new Exception('Service authentication bypass.'); }
  echo "Inbound MySQL PHP receipt contract passed.\n";
} finally {
  $mysql->query('DROP DATABASE '.$database);
  $mysql->close();
}
