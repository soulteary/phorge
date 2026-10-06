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
$namespace = 'gorge_scan_contract_'.getmypid().'_'.bin2hex(random_bytes(4));
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

final class GorgeSourceScanContractObject extends PhabricatorSearchDAO
  implements PhabricatorFulltextInterface {
  protected $phid;
  protected $title;
  public static $onBuild;
  public function getTableName() { return 'contract_scan_source'; }
  protected function getConfiguration() {
    return array(self::CONFIG_AUX_PHID => true, self::CONFIG_TIMESTAMPS => false)
      + parent::getConfiguration();
  }
  public function newFulltextEngine() {
    if ($this->getTitle() === 'no-engine') { return null; }
    return new class extends PhabricatorFulltextEngine {
      protected function newFulltextExtensions() { return array(); }
      protected function buildAbstractDocument($doc, $object) {
        if ($object->getTitle() === 'fail') {
          throw new Exception('contract: source build failed');
        }
        if (GorgeSourceScanContractObject::$onBuild) {
          call_user_func(GorgeSourceScanContractObject::$onBuild, $object);
        }
        $doc->setDocumentTitle($object->getTitle())
          ->setDocumentCreated(10)->setDocumentModified(11);
      }
    };
  }
}
final class GorgeSourceScanContractProvider extends PhabricatorSearchSourceProvider {
  protected function newSourceObjects() {
    return array(new GorgeSourceScanContractObject());
  }
}
function assertSourceContract($condition, $message) {
  if (!$condition) { throw new Exception($message); }
}
try {
  $ddl = file_get_contents(dirname(__FILE__).'/../../../resources/sql/autopatches/20261006.search.01.gorgeprojection.sql');
  foreach (explode(';', str_replace('{$NAMESPACE}_search.', '', $ddl)) as $statement) {
    if (trim($statement) !== '') { $admin->query($statement); }
  }
  $admin->query('CREATE TABLE contract_scan_source (id BIGINT PRIMARY KEY, phid VARBINARY(64) UNIQUE, title LONGTEXT NOT NULL) ENGINE=InnoDB');
  $admin->query("INSERT INTO contract_scan_source VALUES (1,'PHID-TASK-scan1','first'), (2,'PHID-TASK-scan2','fail'), (3,'PHID-TASK-scan3','third')");
  $provider = new GorgeSourceScanContractProvider();
  $catalog = $provider->catalog();
  assertSourceContract($catalog['sources'][0]['upperID'] === '3', 'Primary upper bound is wrong.');
  $failed = false;
  try { $provider->scan('GorgeSourceScanContractObject', '0', '3'); }
  catch (Throwable $ex) { $failed = true; }
  $count = $admin->query('SELECT COUNT(*) AS n FROM search_gorgeoutbox')->fetch_assoc();
  assertSourceContract($failed && (int)$count['n'] === 1, 'Partial capture fault did not persist only its completed prefix.');
  $admin->query("UPDATE contract_scan_source SET title='second' WHERE id=2");
  $admin->query("INSERT INTO contract_scan_source VALUES (4,'PHID-TASK-scan4','late')");
  $page = $provider->scan('GorgeSourceScanContractObject', '0', '3');
  assertSourceContract($page['complete'] && $page['nextID'] === '3' && count($page['items']) === 3,
    'Retry failed to scan exactly the captured range.');
  assertSourceContract($page['items'][0]['event']['revision'] === '1', 'Retry recaptured unchanged content with a new revision.');
  $repeat = $provider->scan('GorgeSourceScanContractObject', '0', '3');
  assertSourceContract($repeat === $page, 'Identical snapshot replay changed its envelope.');
  $sample = getenv('GORGE_TEST_SEARCH_SCAN_PAGE');
  if ($sample) { file_put_contents($sample, json_encode(array('catalog' => $catalog, 'page' => $page))); }
  $admin->query("UPDATE contract_scan_source SET title='no-engine' WHERE id=3");
  GorgeSourceScanContractObject::$onBuild = function($object) use ($admin) {
    if ((int)$object->getID() === 1) { $admin->query('DELETE FROM contract_scan_source WHERE id=2'); }
  };
  $page = $provider->scan('GorgeSourceScanContractObject', '0', '3');
  assertSourceContract(array_column($page['items'], 'status') === array('materialized', 'missing', 'no-engine'),
    'Source disappearance or engine exclusion was misclassified.');
  foreach ($page['items'] as $item) {
    assertSourceContract(!isset($item['event']) || $item['event']['operation'] === 'upsert', 'Missing source became a tombstone.');
  }
  GorgeSourceScanContractObject::$onBuild = null;
  foreach (array('', '-1', '01', '1e2', '9223372036854775808', 1) as $bad) {
    $failed = false;
    try { PhabricatorSearchSourceProvider::parseID($bad); }
    catch (Throwable $ex) { $failed = true; }
    assertSourceContract($failed, 'Noncanonical cursor was accepted.');
  }
  $failed = false;
  try { $provider->scan('ManiphestTask', '0', '3'); }
  catch (Throwable $ex) { $failed = true; }
  assertSourceContract($failed, 'An unregistered requested source class was constructed.');
  $env->overrideEnvConfig('gorge.conduit.token', 'scan-contract-token');
  $env->overrideEnvConfig('gorge.search.source-scan', true);
  $env->overrideEnvConfig('gorge.search.projection-shadow', true);
  PhabricatorSearchSourceConduitAPIMethod::assertEnabledToken('scan-contract-token');
  foreach (array('', 'wrong') as $bad) {
    $failed = false;
    try { PhabricatorSearchSourceConduitAPIMethod::assertEnabledToken($bad); }
    catch (Throwable $ex) { $failed = true; }
    assertSourceContract($failed, 'Source scanning bypassed token authentication.');
  }
  $env->overrideEnvConfig('gorge.search.source-scan', false);
  $failed = false;
  try { PhabricatorSearchSourceConduitAPIMethod::assertEnabledToken('scan-contract-token'); }
  catch (Throwable $ex) { $failed = true; }
  assertSourceContract($failed, 'Source scanning bypassed its opt-in guard.');
  // A full 32-row page needs a second empty page before declaring completion.
  $admin->query('DELETE FROM contract_scan_source');
  for ($id = 1; $id <= 32; $id++) {
    $admin->query("INSERT INTO contract_scan_source VALUES (".$id.", 'PHID-TASK-page".$id."', 'page')");
  }
  $page = $provider->scan('GorgeSourceScanContractObject', '0', '32');
  assertSourceContract(!$page['complete'] && count($page['items']) === 32 && $page['nextID'] === '32', 'Full page prematurely completed.');
  $page = $provider->scan('GorgeSourceScanContractObject', '32', '32');
  assertSourceContract($page['complete'] && !$page['items'], 'Empty terminal page did not complete.');
  // The byte budget returns a resumable prefix before publishing the next row.
  $admin->query('DELETE FROM contract_scan_source');
  $large_title = str_repeat('x', 800000);
  $insert = $admin->prepare('INSERT INTO contract_scan_source VALUES (?, ?, ?)');
  for ($id = 1; $id <= 2; $id++) {
    $phid = 'PHID-TASK-large'.$id;
    $insert->bind_param('iss', $id, $phid, $large_title);
    $insert->execute();
  }
  $page = $provider->scan('GorgeSourceScanContractObject', '0', '2');
  assertSourceContract(!$page['complete'] && count($page['items']) === 1 && $page['nextID'] === '1', 'Byte budget skipped an unpublished row.');
  $page = $provider->scan('GorgeSourceScanContractObject', '1', '2');
  assertSourceContract($page['complete'] && count($page['items']) === 1, 'Byte prefix could not resume.');
  $admin->query('DROP TABLE contract_scan_source');
  $failed = false;
  try { $provider->scan('GorgeSourceScanContractObject', '0', '3'); }
  catch (Throwable $ex) { $failed = true; }
  assertSourceContract($failed, 'Source table errors were treated as an empty complete range.');
  echo "Search source range, materialization, replay, missing and authentication contracts passed.\n";
} finally {
  $admin->query('DROP DATABASE `'.$name.'`');
  $admin->query('DROP DATABASE `'.$system_name.'`');
}
