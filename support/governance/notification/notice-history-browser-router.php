<?php
/** Owned native application browser entry; service callbacks retain their original router. */
$uri=$_SERVER['REQUEST_URI']??null;
if(in_array($uri,array('/api/mail.delivery','/api/worker.execute'),true)){
  require __DIR__.'/notice-wire-router.php';return;
}
$root=dirname(__DIR__,3);$proof=null;$guard=null;$installed=false;$level=ob_get_level();ob_start();ini_set('display_errors','0');
set_error_handler(static function($severity,$message,$file,$line){if(!(error_reporting()&$severity)){return false;}throw new ErrorException('notice_history_runtime_error',0,$severity,$file,$line);});
try{
  require_once __DIR__.'/notice-history-browser.php';require_once $root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  governance_notice_history_need(PHP_SAPI==='cli-server'&&getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')==='1');
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$v=getenv($key);governance_notice_history_need($v===false||$v==='');}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){governance_notice_history_need(!file_exists($root.'/conf/local/'.$name)&&!is_link($root.'/conf/local/'.$name));}
  require_once __DIR__.'/notice-wire-config.php';$config=governance_notice_wire_config(getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR'));$data=$config['base'];
  governance_notice_history_manifest($config['directory'],$data);
  governance_notice_history_need(($_SERVER['REMOTE_ADDR']??null)==='127.0.0.1');
  $path=governance_notice_history_route($_SERVER['HTTP_HOST']??null,$uri,$data['callback_origin'],$_SERVER['REQUEST_METHOD']??null);
  // Browser requests must not gain the service-token callback authority.
  governance_notice_history_need(!isset($_SERVER['HTTP_X_SERVICE_TOKEN']));
  require_once $root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  governance_notice_history_need(hash_equals($data['source_sha256'],hash_file('sha256',$root.'/resources/sql/quickstart.sql')));
  $proof=governance_private_mail_proof($data);governance_notice_wire_install_env($config);$installed=true;
  $console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
  foreach(array('HTTP_FORWARDED','HTTP_X_FORWARDED_PROTO','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_FOR','HTTPS')as$key){unset($_SERVER[$key]);}
  $application=(new AphrontApplicationConfiguration())->setHost($_SERVER['HTTP_HOST'])->setPath($path);
  $request=$application->buildRequest();
  $guard=new AphrontWriteGuard(static function()use($proof,$data,$request){
    $row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    governance_notice_history_need(is_array($row)&&hash_equals($data['fixture_nonce'],$row['owner_nonce']));$request->validateCSRF();
  });
  governance_notice_history_need(ob_get_length()===0);ob_end_clean();
  require_once $root.'/support/startup/PhabricatorStartup.php';PhabricatorStartup::beginOutputCapture();
  $meter=MultimeterControl::newInstance();$meter->setSampleRate(0);
  $application->processRequest($request,new PhutilDeferredLog(null,''),new AphrontPHPHTTPSink(),$meter);
}catch(Throwable$error){
  while(ob_get_level()>$level){ob_end_clean();}
  error_log(json_encode(array('scope'=>'notice-history-browser','type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()),JSON_THROW_ON_ERROR));
  if(!headers_sent()){http_response_code(500);header('Content-Type: application/json');header('Cache-Control: no-store');}
  echo '{"error":"notice_history_unavailable"}';
}finally{
  if($guard!==null){$guard->dispose();}if($installed){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}restore_error_handler();
}
