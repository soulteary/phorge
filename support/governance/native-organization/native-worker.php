<?php

// A new process owns every SQL connection it creates. Never include this file.
require_once dirname(__DIR__,2).'/runtime/php-version.php';
$root=dirname(__DIR__,3); $path=$argv[1]??null;
$guard=null; $proof=null; $result=null; $scope_owned=false;
try {
  phorge_assert_supported_php_version(PHP_VERSION);
  if (PHP_SAPI!=='cli' || count($argv)!==2 || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1') {
    throw new RuntimeException('native_worker_scope_refused');
  }
  foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $selector) {
    $value=getenv($selector); if ($value!==false && $value!=='') { throw new RuntimeException('native_worker_deployment_refused'); }
  }
  foreach (array('local.json','deployment.json','ENVIRONMENT') as $file) {
    if (file_exists($root.'/conf/local/'.$file)) { throw new RuntimeException('native_worker_deployment_refused'); }
  }
  if (!is_string($path) || is_link($path) || realpath(dirname($path))!==realpath(sys_get_temp_dir()) ||
      !preg_match('/\Agovernance-native-worker-[a-f0-9]{24}\z/',basename($path))) {
    throw new RuntimeException('native_worker_directory_refused');
  }
  $stat=lstat($path);
  if (!$stat || ($stat['mode']&0777)!==0700) { throw new RuntimeException('native_worker_directory_refused'); }
  foreach (array('owner','payload.json') as $name) {
    $file=$path.'/'.$name;
    if (!is_file($file) || is_link($file) || (fileperms($file)&0777)!==0600 || filesize($file)>65536) {
      throw new RuntimeException('native_worker_payload_refused');
    }
  }
  $payload=json_decode(file_get_contents($path.'/payload.json'),true,16,JSON_THROW_ON_ERROR);
  $keys=array_keys($payload); sort($keys);
  if ($keys!==array('action','config','directory_dev','directory_ino','fixture_nonce','owner_token','source_sha256') ||
      $payload['directory_dev']!==$stat['dev'] || $payload['directory_ino']!==$stat['ino'] ||
      !is_string($payload['owner_token']) || !preg_match('/\A[a-f0-9]{64}\z/',$payload['owner_token']) ||
      !hash_equals($payload['owner_token'],file_get_contents($path.'/owner')) ||
      !is_string($payload['fixture_nonce']) || !preg_match('/\A[a-f0-9]{64}\z/',$payload['fixture_nonce']) ||
      !hash_equals(hash_file('sha256',$root.'/resources/sql/quickstart.sql'),$payload['source_sha256'])) {
    throw new RuntimeException('native_worker_owner_refused');
  }
  $scope_owned=true;
  require_once $root.'/support/runtime/bootstrap-cli.php';
  ob_start();
  phutil_load_library($root.'/src/');
  require_once __DIR__.'/workers.php';
  $quiet=PhutilConsole::newLocalConsole(); $quiet->disableOut(); PhutilConsole::setConsole($quiet);
  $config=$payload['config']; $keys=array_keys($config); sort($keys);
  if ($keys!==array('host','namespace','password','port','user') ||
      $config['host']!=='127.0.0.1' || !is_int($config['port']) || $config['port']<1 || $config['port']>65535 ||
      !is_string($config['namespace']) || !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/',$config['namespace']) ||
      !is_string($config['user']) || !preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/',$config['user']) ||
      !is_string($config['password']) || !preg_match('/\A[a-f0-9]{48}\z/',$config['password'])) {
    throw new RuntimeException('native_worker_config_refused');
  }
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  $proof=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
  $proof->set_charset('utf8mb4');
  $seen_guard=false; $seen_user=false;
  foreach ($proof->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM) as $entry) {
    $grant=$entry[0];
    if (str_contains($grant,'WITH GRANT OPTION')) { throw new RuntimeException('native_worker_grants_refused'); }
    if (preg_match('/\AGRANT USAGE ON \*\.\* TO /',$grant)) { continue; }
    if (!preg_match('/\AGRANT (.+) ON `([^`]+)`\.\* TO /',$grant,$match)) {
      throw new RuntimeException('native_worker_grants_refused');
    }
    $db=str_replace('\\_','_',$match[2]);
    if (!preg_match('/\A'.preg_quote($config['namespace'],'/').'_[a-z_]+\z/',$db)) {
      throw new RuntimeException('native_worker_grants_refused');
    }
    $privileges=array_map('trim',explode(',',$match[1])); sort($privileges);
    $wanted=$db===$config['namespace'].'_governance'?array('SELECT'):array('DELETE','INSERT','SELECT','UPDATE');
    if ($privileges!==$wanted) { throw new RuntimeException('native_worker_grants_refused'); }
    if ($db===$config['namespace'].'_governance') { $seen_guard=true; }
    if ($db===$config['namespace'].'_user') { $seen_user=true; }
  }
  if (!$seen_guard || !$seen_user) { throw new RuntimeException('native_worker_grants_refused'); }
  $assert_owned=function() use ($proof,$config,$payload) {
    $row=$proof->query('SELECT marker,quickstart_sha256,owner_nonce FROM `'.$config['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if (!$row || $row['marker']!=='N07.NATIVE-SOURCE.v1:synthetic-only' ||
        !hash_equals($payload['source_sha256'],$row['quickstart_sha256']) ||
        !hash_equals($payload['fixture_nonce'],$row['owner_nonce'])) {
      throw new RuntimeException('native_worker_lineage_refused');
    }
  };
  $assert_owned();
  if (PhabricatorEnv::getConfigSourceStack()!==null ||
      (new ReflectionProperty(LiskDAO::class,'connections'))->getValue()) {
    throw new RuntimeException('native_worker_inherited_context_refused');
  }
  $stack=new PhabricatorConfigStackSource(); $stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host'=>$config['host'],'mysql.port'=>$config['port'],'mysql.user'=>$config['user'],
    'mysql.pass'=>$config['password'],'storage.default-namespace'=>$config['namespace'],
    'phabricator.cache-namespace'=>$config['namespace'],'cluster.databases'=>array(),'cluster.search'=>array(),
    'phabricator.base-uri'=>'http://fixture.invalid/','phabricator.silent'=>false,'policy.allow-public'=>false,
    'governance.organization.enabled'=>true,'governance.media-exchange.enabled'=>true,
    'projects.default-navigation'=>'flat','project.history-state'=>'off','project.workspace-mode'=>'native',
    'project.history-task-drafts'=>false,'gorge.search.source-scan'=>false,'gorge.search.projection-shadow'=>false,
    'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'gorge.notification.outbox'=>false,
    'notification.servers'=>array(),'gorge.db.uri'=>null,'gorge.file.uri'=>null,'gorge.image.uri'=>null,'gorge.render.uri'=>null)));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  if (AphrontWriteGuard::isGuardActive() ||
      (new ReflectionProperty(AphrontWriteGuard::class,'allowUnguardedWrites'))->getValue()) {
    throw new RuntimeException('native_worker_write_guard_refused');
  }
  $guard=new AphrontWriteGuard(function() use ($assert_owned,$config) {
    $assert_owned();
    foreach (array('mysql.host'=>'host','mysql.port'=>'port','mysql.user'=>'user','mysql.pass'=>'password',
      'storage.default-namespace'=>'namespace') as $key=>$field) {
      if (PhabricatorEnv::getEnvConfig($key)!==$config[$field]) { throw new RuntimeException('native_worker_route_refused'); }
    }
  });
  $action=$payload['action']; $kind=$action['action']??null;
  $conn=(new PhabricatorUser())->establishConnection('w');
  $thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
  if ($kind==='org.list' || $kind==='org.count') {
    $keys=array_keys($action); sort($keys);
    if ($keys!==array('action','token') || !is_string($action['token']) || strlen($action['token'])>256) {
      throw new RuntimeException('native_worker_action_refused');
    }
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if (!$viewer) { throw new RuntimeException('governance_web_credential_required'); }
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/'))->setUser($viewer);
    $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    $service=PhorgeOrganizationService::newForActor($context);
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    $value=$kind==='org.list'?$service->readUnits():$service->countUnits();
    $result=array('status'=>'committed','value'=>$value);
  } else if ($kind==='media.redeem') {
    $keys=array_keys($action); sort($keys);
    if ($keys!==array('action','audience','browser_secret','code','path','purpose','transaction')) {
      throw new RuntimeException('native_worker_action_refused');
    }
    $media=new PhorgeGovernanceMediaExchangeService();
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    $redeemed=$media->redeem($action['transaction'],$action['code'],$action['browser_secret'],
      $action['audience'],$action['purpose'],$action['path']);
    $snapshot=$media->authenticate($redeemed['credential']->openEnvelope());
    $result=array('status'=>'committed','credential_authenticated'=>$snapshot->getCredentialType()==='web');
  } else { throw new RuntimeException('native_worker_action_refused'); }
} catch (Throwable $error) {
  $expected=get_class($error)===RuntimeException::class &&
    preg_match('/\A(?:organization|governance|media)_[a-z_]+\z/',$error->getMessage());
  $result=$expected?array('status'=>'rejected','reason'=>$error->getMessage()):
    array('status'=>'failed','error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
} finally {
  try {
    if (class_exists('LiskDAO',false)) {
      foreach ((new ReflectionProperty(LiskDAO::class,'connections'))->getValue() as $connection) {
        $budget=32;
        while ($connection->isInsideTransaction() && $budget-- >0) { $connection->killTransaction(); }
      }
    }
    if ($guard!==null) { $guard->dispose(); }
    if (class_exists('LiskDAO',false)) { LiskDAO::closeAllConnections(); }
    if ($proof!==null) { $proof->close(); }
  } catch (Throwable $cleanup) { $result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_worker_cleanup_failed'); }
  if ($scope_owned && function_exists('governance_native_worker_atomic')) {
    governance_native_worker_atomic($path,'result.json',$result);
  }
  if (ob_get_level()>0) { ob_end_clean(); }
}
exit(($result['status']??null)==='failed'?1:0);
