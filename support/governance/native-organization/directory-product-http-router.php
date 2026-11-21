<?php

/** Disposable directory browser fixture. Never a deployment front controller. */
$root=dirname(__DIR__,3);$guard=null;$proof=null;$scope=false;$level=ob_get_level();ob_start();
ini_set('display_errors','0');
set_error_handler(static function($severity){if(!(error_reporting()&$severity)){return false;}throw new ErrorException('directory_http_runtime_error');});
try{
  require_once $root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli-server'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){throw new RuntimeException('directory_http_scope');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){if(getenv($key)!==false&&getenv($key)!==''){throw new RuntimeException('directory_http_deployment');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$file){if(file_exists($root.'/conf/local/'.$file)){throw new RuntimeException('directory_http_deployment');}}
  $directory=getenv('PHORGE_DIRECTORY_HTTP_FIXTURE');
  if(!is_string($directory)||is_link($directory)||realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Adirectory-http-[a-f0-9]{24}\z/',basename($directory))||(fileperms($directory)&0777)!==0700){throw new RuntimeException('directory_http_directory');}
  foreach(array('owner','config.json')as$name){$p=$directory.'/'.$name;
    if(!is_file($p)||is_link($p)||(fileperms($p)&0777)!==0600||filesize($p)>65536){throw new RuntimeException('directory_http_config');}}
  $c=json_decode(file_get_contents($directory.'/config.json'),true,16,JSON_THROW_ON_ERROR);
  $keys=array_keys($c);sort($keys);
  if($keys!==array('fixture_nonce','gorge_file_token','gorge_file_uri','origin','owner','source_sha256','transport','writer')){throw new RuntimeException('directory_http_config');}
  foreach(array('owner','transport','source_sha256','fixture_nonce','gorge_file_token')as$key){
    if(!is_string($c[$key])||!preg_match('/\A[a-f0-9]{64}\z/',$c[$key])){throw new RuntimeException('directory_http_config');}}
  if(!hash_equals($c['owner'],file_get_contents($directory.'/owner'))||
    !hash_equals($c['source_sha256'],hash_file('sha256',$root.'/resources/sql/quickstart.sql'))||
    !hash_equals($c['transport'],$_SERVER['HTTP_X_PHORGE_ISOLATED_DIRECTORY']??'')) {throw new RuntimeException('directory_http_transport');}
  if(!preg_match('@\Ahttp://directory\.localhost:([1-9][0-9]{0,4})\z@D',$c['origin'],$m)||(int)$m[1]>65535||
    ($_SERVER['HTTP_HOST']??'')!=='directory.localhost:'.$m[1]){throw new RuntimeException('directory_http_origin');}
  $writer=$c['writer'];$keys=array_keys($writer);sort($keys);
  if($keys!==array('host','namespace','password','port','user')||$writer['host']!=='127.0.0.1'||
    !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/',$writer['namespace'])||
    !preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/',$writer['user'])||
    !preg_match('/\A[a-f0-9]{48}\z/',$writer['password'])||$writer['port']!==3306){throw new RuntimeException('directory_http_writer');}
  if(!preg_match('@\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z@D',$c['gorge_file_uri'])){throw new RuntimeException('directory_http_gorge');}
  require_once $root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  $proof=new mysqli('127.0.0.1',$writer['user'],$writer['password'],'',3306);
  $assertOwned=static function()use($proof,$writer,$c){
    $row=$proof->query('SELECT marker,quickstart_sha256,owner_nonce FROM `'.$writer['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||$row['marker']!=='N07.NATIVE-SOURCE.v1:synthetic-only'||
      !hash_equals($c['source_sha256'],$row['quickstart_sha256'])||!hash_equals($c['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('directory_http_owner');}};
  $assertOwned();$scope=true;
  if(PhabricatorEnv::getConfigSourceStack()!==null||(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()||AphrontWriteGuard::isGuardActive()){throw new RuntimeException('directory_http_inherited');}
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array('mysql.host'=>$writer['host'],'mysql.port'=>$writer['port'],
    'mysql.user'=>$writer['user'],'mysql.pass'=>$writer['password'],'storage.default-namespace'=>$writer['namespace'],
    'phabricator.cache-namespace'=>$writer['namespace'],'phabricator.base-uri'=>$c['origin'].'/',
    'cluster.databases'=>array(),'cluster.search'=>array(),'policy.allow-public'=>false,
    'governance.organization.enabled'=>true,'gorge.file.uri'=>$c['gorge_file_uri'],'gorge.file.token'=>$c['gorge_file_token'],
    'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'gorge.notification.outbox'=>false,'notification.servers'=>array())));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
  if(!is_string($path)||!preg_match('@\A/(?:people/organization/(?:typeahead/)?|p/[a-zA-Z0-9]+/|res/[^\r\n]*|rsrc/[^\r\n]*|typeahead/[^\r\n]*)\z@D',$path)){throw new RuntimeException('directory_http_route');}
  $configuration=(new AphrontApplicationConfiguration())->setHost($_SERVER['HTTP_HOST'])->setPath($path);
  $request=$configuration->buildRequest();
  $guard=new AphrontWriteGuard(static function()use($request,$assertOwned){$assertOwned();$request->validateCSRF();});
  ob_end_clean();require_once $root.'/support/startup/PhabricatorStartup.php';PhabricatorStartup::beginOutputCapture();
  $meter=MultimeterControl::newInstance();$meter->setSampleRate(0);
  $configuration->processRequest($request,new PhutilDeferredLog(null,''),new AphrontPHPHTTPSink(),$meter);
}catch(Throwable $error){
  while(ob_get_level()>$level){ob_end_clean();}
  if($scope){$log=$directory.'/failures.jsonl';file_put_contents($log,json_encode(array('type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()))."\n",FILE_APPEND);chmod($log,0600);}
  if(!headers_sent()){http_response_code(500);header('Content-Type: application/json');header('Cache-Control: no-store');}
  echo '{"error":"isolated_directory_unavailable"}';
}finally{if($guard!==null){$guard->dispose();}if($scope&&class_exists('LiskDAO',false)){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}restore_error_handler();}
