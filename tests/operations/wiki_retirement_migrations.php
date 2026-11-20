<?php
// Synthetic historical rows only. No deployment configuration is accepted.
$root = dirname(__DIR__, 2);
require $root.'/support/runtime/bootstrap-cli.php';
phutil_load_library($root.'/src/');
if (getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
  throw new RuntimeException('isolated scope required');
}
foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $key) {
  if (getenv($key)) { throw new RuntimeException('deployment selector refused'); }
}
$admin = new mysqli('127.0.0.1', getenv('PHORGE_GOVERNANCE_FIXTURE_USER'),
  getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD'), '', 3306);
$namespace = 'wiki_retirement_'.bin2hex(random_bytes(8));
$created = array();
$guard = null;
try {
  foreach (array('phriction','metamta') as $suffix) {
    $name=$namespace.'_'.$suffix;
    $admin->query('CREATE DATABASE `'.$name.'`');
    $created[]=$name;
  }
  $admin->select_db($namespace.'_phriction');
  $admin->query('CREATE TABLE phriction_document (id INT PRIMARY KEY, phid VARCHAR(64), slug VARCHAR(128), viewPolicy VARCHAR(64), editPolicy VARCHAR(64), mailKey VARCHAR(32), contentID INT, contentPHID VARCHAR(64), editedEpoch INT, maxVersion INT)');
  $admin->query('CREATE TABLE phriction_content (id INT PRIMARY KEY, phid VARCHAR(64), documentID INT, documentPHID VARCHAR(64), version INT, dateCreated INT, UNIQUE KEY documentID(documentID))');
  $admin->query("INSERT INTO phriction_document VALUES (1,'PHID-WIKI-aaaaaaaaaaaaaaaaaaaa','docs/','','','',1,'',0,0),(2,'PHID-WIKI-bbbbbbbbbbbbbbbbbbbb','notes/','admin','admin','bbbbbbbbbbbbbbbbbbbb',2,'',0,0)");
  $admin->query("INSERT INTO phriction_content VALUES (1,'',1,'',1,100),(2,'PHID-WRDS-bbbbbbbbbbbbbbbbbbbb',2,'',4,200)");
  $admin->select_db($namespace.'_metamta');
  $admin->query('CREATE TABLE metamta_mailproperties (id INT AUTO_INCREMENT PRIMARY KEY, objectPHID VARCHAR(64) UNIQUE, mailProperties LONGTEXT, dateCreated INT, dateModified INT)');
  $stack=new PhabricatorConfigStackSource();
  $stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host'=>'127.0.0.1','mysql.port'=>3306,
    'mysql.user'=>getenv('PHORGE_GOVERNANCE_FIXTURE_USER'),
    'mysql.pass'=>getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD'),
    'storage.default-namespace'=>$namespace)));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  $guard=new AphrontWriteGuard(static function() use ($namespace) {
    if (PhabricatorEnv::getEnvConfig('storage.default-namespace')!==$namespace) {
      throw new RuntimeException('synthetic namespace changed');
    }
  });
  $api=(new PhabricatorStorageManagementAPI())->setHost('127.0.0.1')->setPort(3306)
    ->setUser(getenv('PHORGE_GOVERNANCE_FIXTURE_USER'))
    ->setPassword(getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD'))->setNamespace($namespace);
  ob_start();
  foreach (array('20141107.phriction.policy.2.php','20141107.phriction.popkeys.php',
    '20180215.phriction.02.phidvalues.php','20180828.phriction.04.migrate.php',
    '20180828.phriction.06.c.documentid.php','20180829.phriction.01.mailkey.php',
    '20180830.phriction.02.maxes.php') as $patch) {
    $api->applyPatchPHP($root.'/resources/sql/autopatches/'.$patch);
  }
  ob_end_clean();
  $admin->select_db($namespace.'_phriction');
  $docs=$admin->query('SELECT * FROM phriction_document ORDER BY id')->fetch_all(MYSQLI_ASSOC);
  $contents=$admin->query('SELECT * FROM phriction_content ORDER BY id')->fetch_all(MYSQLI_ASSOC);
  $ok=count($docs)===2 && count($contents)===2 && $docs[0]['viewPolicy']==='users' &&
    $docs[1]['viewPolicy']==='admin' && strlen($docs[0]['mailKey'])===20 &&
    $docs[1]['mailKey']==='bbbbbbbbbbbbbbbbbbbb' &&
    str_starts_with($contents[0]['phid'],'PHID-WRDS-') &&
    $docs[0]['contentPHID']===$contents[0]['phid'] &&
    $contents[1]['documentPHID']===$docs[1]['phid'] &&
    (int)$docs[0]['maxVersion']===1 && (int)$docs[1]['maxVersion']===4;
  if (!$ok) { throw new RuntimeException('historical data preservation failed'); }
  $admin->select_db($namespace.'_metamta');
  if ((int)$admin->query('SELECT COUNT(*) n FROM metamta_mailproperties')->fetch_assoc()['n']!==2) {
    throw new RuntimeException('mail property preservation failed');
  }
  echo json_encode(array('passed'=>true,'patches'=>7,'documents_preserved'=>2,
    'content_rows_preserved'=>2,'mail_properties_preserved'=>2))."\n";
} finally {
  LiskDAO::closeAllConnections();
  if ($guard) { $guard->dispose(); }
  foreach (array_reverse($created) as $name) { $admin->query('DROP DATABASE `'.$name.'`'); }
  $admin->close();
}
