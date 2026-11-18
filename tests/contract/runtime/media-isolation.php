<?php

// Real transactional probe in a fresh, test-owned schema only. No app configuration.
require_once dirname(__DIR__).'/bootstrap.php';
if (PHP_SAPI!=='cli' || getenv('PHORGE_MEDIA_ISOLATION_TEST')!=='1') {
  throw new RuntimeException('Explicit isolated media test required.');
}
$expected=getenv('PHORGE_MEDIA_DATABASE_FAMILY');
if (!in_array($expected,array('mysql','mariadb'),true)) {
  throw new RuntimeException('Explicit database family required.');
}
$config=array('host'=>getenv('PHORGE_MEDIA_MYSQL_HOST'),'port'=>3306,'user'=>'root',
  'pass'=>new PhutilOpaqueEnvelope(getenv('PHORGE_MEDIA_MYSQL_PASSWORD')),'retries'=>1,'timeout'=>5);
$schema='media_isolation_'.bin2hex(random_bytes(12));$created=false;$first=null;$second=null;
$admin=new AphrontMySQLiDatabaseConnection($config);$checks=array();
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException($name);} $checks[]=$name;};
$level=new ReflectionMethod(PhorgeGovernanceMediaExchangeService::class,'readSessionIsolationLevel');
$begin=new ReflectionMethod(PhorgeGovernanceMediaExchangeService::class,'begin');
AphrontWriteGuard::allowDangerousUnguardedWrites(true);
try {
  $version=queryfx_one($admin,'SELECT VERSION() AS version')['version'];
  $maria=stripos($version,'MariaDB')!==false;
  $check($maria===($expected==='mariadb'),'expected-database-family');
  $check(!queryfx_one($admin,'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=%s',$schema),'fresh-schema');
  queryfx($admin,'CREATE DATABASE %T',$schema);$created=true;
  $config['database']=$schema;$first=new AphrontMySQLiDatabaseConnection($config);$second=new AphrontMySQLiDatabaseConnection($config);
  foreach(array('governance_mediabrowser'=>'browserDigest','governance_mediaexchange'=>'transactionDigest',
    'governance_mediacredential'=>'secretDigest','governance_mediaquota'=>'bucketDigest')as$table=>$key){
    queryfx($first,'CREATE TABLE %T (%C BINARY(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB',$table,$key);
  }
  queryfx($first,'CREATE TABLE isolation_probe (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
  queryfx($first,'INSERT INTO isolation_probe VALUES (1,1)');
  queryfx($first,'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $check($level->invoke(null,$first)==='REPEATABLE-READ','default-repeatable-read');
  $modern=queryfx_one($first,"SHOW SESSION VARIABLES WHERE Variable_name = 'transaction_isolation'");
  $legacy=queryfx_one($first,"SHOW SESSION VARIABLES WHERE Variable_name = 'tx_isolation'");
  $check($maria?$legacy!==null:$modern!==null,'actual-supported-variable');
  $begin->invoke(null,$first);
  $check($first->isInsideTransaction(),'media-transaction-open');
  $check((int)queryfx_one($first,'SELECT value FROM isolation_probe WHERE id=1')['value']===1,'media-initial-value');
  queryfx($second,'UPDATE isolation_probe SET value=2 WHERE id=1');
  $check((int)queryfx_one($first,'SELECT value FROM isolation_probe WHERE id=1')['value']===2,'media-inside-read-committed');
  $first->killTransaction();
  $check($level->invoke(null,$first)==='REPEATABLE-READ','default-unchanged-after-media');
  $first->openTransaction();
  $check((int)queryfx_one($first,'SELECT value FROM isolation_probe WHERE id=1')['value']===2,'next-native-initial');
  queryfx($second,'UPDATE isolation_probe SET value=3 WHERE id=1');
  $check((int)queryfx_one($first,'SELECT value FROM isolation_probe WHERE id=1')['value']===2,'next-native-repeatable-read');
  $first->killTransaction();
  foreach(array('engine','missing')as$failure){
    if($failure==='engine'){queryfx($first,'ALTER TABLE governance_mediaquota ENGINE=MyISAM');}
    else {queryfx($first,'DROP TABLE governance_mediaquota');}
    $refused=false;
    try{$begin->invoke(null,$first);}catch(RuntimeException $error){$refused=$error->getMessage()==='media_exchange_transactional_schema_required';}
    $check($refused,'reject-'.$failure);$check(!$first->isInsideTransaction(),'no-open-transaction-after-'.$failure);
    $check($level->invoke(null,$first)==='REPEATABLE-READ','default-restored-after-'.$failure);
    $value=(int)queryfx_one($first,'SELECT value FROM isolation_probe WHERE id=1')['value'];
    $first->openTransaction();
    queryfx_one($first,'SELECT value FROM isolation_probe WHERE id=1');
    queryfx($second,'UPDATE isolation_probe SET value=%d WHERE id=1',$value+1);
    $check((int)queryfx_one($first,'SELECT value FROM isolation_probe WHERE id=1')['value']===$value,'next-native-after-'.$failure);
    $first->killTransaction();
    if($failure==='engine'){queryfx($first,'ALTER TABLE governance_mediaquota ENGINE=InnoDB');}
  }
  $first->simulateErrorOnNextQuery(1193);$refused=false;
  try{$level->invoke(null,$first);}catch(AphrontQueryException $error){$refused=$error->getCode()===1193;}
  $check($refused,'query-error-is-not-variable-absence');
  $first->simulateErrorOnNextQuery(1227);$refused=false;
  try{$level->invoke(null,$first);}catch(AphrontAccessDeniedQueryException $error){$refused=true;}
  $check($refused,'permission-error-propagates');
} finally {
  if($first){if($first->isInsideTransaction()){$first->killTransaction();}$first->close();}
  if($second){if($second->isInsideTransaction()){$second->killTransaction();}$second->close();}
  if($created){
    queryfx($admin,'DROP DATABASE %T',$schema);
    $check(!queryfx_one($admin,'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=%s',$schema),'owned-schema-removed');
  }
  $admin->close();
}
echo json_encode(array('family'=>$expected,'database_version'=>$version,'passed'=>true,
  'checks'=>$checks,'schema_is_ephemeral'=>true,'application_flags_changed'=>false))."\n";
