<?php

// Disposable MySQL only. Exercise real PHP planning and ownership transactions.
$arcanist = getenv('GORGE_TEST_ARCANIST_DIR');
$port = getenv('GORGE_TEST_MYSQL_PORT');
$password = getenv('GORGE_TEST_MYSQL_PASSWORD');
if (!$arcanist || !$port || $password === false) {
  fwrite(STDERR, "Set Arcanist and disposable MySQL test environment variables.\n");
  exit(1);
}
require $arcanist.'/src/init/init-library.php';
phutil_load_library($arcanist.'/src');
$root = dirname(__FILE__).'/../../..';
phutil_load_library($root.'/src');
PhabricatorEnv::initializeScriptEnvironment(true, true);
$namespace = 'gorge_scheduler_'.getmypid().'_'.bin2hex(random_bytes(4));
$admin = new mysqli('127.0.0.1', 'root', $password, '', (int)$port);
$names = array($namespace.'_worker', $namespace.'_system');
$directory = sys_get_temp_dir().'/gorge-scheduler-http-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$process = null;
function checkScheduler($condition, $message) {
  if (!$condition) { throw new Exception($message); }
}
try {
  foreach ($names as $name) { $admin->query('CREATE DATABASE `'.$name.'`'); }
  $admin->select_db($names[0]);
  $admin->query('CREATE TABLE worker_trigger (id INT UNSIGNED NOT NULL PRIMARY KEY,
    phid VARBINARY(64), triggerVersion INT UNSIGNED NOT NULL, clockClass VARCHAR(64),
    clockProperties LONGTEXT, actionClass VARCHAR(64), actionProperties LONGTEXT) ENGINE=InnoDB');
  foreach (array('scheduler', 'scheduleridentity') as $patch) {
    $sql = file_get_contents($root.'/resources/sql/autopatches/20261007.worker.'.$patch.'.sql');
    foreach (explode(';', str_replace('{$NAMESPACE}', $namespace, $sql)) as $statement) {
      if (trim($statement) !== '') { $admin->query($statement); }
    }
  }
  $database_id = $admin->query('SELECT databaseID FROM worker_gorgeschedulercontrol WHERE id=1')->fetch_assoc()['databaseID'];
  checkScheduler(strlen($database_id) === 36, 'Identity migration did not initialize the database.');
  $admin->query('CREATE TABLE scheduler_fixture (id INT PRIMARY KEY) ENGINE=InnoDB');
  $env = PhabricatorEnv::beginScopedEnv();
  foreach (array('mysql.host'=>'127.0.0.1', 'mysql.port'=>(string)$port,
    'mysql.user'=>'root', 'mysql.pass'=>$password, 'storage.default-namespace'=>$namespace,
    'cluster.databases'=>array(), 'cluster.instance'=>null,
    'gorge.conduit.token'=>'scheduler-contract-only',
    'gorge.taskqueue.uri'=>'http://queue.invalid') as $key=>$value) {
    $env->overrideEnvConfig($key, $value);
  }
  PhabricatorEnv::setReadOnly(false, null);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
  $_SERVER['HTTP_X_SERVICE_TOKEN'] = 'scheduler-contract-only';
  $props = array('class'=>'PhabricatorCalendarImportReloadWorker',
    'data'=>array('importPHID'=>'PHID-CIMP-fixture'),
    'options'=>array('priority'=>0, 'delayUntil'=>123, 'objectPHID'=>'PHID-CIMP-fixture'));
  $insert = $admin->prepare('INSERT INTO worker_trigger VALUES (1,?,1,?,?,?,?)');
  $phid = 'PHID-WTRG-fixture';
  $clock = 'PhabricatorOneTimeTriggerClock';
  $clock_props = json_encode(array('epoch'=>100));
  $action = 'PhabricatorScheduleTaskTriggerAction';
  $action_props = json_encode($props);
  $insert->bind_param('sssss', $phid, $clock, $clock_props, $action, $action_props);
  $insert->execute();
  $params = array('protocol'=>1, 'phase'=>'plan', 'triggerID'=>1, 'version'=>1,
    'scheduledVersion'=>0, 'lastEpoch'=>null, 'nextEpoch'=>null, 'now'=>110,
    'databaseID'=>$database_id);
  foreach (array('plan', 'capabilities') as $phase) {
    $wrong_database = $params;
    $wrong_database['phase'] = $phase;
    $wrong_database['databaseID'] = '00000000-0000-0000-0000-000000000002';
    try { id(new ConduitCall('trigger.plan', $wrong_database))->execute(); throw new Exception('Other database accepted.'); }
    catch (ConduitException $ex) { checkScheduler($ex->getMessage() === 'ERR-TRIGGER-PLAN', 'Wrong database error.'); }
  }
  $plan = id(new ConduitCall('trigger.plan', $params))->execute();
  checkScheduler(!$plan['fire'] && $plan['nextEpoch'] === 100 && $plan['task'] === null,
    'Materialization executed the action.');
  $params['scheduledVersion'] = 1; $params['nextEpoch'] = 100;
  $plan = id(new ConduitCall('trigger.plan', $params))->execute();
  $data = json_decode($plan['task']['data'], true);
  checkScheduler($plan['fire'] && $plan['nextEpoch'] === null &&
    $plan['task']['priority'] === 0 && $plan['task']['delayUntil'] === 123 &&
    $data['trigger.this-epoch'] === 100 && $data['trigger.last-epoch'] === null,
    'One-time clock or task options changed.');
  // Planning must never mutate event/task tables: none are present here.
  $params['version'] = 2;
  try { id(new ConduitCall('trigger.plan', $params))->execute(); throw new Exception('Stale plan accepted.'); }
  catch (ConduitException $ex) { checkScheduler($ex->getMessage() === 'ERR-TRIGGER-PLAN', 'Wrong stale error.'); }
  $params['version'] = 1;
  PhabricatorEnv::setReadOnly(true, PhabricatorEnv::READONLY_UNREACHABLE);
  foreach (array('plan', 'capabilities') as $phase) {
    $read_only_params = $params;
    $read_only_params['phase'] = $phase;
    try { id(new ConduitCall('trigger.plan', $read_only_params))->execute(); throw new Exception('Read-only planning accepted.'); }
    catch (ConduitException $ex) { checkScheduler($ex->getMessage() === 'ERR-TRIGGER-READONLY', 'Wrong read-only error.'); }
  }
  PhabricatorEnv::setReadOnly(false, null);
  $_SERVER['HTTP_X_SERVICE_TOKEN'] = 'wrong';
  try { id(new ConduitCall('trigger.plan', $params))->execute(); throw new Exception('Unauthenticated plan accepted.'); }
  catch (ConduitException $ex) { checkScheduler($ex->getMessage() === 'ERR-TRIGGER-AUTH', 'Wrong auth error.'); }
  $_SERVER['HTTP_X_SERVICE_TOKEN'] = 'scheduler-contract-only';
  // Recurrence continues to use the PHP clock, including its time handling.
  $admin->query("UPDATE worker_trigger SET clockClass='PhabricatorMetronomicTriggerClock', clockProperties='{\"period\":30}' WHERE id=1");
  $plan = id(new ConduitCall('trigger.plan', $params))->execute();
  checkScheduler($plan['nextEpoch'] === 130, 'Recurring clock changed.');
  $admin->query("UPDATE worker_trigger SET actionClass='PhabricatorLogTriggerAction', actionProperties='{\"message\":\"test\"}' WHERE id=1");
  try { id(new ConduitCall('trigger.plan', $params))->execute(); throw new Exception('Unsupported action accepted.'); }
  catch (ConduitException $ex) { checkScheduler($ex->getMessage() === 'ERR-TRIGGER-PLAN', 'Wrong action error.'); }
  $ran = PhabricatorWorkerGorgeSchedulerControl::runPHP(function() use ($admin) {
    $admin->query('SET innodb_lock_wait_timeout=1');
    $blocked = false;
    try {
      $ok = $admin->query("UPDATE worker_gorgeschedulercontrol SET owner='paused' WHERE id=1");
      $blocked = ($ok === false && $admin->errno === 1205);
    }
    catch (Throwable $ex) { $blocked = true; }
    checkScheduler($blocked, 'Ownership crossed a PHP execution transaction.');
  });
  checkScheduler($ran, 'PHP did not own initial scheduler.');
  try {
    PhabricatorWorkerGorgeSchedulerControl::runPHP(function() {
      queryfx(id(new PhabricatorWorkerTrigger())->establishConnection('w'),
        'INSERT INTO scheduler_fixture VALUES (1)');
      throw new Exception('injected rollback');
    });
  } catch (Exception $ex) { checkScheduler($ex->getMessage() === 'injected rollback', 'Wrong rollback error.'); }
  checkScheduler((int)$admin->query('SELECT COUNT(*) AS n FROM scheduler_fixture')->fetch_assoc()['n'] === 0,
    'PHP owner guard did not roll back.');
  foreach (array('paused','gorge') as $owner) {
    $admin->query("UPDATE worker_gorgeschedulercontrol SET owner='$owner',epoch=epoch+1 WHERE id=1");
    $called = false;
    $ran = PhabricatorWorkerGorgeSchedulerControl::runPHP(function() use (&$called) { $called = true; });
    checkScheduler(!$called && !$ran, 'Non-PHP owner ran legacy scheduling.');
  }
  $switch_owner = function($owner) {
    $workflow = new PhabricatorWorkerTriggerManagementOwnerWorkflow();
    $args = new PhutilArgumentParser(array('trigger', '--set', $owner));
    $args->parsePartial($workflow->getArguments());
    return $workflow->execute($args);
  };
  try { $switch_owner('php'); throw new Exception('Direct owner switch accepted.'); }
  catch (PhutilArgumentUsageException $ex) { /* Explicit pause is required. */ }
  $switch_owner('paused');
  $switch_owner('php');
  // Verify the real ownership workflow seeds legacy versions without resetting
  // a pending metronomic first occurrence. The HTTP fixture models queue meta.
  $admin->query('CREATE TABLE lisk_counter (counterName VARBINARY(64) PRIMARY KEY, counterValue BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
  $admin->query("INSERT INTO lisk_counter VALUES ('trigger.cursor',2)");
  $admin->query('CREATE TABLE worker_triggerevent (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, triggerID INT UNSIGNED UNIQUE, lastEventEpoch INT UNSIGNED NULL, nextEventEpoch INT UNSIGNED NULL) ENGINE=InnoDB');
  $admin->query('INSERT INTO worker_triggerevent (triggerID,lastEventEpoch,nextEventEpoch) VALUES (1,NULL,100)');
  $admin->query("UPDATE worker_trigger SET actionClass='PhabricatorScheduleTaskTriggerAction' WHERE id=1");
  $admin->query("UPDATE worker_trigger SET actionProperties='".$admin->real_escape_string($action_props)."' WHERE id=1");
  file_put_contents($directory.'/router.php', <<<'PHP'
<?php
if (($_SERVER['HTTP_X_SERVICE_TOKEN'] ?? '') !== 'scheduler-contract-only') {
  http_response_code(401); exit;
}
echo file_get_contents(__DIR__.'/meta.json');
PHP
  );
  $queue_meta = array('data'=>array('schedulerProtocol'=>1,
    'schedulerAtomicEnqueue'=>true, 'schedulerDatabaseID'=>$database_id));
  file_put_contents($directory.'/meta.json', json_encode($queue_meta));
  $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
  if (!$socket) { throw new Exception('Unable to allocate fixture port.'); }
  $address = stream_socket_get_name($socket, false);
  fclose($socket);
  $process = proc_open(array(PHP_BINARY, '-S', $address, $directory.'/router.php'),
    array(0=>array('pipe','r'), 1=>array('file',$directory.'/server.log','a'),
      2=>array('file',$directory.'/server.log','a')), $pipes);
  fclose($pipes[0]);
  $env->overrideEnvConfig('gorge.taskqueue.uri', 'http://'.$address);
  $env->overrideEnvConfig('gorge.taskqueue.token', 'scheduler-contract-only');
  $started = false;
  for ($attempt = 0; $attempt < 50; $attempt++) {
    try { id(new PhabricatorGorgeTaskQueueClient())->getSchedulerCapabilities(); $started = true; break; }
    catch (Throwable $ex) { usleep(20000); }
  }
  checkScheduler($started, 'Queue fixture did not start.');
  $switch_owner('paused');
  $wrong_meta = $queue_meta;
  $wrong_meta['data']['schedulerDatabaseID'] = '00000000-0000-0000-0000-000000000002';
  file_put_contents($directory.'/meta.json', json_encode($wrong_meta));
  $rejected = false;
  try { $switch_owner('gorge'); }
  catch (Exception $ex) { $rejected = true; }
  checkScheduler($rejected, 'Queue using another database passed takeover.');
  $control = $admin->query('SELECT owner, epoch FROM worker_gorgeschedulercontrol')->fetch_assoc();
  checkScheduler($control['owner'] === 'paused' && (int)$control['epoch'] === 6,
    'Database mismatch changed ownership.');
  file_put_contents($directory.'/meta.json', json_encode($queue_meta));
  $admin->query("UPDATE worker_trigger SET clockProperties='{\"period\":4294967296}' WHERE id=1");
  $rejected = false;
  try { $switch_owner('gorge'); }
  catch (Exception $ex) { $rejected = true; }
  checkScheduler($rejected, 'Unrepresentable clock passed takeover preflight.');
  $control = $admin->query('SELECT owner, epoch FROM worker_gorgeschedulercontrol')->fetch_assoc();
  checkScheduler($control['owner'] === 'paused' && (int)$control['epoch'] === 6,
    'Failed preflight changed ownership.');
  try { id(new ConduitCall('trigger.plan', $params))->execute(); throw new Exception('Unrepresentable clock plan accepted.'); }
  catch (ConduitException $ex) { checkScheduler($ex->getMessage() === 'ERR-TRIGGER-PLAN', 'Wrong clock range error.'); }
  $admin->query("UPDATE worker_trigger SET clockProperties='{\"period\":30}' WHERE id=1");
  $switch_owner('gorge');
  $record = $admin->query('SELECT scheduledVersion FROM worker_gorgeschedule WHERE triggerID=1')->fetch_assoc();
  checkScheduler((int)$record['scheduledVersion'] === 1, 'Legacy event version was not preserved.');
  $plan = id(new ConduitCall('trigger.plan', $params))->execute();
  checkScheduler($plan['fire'] && $plan['nextEpoch'] === 130,
    'First metronomic occurrence was reset during takeover.');
  $switch_owner('paused');
  $switch_owner('php');
  $control = $admin->query('SELECT owner, epoch FROM worker_gorgeschedulercontrol')->fetch_assoc();
  checkScheduler($control['owner'] === 'php' && (int)$control['epoch'] === 9,
    'Owner workflow did not commit the expected generation.');
  // PHP's real cursor scan must preserve a version scheduled during Go
  // ownership, even when that version is ahead of the old PHP cursor.
  $admin->query('UPDATE worker_trigger SET triggerVersion=2 WHERE id=1');
  $admin->query('UPDATE worker_gorgeschedule SET scheduledVersion=2 WHERE triggerID=1');
  $admin->query('UPDATE worker_triggerevent SET nextEventEpoch=170 WHERE triggerID=1');
  $admin->query("INSERT INTO lisk_counter VALUES ('trigger.version',2)");
  $method = new ReflectionMethod(PhabricatorTriggerDaemon::class, 'scheduleTriggers');
  PhutilSignalRouter::initialize();
  $daemon = new PhabricatorTriggerDaemon(array());
  PhabricatorWorkerGorgeSchedulerControl::runPHP(function() use ($method, $daemon) {
    $method->invoke($daemon, 2);
  });
  $event = $admin->query('SELECT lastEventEpoch, nextEventEpoch FROM worker_triggerevent WHERE triggerID=1')->fetch_assoc();
  checkScheduler($event['lastEventEpoch'] === null && (int)$event['nextEventEpoch'] === 170,
    'PHP rollback rescheduled a Go-materialized occurrence.');
  $cursor = $admin->query("SELECT counterValue FROM lisk_counter WHERE counterName='trigger.cursor'")->fetch_assoc();
  checkScheduler((int)$cursor['counterValue'] === 3, 'PHP rollback failed to advance its cursor.');
  $admin->query('UPDATE worker_trigger SET triggerVersion=3 WHERE id=1');
  $admin->query("UPDATE lisk_counter SET counterValue=3 WHERE counterName='trigger.version'");
  PhabricatorWorkerGorgeSchedulerControl::runPHP(function() use ($method, $daemon) {
    $method->invoke($daemon, 3);
  });
  $event = $admin->query('SELECT nextEventEpoch FROM worker_triggerevent WHERE triggerID=1')->fetch_assoc();
  $materialized = $admin->query('SELECT scheduledVersion FROM worker_gorgeschedule WHERE triggerID=1')->fetch_assoc();
  checkScheduler((int)$event['nextEventEpoch'] > 170 && (int)$materialized['scheduledVersion'] === 3,
    'PHP skipped scheduling an updated trigger after rollback.');
  $clock_results = id(new PhabricatorTriggerClockTestCase())
    ->setWorkingCopy(ArcanistWorkingCopyIdentity::newFromPath($root))
    ->run();
  checkScheduler(count($clock_results) === 6, 'Existing clock tests were not executed.');
  foreach ($clock_results as $result) {
    checkScheduler($result->getResult() === ArcanistUnitTestResult::RESULT_PASS,
      'Clock regression: '.$result->getName().' '.$result->getUserData());
  }
  echo "PHP scheduler planning and ownership contract passed.\n";
} finally {
  if (is_resource($process)) { proc_terminate($process); proc_close($process); }
  foreach (glob($directory.'/*') as $path) { unlink($path); }
  rmdir($directory);
  foreach (array_reverse($names) as $name) { $admin->query('DROP DATABASE IF EXISTS `'.$name.'`'); }
}
