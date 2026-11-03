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
  if(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||($_SERVER['REQUEST_METHOD']??'')!=='POST'||
    ($_SERVER['REQUEST_URI']??'')!=='/api/mail.delivery'||!is_string($_SERVER['HTTP_X_SERVICE_TOKEN']??null)||
    !hash_equals($data['service_token'],$_SERVER['HTTP_X_SERVICE_TOKEN'])){throw new RuntimeException('private_mail_route_refused');}
  $expected=parse_url($data['callback_origin'],PHP_URL_HOST).':'.parse_url($data['callback_origin'],PHP_URL_PORT);
  if(($_SERVER['HTTP_HOST']??'')!==$expected){throw new RuntimeException('private_mail_host_refused');}
  require_once$root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  if(!hash_equals($data['source_sha256'],hash_file('sha256',$root.'/resources/sql/quickstart.sql'))){throw new RuntimeException('private_mail_source_refused');}
  $proof=governance_private_mail_proof($data);governance_private_mail_install_env($data);$scope=true;
  $console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
  $configuration=(new AphrontApplicationConfiguration())->setHost($expected)->setPath('/api/mail.delivery');
  $request=$configuration->buildRequest();$guard=new AphrontWriteGuard(static function()use($proof,$data){
    $row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||!hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('private_mail_owner_changed');}});
  if(ob_get_length()!==0){throw new RuntimeException('private_mail_unexpected_output');}ob_end_clean();
  require_once$root.'/support/startup/PhabricatorStartup.php';PhabricatorStartup::beginOutputCapture();
  $meter=MultimeterControl::newInstance();$meter->setSampleRate(0);
  $configuration->processRequest($request,new PhutilDeferredLog(null,''),new AphrontPHPHTTPSink(),$meter);
}catch(Throwable$error){while(ob_get_level()>$level){ob_end_clean();}
  if(!headers_sent()){http_response_code(500);header('Content-Type: application/json');header('Cache-Control: no-store');}
  echo '{"error":"private_mail_fixture_unavailable"}';
}finally{if($guard!==null){$guard->dispose();}if($scope){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}restore_error_handler();}
