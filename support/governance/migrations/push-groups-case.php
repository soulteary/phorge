<?php

/** Actual fresh native storage workflow; never a deployment migration tool. */
$root=dirname(__DIR__,3);
require_once $root.'/support/runtime/php-version.php';
$profile=array_slice($argv,1)===array('--push-groups-regression')?'push-groups-regression':null;
$control=null;$api=null;$guard=null;$created=array();$principal=null;$principal_created=false;
$cleanup=array('schemas_removed'=>0,'schemas_already_removed_by_native'=>0,'principals_removed'=>0);
$errors=array();$failure=null;$passed=false;$capture=false;$output='';$summary=array();
$quote=function($value){return '`'.str_replace('`','``',$value).'`';};
$cleanup_ran=false;
$cleanup_owned=function() use (&$cleanup_ran,&$control,&$api,&$guard,&$created,
  &$principal,&$principal_created,&$nonce,&$cleanup,&$errors,$quote) {
  if($cleanup_ran){return;}$cleanup_ran=true;
  try{if(class_exists('LiskDAO',false)){LiskDAO::closeAllConnections();}}catch(Throwable $ex){$errors[]='native_connection_cleanup';}
  $api=null;$guard=null;
  if($control!==null){
    try{
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      if($created){
        $row=$control->query('SELECT nonce FROM '.$quote($created[0]).'.marker WHERE id=1')->fetch_assoc();
        if(!$row || !hash_equals($nonce,$row['nonce'])){throw new LogicException('Bootstrap ownership lost.');}
        foreach(array_reverse($created) as $database){
          if(!preg_match('/\Agovernance_boot_[a-f0-9]{16}_[a-z_]+\z/D',$database)){
            throw new LogicException('Unexpected cleanup database.');
          }
          $escaped_name=$control->real_escape_string($database);
          $exists=$control->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY '".$escaped_name."'")->fetch_assoc();
          if($exists){$control->query('DROP DATABASE '.$quote($database));$cleanup['schemas_removed']++;}
          else{$cleanup['schemas_already_removed_by_native']++;}
        }
      }
    }catch(Throwable $ex){$errors[]='owned_schema_cleanup';}
    try{if($principal_created){$control->query("DROP USER '".$principal."'@'127.0.0.1'");$cleanup['principals_removed']++;}}
    catch(Throwable $ex){$errors[]='owned_principal_cleanup';}
    $control->close();
  }
};
register_shutdown_function($cleanup_owned);
try {
  phorge_assert_supported_php_version(PHP_VERSION);
  if ($profile===null || PHP_SAPI!=='cli' ||
      getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1') {
    throw new LogicException('Explicit owned bootstrap CLI profile required.');
  }
  foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $key) {
    $value=getenv($key);
    if ($value!==false && $value!=='') {throw new LogicException('Deployment selector refused.');}
  }
  foreach (array('local.json','deployment.json','ENVIRONMENT') as $name) {
    if (file_exists($root.'/conf/local/'.$name)) {throw new LogicException('Deployment configuration refused.');}
  }
  require_once $root.'/support/runtime/bootstrap-cli.php';
  // Preserve actual echo/fprintf chronology in the supervisor's single pipe.
  // Chunk size 1 forwards echo immediately instead of hiding native workflow
  // messages inside a child-only buffer. Retained bytes are hash input only.
  ob_start(function($chunk) use (&$output) {
    $output.=$chunk;
    return $chunk;
  },1);$capture=true;
  phutil_load_library($root.'/src/');
  $dsn=getenv('PHORGE_GOVERNANCE_FIXTURE_DSN');
  PhorgeGovernanceIsolatedDirectoryStore::validateFixtureDSN($dsn);
  $parts=array();foreach(explode(';',substr($dsn,6)) as $item) {
    if($item!==''){list($key,$value)=explode('=',$item,2);$parts[$key]=$value;}
  }
  if(($parts['host']??null)!=='127.0.0.1'){throw new LogicException('Disposable loopback required.');}
  $port=(int)($parts['port']??3306);
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  $control=new mysqli('127.0.0.1',getenv('PHORGE_GOVERNANCE_FIXTURE_USER'),
    getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD'),'',$port);
  $control->set_charset('utf8mb4');
  $namespace='governance_boot_'.bin2hex(random_bytes(8));
  $nonce=bin2hex(random_bytes(16));$password=bin2hex(random_bytes(24));
  $apps=array('meta_data'=>true,'repository'=>true);
  // Precreated empty databases establish exact cleanup ownership, not schema restore.
  $owner=$namespace.'_ownership';
  $control->query('CREATE DATABASE '.$quote($owner));$created[]=$owner;
  $control->query('CREATE TABLE '.$quote($owner).'.marker (id INT PRIMARY KEY,nonce VARBINARY(32) NOT NULL) ENGINE=InnoDB');
  $control->query("INSERT INTO ".$quote($owner).".marker VALUES (1,'".$nonce."')");
  foreach(array_keys($apps) as $app){
    $database=$namespace.'_'.$app;
    $control->query('CREATE DATABASE '.$quote($database));$created[]=$database;
  }
  $principal='gb_'.bin2hex(random_bytes(8));
  $control->query("CREATE USER '".$principal."'@'127.0.0.1' IDENTIFIED BY '".$password."'");
  $principal_created=true;
  foreach(array_slice($created,1) as $database){
    $escaped=str_replace(array('\\','_','%'),array('\\\\','\\_','\\%'),$database);
    $control->query('GRANT ALL PRIVILEGES ON '.$quote($escaped).".* TO '".$principal."'@'127.0.0.1'");
  }
  if(PhabricatorEnv::getConfigSourceStack()!==null ||
    (new ReflectionProperty(LiskDAO::class,'connections'))->getValue()!==array()){
    throw new LogicException('Inherited native environment refused.');
  }
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host'=>'127.0.0.1','mysql.port'=>$port,'mysql.user'=>$principal,'mysql.pass'=>$password,
    'storage.default-namespace'=>$namespace,'cluster.databases'=>array(),'cluster.search'=>array(),
    'gorge.db.uri'=>null,'gorge.file.uri'=>null,'gorge.image.uri'=>null,'gorge.render.uri'=>null,
    'gorge.taskqueue.uri'=>null,'gorge.taskqueue.owner'=>'phorge',
    'governance.organization.enabled'=>false,'governance.media-exchange.enabled'=>false,
'notification.servers'=>array())));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  $guard=new AphrontWriteGuard(function() use($namespace,$principal){
    if(PhabricatorEnv::getEnvConfig('storage.default-namespace')!==$namespace ||
       PhabricatorEnv::getEnvConfig('mysql.user')!==$principal){throw new LogicException('Bootstrap route drift.');}
  });
  $refs=PhabricatorDatabaseRef::getActiveDatabaseRefs();
  if(count($refs)!==1){throw new LogicException('Single owned bootstrap primary required.');}
  $api=(new PhabricatorStorageManagementAPI())->setHost('127.0.0.1')->setPort($port)
    ->setUser($principal)->setPassword(new PhutilOpaqueEnvelope($password))->setNamespace($namespace)
    ->setRef(head($refs));
  if($api->getAppliedPatches()!==null){throw new LogicException('Fresh storage required.');}
  require_once __DIR__.'/push-groups-regression.php';
  $summary=governance_bootstrap_push_groups_regression($api,$root);
  $passed=true;
} catch(Throwable $ex){
  $failure=array('class'=>get_class($ex),'code'=>(int)$ex->getCode(),
    'file'=>basename($ex->getFile()),'line'=>$ex->getLine());

}
finally{
  if($capture){ob_end_flush();}
  $cleanup_owned();
}
$summary['console_order_verified_by_child']=false;
echo "\nGOVERNANCE_BOOTSTRAP_RECEIPT:".json_encode(array('batch'=>'actual-owned-native-fresh-storage-bootstrap','profile'=>$profile,
  'passed'=>$passed && !$errors,'runtime'=>PHP_VERSION,'native'=>$summary,'failure'=>$failure,
  'cleanup'=>$cleanup,'cleanup_failures'=>$errors,'native_output_bytes'=>strlen($output),
  'native_output_sha256'=>hash('sha256',$output),'production_deployment'=>false,
  'optional_features_enabled'=>false,'native_permission_axes_changed'=>false))."\n";
exit($passed && !$errors?0:1);
