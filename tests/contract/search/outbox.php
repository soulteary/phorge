<?php

// Dedicated disposable MySQL only; create and drop our own random database.
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
$namespace = 'gorge_search_contract_'.getmypid().'_'.bin2hex(random_bytes(4));
$name = $namespace.'_search';
$admin->query('CREATE DATABASE `'.$name.'`');
$admin->select_db($name);
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
try {
  $ddl = file_get_contents(dirname(__FILE__).'/../../../resources/sql/autopatches/20261006.search.01.gorgeprojection.sql');
  foreach (explode(';', str_replace('{$NAMESPACE}_search.', '', $ddl)) as $statement) {
    if (trim($statement) !== '') { $admin->query($statement); }
  }
  $doc = id(new PhabricatorSearchAbstractDocument())
    ->setPHID('PHID-TASK-outbox')->setDocumentType('TASK')
    ->setDocumentTitle('中文 snapshot')->setDocumentCreated(11)->setDocumentModified(12)
    ->addRelationship('auth', 'PHID-USER-contract', 'USER', '13');
  $first = PhabricatorSearchProjectionPublisher::publishDocument($namespace, $doc);
  $repeat = PhabricatorSearchProjectionPublisher::publishDocument($namespace, $doc);
  if ($first !== $repeat || $first['revision'] !== '1') {
    throw new Exception('Snapshot retry did not retain its revision and event.');
  }
  // Exercise the actual adapter's capture, without a remote backend write.
  $env->overrideEnvConfig('gorge.search.projection-shadow', true);
  $adapter = new PhabricatorGorgeFulltextStorageEngine();
  $service = new class($adapter) extends PhabricatorSearchService {
    public function getAnyHostForRole($role) {
      throw new RuntimeException('contract: capture complete');
    }
  };
  $adapter->setService($service);
  $doc->setForceProjection(true);
  try {
    $adapter->reindexAbstractDocument($doc);
    throw new Exception('Contract host boundary was not reached.');
  } catch (RuntimeException $ex) {
    if ($ex->getMessage() !== 'contract: capture complete') { throw $ex; }
  }
  $forced_row = $admin->query(
    'SELECT payload FROM search_gorgeoutbox ORDER BY id DESC LIMIT 1')->fetch_assoc();
  $forced = phutil_json_decode($forced_row['payload']);
  $doc->setForceProjection(false);
  $deleted = PhabricatorSearchProjectionPublisher::publishDeletion(
    $namespace, $doc->getPHID(), 'TASK', 'authoritative-delete');
  $restored = PhabricatorSearchProjectionPublisher::publishDocument($namespace, $doc);
  if ($forced['revision'] !== '2' || $deleted['revision'] !== '3' ||
      isset($deleted['document']) || $restored['revision'] !== '4') {
    throw new Exception('Revision monotonicity across force/delete/upsert failed.');
  }
  $admin->query("CREATE TRIGGER reject_projection BEFORE INSERT ON search_gorgeoutbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test outbox rollback'");
  $doc->setDocumentTitle('new content');
  $failed = false;
  try { PhabricatorSearchProjectionPublisher::publishDocument($namespace, $doc); }
  catch (Throwable $ex) { $failed = true; }
  $row = $admin->query('SELECT revision FROM search_gorgeprojection')->fetch_assoc();
  $count = $admin->query('SELECT COUNT(*) AS n FROM search_gorgeoutbox')->fetch_assoc();
  if (!$failed || (int)$row['revision'] !== 4 || (int)$count['n'] !== 4) {
    throw new Exception('Outbox failure consumed a revision or committed a snapshot.');
  }
  $new = clone $doc;
  $new->setPHID('PHID-TASK-newobject');
  $failed = false;
  try { PhabricatorSearchProjectionPublisher::publishDocument($namespace, $new); }
  catch (Throwable $ex) { $failed = true; }
  $count = $admin->query('SELECT COUNT(*) AS n FROM search_gorgeprojection')->fetch_assoc();
  if (!$failed || (int)$count['n'] !== 1) {
    throw new Exception('Failed first publication left an allocated state row.');
  }
  $admin->query('DROP TRIGGER reject_projection');
  $fifth = PhabricatorSearchProjectionPublisher::publishDocument($namespace, $doc);
  if ($fifth['revision'] !== '5') { throw new Exception('Retry skipped a revision.'); }
  $admin->query('UPDATE search_gorgeprojection SET revision = 9223372036854775807');
  $failed = false;
  try { PhabricatorSearchProjectionPublisher::publishDocument($namespace, $doc, null, true); }
  catch (Throwable $ex) { $failed = true; }
  if (!$failed) { throw new Exception('Revision overflow was accepted.'); }
  // Preserve a cross-language sample for the optional Go decoder check.
  $sample = getenv('GORGE_TEST_SEARCH_EVENT_FILE');
  if ($sample) { file_put_contents($sample, json_encode(array($first, $forced, $deleted, $restored, $fifth)));  }
  echo "Search revision, replay, deletion and outbox rollback contracts passed.\n";
} finally {
  $admin->query('DROP DATABASE `'.$name.'`');
}
