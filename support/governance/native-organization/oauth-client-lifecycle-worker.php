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
  // This standalone child has one reachable fixed action. Copied legacy
  // phase/measurement branches below cannot be entered, even before proof IO.
  $candidate=$payload['action']??null;$action_keys=is_array($candidate)?array_keys($candidate):array();sort($action_keys);
  if($action_keys!==array('action','client','operation','subject','token')||$candidate['action']!=='recovery.oauth-client-lifecycle.borrowed-owner.save'||
    !in_array($candidate['operation'],array('disabled','redirect','trust','secret','authorization-delete','client-cascade'),true)||!is_string($candidate['client'])||!preg_match('/\APHID-OASC-[a-zA-Z0-9]{20}\z/D',$candidate['client'])||!is_string($candidate['subject'])||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$candidate['subject'])||
    !is_string($candidate['token'])||$candidate['token']===''||strlen($candidate['token'])>256){throw new RuntimeException('native_worker_action_refused');}
  $keys=array_keys($payload); sort($keys);
  $wanted_keys=array('action','config','directory_dev','directory_ino','fixture_nonce','media_issuer','owner_token','source_sha256');
  if(in_array(($payload['action']['action']??null),array('measurement.api-token-auth','recovery.oauth.authenticate'),true)){$wanted_keys[]='measurement_file';sort($wanted_keys);}
  if(in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue','recovery.oauth-client-lifecycle.borrowed-owner.save','recovery.org.member.insert','recovery.org.approval.propose'),true)){$wanted_keys[]='recovery_incarnation';sort($wanted_keys);
    if(!is_string($payload['recovery_incarnation']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$payload['recovery_incarnation'])){throw new RuntimeException('native_worker_recovery_incarnation_refused');}}
  if ($keys!==$wanted_keys ||
      $payload['directory_dev']!==$stat['dev'] || $payload['directory_ino']!==$stat['ino'] ||
      !is_string($payload['owner_token']) || !preg_match('/\A[a-f0-9]{64}\z/',$payload['owner_token']) ||
      !hash_equals($payload['owner_token'],file_get_contents($path.'/owner')) ||
      !is_string($payload['fixture_nonce']) || !preg_match('/\A[a-f0-9]{64}\z/',$payload['fixture_nonce']) ||
      !hash_equals(hash_file('sha256',$root.'/resources/sql/quickstart.sql'),$payload['source_sha256'])) {
    throw new RuntimeException('native_worker_owner_refused');
  }
  if (!is_array($payload['media_issuer']) || array_keys($payload['media_issuer'])!==array('key','incarnation') ||
      !is_string($payload['media_issuer']['key']) || !preg_match('/\A[a-f0-9]{64}\z/',$payload['media_issuer']['key']) ||
      !is_string($payload['media_issuer']['incarnation']) || !preg_match('/\A[a-f0-9]{32}\z/',$payload['media_issuer']['incarnation'])) {
    throw new RuntimeException('native_worker_issuer_refused');
  }
  $scope_owned=true;
  require_once $root.'/support/runtime/bootstrap-cli.php';
  ob_start();
  phutil_load_library($root.'/src/');
  require_once __DIR__.'/oauth-client-lifecycle-workers.php';
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
  $measurement_file=governance_oauth_client_lifecycle_native_measurement_file_source($payload['action']['action']??null,$payload['measurement_file']??null,
    $config['namespace'],$payload['fixture_nonce'],$payload['source_sha256']);

  $stack=new PhabricatorConfigStackSource(); $stack->pushSource(new PhabricatorConfigDefaultSource());

  $settings=array(
    'mysql.host'=>$config['host'],'mysql.port'=>$config['port'],'mysql.user'=>$config['user'],
    'mysql.pass'=>$config['password'],'storage.default-namespace'=>$config['namespace'],
    'phabricator.cache-namespace'=>$config['namespace'],'cluster.databases'=>array(),'cluster.search'=>array(),
    'phabricator.base-uri'=>'http://fixture.invalid/','phabricator.silent'=>false,'policy.allow-public'=>false,
    'governance.recovery-stop-ledger.enabled'=>in_array(($payload['action']['action']??null),array('recovery.stop.record','recovery.stop.history'),true),
    'governance.notifications.enabled'=>($payload['action']['action']??null)==='org.notice.create',
    'governance.private-mail.enabled'=>in_array(($payload['action']['action']??null),array('org.notice.create','legalpad.verify','legalpad.observe'),true),'governance.organization.enabled'=>false,'governance.media-exchange.enabled'=>false,
    'governance.account-recovery.enabled'=>in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue','recovery.oauth-client-lifecycle.borrowed-owner.save','recovery.org.member.insert','recovery.org.approval.propose'),true),
    'governance.account-recovery.incarnation'=>in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue','recovery.oauth-client-lifecycle.borrowed-owner.save','recovery.org.member.insert','recovery.org.approval.propose'),true)?$payload['recovery_incarnation']:null,
    'governance.media-exchange.issuer-key'=>$payload['media_issuer']['key'],
    'governance.media-exchange.incarnation'=>$payload['media_issuer']['incarnation'],
    'projects.default-navigation'=>'flat','project.history-state'=>'off','project.workspace-mode'=>'native',
    'project.history-task-drafts'=>false,'gorge.search.source-scan'=>false,'gorge.search.projection-shadow'=>false,
    'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'gorge.notification.outbox'=>false,
    'notification.servers'=>array(),'gorge.db.uri'=>null,'gorge.file.uri'=>null,'gorge.image.uri'=>null,'gorge.render.uri'=>null);
  if(($payload['action']['action']??null)==='org.notice.create'){$settings['metamta.gorge-delivery-mode']='native';}
  if($measurement_file!==null){
    $settings['gorge.file.uri']=$measurement_file['uri'];$settings['gorge.file.token']=$measurement_file['token'];
    $settings['gorge.service-policies']=array('file'=>'required');
  }
  $stack->pushSource(new PhabricatorConfigDictionarySource($settings));
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
  if($kind!=='recovery.oauth-client-lifecycle.borrowed-owner.save'){throw new RuntimeException('native_worker_action_refused');}

  $conn=(new PhabricatorUser())->establishConnection('w');
  $thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];

  require_once dirname(__DIR__).'/recovery/oauth-client-lifecycle-producer.php';
  $result=governance_recovery_oauth_client_lifecycle_native_producer($conn,$action,$path,$payload,$thread);
} catch (Throwable $error) {
  $expected=get_class($error)===RuntimeException::class &&
    preg_match('/\A(?:organization|governance|media)_[a-z_]+\z/',$error->getMessage());
  $result=$expected?array('status'=>'rejected','reason'=>$error->getMessage()):
    array('status'=>'failed','error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
} finally {
  // A failed/unknown native operation never authorizes rollback/close.
  if(($result['status']??null)==='committed'&&($result['native_terminal_known']??null)===true){
    try{
      $stateReader=new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState');
      foreach((new ReflectionProperty(LiskDAO::class,'connections'))->getValue()as$connection){
        if($connection->isInsideTransaction()||$stateReader->invoke($connection)->getDepth()!==0){throw new RuntimeException('oauth_client_lifecycle_cleanup_terminal_unknown');}}
      if($guard!==null){$guard->dispose();}LiskDAO::closeAllConnections();
      if($proof!==null){$proof->close();}
    }catch(Throwable$cleanup){$result=array('status'=>'failed','error_type'=>get_class($cleanup),'source_file'=>basename($cleanup->getFile()),'source_line'=>$cleanup->getLine());}
  }
  if ($scope_owned && function_exists('governance_oauth_client_lifecycle_native_worker_atomic')) {
    governance_oauth_client_lifecycle_native_worker_atomic($path,'result.json',$result);
  }
  if (ob_get_level()>0) { ob_end_clean(); }
}
exit(($result['status']??null)==='failed'?1:0);
