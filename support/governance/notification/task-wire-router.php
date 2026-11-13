<?php
/** Owned native Conduit driver only. No user login or arbitrary methods. */
$root=dirname(__DIR__,3);$proof=null;$guard=null;$scope=false;$level=ob_get_level();ob_start();
set_error_handler(static function($severity,$message,$file,$line){if(!(error_reporting()&$severity)){return false;}
  throw new ErrorException('private_mail_fixture_runtime_error',0,$severity,$file,$line);});
try{
  require_once$root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli-server'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){throw new RuntimeException('private_mail_http_scope_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$v=getenv($key);if($v!==false&&$v!==''){throw new RuntimeException('private_mail_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('private_mail_deployment_refused');}}
  require_once __DIR__.'/owned-config.php';$data=governance_private_mail_config(getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR'));
  $browser_request=!in_array(($_SERVER['REQUEST_URI']??''),array('/api/mail.delivery','/api/worker.execute'),true);
  if($browser_request){
    if(getenv('PHORGE_GOVERNANCE_TASK_BROWSER_MODE')!=='1'||($_SERVER['REMOTE_ADDR']??null)!=='127.0.0.1'||isset($_SERVER['HTTP_X_SERVICE_TOKEN'])){throw new RuntimeException('task_browser_route_refused');}
    require_once __DIR__.'/task-wire-browser.php';$browser=governance_task_browser_manifest(getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR'),$data);
    $browser_path=governance_task_browser_route($_SERVER['HTTP_HOST']??null,$_SERVER['REQUEST_URI']??null,$data['callback_origin'],$_SERVER['REQUEST_METHOD']??null,$browser);
  }elseif(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||($_SERVER['REQUEST_METHOD']??'')!=='POST'||
    !is_string($_SERVER['HTTP_X_SERVICE_TOKEN']??null)||!hash_equals($data['service_token'],$_SERVER['HTTP_X_SERVICE_TOKEN'])){throw new RuntimeException('private_mail_route_refused');}
  $expected=parse_url($data['callback_origin'],PHP_URL_HOST).':'.parse_url($data['callback_origin'],PHP_URL_PORT);
  if(($_SERVER['HTTP_HOST']??'')!==$expected){throw new RuntimeException('private_mail_host_refused');}
  if($_SERVER['REQUEST_URI']==='/api/worker.execute'){
    $params=json_decode($_POST['params']??'',true,16,JSON_THROW_ON_ERROR);
    if(!is_array($params)||!(($params['phase']??null)==='capabilities'&&($params['taskClass']??null)===''||
      in_array($params['phase']??null,array('prepare','execute'),true)&&
      ($params['taskClass']??null)==='PhabricatorApplicationTransactionPublishWorker')){
      throw new RuntimeException('task_wire_worker_class_refused');}
  }
  require_once$root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  if(!hash_equals($data['source_sha256'],hash_file('sha256',$root.'/resources/sql/quickstart.sql'))){throw new RuntimeException('private_mail_source_refused');}
  $proof=governance_private_mail_proof($data);governance_private_mail_install_env($data);$scope=true;
  require_once __DIR__.'/business-queue-config.php';$task_scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('metamta.one-mail-per-recipient',true)->overrideEnvConfig('metamta.default-address','void@example.invalid');
  $queue_scope=governance_private_business_queue_scope(getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR'),$data['owner']);
  if(($_SERVER['REQUEST_URI']??'')==='/api/worker.execute'&&($params['phase']??null)!=='capabilities'){
    $ready_path=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR').'/ready.json';
    if(!is_file($ready_path)||is_link($ready_path)||(fileperms($ready_path)&0777)!==0600||filesize($ready_path)>16384){throw new RuntimeException('task_wire_ready_binding_refused');}
    $ready=json_decode(file_get_contents($ready_path),true,8,JSON_THROW_ON_ERROR);
    if(($ready['owner']??null)!==$data['owner']||($params['taskID']??null)!==($ready['publish_task_id']??null)){
      throw new RuntimeException('task_wire_owned_publish_id_required');}
  }
  $console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
  if($browser_request){foreach(array('HTTP_FORWARDED','HTTP_X_FORWARDED_PROTO','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_FOR','HTTPS')as$key){unset($_SERVER[$key]);}}
  $configuration=(new AphrontApplicationConfiguration())->setHost($expected)->setPath($browser_request?$browser_path:$_SERVER['REQUEST_URI']);
  $request=$configuration->buildRequest();$guard=new AphrontWriteGuard(static function()use($proof,$data,$request,$browser_request){
    $row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||!hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('private_mail_owner_changed');}
    if($browser_request){$request->validateCSRF();}});
  if(ob_get_length()!==0){throw new RuntimeException('private_mail_unexpected_output');}ob_end_clean();
  require_once$root.'/support/startup/PhabricatorStartup.php';PhabricatorStartup::beginOutputCapture();
  $meter=MultimeterControl::newInstance();$meter->setSampleRate(0);
  $configuration->processRequest($request,new PhutilDeferredLog(null,''),new AphrontPHPHTTPSink(),$meter);
}catch(Throwable$error){while(ob_get_level()>$level){ob_end_clean();}
  if(!headers_sent()){http_response_code(500);header('Content-Type: application/json');header('Cache-Control: no-store');}
  echo '{"error":"private_mail_fixture_unavailable"}';
}finally{if($guard!==null){$guard->dispose();}if($scope){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}restore_error_handler();}
