<?php
/** Private loopback fixture router. Never registered as a production route. */
$root=dirname(__DIR__,3);$proof=null;$guard=null;$installed=false;$level=ob_get_level();ob_start();
try{
  require_once$root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli-server'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){
    throw new RuntimeException('measurement_http_scope_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){
    $v=getenv($key);if($v!==false&&$v!==''){throw new RuntimeException('measurement_http_scope_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){
    if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('measurement_http_scope_refused');}}
  require_once dirname(__DIR__).'/notification/owned-config.php';
  $data=governance_private_mail_config(getenv('PHORGE_GOVERNANCE_MEASUREMENT_HTTP_DIR'));
  $host=parse_url($data['callback_origin'],PHP_URL_HOST).':'.parse_url($data['callback_origin'],PHP_URL_PORT);
  if(($_SERVER['REMOTE_ADDR']??null)!=='127.0.0.1'||($_SERVER['HTTP_HOST']??null)!==$host||
    isset($_SERVER['HTTP_COOKIE'])||isset($_SERVER['HTTP_AUTHORIZATION'])||
    isset($_SERVER['HTTP_X_FORWARDED_FOR'])||isset($_SERVER['HTTP_FORWARDED'])){
    throw new RuntimeException('measurement_http_request_refused');}
  $path=$_SERVER['REQUEST_URI']??'';$method=$_SERVER['REQUEST_METHOD']??'';
  if(!(($path==='/measurement-ready'&&$method==='GET')||
    ($path==='/api/user.whoami'&&$method==='POST'&&($_SERVER['CONTENT_TYPE']??'')==='application/x-www-form-urlencoded'&&
      (int)($_SERVER['CONTENT_LENGTH']??0)>0&&(int)$_SERVER['CONTENT_LENGTH']<=4096))){
    throw new RuntimeException('measurement_http_request_refused');}
  require_once$root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  $proof=governance_private_mail_proof($data);governance_private_mail_install_env($data);$installed=true;
  $guard=new AphrontWriteGuard(static function()use($proof,$data){
    $row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||!hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('measurement_http_owner_changed');}});
  if(ob_get_length()!==0){throw new RuntimeException('measurement_http_unexpected_output');}ob_end_clean();
  if($path==='/measurement-ready'){
    $boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
    $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
    if(!preg_match('/\A[a-f0-9-]{36}\z/',$boot)||!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
      throw new RuntimeException('measurement_http_clock_source_refused');}
    header('Content-Type: application/json');echo json_encode(array('ready'=>true,
      'boot_id_sha256'=>hash('sha256',$boot),'router_sha256'=>hash_file('sha256',__FILE__),'source_revision'=>$revision));
  }else{
    // Full native route/controller/authentication/response path. No reflection,
    // synthetic viewer, private service credential, or direct method call.
    $console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
    $configuration=(new AphrontApplicationConfiguration())->setHost($host)->setPath('/api/user.whoami');
    $request=$configuration->buildRequest();
    require_once$root.'/support/startup/PhabricatorStartup.php';PhabricatorStartup::beginOutputCapture();
    $meter=MultimeterControl::newInstance();$meter->setSampleRate(0);
    $configuration->processRequest($request,new PhutilDeferredLog(null,''),new AphrontPHPHTTPSink(),$meter);
  }
}catch(Throwable$error){
  while(ob_get_level()>$level){ob_end_clean();}
  if(!headers_sent()){http_response_code(500);header('Content-Type: application/json');}
  echo '{"fixture_error":true}';
}finally{
  if($guard!==null){$guard->dispose();}if($installed){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}
}
