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
  if(in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.org.member.insert','recovery.org.approval.propose'),true)){$wanted_keys[]='recovery_incarnation';sort($wanted_keys);
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
  $measurement_file=governance_native_measurement_file_source($payload['action']['action']??null,$payload['measurement_file']??null,
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
    'governance.private-mail.enabled'=>in_array(($payload['action']['action']??null),array('org.notice.create','legalpad.verify','legalpad.observe'),true),'governance.organization.enabled'=>true,'governance.media-exchange.enabled'=>true,
    'governance.account-recovery.enabled'=>in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.org.member.insert','recovery.org.approval.propose'),true),
    'governance.account-recovery.incarnation'=>in_array(($payload['action']['action']??null),array('recovery.session.issue','recovery.org.member.insert','recovery.org.approval.propose'),true)?$payload['recovery_incarnation']:null,
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
  $api_phase('writer-connect-start');
  $conn=(new PhabricatorUser())->establishConnection('w');
  $thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
  $api_phase('writer-connected',array('writer_thread'=>$thread));
  if($kind==='recovery.oauth.authenticate'){
    $keys=array_keys($action);sort($keys);
    if($keys!==array('action','token','user')||!is_string($action['token'])||
      !preg_match('/\A[A-Za-z0-9]{32}\z/D',$action['token'])){
      throw new RuntimeException('native_worker_action_refused');}
    PhorgeRecoveryStopRequest::user($action['user']);
    $api_phase('oauth-reader-connect-start');
    $oauth_conn=(new PhabricatorOAuthServerAccessToken())->establishConnection('r');
    $oauth_thread=(int)queryfx_one($oauth_conn,'SELECT CONNECTION_ID() n')['n'];
    $api_phase('oauth-reader-connected',array('credential_thread'=>$oauth_thread));
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$oauth_thread));
    $api_phase('ready-published');$api_phase('before-native-oauth-auth');
    $api=new ConduitAPIRequest(array(),true);
    $controller=(new PhabricatorConduitAPIController())->setRequest(
      (new AphrontRequest('fixture.invalid','/api/user.whoami'))->setUser(new PhabricatorUser()));
    $error=(new ReflectionMethod(PhabricatorConduitAPIController::class,'authenticateUser'))
      ->invoke($controller,$api,array('access_token'=>$action['token']),'user.whoami');
    $api_phase('native-oauth-auth-returned');
    if($error===null&&$api->getUser()->getPHID()===$action['user']){
      $outcome='allowed';$code='native-success';
    }else if(is_array($error)&&array_keys($error)===array(0,1)&&$error[0]==='ERR-INVALID-AUTH'){
      $outcome='denied';$code='ERR-INVALID-AUTH';
    }else{throw new RuntimeException('recovery_oauth_auth_unknown');}
    $result=array('status'=>'committed','outcome'=>$outcome,'authentication_code'=>$code,
      'restoration_authorized'=>false);
  }else if($kind==='recovery.api.current'){
    $keys=array_keys($action);sort($keys);
    if($keys!==array('action','token','user')||!is_string($action['token'])||
      !preg_match('/\A(?:api|cli)-[A-Za-z0-9]{28}\z/D',$action['token'])){
      throw new RuntimeException('native_worker_action_refused');}
    PhorgeRecoveryStopRequest::user($action['user']);
    $viewer_dao=new PhabricatorUser();$api_phase('user-reader-connect-start');
    $reader=$viewer_dao->establishConnection('r');$reader_thread=(int)queryfx_one($reader,'SELECT CONNECTION_ID() n')['n'];
    $api_phase('user-reader-connected',array('reader_thread'=>$reader_thread));$api_phase('user-load-start');
    $viewer=$viewer_dao->loadOneWhere('phid=%s',$action['user']);$api_phase('user-loaded');
    if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
    // This is the actual current-source reader, not a claim that the caller's
    // PHID authenticates a token. lockCurrent verifies the stored token binding.
    $context=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken(
      $viewer,new PhutilOpaqueEnvelope($action['token']));
    $api_phase('context-ready');
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    $api_phase('ready-published');
    queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$conn->openTransaction();
    try {
      // Observe the actual native Conduit connection; do not label the User writer as it.
      $credential_conn=(new PhabricatorConduitToken())->establishConnection('w');
      $credential_thread=(int)queryfx_one($credential_conn,'SELECT CONNECTION_ID() n')['n'];
      $api_phase('before-current-read',array('credential_thread'=>$credential_thread));
      $current=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);$api_phase('current-read-returned');
      if($current->getUserPHID()!==$action['user']){
        throw new RuntimeException('recovery_api_current_binding_refused');}
      $conn->saveTransaction();
    }catch(Throwable$error){$conn->killTransaction();throw$error;}
    $result=array('status'=>'committed','current_token_verified'=>true,'restoration_authorized'=>false);
  }else if($kind==='recovery.org.approval.propose'){
    $keys=array_keys($action);sort($keys,SORT_STRING);
    if($keys!==array('action','subject','token')||!is_string($action['token'])||strlen($action['token'])>256){
      throw new RuntimeException('native_worker_action_refused');}
    PhorgeRecoveryStopRequest::user($action['subject']);
    $owned_subject=queryfx_one($conn,'SELECT phid,username FROM user WHERE phid=%s',$action['subject']);
    if(!$owned_subject||$owned_subject['phid']!==$action['subject']||
      $owned_subject['username']!=='rlfmember'.substr($config['namespace'],-10)){
      throw new RuntimeException('native_lifecycle_subject_refused');}
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/people/recovery/organization-producer-fixture/'))->setUser($viewer)
      ->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
    $request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    $conn->openTransaction();
    try {
      $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      if(!$actor->isSystemAdmin()){throw new RuntimeException('recovery_restore_admin_required');}
      PhorgeGovernanceNativeIdentitySource::revalidate($actor,$conn);
      $conn->saveTransaction();
    }catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
    // Observe the actual native organization DAO writer after actor bootstrap.
    $conn=(new PhorgeOrganizationMember())->establishConnection('w');
    $thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    governance_native_worker_atomic($path,'producer-ready.json',array('status'=>'producer-ready','mysql_thread'=>$thread));
    $deadline=microtime(true)+12;$released=false;
    do {
      $directory=lstat($path);$owner=$path.'/owner';$release=$path.'/producer-continue';
      if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||
        $directory['ino']!==$payload['directory_ino']||($directory['mode']&0777)!==0700||
        !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
        !hash_equals($payload['owner_token'],file_get_contents($owner))){throw new RuntimeException('native_organization_producer_owner_changed');}
      if(is_file($release)){
        if(is_link($release)||(fileperms($release)&0777)!==0600||filesize($release)!==64||
          !hash_equals($payload['owner_token'],file_get_contents($release))){throw new RuntimeException('native_organization_producer_release_refused');}
        $released=true;break;
      }
      usleep(20000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('native_organization_producer_timeout');}
    $id='recovery-lifecycle-race-'.substr($payload['owner_token'],0,16);
    $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
    $service=PhorgeOrganizationService::newForActor($context);
    $receipt=$service->proposeApproval(array('approval_id'=>$id,'operation_id'=>$id,
      'team'=>'recovery-lifecycle-team','action'=>'appointment.grant','target_user'=>$action['subject'],
      'valid_from'=>$now,'valid_until'=>null,'ttl_seconds'=>120));
    $stored=queryfx_one($conn,'SELECT * FROM organization_approval WHERE approvalID=%s',$id);
    if(!$stored||$stored['approvalID']!==$id||$receipt['replayed']!==false||$conn->isInsideTransaction()){
      throw new RuntimeException('native_lifecycle_producer_commit_unknown');}
    $result=array('status'=>'committed','native_approval_digest'=>PhorgeRecoveryRestoreRequest::digest($stored),
      'restoration_authorized'=>false);
  }else if($kind==='recovery.org.member.insert'){
    $keys=array_keys($action);sort($keys,SORT_STRING);
    if($keys!==array('action','token')||!is_string($action['token'])||strlen($action['token'])>256){
      throw new RuntimeException('native_worker_action_refused');}
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/people/recovery/organization-producer-fixture/'))->setUser($viewer)
      ->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
    $request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    $conn->openTransaction();
    try {
      $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      if(!$actor->isSystemAdmin()){throw new RuntimeException('recovery_restore_admin_required');}
      PhorgeGovernanceNativeIdentitySource::revalidate($actor,$conn);
      $conn->saveTransaction();
    }catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
    // Observe the actual native organization DAO writer after actor bootstrap.
    $conn=(new PhorgeOrganizationMember())->establishConnection('w');
    $thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    governance_native_worker_atomic($path,'producer-ready.json',array('status'=>'producer-ready','mysql_thread'=>$thread));
    $deadline=microtime(true)+12;$released=false;
    do {
      $directory=lstat($path);$owner=$path.'/owner';$release=$path.'/producer-continue';
      if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||
        $directory['ino']!==$payload['directory_ino']||($directory['mode']&0777)!==0700||
        !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
        !hash_equals($payload['owner_token'],file_get_contents($owner))){throw new RuntimeException('native_organization_producer_owner_changed');}
      if(is_file($release)){
        if(is_link($release)||(fileperms($release)&0777)!==0600||filesize($release)!==64||
          !hash_equals($payload['owner_token'],file_get_contents($release))){throw new RuntimeException('native_organization_producer_release_refused');}
        $released=true;break;
      }
      usleep(20000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('native_organization_producer_timeout');}
    // Fixed synthetic unit comes only from this owned worker nonce, never an
    // externally supplied identity or arbitrary SQL payload.
    $unit='recovery-producer-'.substr($payload['owner_token'],0,16);
    $member=(new PhorgeOrganizationMember())->setUnitID($unit)->setUserPHID($viewer->getPHID())
      ->setState('active')->setValidFrom(0)->setValidUntil(null)->setVersion(1)->save();
    if($conn->isInsideTransaction()){throw new RuntimeException('native_organization_producer_commit_unknown');}
    $stored=queryfx_one($conn,'SELECT id,unitID,userPHID,state,validFrom,validUntil,version FROM organization_member WHERE id=%d',$member->getID());
    if(!$stored||$stored['unitID']!==$unit||$stored['userPHID']!==$viewer->getPHID()){
      throw new RuntimeException('native_organization_producer_result_unknown');}
    $result=array('status'=>'committed','native_member_digest'=>PhorgeRecoveryRestoreRequest::digest($stored),'restoration_authorized'=>false);
  }else if($kind==='recovery.policy.insert'){
    $keys=array_keys($action);sort($keys,SORT_STRING);
    if($keys!==array('action','token')||!is_string($action['token'])||strlen($action['token'])>256){
      throw new RuntimeException('native_worker_action_refused');}
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/people/recovery/policy-fixture/'))->setUser($viewer)
      ->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
    $request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    $conn->openTransaction();
    try {
      $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      if(!$actor->isSystemAdmin()){throw new RuntimeException('recovery_restore_admin_required');}
      PhorgeGovernanceNativeIdentitySource::revalidate($actor,$conn);
      $conn->saveTransaction();
    }catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
    // Observe the actual native Policy DAO writer, not the bootstrap User thread.
    $conn=(new PhabricatorPolicy())->establishConnection('w');
    $thread=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    governance_native_worker_atomic($path,'producer-ready.json',array('status'=>'producer-ready','mysql_thread'=>$thread));
    $deadline=microtime(true)+12;$released=false;
    do {
      $directory=lstat($path);$owner=$path.'/owner';$release=$path.'/producer-continue';
      if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||
        $directory['ino']!==$payload['directory_ino']||($directory['mode']&0777)!==0700||
        !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
        !hash_equals($payload['owner_token'],file_get_contents($owner))){throw new RuntimeException('native_policy_producer_owner_changed');}
      if(is_file($release)){
        if(is_link($release)||(fileperms($release)&0777)!==0600||filesize($release)!==64||
          !hash_equals($payload['owner_token'],file_get_contents($release))){throw new RuntimeException('native_policy_producer_release_refused');}
        $released=true;break;
      }
      usleep(20000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('native_policy_producer_timeout');}
    // No fake hasCapability boolean and no external SQL/policy payload. This
    // action tests physical native DAO serialization, not a publication API.
    $policy=(new PhabricatorPolicy())->setRules(array())->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->save();
    if($conn->isInsideTransaction()){throw new RuntimeException('native_policy_producer_commit_unknown');}
    $stored=queryfx_one($conn,'SELECT phid FROM %R WHERE id=%d',new PhabricatorPolicy(),$policy->getID());
    if(!$stored||$stored['phid']!==$policy->getPHID()){throw new RuntimeException('native_policy_producer_result_unknown');}
    $result=array('status'=>'committed','native_policy_digest'=>hash('sha256',$stored['phid']),'restoration_authorized'=>false);
  }else if($kind==='recovery.session.issue'){
    $keys=array_keys($action);sort($keys);
    if($keys!==array('action','subject','token')||!is_string($action['token'])||strlen($action['token'])>256){
      throw new RuntimeException('native_worker_action_refused');}
    PhorgeRecoveryStopRequest::user($action['subject']);
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/people/recovery/session-fixture/'))->setUser($viewer)
      ->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
    $request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    if(strcmp($context->getUserPHID(),$action['subject'])>=0){
      throw new RuntimeException('recovery_session_worker_lock_order_unknown');}
    queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$conn->openTransaction();
    try {
      // The fixture chooses actor before subject. Do not prelock its PRIMARY:
      // the actual SessionEngine -> DAO INSERT fence must cause its own wait.
      $snapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
      if(!$snapshot->isSystemAdmin()){throw new RuntimeException('recovery_restore_admin_required');}
      governance_native_worker_atomic($path,'producer-ready.json',array('status'=>'producer-ready','mysql_thread'=>$thread));
      $deadline=microtime(true)+12;$released=false;
      do {
        $directory=lstat($path);$owner=$path.'/owner';
        if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||
          $directory['ino']!==$payload['directory_ino']||($directory['mode']&0777)!==0700||
          !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
          !hash_equals($payload['owner_token'],file_get_contents($owner))){
          throw new RuntimeException('recovery_session_producer_owner_changed');}
        $continue=$path.'/producer-continue';
        if(is_file($continue)&&!is_link($continue)&&(fileperms($continue)&0777)===0600&&filesize($continue)===64&&
          hash_equals($payload['owner_token'],file_get_contents($continue))){$released=true;break;}
        usleep(20000);
      }while(microtime(true)<$deadline);
      if(!$released){throw new RuntimeException('recovery_session_producer_handshake_timeout');}
      // Exact old source query, no INSERT or identity qualification effect.
      $covering=queryfx_one($conn,'SELECT phid FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',
        new PhabricatorUser(),$action['subject']);
      if(!$covering||$covering['phid']!==$action['subject']){throw new RuntimeException('recovery_session_covering_control_refused');}
      governance_native_worker_atomic($path,'covering-control-ready.json',array('status'=>'covering-control-returned','mysql_thread'=>$thread));
      $deadline=microtime(true)+12;$released=false;
      do {
        $directory=lstat($path);$owner=$path.'/owner';
        if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||
          $directory['ino']!==$payload['directory_ino']||($directory['mode']&0777)!==0700||
          !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
          !hash_equals($payload['owner_token'],file_get_contents($owner))){
          throw new RuntimeException('recovery_session_producer_owner_changed');}
        $continue=$path.'/fence-continue';
        if(is_file($continue)&&!is_link($continue)&&(fileperms($continue)&0777)===0600&&filesize($continue)===64&&
          hash_equals($payload['owner_token'],file_get_contents($continue))){$released=true;break;}
        usleep(20000);
      }while(microtime(true)<$deadline);
      if(!$released){throw new RuntimeException('recovery_session_fence_handshake_timeout');}
      $secret=(new PhabricatorAuthSessionEngine())->establishSession(PhabricatorAuthSession::TYPE_WEB,$action['subject'],false);
      $digest=PhabricatorAuthSession::newSessionDigest(new PhutilOpaqueEnvelope($secret));unset($secret);
      $session=queryfx_one($conn,'SELECT id,userPHID FROM %R WHERE sessionKey=%s LOCK IN SHARE MODE',
        new PhabricatorAuthSession(),$digest);unset($digest);
      if(!$session||$session['userPHID']!==$action['subject']){throw new RuntimeException('recovery_session_native_result_unknown');}
      PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn);
      $conn->saveTransaction();
      $result=array('status'=>'committed','session_id_digest'=>hash('sha256',(string)$session['id']),
        'restoration_authorized'=>false);
    }catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
  }else if($kind==='recovery.stop.record'||$kind==='recovery.stop.history'){
    $keys=array_keys($action);sort($keys);
    $wanted=$kind==='recovery.stop.record'?array('action','category','event','operation','reason','subject','token'):
      array('action','subject','token');
    if($keys!==$wanted||!is_string($action['token'])||strlen($action['token'])>256){
      throw new RuntimeException('native_worker_action_refused');}
    PhorgeRecoveryStopRequest::user($action['subject']);
    if($kind==='recovery.stop.record'){
      PhorgeRecoveryStopRequest::validate($action['subject'],$action['event'],$action['category'],$action['reason'],$action['operation']);}
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/people/recovery/fixture/'))->setUser($viewer)
      ->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
    $request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    if($kind==='recovery.stop.record'){
      $receipt=PhorgeRecoveryStopService::recordAlreadyStopped($context,$action['subject'],$action['event'],
        $action['category'],$action['reason'],$action['operation']);
      if($receipt['native_transaction_id']!==$action['event']){throw new RuntimeException('native_stop_worker_binding_refused');}
      $result=array('status'=>'committed','replayed'=>$receipt['replayed'],'recovery_authorized'=>$receipt['recovery_authorized']);
    }else{
      $rows=PhorgeRecoveryStopService::history($context,$action['subject'],'0',1);
      $result=array('status'=>'committed','count'=>count($rows));
    }
  }else if($kind==='legalpad.verify'||$kind==='legalpad.observe'){
    $keys=array_keys($action);sort($keys);
    $expected_keys=$kind==='legalpad.observe'?array('action','code','signature_id','token'):array('action','code','token');
    if($keys!==$expected_keys||($kind==='legalpad.observe'&&(!is_int($action['signature_id'])||$action['signature_id']<1))||!is_string($action['token'])||strlen($action['token'])>256||
      !is_string($action['code'])||!preg_match('/\A[a-z2-7]{20}\z/D',$action['code'])){throw new RuntimeException('native_worker_action_refused');}
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer){throw new RuntimeException('governance_web_credential_required');}
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/legalpad/verify/'.$action['code'].'/'))->setUser($viewer)
      ->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
    $request->validateCSRF();
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    if($kind==='legalpad.verify'){
      $receipt=PhorgeLegalpadVerification::inspect($request,$action['code'],true);
      $result=array('status'=>'committed','already_verified'=>$receipt['already_verified'],'verified'=>$receipt['verified']);
    }else{
      $signature=(new LegalpadDocumentSignature())->load($action['signature_id']);
      if(!$signature||!hash_equals($signature->getSecretKey(),$action['code'])){throw new RuntimeException('native_legalpad_observation_target_refused');}
      $mails=new PhabricatorMetaMTAMail();$before=(int)queryfx_one($mails->establishConnection('r'),'SELECT COUNT(*) n FROM %R',$mails)['n'];
      $outcome=null;$exception=null;
      try{$receipt=PhorgeLegalpadVerification::inspect($request,$action['code'],true);$outcome=$receipt['already_verified']?'already-verified':'verified';}
      catch(PhorgeLegalpadVerificationException$error){$outcome='source-refused';$exception=get_class($error);}
      catch(AphrontDeadlockQueryException$error){$outcome='deadlock';$exception=get_class($error);}
      $stored=(new LegalpadDocumentSignature())->load($action['signature_id']);
      $after=(int)queryfx_one($mails->establishConnection('r'),'SELECT COUNT(*) n FROM %R',$mails)['n'];
      $result=array('status'=>'observed','outcome'=>$outcome,'exception_class'=>$exception,'verified'=>$stored->isVerified(),
        'transaction_open'=>$conn->isInsideTransaction(),'mail_delta'=>$after-$before);
    }
  }else if($kind==='measurement.api-token-auth'){
    $keys=array_keys($action);sort($keys);
    if($keys!==array('action','owner_binding','token','token_id')||!is_string($action['token'])||
      !preg_match('/\Aapi-[a-z0-9]{28}\z/',$action['token'])||!is_int($action['token_id'])||$action['token_id']<1||
      !is_string($action['owner_binding'])||!preg_match('/\A[a-f0-9]{64}\z/',$action['owner_binding'])){
      throw new RuntimeException('native_worker_action_refused');}
    $token_conn=(new PhabricatorConduitToken())->establishConnection('w');
    $row=queryfx_one($token_conn,'SELECT id,objectPHID,tokenType,token,expires FROM %R WHERE id=%d',
      new PhabricatorConduitToken(),$action['token_id']);
    if($row&&(!hash_equals($action['token'],$row['token'])||$row['tokenType']!==PhabricatorConduitToken::TYPE_STANDARD||
      hash('sha256',$row['objectPHID'])!==$action['owner_binding'])){
      throw new RuntimeException('measurement_token_binding_refused');}
    $boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
    if(!preg_match('/\A[a-f0-9-]{36}\z/',$boot)){throw new RuntimeException('measurement_linux_clock_required');}
    $actual_thread=(int)queryfx_one($token_conn,'SELECT CONNECTION_ID() n')['n'];
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$actual_thread));
    $api=new ConduitAPIRequest(array(),true);
    $controller=(new PhabricatorConduitAPIController())->setRequest(
      (new AphrontRequest('fixture.invalid','/api/user.whoami'))->setUser(new PhabricatorUser()));
    $error=(new ReflectionMethod(PhabricatorConduitAPIController::class,'authenticateUser'))
      ->invoke($controller,$api,array('token'=>$action['token']),'user.whoami');
    $outcome='unknown';$code='unknown';
    if($error===null&&$row&&hash('sha256',$api->getUser()->getPHID())===$action['owner_binding']){
      $outcome='allowed';$code='native-success';
    }else if(!$row&&is_array($error)&&array_keys($error)===array(0,1)&&$error[0]==='ERR-INVALID-AUTH'){
      $outcome='denied';$code='ERR-INVALID-AUTH';
    }
    $result=array('status'=>'committed','outcome'=>$outcome,'authentication_code'=>$code,'asset_binding'=>$measurement_file['binding'],
      'token_present'=>$row!==null,'boot_id_sha256'=>hash('sha256',$boot),
      'owner_binding'=>$action['owner_binding'],
      'source_binding'=>hash('sha256',phutil_json_encode(array('id'=>$action['token_id'],'row'=>$row))));
  } else if($kind==='measurement.project-query'){
    $keys=array_keys($action);sort($keys);
    if($keys!==array('action','target','token')||!is_string($action['token'])||strlen($action['token'])>256||
      !is_string($action['target'])||!preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/',$action['target'])){
      throw new RuntimeException('native_worker_action_refused');}
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer||$viewer->getIsDisabled()||!$viewer->getIsApproved()||$viewer->getIsSystemAgent()||$viewer->getIsMailingList()){
      throw new RuntimeException('governance_web_credential_required');}
    $project_conn=(new PhabricatorProject())->establishConnection('w');
    $row=queryfx_one($project_conn,'SELECT phid,viewPolicy FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorProject(),$action['target']);
    $latest=queryfx_one($project_conn,'SELECT id,metadata FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1',new PhabricatorProjectTransaction(),$action['target']);
    if(!$row||$row['phid']!==$action['target']){throw new RuntimeException('measurement_target_missing');}
    $boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
    if(!preg_match('/\A[a-f0-9-]{36}\z/',$boot)){throw new RuntimeException('measurement_linux_clock_required');}
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    $object=null;
    try{$object=(new PhabricatorProjectQuery())->setViewer($viewer)->withPHIDs(array($action['target']))->executeOne();}
    catch(PhabricatorPolicyException$error){if(get_class($error)!==PhabricatorPolicyException::class){throw$error;}$object=null;}
    $result=array('status'=>'committed','outcome'=>$object?'allowed':'denied',
      'boot_id_sha256'=>hash('sha256',$boot),'target_binding'=>hash('sha256',$action['target']),
      'source_binding'=>hash('sha256',phutil_json_encode(array($row,$latest))));
  } else if ($kind==='org.list' || $kind==='org.count') {
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
  } else if ($kind==='org.notice.create' || $kind==='org.members.edit' || $kind==='org.compound.execute' || $kind==='org.compound.execute-approved' || $kind==='org.transfer.execute' || $kind==='org.handover.execute') {
    $keys=array_keys($action);sort($keys);
    if($keys!==array('action','payload','token')||!is_string($action['token'])||strlen($action['token'])>256||!is_array($action['payload'])){
      throw new RuntimeException('native_worker_action_refused');}
    $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
    if(!$viewer){throw new RuntimeException('governance_web_credential_required');}
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
    $request=(new AphrontRequest('fixture.invalid','/'))->setUser($viewer);
    if($kind==='org.notice.create'){$_SERVER['REQUEST_METHOD']='POST';$request->setRequestData(array(AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));$request->validateCSRF();}
    $service=PhorgeOrganizationService::newForActor(PhorgeGovernanceNativeActorContext::newFromWebRequest($request));
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    if($kind==='org.notice.create'){$receipt=$service->createUnit($action['payload']);}
    else if($kind==='org.transfer.execute'||$kind==='org.handover.execute'){
      $keys=array_keys($action['payload']);sort($keys);
      if($keys!==array('acknowledge_no_project_permission_change','transfer_id')){throw new RuntimeException('native_worker_action_refused');}
      $receipt=$kind==='org.transfer.execute'?$service->executeTransfer($action['payload']['transfer_id'],$action['payload']['acknowledge_no_project_permission_change']):
        $service->executeHandover($action['payload']['transfer_id'],$action['payload']['acknowledge_no_project_permission_change']);
    }else if($kind==='org.compound.execute-approved'){
      if(array_keys($action['payload'])!==array('approval_id')){throw new RuntimeException('native_worker_action_refused');}
      $receipt=$service->executeApprovedCompound($action['payload']['approval_id']);
    }else{$receipt=$kind==='org.members.edit'?$service->execute($action['payload']):$service->executeCompound($action['payload']);}
    $result=array('status'=>'committed','replayed'=>$receipt['replayed']);
  } else if($kind==='media.prepare'){
    $keys=array_keys($action);sort($keys);
    if($keys!==array('action','browser_secret','client_nonce','path')||
      ($action['browser_secret']!==null&&!is_string($action['browser_secret']))){throw new RuntimeException('native_worker_action_refused');}
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    $request=(new AphrontRequest('fixture.invalid','/media/session/prepare/'))->setRequestData(array());
    $target=PhorgeGovernanceMediaTarget::fromPath($action['path']);
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    (new PhorgeGovernanceMediaExchangeService())->beginBrowserTransaction($action['browser_secret'],$target,$request,$action['client_nonce']);
    $result=array('status'=>'committed','prepared'=>true);
  } else if ($kind==='media.redeem') {
    $keys=array_keys($action); sort($keys);
    if ($keys!==array('action','audience','browser_secret','client_nonce','code','path','purpose','transaction')) {
      throw new RuntimeException('native_worker_action_refused');
    }
    $media=new PhorgeGovernanceMediaExchangeService();
    governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
    $redeemed=$media->redeem($action['transaction'],$action['code'],$action['browser_secret'],
      $action['audience'],$action['purpose'],$action['path'], $action['client_nonce']);
    $snapshot=$media->authenticate($redeemed['credential']->openEnvelope());
    $result=array('status'=>'committed','credential_authenticated'=>$snapshot->getCredentialType()==='web');
  } else { throw new RuntimeException('native_worker_action_refused'); }
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
  if(($kind??null)==='recovery.session.issue'){
    $allowed=get_class($error)===RuntimeException::class&&in_array($error->getMessage(),
      array('recovery_restore_admin_required','governance_native_credential_ineligible'),true);
    $result=$allowed?array('status'=>'rejected','reason'=>$error->getMessage()):
      array('status'=>'failed','error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  }
  if(($kind??null)==='recovery.session.issue'&&($result['status']??null)==='failed'&&
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
      governance_native_worker_atomic($path,'result.json',$result);$deadline=microtime(true)+15;$released=false;
      do{$release=$path.'/observe-release';
        if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&
          hash_equals($payload['owner_token'],file_get_contents($release))){$released=true;break;}usleep(20000);
      }while(microtime(true)<$deadline);
      if(!$released){$result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_legalpad_observation_release_timeout');}
    }
  }catch(Throwable$error){$result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_legalpad_observation_handshake_failed');}
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
    if(($kind??null)==='recovery.session.issue'){
      // Preserve an original failed child location if cleanup also fails.
      if(($result['status']??null)!=='failed'||!isset($result['source_file'],$result['source_line'])){
        $result=array('status'=>'failed','error_type'=>get_class($cleanup),
          'source_file'=>basename($cleanup->getFile()),'source_line'=>$cleanup->getLine());
        if($cleanup instanceof AphrontQueryException&&is_int($cleanup->getCode())&&
          $cleanup->getCode()>=0&&$cleanup->getCode()<=65535){$result['query_code']=$cleanup->getCode();}
      }
    }else{$result=array('status'=>'failed','error_type'=>'RuntimeException','reason'=>'native_worker_cleanup_failed');}
  }
  if ($scope_owned && function_exists('governance_native_worker_atomic')) {
    governance_native_worker_atomic($path,'result.json',$result);
  }
  if (ob_get_level()>0) { ob_end_clean(); }
}
exit(($result['status']??null)==='failed'?1:0);
