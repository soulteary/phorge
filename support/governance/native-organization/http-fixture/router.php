<?php

/** Owned cli-server fixture only. Never use as a deployment front controller. */
$root=dirname(__DIR__,4);$proof=null;$guard=null;$scope=false;$directory=null;
$level=ob_get_level();ob_start();ini_set('display_errors','0');
$old_handler=set_error_handler(static function($severity,$message,$file,$line){
  if(!(error_reporting()&$severity)){return false;}
  throw new ErrorException('owned_http_runtime_error',0,$severity,$file,$line);
});
try{
  require_once $root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli-server'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){
    throw new RuntimeException('owned_http_scope_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $selector){
    $value=getenv($selector);if($value!==false&&$value!==''){throw new RuntimeException('owned_http_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT') as $file){
    if(file_exists($root.'/conf/local/'.$file)){throw new RuntimeException('owned_http_deployment_refused');}}
  $directory=getenv('PHORGE_GOVERNANCE_HTTP_FIXTURE_DIR');
  if(!is_string($directory)||is_link($directory)||realpath($directory)!==$directory||
    realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-media-wire-[a-f0-9]{24}\z/',basename($directory))||
    (fileperms($directory)&0777)!==0700){throw new RuntimeException('owned_http_directory_refused');}
  foreach(array('owner','router.json','issuer.json') as $name){$path=$directory.'/'.$name;
    if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>65536){throw new RuntimeException('owned_http_private_config_refused');}}
  $config=json_decode(file_get_contents($directory.'/router.json'),true,16,JSON_THROW_ON_ERROR);
  $keys=array_keys($config);sort($keys);
  if($keys!==array('app_origin','file_origin','fixture_nonce','gorge_file_token','gorge_file_uri','gorge_image_token','gorge_image_uri',
    'issuer_incarnation','issuer_key','owner','source_sha256','transport_proof','writer')){throw new RuntimeException('owned_http_config_refused');}
  foreach(array('owner','fixture_nonce','source_sha256','transport_proof','issuer_key','gorge_file_token','gorge_image_token') as $key){
    if(!is_string($config[$key])||!preg_match('/\A[a-f0-9]{64}\z/',$config[$key])){throw new RuntimeException('owned_http_config_refused');}}
  if(!hash_equals($config['owner'],file_get_contents($directory.'/owner'))||
    !hash_equals($config['source_sha256'],hash_file('sha256',$root.'/resources/sql/quickstart.sql'))||
    !is_string($config['issuer_incarnation'])||!preg_match('/\A[a-f0-9]{32}\z/',$config['issuer_incarnation'])){
    throw new RuntimeException('owned_http_lineage_refused');}
  if(($_SERVER['REMOTE_ADDR']??null)!=='127.0.0.1'||!is_string($_SERVER['HTTP_X_PHORGE_ISOLATED_TLS']??null)||
    !hash_equals($config['transport_proof'],$_SERVER['HTTP_X_PHORGE_ISOLATED_TLS'])){
    throw new RuntimeException('owned_http_tls_transport_refused');}
  foreach(array('gorge_file_uri','gorge_image_uri') as $key){
    if(!is_string($config[$key])||!preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$config[$key],$port)||
      (int)$port[1]>65535){throw new RuntimeException('owned_http_service_refused');}}
  $writer=$config['writer'];$keys=array_keys($writer);sort($keys);
  if($keys!==array('host','namespace','password','port','user')||$writer['host']!=='127.0.0.1'||
    !is_int($writer['port'])||$writer['port']<1||$writer['port']>65535||
    !is_string($writer['namespace'])||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/',$writer['namespace'])||
    !is_string($writer['user'])||!preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/',$writer['user'])||
    !is_string($writer['password'])||!preg_match('/\A[a-f0-9]{48}\z/',$writer['password'])){
    throw new RuntimeException('owned_http_database_refused');}
  require_once $root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  $console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
  $app=PhorgeGovernanceMediaHTTPProtocol::origin($config['app_origin']);$files=PhorgeGovernanceMediaHTTPProtocol::origin($config['file_origin']);
  PhorgeGovernanceMediaHTTPProtocol::assertSeparateOrigins($app,$files);
  if((new PhutilURI($app))->getDomain()!=='app.media.localhost'||(new PhutilURI($files))->getDomain()!=='files.media.localhost'){
    throw new RuntimeException('owned_http_hosts_refused');}
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  $proof=new mysqli($writer['host'],$writer['user'],$writer['password'],'',$writer['port']);$proof->set_charset('utf8mb4');
  $seen_guard=false;$seen_user=false;
  foreach($proof->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM) as $entry){
    $grant=$entry[0];if(str_contains($grant,'WITH GRANT OPTION')){throw new RuntimeException('owned_http_grants_refused');}
    if(preg_match('/\AGRANT USAGE ON \*\.\* TO /',$grant)){continue;}
    if(!preg_match('/\AGRANT (.+) ON `([^`]+)`\.\* TO /',$grant,$match)){throw new RuntimeException('owned_http_grants_refused');}
    $db=str_replace('\\_','_',$match[2]);
    if(!preg_match('/\A'.preg_quote($writer['namespace'],'/').'_[a-z_]+\z/',$db)){throw new RuntimeException('owned_http_grants_refused');}
    $privileges=array_map('trim',explode(',',$match[1]));sort($privileges);
    $wanted=$db===$writer['namespace'].'_governance'?array('SELECT'):array('DELETE','INSERT','SELECT','UPDATE');
    if($privileges!==$wanted){throw new RuntimeException('owned_http_grants_refused');}
    $seen_guard=$seen_guard||$db===$writer['namespace'].'_governance';$seen_user=$seen_user||$db===$writer['namespace'].'_user';
  }
  if(!$seen_guard||!$seen_user){throw new RuntimeException('owned_http_grants_refused');}
  $assert_owned=static function() use($proof,$writer,$config){
    $row=$proof->query('SELECT marker,quickstart_sha256,owner_nonce FROM `'.$writer['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||$row['marker']!=='N07.NATIVE-SOURCE.v1:synthetic-only'||
      !hash_equals($config['source_sha256'],$row['quickstart_sha256'])||!hash_equals($config['fixture_nonce'],$row['owner_nonce'])){
      throw new RuntimeException('owned_http_lineage_refused');}
  };$assert_owned();
  if(PhabricatorEnv::getConfigSourceStack()!==null||(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()||AphrontWriteGuard::isGuardActive()){
    throw new RuntimeException('owned_http_inherited_context_refused');}
  $scope=true;
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host'=>$writer['host'],'mysql.port'=>$writer['port'],'mysql.user'=>$writer['user'],'mysql.pass'=>$writer['password'],
    'storage.default-namespace'=>$writer['namespace'],'phabricator.cache-namespace'=>$writer['namespace'],
    'cluster.databases'=>array(),'cluster.search'=>array(),'phabricator.base-uri'=>$app.'/','phabricator.production-uri'=>$app.'/',
    'security.alternate-file-domain'=>$files.'/','security.require-https'=>true,'policy.allow-public'=>false,
    'governance.organization.enabled'=>true,'governance.media-exchange.enabled'=>true,'governance.media-exchange.http-enabled'=>true,
    'gorge.file.uri'=>$config['gorge_file_uri'],'gorge.file.token'=>$config['gorge_file_token'],
    'gorge.image.uri'=>$config['gorge_image_uri'],'gorge.image.token'=>$config['gorge_image_token'],'gorge.image.mode'=>'gorge',
    'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'gorge.notification.outbox'=>false,'gorge.db.uri'=>null,
    'notification.servers'=>array(),'phabricator.silent'=>true,'projects.default-navigation'=>'flat','project.history-state'=>'off',
    'project.workspace-mode'=>'native','project.history-task-drafts'=>false,'gorge.search.source-scan'=>false,'gorge.search.projection-shadow'=>false)));
  $issuer_source=new PhabricatorDeploymentConfigSource($directory.'/issuer.json');
  $issuer_values=$issuer_source->getAllKeys();$issuer_keys=array_keys($issuer_values);sort($issuer_keys);
  if($issuer_keys!==array('governance.media-exchange.incarnation','governance.media-exchange.issuer-key')||
    $issuer_values['governance.media-exchange.issuer-key']!==$config['issuer_key']||
    $issuer_values['governance.media-exchange.incarnation']!==$config['issuer_incarnation']){
    throw new RuntimeException('owned_http_external_issuer_refused');}
  $stack->pushSource($issuer_source);
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  (new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,null);
  $_SERVER['HTTPS']='on';foreach(array('HTTP_FORWARDED','HTTP_X_FORWARDED_PROTO','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_FOR') as $header){unset($_SERVER[$header]);}
  $host=$_SERVER['HTTP_HOST']??'';$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  if(!is_string($host)||!is_string($path)||strlen($path)>4096||
    !preg_match('@\A/(?:auth/.*|logout/.*|media/session/(?:bridge|prepare|redeem|ready)/|media/static/[a-f0-9]{64}\.png|file/(?:data|download|xform)/.*|favicon\.ico|res/.*|F[1-9][0-9]*|)\z@D',$path)){
    throw new RuntimeException('owned_http_route_scope_refused');}
  $expected_hosts=array();
  foreach(array($app,$files)as$origin){$uri=new PhutilURI($origin);$port=(int)$uri->getPortWithProtocolDefault();
    $expected_hosts[]=$uri->getDomain().($port===443?'':':'.$port);}
  if(!in_array($host,$expected_hosts,true)){throw new RuntimeException('owned_http_host_refused');}
  $configuration=(new AphrontApplicationConfiguration())->setHost($host)->setPath($path);
  $request=$configuration->buildRequest();
  $guard=new AphrontWriteGuard(static function() use($request,$assert_owned){$assert_owned();$request->validateCSRF();});
  if(ob_get_length()!==0){throw new RuntimeException('owned_http_unexpected_output');}
  ob_end_clean();
  require_once $root.'/support/startup/PhabricatorStartup.php';
  PhabricatorStartup::beginOutputCapture();
  // Use the genuine request driver, including response producers (native
  // password login returns a DialogView), delegation, and the real HTTP sink.
  // The native deferred log has an officially supported null destination;
  // no username, capability path, credentials or response body is logged.
  $access_log=new PhutilDeferredLog(null,'');
  $multimeter=MultimeterControl::newInstance();$multimeter->setSampleRate(0);
  $configuration->processRequest($request,$access_log,new AphrontPHPHTTPSink(),$multimeter);
}catch(Throwable $error){
  while(ob_get_level()>$level){ob_end_clean();}
  if(is_string($directory)&&is_dir($directory)&&$scope){
    $diagnostic=json_encode(array('type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()),JSON_THROW_ON_ERROR)."\n";
    $log=$directory.'/router-failures.jsonl';file_put_contents($log,$diagnostic,FILE_APPEND);chmod($log,0600);
  }
  if(!headers_sent()){http_response_code(500);header('Content-Type: application/json');header('Cache-Control: no-store');}
  echo '{"error":"owned_http_fixture_unavailable"}';
}finally{
  if($guard!==null){$guard->dispose();}
  if($scope&&class_exists('LiskDAO',false)){LiskDAO::closeAllConnections();}
  if($proof!==null){$proof->close();}restore_error_handler();
}
