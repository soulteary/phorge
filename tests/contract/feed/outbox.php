<?php

// Run only against a disposable server. Creates and drops a random database.
$arcanist = getenv('GORGE_TEST_ARCANIST_DIR');
$port = getenv('GORGE_TEST_MYSQL_PORT');
$password = getenv('GORGE_TEST_MYSQL_PASSWORD');
if (!$arcanist || !$port || $password === false) {
  fwrite(STDERR, "Set GORGE_TEST_ARCANIST_DIR, GORGE_TEST_MYSQL_PORT and GORGE_TEST_MYSQL_PASSWORD.\n");
  exit(1);
}
require $arcanist.'/src/init/init-library.php';
phutil_load_library($arcanist.'/src');
phutil_load_library(dirname(__FILE__).'/../../../src');
PhabricatorEnv::initializeScriptEnvironment(true, true);

$admin = new mysqli('127.0.0.1', 'root', $password, '', (int)$port);
$namespace = 'gorge_contract_'.getmypid().'_'.bin2hex(random_bytes(4));
$name = $namespace.'_feed';
$admin->query('CREATE DATABASE `'.$name.'`');
$admin->select_db($name);
$env = PhabricatorEnv::beginScopedEnv();
$env->overrideEnvConfig('mysql.host', '127.0.0.1');
$env->overrideEnvConfig('mysql.port', (string)$port);
$env->overrideEnvConfig('mysql.user', 'root');
$env->overrideEnvConfig('mysql.pass', $password);
$env->overrideEnvConfig('cluster.databases', array());
PhabricatorEnv::setReadOnly(false, null);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
$env->overrideEnvConfig('storage.default-namespace', $namespace);
$env->overrideEnvConfig('cluster.instance', null);
$env->overrideEnvConfig('gorge.taskqueue.uri', 'http://127.0.0.1:1');
$env->overrideEnvConfig('gorge.service-policy', array('taskqueue' => 'required'));

final class GorgeOutboxContractStory extends PhabricatorFeedStory {
  public function renderView() { return null; }
  public function renderText() { return 'contract'; }
}

try {
  $notification = array(
    'type' => 'notification', 'key' => '123',
    'subscribers' => array('PHID-USER-contract'), 'uniqueID' => 'stable',
  );
  $task_data = array('key' => '123', 'notification' => $notification);
  $old_events = FeedPublisherWorker::newGorgeEvents('123', $task_data, false);
  $new_events = FeedPublisherWorker::newGorgeEvents('123', $task_data, true);
  $old_data = json_decode($old_events[0]['task']['data'], true);
  $new_data = json_decode($new_events[1]['task']['data'], true);
  $notify_data = json_decode($new_events[0]['task']['data'], true);
  if (count($old_events) !== 1 || count($new_events) !== 2 ||
      $old_data['notification'] !== $notification ||
      isset($new_data['notification']) ||
      $new_data['notificationDeliveryVersion'] !== 1 ||
      $notify_data['message'] !== $notification ||
      $notify_data['instance'] !== 'default' ||
      $notify_data['eventID'] !== 'notification.publish/123') {
    throw new Exception('Independent notification producer compatibility failed.');
  }
  $admin->query('CREATE TABLE feed_storydata (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, phid VARCHAR(64) NOT NULL, storyType VARCHAR(64) NOT NULL, storyData LONGTEXT NOT NULL, authorPHID VARCHAR(64) NOT NULL, chronologicalKey BIGINT UNSIGNED NOT NULL UNIQUE, dateCreated BIGINT NOT NULL, dateModified BIGINT NOT NULL) ENGINE=InnoDB');
  $sql = file_get_contents(dirname(__FILE__).'/../../../resources/sql/autopatches/20261006.feed.01.gorgeoutbox.sql');
  $admin->query(str_replace('{$NAMESPACE}_feed.', '', $sql));

  // Both producer events use the same InnoDB transaction. Force failure of
  // the second event to prove that the first notification cannot escape alone.
  $pair = FeedPublisherWorker::newGorgeEvents('999', $task_data, true);
  $admin->query("CREATE TRIGGER reject_feed_pair BEFORE INSERT ON feed_gorgeoutbox FOR EACH ROW BEGIN IF NEW.eventID = 'feed.publish/999' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'contract injection'; END IF; END");
  $pair_failed = false;
  $admin->begin_transaction();
  try {
    foreach ($pair as $event) {
      $stmt = $admin->prepare('INSERT INTO feed_gorgeoutbox (eventID,payload,lastError) VALUES (?,?,?)');
      $payload = phutil_json_encode($event);
      $empty = '';
      $stmt->bind_param('sss', $event['eventID'], $payload, $empty);
      if (!$stmt->execute()) {
        throw new Exception('Injected outbox write failed.');
      }
      $stmt->close();
    }
    $admin->commit();
  } catch (Throwable $ex) {
    $pair_failed = true;
    $admin->rollback();
  }
  $n = (int)$admin->query('SELECT COUNT(*) AS n FROM feed_gorgeoutbox')->fetch_assoc()['n'];
  if (!$pair_failed || $n !== 0) {
    throw new Exception('Partial notification/feed outbox transaction escaped.');
  }
  $admin->query('DROP TRIGGER reject_feed_pair');

  $make = function() {
    return id(new PhabricatorFeedStoryPublisher())
      ->setStoryType('GorgeOutboxContractStory')
      ->setStoryData(array('test' => true))
      ->setStoryAuthorPHID('PHID-USER-contract');
  };
  // Unreachable queue endpoint must not prevent the local transaction commit.
  $make()->publish();
  $row = $admin->query('SELECT eventID, payload FROM feed_gorgeoutbox')->fetch_assoc();
  if (!$row || json_decode($row['payload'], true)['eventID'] !== $row['eventID']) {
    throw new Exception('Committed event missing.');
  }
  $count = (int)$admin->query('SELECT COUNT(*) AS n FROM feed_storydata')->fetch_assoc()['n'];
  $admin->query('DROP TABLE feed_gorgeoutbox');
  $failed = false;
  try { $make()->publish(); } catch (Throwable $ex) { $failed = true; }
  $after = (int)$admin->query('SELECT COUNT(*) AS n FROM feed_storydata')->fetch_assoc()['n'];
  if (!$failed || $after !== $count) {
    throw new Exception('Story committed without its outbox event.');
  }
  echo "Feed outbox MySQL commit and rollback contract passed.\n";
} finally {
  $admin->query('DROP DATABASE `'.$name.'`');
  $admin->close();
}
