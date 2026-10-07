<?php

// Dedicated disposable MySQL only; create and drop our own random database.
$port = getenv('GORGE_TEST_MYSQL_PORT');
$password = getenv('GORGE_TEST_MYSQL_PASSWORD');
if (!$port || $password === false) {
  fwrite(STDERR, "Set GORGE_TEST_MYSQL_PORT and GORGE_TEST_MYSQL_PASSWORD.\n");
  exit(1);
}
require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);
$admin = new mysqli('127.0.0.1', 'root', $password, '', (int)$port);
$namespace = 'gorge_delete_contract_'.getmypid().'_'.bin2hex(random_bytes(4));
$name = $namespace.'_search';
$admin->query('CREATE DATABASE `'.$name.'`');
$admin->select_db($name);
$system_name = $namespace.'_system';
$admin->query('CREATE DATABASE `'.$system_name.'`');
$env = PhabricatorEnv::beginScopedEnv();
$env->overrideEnvConfig('mysql.host', '127.0.0.1');
$env->overrideEnvConfig('mysql.port', (string)$port);
$env->overrideEnvConfig('mysql.user', 'root');
$env->overrideEnvConfig('mysql.pass', $password);
$env->overrideEnvConfig('storage.default-namespace', $namespace);
$env->overrideEnvConfig('cluster.instance', null);
$env->overrideEnvConfig('cluster.databases', array());
PhabricatorEnv::setReadOnly(false, null);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);

final class GorgeDeletionContractSource extends PhabricatorSearchDAO
  implements PhabricatorFulltextInterface, PhabricatorDestructibleInterface {
  protected $phid;
  protected function getConfiguration() {
    return array(self::CONFIG_AUX_PHID => true) + parent::getConfiguration();
  }
  public function getTableName() { return 'contract_source'; }
  public function newFulltextEngine() { return null; }
  public function destroyObjectPermanently(PhabricatorDestructionEngine $engine) {
    queryfx($this->establishConnection('w'),
      'DELETE FROM %R WHERE phid=%s', $this, $this->getPHID());
    throw new RuntimeException('contract: crash after source commit');
  }
}

function assertDeletionContract($condition, $message) {
  if (!$condition) { throw new Exception($message); }
}

try {
  foreach (array('20261006.search.01.gorgeprojection.sql',
    '20261006.search.02.gorgedeletion.sql') as $migration) {
    $ddl = file_get_contents(dirname(__FILE__).'/../../../resources/sql/autopatches/'.$migration);
    foreach (explode(';', str_replace('{$NAMESPACE}_search.', '', $ddl)) as $statement) {
      if (trim($statement) !== '') { $admin->query($statement); }
    }
  }
  $admin->query('CREATE TABLE contract_source (id BIGINT PRIMARY KEY, phid VARBINARY(64) UNIQUE) ENGINE=InnoDB');
  $source = id(new GorgeDeletionContractSource())->setPHID('PHID-TASK-deletion');
  $admin->query("INSERT INTO contract_source VALUES (1, 'PHID-TASK-deletion')");
  PhabricatorSearchDeletionRecovery::begin($source);
  assertDeletionContract(!PhabricatorSearchDeletionRecovery::reconcile($source->getPHID()),
    'A surviving source became a tombstone.');
  $count = $admin->query('SELECT COUNT(*) AS n FROM search_gorgeoutbox')->fetch_assoc();
  assertDeletionContract((int)$count['n'] === 0, 'A surviving source published a delete.');
  // Crash after source commit: the pre-existing intent is sufficient to recover.
  $admin->query('DELETE FROM contract_source');
  $admin->query("CREATE TRIGGER reject_deletion BEFORE INSERT ON search_gorgeoutbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test deletion rollback'");
  $failed = false;
  try { PhabricatorSearchDeletionRecovery::reconcile($source->getPHID()); }
  catch (Throwable $ex) { $failed = true; }
  $row = $admin->query('SELECT completedEpoch FROM search_gorgedeletion')->fetch_assoc();
  $count = $admin->query('SELECT COUNT(*) AS n FROM search_gorgeprojection')->fetch_assoc();
  assertDeletionContract($failed && $row['completedEpoch'] === null && (int)$count['n'] === 0,
    'Failed publication completed the intent or consumed a revision.');
  $admin->query('DROP TRIGGER reject_deletion');
  assertDeletionContract(PhabricatorSearchDeletionRecovery::reconcile($source->getPHID()),
    'Committed source deletion did not recover.');
  assertDeletionContract(!PhabricatorSearchDeletionRecovery::reconcile($source->getPHID()),
    'Completed deletion was published twice.');
  $event = json_decode($admin->query('SELECT payload FROM search_gorgeoutbox')->fetch_assoc()['payload'], true);
  assertDeletionContract($event['operation'] === 'delete' && $event['revision'] === '1',
    'Wrong authoritative deletion envelope.');
  assertDeletionContract(!PhabricatorSearchDeletionRecovery::reconcile('PHID-TASK-missing'),
    'Absence without an intent became authoritative.');
  // A missing source table is an error, never proof of absence.
  PhabricatorSearchDeletionRecovery::begin($source);
  $admin->query('DROP TABLE contract_source');
  $failed = false;
  try { PhabricatorSearchDeletionRecovery::reconcile($source->getPHID()); }
  catch (Throwable $ex) { $failed = true; }
  $row = $admin->query('SELECT completedEpoch FROM search_gorgedeletion')->fetch_assoc();
  assertDeletionContract($failed && $row['completedEpoch'] === null,
    'Source read failure completed a deletion.');
  $admin->query('CREATE TABLE contract_source (id BIGINT PRIMARY KEY, phid VARBINARY(64) UNIQUE) ENGINE=InnoDB');
  $admin->query("INSERT INTO contract_source VALUES (1, 'PHID-TASK-deletion')");
  $source->openTransaction();
  try {
    $failed = false;
    try { PhabricatorSearchDeletionRecovery::begin($source); }
    catch (Throwable $ex) { $failed = true; }
    assertDeletionContract($failed, 'Uncommitted caller accepted a durable intent.');
    queryfx($source->establishConnection('w'), 'DELETE FROM %R', $source);
    assertDeletionContract(!PhabricatorSearchDeletionRecovery::reconcile($source->getPHID()),
      'An uncommitted deletion became authoritative.');
  } finally { $source->killTransaction(); }
  $count = $admin->query('SELECT COUNT(*) AS n FROM contract_source')->fetch_assoc();
  assertDeletionContract((int)$count['n'] === 1, 'Caller rollback did not restore source.');
  // A poison intent must not prevent later intents from completing.
  $admin->query("UPDATE search_gorgedeletion SET objectClass='RetiredContractClass', nextAttempt=0");
  $other = id(new GorgeDeletionContractSource())->setPHID('PHID-TASK-recovery');
  PhabricatorSearchDeletionRecovery::begin($other);
  assertDeletionContract(PhabricatorSearchDeletionRecovery::recoverBatch() === 1,
    'Recovery did not continue after a poison intent.');
  $row = $admin->query("SELECT completedEpoch,nextAttempt FROM search_gorgedeletion WHERE objectPHID='PHID-TASK-deletion'")->fetch_assoc();
  assertDeletionContract($row['completedEpoch'] === null && (int)$row['nextAttempt'] > time(),
    'Unavailable class was completed or left in a hot retry loop.');
  // Exercise the actual engine wrapper: capture failure must precede deletion.
  $env->overrideEnvConfig('gorge.search.projection-shadow', true);
  $hook = id(new GorgeDeletionContractSource())->setPHID('PHID-TASK-hook');
  $admin->query("INSERT INTO contract_source VALUES (2, 'PHID-TASK-hook')");
  $admin->query("CREATE TRIGGER reject_intent BEFORE INSERT ON search_gorgedeletion FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test intent failure'");
  $failed = false;
  try { id(new PhabricatorDestructionEngine())->destroyObject($hook); }
  catch (Throwable $ex) { $failed = true; }
  $count = $admin->query("SELECT COUNT(*) AS n FROM contract_source WHERE phid='PHID-TASK-hook'")->fetch_assoc();
  assertDeletionContract($failed && (int)$count['n'] === 1,
    'Engine deleted source before a durable intent was recorded.');
  $admin->query('DROP TRIGGER reject_intent');
  $admin->query('CREATE TABLE `'.$system_name.'`.system_destructionlog (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, objectClass VARCHAR(128), rootLogID INT UNSIGNED NULL, objectPHID VARBINARY(64) NULL, objectMonogram VARCHAR(64) NULL, epoch INT UNSIGNED NOT NULL) ENGINE=InnoDB');
  $failed = false;
  try { id(new PhabricatorDestructionEngine())->destroyObject($hook); }
  catch (RuntimeException $ex) {
    if ($ex->getMessage() !== 'contract: crash after source commit') { throw $ex; }
    $failed = true;
  }
  assertDeletionContract($failed, 'Simulated engine crash did not run.');
  $row = $admin->query("SELECT completedEpoch FROM search_gorgedeletion WHERE objectPHID='PHID-TASK-hook'")->fetch_assoc();
  assertDeletionContract($row && $row['completedEpoch'] === null,
    'Engine crash lost or completed the pending intent.');
  assertDeletionContract(PhabricatorSearchDeletionRecovery::recoverBatch() === 1,
    'Engine pre-intent did not recover after source commit.');
  echo "Search deletion intent, source absence, replay and rollback contracts passed.\n";
} finally {
  $admin->query('DROP DATABASE `'.$name.'`');
  $admin->query('DROP DATABASE `'.$system_name.'`');
}
