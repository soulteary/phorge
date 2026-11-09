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
  $wanted_keys=array('action','config','directory_dev','directory_ino','fixture_nonce','media_issuer','owner_token','source_sha256');
  if(in_array(($payload['action']['action']??null),array('measurement.api-token-auth','recovery.oauth.authenticate'),true)){$wanted_keys[]='measurement_file';sort($wanted_keys);}
  if(in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue','recovery.oauth-code.borrowed-owner.issue','recovery.org.member.insert','recovery.org.approval.propose'),true)){$wanted_keys[]='recovery_incarnation';sort($wanted_keys);
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
  $api_phase_state=array('owner'=>$payload['owner_token'],'phase'=>'payload-verified',
    'proof_thread'=>null,'writer_thread'=>null,'reader_thread'=>null,'credential_thread'=>null);
  $api_phase=function($phase,array$threads=array())use($path,$payload,&$api_phase_state){
    if(!in_array($payload['action']['action']??null,array('recovery.api.current','recovery.oauth.authenticate'),true)){return;}
    $phases=array('payload-verified','bootstrap-loaded','library-loaded','proof-connected','config-default-start',
      'config-default-ready','config-installed','writer-connect-start','writer-connected','user-reader-connect-start',
      'user-reader-connected','user-load-start','user-loaded','context-ready','ready-published','before-current-read',
      'current-read-returned','oauth-reader-connect-start','oauth-reader-connected','before-native-oauth-auth','native-oauth-auth-returned');
    if(!in_array($phase,$phases,true)){throw new RuntimeException('recovery_api_phase_refused');}
    foreach($threads as$key=>$value){if(!in_array($key,array('proof_thread','writer_thread','reader_thread','credential_thread'),true)||
      !is_int($value)||$value<1){throw new RuntimeException('recovery_api_phase_thread_refused');}$api_phase_state[$key]=$value;}
    $directory=lstat($path);$owner=$path.'/owner';
    if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||$directory['ino']!==$payload['directory_ino']||
      ($directory['mode']&0777)!==0700||!is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||
      filesize($owner)!==64||!hash_equals($payload['owner_token'],file_get_contents($owner))){throw new RuntimeException('recovery_api_phase_owner_refused');}
    $api_phase_state['phase']=$phase;$temporary=$path.'/.write-'.bin2hex(random_bytes(8));$stream=fopen($temporary,'x');
    if(!$stream){throw new RuntimeException('recovery_api_phase_write_refused');}
    try{if(!chmod($temporary,0600)){throw new RuntimeException('recovery_api_phase_write_refused');}
      $raw=json_encode($api_phase_state,JSON_THROW_ON_ERROR);if(fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)){
        throw new RuntimeException('recovery_api_phase_write_refused');}}
    finally{fclose($stream);}
    if(!rename($temporary,$path.'/api-phase.json')){throw new RuntimeException('recovery_api_phase_write_refused');}
  };
  $api_phase('payload-verified');
  $scope_owned=true;
  require_once $root.'/support/runtime/bootstrap-cli.php';$api_phase('bootstrap-loaded');
  ob_start();
  phutil_load_library($root.'/src/');$api_phase('library-loaded');
  require_once __DIR__.'/oauth-code-workers.php';
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
  $proof->set_charset('utf8mb4');$api_phase('proof-connected',array('proof_thread'=>(int)$proof->thread_id));
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
  $measurement_file=governance_oauth_code_native_measurement_file_source($payload['action']['action']??null,$payload['measurement_file']??null,
    $config['namespace'],$payload['fixture_nonce'],$payload['source_sha256']);
  $api_phase('config-default-start');
  $stack=new PhabricatorConfigStackSource(); $stack->pushSource(new PhabricatorConfigDefaultSource());
  $api_phase('config-default-ready');
  $settings=array(
    'mysql.host'=>$config['host'],'mysql.port'=>$config['port'],'mysql.user'=>$config['user'],
    'mysql.pass'=>$config['password'],'storage.default-namespace'=>$config['namespace'],
    'phabricator.cache-namespace'=>$config['namespace'],'cluster.databases'=>array(),'cluster.search'=>array(),
    'phabricator.base-uri'=>'http://fixture.invalid/','phabricator.silent'=>false,'policy.allow-public'=>false,
    'governance.recovery-stop-ledger.enabled'=>in_array(($payload['action']['action']??null),array('recovery.stop.record','recovery.stop.history'),true),
    'governance.notifications.enabled'=>($payload['action']['action']??null)==='org.notice.create',
    'governance.private-mail.enabled'=>in_array(($payload['action']['action']??null),array('org.notice.create','legalpad.verify','legalpad.observe'),true),'governance.organization.enabled'=>false,'governance.media-exchange.enabled'=>false,
    'governance.account-recovery.enabled'=>in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue','recovery.oauth-code.borrowed-owner.issue','recovery.org.member.insert','recovery.org.approval.propose'),true),
    'governance.account-recovery.incarnation'=>in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue','recovery.oauth-code.borrowed-owner.issue','recovery.org.member.insert','recovery.org.approval.propose'),true)?$payload['recovery_incarnation']:null,
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
  $api_phase('config-installed');
  $action=$payload['action']; $kind=$action['action']??null;
  if($kind!=='recovery.oauth-code.borrowed-owner.issue'){throw new RuntimeException('native_worker_action_refused');}
  $api_phase('writer-connect-start');
  $conn=(new PhabricatorUser())->establishConnection('w');
  $thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
  $api_phase('writer-connected',array('writer_thread'=>$thread));
  require_once dirname(__DIR__).'/recovery/oauth-code-producer.php';
  $result=governance_recovery_oauth_code_native_producer($conn,$action,$path,$payload,$thread);
} catch (Throwable $error) {
  $expected=get_class($error)===RuntimeException::class &&
    preg_match('/\A(?:organization|governance|media)_[a-z_]+\z/',$error->getMessage());
  $result=$expected?array('status'=>'rejected','reason'=>$error->getMessage()):
    array('status'=>'failed','error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  if(in_array(($kind??null),array('recovery.stop.record','recovery.stop.history'),true)){
    $allowed=get_class($error)===RuntimeException::class&&in_array($error->getMessage(),
      array('recovery_stop_admin_required','governance_native_credential_ineligible'),true);
    $result=$allowed?array('status'=>'rejected','reason'=>$error->getMessage()):
      array('status'=>'failed','error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  }
  if(($kind??null)==='recovery.org.approval.propose'){$result=array('status'=>'failed','error_type'=>get_class($error),'reason'=>'native_lifecycle_producer_failed');}
  if(($kind??null)==='recovery.org.member.insert'){$result=array('status'=>'failed','error_type'=>get_class($error),'reason'=>'native_organization_producer_failed');}
  if(($kind??null)==='recovery.policy.insert'){$result=array('status'=>'failed','error_type'=>get_class($error),'reason'=>'native_policy_producer_failed');}
  if(in_array(($kind??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue'),true)){
    $allowed=get_class($error)===RuntimeException::class&&in_array($error->getMessage(),
      array('recovery_restore_admin_required','governance_native_credential_ineligible'),true);
    $result=$allowed?array('status'=>'rejected','reason'=>$error->getMessage()):
      array('status'=>'failed','error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  }
  if(in_array(($kind??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue'),true)&&($result['status']??null)==='failed'&&
    $error instanceof AphrontQueryException&&is_int($error->getCode())&&$error->getCode()>=0&&$error->getCode()<=65535){
    // Exception code only; a native SchemaException may legitimately have zero.
    $result['query_code']=$error->getCode();
  }
  if(($kind??null)==='legalpad.verify'&&get_class($error)===PhorgeLegalpadVerificationException::class){
    $result=array('status'=>'rejected','reason'=>'governance_legalpad_verification_refused');
  }
} finally {
  // Expose the result while the original native writer connection is still
  // alive, so the parent can prove rollback released its locks before close.
  try{
    if(($kind??null)==='legalpad.observe'&&($result['status']??null)==='observed'){
      governance_oauth_code_native_worker_atomic($path,'result.json',$result);$deadline=microtime(true)+15;$released=false;
      do{$release=$path.'/observe-release';
        if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&
          hash_equals($payload['owner_token'],file_get_contents($release))){$released=true;break;}usleep(20000);
      }while(microtime(true)<$deadline);
      if(!$released){$result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_legalpad_observation_release_timeout');}
    }
  }catch(Throwable$error){$result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_legalpad_observation_handshake_failed');}
  $conduit_cleanup_known=($kind??null)!=='recovery.oauth-code.borrowed-owner.issue'||
    (($result['status']??null)==='committed'&&class_exists('PhorgeRecoveryConduitProducerFence',false)&&
    (new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->getValue()===array());
  if($conduit_cleanup_known){
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
  } catch (Throwable $cleanup) {
    if(in_array(($kind??null),array('recovery.session.issue','recovery.session.borrowed-owner.issue'),true)){
      // Preserve an original failed child location if cleanup also fails.
      if(($result['status']??null)!=='failed'||!isset($result['source_file'],$result['source_line'])){
        $result=array('status'=>'failed','error_type'=>get_class($cleanup),
          'source_file'=>basename($cleanup->getFile()),'source_line'=>$cleanup->getLine());
        if($cleanup instanceof AphrontQueryException&&is_int($cleanup->getCode())&&
          $cleanup->getCode()>=0&&$cleanup->getCode()<=65535){$result['query_code']=$cleanup->getCode();}
      }
    }else{$result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_worker_cleanup_failed');}
  }
  }else{
    // No explicit pool rollback/close or guard/proof teardown on unknown NEW
    // producer state. PHP shutdown may naturally close; not a live lease claim.
    if(($result['status']??null)==='committed'){$result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_conduit_terminal_unproved');}
  }
  if ($scope_owned && function_exists('governance_oauth_code_native_worker_atomic')) {
    governance_oauth_code_native_worker_atomic($path,'result.json',$result);
  }
  if (ob_get_level()>0) { ob_end_clean(); }
}
exit(($result['status']??null)==='failed'?1:0);
