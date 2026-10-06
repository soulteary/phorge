<?php
// Disposable MySQL only: validates the real GC entry point and guard transaction.
$arcanist = getenv('GORGE_TEST_ARCANIST_DIR');
$port = getenv('GORGE_TEST_MYSQL_PORT');
$password = getenv('GORGE_TEST_MYSQL_PASSWORD');
if (!$arcanist || !$port || $password === false) {
  fwrite(STDERR, "Set GORGE_TEST_ARCANIST_DIR, GORGE_TEST_MYSQL_PORT and GORGE_TEST_MYSQL_PASSWORD.\n"); exit(1);
}
require $arcanist.'/src/init/init-library.php';
phutil_load_library($arcanist.'/src');
$root = dirname(__FILE__).'/../../..';
phutil_load_library($root.'/src');
PhabricatorEnv::initializeScriptEnvironment(true, true);
$namespace = 'gorge_cleanup_'.getmypid().'_'.bin2hex(random_bytes(4));
$admin = new mysqli('127.0.0.1', 'root', $password, '', (int)$port);
$names = array();
try {
  foreach (array('cache', 'conduit', 'daemon', 'system') as $role) {
    $name = $namespace.'_'.$role; $names[] = $name;
    $admin->query('CREATE DATABASE `'.$name.'`');
    if ($role !== 'system') {
      $admin->select_db($name);
      $sql = file_get_contents($root.'/resources/sql/autopatches/20261006.'.$role.'.01.gorgecleanup.sql');
      $admin->query(str_replace('{$NAMESPACE}', $namespace, $sql));
    }
  }
  $admin->select_db($namespace.'_cache');
  $admin->query('CREATE TABLE cache_general (id BIGINT UNSIGNED PRIMARY KEY, cacheCreated BIGINT UNSIGNED NOT NULL, cacheExpires BIGINT UNSIGNED NULL, KEY c(cacheCreated), KEY t(cacheExpires)) ENGINE=InnoDB');
  $env = PhabricatorEnv::beginScopedEnv();
  foreach (array('mysql.host'=>'127.0.0.1','mysql.port'=>(string)$port,'mysql.user'=>'root','mysql.pass'=>$password,'storage.default-namespace'=>$namespace,'cluster.databases'=>array(),'cluster.instance'=>null,'phd.gorge-cleanup'=>true) as $key=>$value) { $env->overrideEnvConfig($key,$value); }
  PhabricatorEnv::setReadOnly(false,null);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
  PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
  $expected = array('cache_general','cache_general','cache_markupcache','conduit_methodcalllog','daemon_logevent','daemon_locklog');
  foreach (array_values(PhabricatorGorgeCleanup::getRegistry()) as $i=>$spec) { if ($spec[0]->getTableName() !== $expected[$i]) {throw new Exception('Registry table drift.');} }
  $env->overrideEnvConfig('phd.garbage-collection', array('cache.general'=>null,'daemon.lock-log'=>0));
  $export = PhabricatorGorgeCleanup::exportPolicies();
  if (!$export['guardEnabled'] || $export['readOnly'] || count($export['policies']) !== 6 || $export['policies'][1]['mode'] !== 'indefinite' || $export['policies'][5]['mode'] !== 'indefinite') {throw new Exception('Policy compatibility failed.');}
  $env->overrideEnvConfig('phd.garbage-collection', array('cache.general'=>86400));
  $export = PhabricatorGorgeCleanup::exportPolicies();
  if ($export['policies'][1]['retentionSeconds'] !== 86400) {throw new Exception('Effective override lost.');}
  if ($path = getenv('GORGE_TEST_CLEANUP_EXPORT')) {file_put_contents($path, phutil_json_encode($export));}
  $collector = new PhabricatorCacheTTLGarbageCollector();
  $admin->query('INSERT INTO cache_general VALUES (1,1,NULL),(2,1,1)');
  $collector->runCollector();
  if ((int)$admin->query('SELECT COUNT(*) AS n FROM cache_general')->fetch_assoc()['n'] !== 1) {throw new Exception('Legacy TTL boundary failed.');}
  $admin->query("UPDATE gorge_gc_control SET owner='gorge' WHERE collectorID='cache.general.ttl'");
  $admin->query('INSERT INTO cache_general VALUES (3,1,1)');
  $env->overrideEnvConfig('phd.gorge-cleanup', false);
  if ($collector->runCollector() !== false || (int)$admin->query('SELECT COUNT(*) AS n FROM cache_general')->fetch_assoc()['n'] !== 2) {throw new Exception('Go ownership did not protect legacy entry.');}
  $env->overrideEnvConfig('phd.gorge-cleanup', true);
  $admin->query("UPDATE gorge_gc_control SET owner='php' WHERE collectorID='cache.general.ttl'");
  $failed = false;
  try {
    PhabricatorGorgeCleanup::runGuarded($collector, function() {
      $conn = id(new PhabricatorKeyValueDatabaseCache())->establishConnection('w');
      queryfx($conn, 'DELETE FROM cache_general WHERE id=3');
      throw new Exception('fixture rollback');
    });
  } catch (Throwable $ex) { $failed = true; }
  if (!$failed || (int)$admin->query('SELECT COUNT(*) AS n FROM cache_general')->fetch_assoc()['n'] !== 2) {throw new Exception('Guard and deletion used different transactions.');}
  // Pause must wait on the same row that the legacy collector holds.
  PhabricatorGorgeCleanup::runGuarded($collector, function() use ($admin) {
    $admin->query('SET innodb_lock_wait_timeout=1');
    $blocked = false;
    try {
      $ok = $admin->query("UPDATE gorge_gc_control SET owner='paused' WHERE collectorID='cache.general.ttl'");
      $blocked = ($ok === false && $admin->errno === 1205);
    } catch (Throwable $ex) {$blocked = true;}
    if (!$blocked) {throw new Exception('Ownership crossed in-flight PHP batch.');}
    return false;
  });
  $admin->query("UPDATE gorge_gc_control SET owner='paused' WHERE collectorID='cache.general.ttl'");
  if ($collector->runCollector() !== false) {throw new Exception('Paused owner ran.');}
  $called = false;
  $admin->query("UPDATE gorge_gc_control SET owner='gorge' WHERE collectorID='cache.general.ttl'");
  try {
    PhabricatorGorgeCleanup::changePolicy($collector, function() use (&$called) {$called = true;});
  } catch (Throwable $ex) {}
  if ($called) {throw new Exception('Active Gorge owner allowed policy change.');}
  $admin->query("UPDATE gorge_gc_control SET owner='paused', policyHash='old' WHERE collectorID='cache.general.ttl'");
  PhabricatorGorgeCleanup::changePolicy($collector, function() use ($admin) {
    $row = $admin->query("SELECT policyHash,lastState FROM gorge_gc_control WHERE collectorID='cache.general.ttl'")->fetch_assoc();
    if ($row['policyHash'] !== '' || $row['lastState'] !== 'policy_changing') {throw new Exception('Policy write was not fenced before callback.');}
  });
  $row = $admin->query("SELECT owner,policyHash,lastState FROM gorge_gc_control WHERE collectorID='cache.general.ttl'")->fetch_assoc();
  if ($row['owner'] !== 'paused' || $row['policyHash'] !== '' || $row['lastState'] !== 'policy_pending') {throw new Exception('Pending policy did not remain paused.');}
  $admin->query('DROP TABLE gorge_gc_control');
  $failed = false; try {$collector->runCollector();} catch (Throwable $ex) {$failed = true;}
  if (!$failed) {throw new Exception('Missing control schema must fail closed.');}
  echo "PHP cleanup guard, effective policy and rollback checks passed.\n";
} finally {
  foreach ($names as $name) {$admin->query('DROP DATABASE `'.$name.'`');}
  $admin->close();
}
