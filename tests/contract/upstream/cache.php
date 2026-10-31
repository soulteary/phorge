<?php

// Use only a disposable MySQL server: all three databases have random names
// and are dropped in finally. No application migrations or Gorge are needed.
$port = getenv('GORGE_TEST_MYSQL_PORT');
$password = getenv('GORGE_TEST_MYSQL_PASSWORD');
if (!$port || $password === false) {
  fwrite(STDERR, "Set GORGE_TEST_MYSQL_PORT and GORGE_TEST_MYSQL_PASSWORD.\n");
  exit(1);
}
require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);
$admin = new mysqli('127.0.0.1', 'root', $password, '', (int)$port);
$namespace = 'upstream_contract_'.getmypid().'_'.bin2hex(random_bytes(4));
$databases = array();
$env = PhabricatorEnv::beginScopedEnv();
foreach (array(
  'mysql.host' => '127.0.0.1',
  'mysql.port' => (string)$port,
  'mysql.user' => 'root',
  'mysql.pass' => $password,
  'storage.default-namespace' => $namespace,
  'phabricator.cache-namespace' => $namespace,
  'cluster.databases' => array(),
  'cluster.instance' => null,
) as $key => $value) {
  $env->overrideEnvConfig($key, $value);
}
PhabricatorEnv::setReadOnly(false, null);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
$check = function($condition, $message) {
  if (!$condition) {
    throw new Exception($message);
  }
};
try {
  foreach (array('cache', 'user', 'feed') as $app) {
    $name = $namespace.'_'.$app;
    $admin->query('CREATE DATABASE `'.$name.'`');
    $databases[] = $name;
  }
  $admin->select_db($namespace.'_cache');
  $admin->query('CREATE TABLE cache_general (
    cacheKeyHash BINARY(12) PRIMARY KEY, cacheKey VARBINARY(255),
    cacheFormat VARBINARY(16), cacheData LONGBLOB,
    cacheCreated BIGINT, cacheExpires BIGINT NULL) ENGINE=InnoDB');
  $admin->select_db($namespace.'_user');
  $admin->query('CREATE TABLE user_preferences (
    id BIGINT PRIMARY KEY, phid VARCHAR(64), userPHID VARCHAR(64) NULL,
    preferences LONGTEXT, builtinKey VARCHAR(32),
    dateCreated BIGINT, dateModified BIGINT) ENGINE=InnoDB');
  $admin->query('CREATE TABLE user_cache (
    userPHID VARCHAR(64), cacheIndex BINARY(12), cacheKey VARBINARY(255),
    cacheData LONGTEXT, cacheType VARBINARY(32),
    UNIQUE KEY (userPHID, cacheIndex)) ENGINE=InnoDB');
  $admin->select_db($namespace.'_feed');
  $admin->query('CREATE TABLE feed_storynotification (
    userPHID VARCHAR(64), primaryObjectPHID VARCHAR(64),
    chronologicalKey BIGINT, hasViewed BOOL) ENGINE=InnoDB');

  // A cache hit containing [] must neither reload preferences nor rewrite
  // cache_general. The trigger makes the latter regression fail immediately.
  $cache = PhabricatorCaches::getMutableStructureCache();
  $key = PhabricatorUser::getGlobalSettingsCacheKey();
  $check((new PhabricatorUser())->getUserSetting('upstream.empty.a') === null,
    'Empty global preferences did not load.');
  $check($cache->getKey($key) === array(), 'Empty preferences were not cached.');
  $admin->select_db($namespace.'_cache');
  $admin->query("CREATE TRIGGER reject_cache_rewrite BEFORE UPDATE ON cache_general
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cache rewritten'");
  $check((new PhabricatorUser())->getUserSetting('upstream.empty.b') === null,
    'An empty cached value was not reusable.');
  $admin->query('DROP TRIGGER reject_cache_rewrite');

  // The existing global-settings invalidation path must still expose changes.
  $admin->select_db($namespace.'_user');
  $admin->query("INSERT INTO user_preferences VALUES
    (1, 'PHID-UPRF-contract', NULL, '{\"timezone\":\"UTC\"}', 'global', 1, 1)");
  $cache->deleteKey($key);
  $check((new PhabricatorUser())->getUserSetting('timezone') === 'UTC',
    'Configured global preferences were not loaded.');
  $admin->query("UPDATE user_preferences SET preferences = '{\"timezone\":\"Asia/Shanghai\"}'");
  $cache->deleteKey($key);
  $check((new PhabricatorUser())->getUserSetting('timezone') === 'Asia/Shanghai',
    'Global preferences did not refresh after invalidation.');

  $type = new PhabricatorUserNotificationCountCacheType();
  $key = PhabricatorUserNotificationCountCacheType::KEY_COUNT;
  $viewer = id(new PhabricatorUser())->setPHID('PHID-USER-contract');
  $seed = function() use ($type, $key, $viewer) {
    PhabricatorUserCache::writeCache($type, $key, $viewer->getPHID(), '1');
    $viewer->attachRawCacheData(array($key => '1'));
  };
  $read = function() use ($type, $key, $viewer) {
    return PhabricatorUserCache::readCaches($type, $key, array($viewer->getPHID()));
  };
  $seed();
  // No matching notification: keep persistent and in-object counts.
  PhabricatorFeedStoryNotification::updateObjectNotificationViews($viewer, 'PHID-TASK-none');
  $check(count($read()) === 1 && $viewer->getUnreadNotificationCount() === 1,
    'A no-op view invalidated notification counts.');
  $admin->select_db($namespace.'_feed');
  $admin->query("INSERT INTO feed_storynotification VALUES
    ('PHID-USER-contract', 'PHID-TASK-contract', 1, 0)");
  PhabricatorFeedStoryNotification::updateObjectNotificationViews($viewer, 'PHID-TASK-contract');
  $check($read() === array(), 'Marking a notification read retained the DB count.');
  // With inline generation disabled, accessing a cleared in-object count throws.
  $cleared = false;
  try {
    $viewer->getUnreadNotificationCount();
  } catch (PhabricatorDataNotAttachedException $ex) {
    $cleared = true;
  }
  $check($cleared, 'Marking a notification read retained the in-object count.');
  $seed();
  PhabricatorFeedStoryNotification::updateObjectNotificationViews($viewer, 'PHID-TASK-contract');
  $check(count($read()) === 1 && $viewer->getUnreadNotificationCount() === 1,
    'Viewing an already-read notification invalidated counts.');
  $row = $admin->query('SELECT hasViewed FROM feed_storynotification')->fetch_assoc();
  $check((int)$row['hasViewed'] === 1, 'Notification was not marked read.');
  echo "Global settings and notification cache MySQL regressions passed.\n";
} finally {
  foreach (array_reverse($databases) as $name) {
    $admin->query('DROP DATABASE `'.$name.'`');
  }
  $admin->close();
}
