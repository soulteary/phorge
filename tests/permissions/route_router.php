<?php

$root=dirname(__DIR__,2);$private=getenv('PERMISSION_ROUTES_PRIVATE');
if(PHP_SAPI!=='cli-server'||getenv('ATOMIC_TEST_DISPOSABLE')!=='1'||!is_string($private)||!is_file($private.'/owner.json')){http_response_code(404);return;}
$o=json_decode(file_get_contents($private.'/owner.json'),true);
if(getenv('PHORGE_DEPLOYMENT_CONFIG')!==$root.'/conf/local/deployment.json'||getenv('PHORGE_CONTROL_PLANE')!=='deployment'||$o['root']!==$root||!hash_equals($o['configurationHash'],hash_file('sha256',$root.'/conf/local/local.json'))||!hash_equals($o['deploymentHash'],hash_file('sha256',$root.'/conf/local/deployment.json'))){http_response_code(503);return;}
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($uri,'/__route/')){
 header('Cache-Control: private, no-store');
 if(!is_string($_GET['key']??null)||!hash_equals($o['key'],$_GET['key'])){http_response_code(404);return;}
 if($uri==='/__route/ready'){header('Content-Type: application/json');echo json_encode(array('ready'=>true,'php'=>PHP_VERSION,'jit'=>ini_get('opcache.jit')));return;}
 if(preg_match('@\A/__route/session/([a-zA-Z]+)/\z@',$uri,$m)){
  $f=json_decode(file_get_contents($private.'/fixture.json'),true);$u=$f['users'][$m[1]]??null;
  if(!$u){http_response_code(404);return;}
  setcookie('phsid',$u['session'],array('path'=>'/','httponly'=>true,'samesite'=>'Lax'));
  setcookie('phusr',$u['username'],array('path'=>'/','httponly'=>true,'samesite'=>'Lax'));
  header('Content-Type: application/json');echo '{"sessionSelected":true}';return;
 }
 http_response_code(404);return;
}
require $root.'/webroot/index.php';
