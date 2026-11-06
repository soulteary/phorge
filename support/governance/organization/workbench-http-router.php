<?php
/** Basic HTTP test router only. No issuer, TLS claim, session injection or production front controller. */
$root=dirname(__DIR__,3);$guard=null;$proof=null;$scope=false;$phase='preflight';$level=ob_get_level();ob_start();ini_set('display_errors','0');
$old=set_error_handler(static function($severity,$message,$file,$line){if(!(error_reporting()&$severity)){return false;}throw new ErrorException('owned_workbench_http_runtime_error',0,$severity,$file,$line);});
$diagnostic=static function($error,$stage){error_log(json_encode(array('scope'=>'owned_workbench_http_failure','phase'=>$stage,'type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()),JSON_THROW_ON_ERROR));};
try {
  require_once __DIR__.'/workbench-http-common.php';require_once $root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  governance_workbench_http_need(PHP_SAPI==='cli-server'&&getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')==='1');
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$value=getenv($key);governance_workbench_http_need($value===false||$value==='');}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$file){governance_workbench_http_need(!file_exists($root.'/conf/local/'.$file)&&!is_link($root.'/conf/local/'.$file));}
  $config=governance_workbench_http_config(getenv('PHORGE_GOVERNANCE_WORKBENCH_HTTP_CONFIG'),getenv('PHORGE_GOVERNANCE_WORKBENCH_HTTP_CONFIG_SHA256'),hash_file('sha256',$root.'/resources/sql/quickstart.sql'));
  governance_workbench_http_need(($_SERVER['REMOTE_ADDR']??null)==='127.0.0.1');$host=$_SERVER['HTTP_HOST']??null;$path=governance_workbench_http_route($host,$_SERVER['REQUEST_URI']??null,$config['origin']);
  require_once $root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');$console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
  $writer=$config['writer'];mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$proof=new mysqli($writer['host'],$writer['user'],$writer['password'],'',$writer['port']);$proof->set_charset('utf8mb4');
  $seen_guard=false;$seen_user=false;
  foreach($proof->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM)as$entry){$grant=$entry[0];governance_workbench_http_need(strpos($grant,'WITH GRANT OPTION')===false);
    if(preg_match('/\AGRANT USAGE ON \*\.\* TO /',$grant)){continue;}
    governance_workbench_http_need(preg_match('/\AGRANT (.+) ON `([^`]+)`\.\* TO /',$grant,$m)===1);$db=str_replace('\\_','_',$m[2]);
    if($db===$writer['namespace'].'_governance'){governance_workbench_http_need($m[1]==='SELECT');$seen_guard=true;}
    else{governance_workbench_http_need(str_starts_with($db,$writer['namespace'].'_')&&$m[1]==='SELECT, INSERT, UPDATE, DELETE');if($db===$writer['namespace'].'_user'){$seen_user=true;}}
  }
  governance_workbench_http_need($seen_guard&&$seen_user);
  $assert_owned=static function()use($proof,$writer,$config){$row=$proof->query('SELECT marker,quickstart_sha256,owner_nonce FROM `'.$writer['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();governance_workbench_http_need(is_array($row)&&$row['marker']==='N07.NATIVE-SOURCE.v1:synthetic-only'&&hash_equals($config['quickstart_sha256'],$row['quickstart_sha256'])&&hash_equals($config['fixture_nonce'],$row['owner_nonce']));};$assert_owned();
  governance_workbench_http_need(PhabricatorEnv::getConfigSourceStack()===null&&!(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()&&!AphrontWriteGuard::isGuardActive());$scope=true;$phase='native-config';
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host'=>$writer['host'],'mysql.port'=>$writer['port'],'mysql.user'=>$writer['user'],'mysql.pass'=>$writer['password'],'storage.default-namespace'=>$writer['namespace'],'phabricator.cache-namespace'=>$writer['namespace'],
    'cluster.databases'=>array(),'cluster.search'=>array(),'phabricator.base-uri'=>$config['origin'].'/','phabricator.production-uri'=>$config['origin'].'/','security.require-https'=>false,'policy.allow-public'=>false,
    'governance.organization.enabled'=>true,'governance.media-exchange.enabled'=>false,'governance.media-exchange.http-enabled'=>false,'governance.account-recovery.enabled'=>false,'governance.notifications.enabled'=>false,
    'gorge.file.uri'=>$config['file_service']['uri'],'gorge.file.token'=>$config['file_service']['token'],'gorge.image.uri'=>null,'gorge.render.uri'=>null,'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'gorge.notification.outbox'=>false,'gorge.db.uri'=>null,'notification.servers'=>array(),'phabricator.silent'=>true,
    'projects.default-navigation'=>'flat','project.history-state'=>'off','project.workspace-mode'=>'native','project.history-task-drafts'=>false,'gorge.search.source-scan'=>false,'gorge.search.projection-shadow'=>false)));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,null);
  foreach(array('HTTP_FORWARDED','HTTP_X_FORWARDED_PROTO','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_FOR','HTTPS')as$key){unset($_SERVER[$key]);}
  $phase='native-request';$configuration=(new AphrontApplicationConfiguration())->setHost($host)->setPath($path);$request=$configuration->buildRequest();$guard=new AphrontWriteGuard(static function()use($request,$assert_owned){$assert_owned();$request->validateCSRF();});
  governance_workbench_http_need(ob_get_length()===0);ob_end_clean();require_once $root.'/support/startup/PhabricatorStartup.php';PhabricatorStartup::beginOutputCapture();$log=new PhutilDeferredLog(null,'');$meter=MultimeterControl::newInstance();$meter->setSampleRate(0);$phase='native-process';
  $configuration->processRequest($request,$log,new AphrontPHPHTTPSink(),$meter);
}catch(Throwable$error){while(ob_get_level()>$level){ob_end_clean();}$diagnostic($error,$phase);if(!headers_sent()){http_response_code(500);header('Content-Type: application/json');header('Cache-Control: no-store');}echo '{"error":"owned_workbench_http_unavailable"}';}
finally{
  try{if($guard!==null){$guard->dispose();}if($scope&&class_exists('LiskDAO',false)){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}}
  catch(Throwable$error){$diagnostic($error,'cleanup-unproved');}restore_error_handler();
}
