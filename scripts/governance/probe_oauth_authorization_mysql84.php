<?php
/** Fixed CI-only read of the same disposable loopback endpoint as the fixture.
 * No application initialization, private files, mutation, retry or arbitrary SQL. */
const OAUTH_AUTHORIZATION_MYSQL84_IMAGE = 'mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7';
function oauth_authorization_mysql84_projection($row,$phase,$revision,$image) {
  if(!in_array($phase,array('before','after'),true)||!is_string($revision)||preg_match('/\A[a-f0-9]{40}\z/D',$revision)!==1||$image!==OAUTH_AUTHORIZATION_MYSQL84_IMAGE){throw new RuntimeException('arguments');}
  if(!is_array($row)){throw new RuntimeException('shape');}$keys=array_keys($row);sort($keys,SORT_STRING);
  if($keys!==array('server_uuid','version')||!is_string($row['server_uuid'])||preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',$row['server_uuid'])!==1){throw new RuntimeException('shape');}
  if(($row['version']??null)!=='8.4.13'){throw new RuntimeException('version');}
  return array('schema'=>'native-oauth-authorization-mysql-runtime-v1','phase'=>$phase,'source_revision'=>$revision,
    'version'=>$row['version'],'server_uuid_sha256'=>hash('sha256',$row['server_uuid']),'observed_service_image'=>$image);
}
function oauth_authorization_mysql84_main(array$args) {
  try {
    if(count($args)!==3||!in_array($args[1],array('before','after'),true)||preg_match('/\A[a-f0-9]{40}\z/D',$args[2])!==1){throw new RuntimeException('arguments');}
    $image=getenv('AUTHZ_MYSQL_OBSERVED_IMAGE');if($image!==OAUTH_AUTHORIZATION_MYSQL84_IMAGE){throw new RuntimeException('arguments');}
    $password=getenv('GORGE_TEST_MYSQL_PASSWORD');if(!is_string($password)||strlen($password)<1||strlen($password)>128){throw new RuntimeException('arguments');}
    $db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,3);$db->options(MYSQLI_OPT_READ_TIMEOUT,3);
    if(!$db->real_connect('127.0.0.1','root',$password,null,3306)){throw new RuntimeException('unavailable');}
    $result=$db->query('SELECT VERSION() AS version,@@server_uuid AS server_uuid');
    if(!$result||$result->num_rows!==1){throw new RuntimeException('shape');}
    $value=oauth_authorization_mysql84_projection($result->fetch_assoc(),$args[1],$args[2],$image);
    $result->free();$db->close();echo json_encode($value,JSON_THROW_ON_ERROR)."\n";return 0;
  }catch(Throwable$error){
    $reason=$error instanceof RuntimeException&&in_array($error->getMessage(),array('arguments','shape','version'),true)?$error->getMessage():'unavailable';
    fwrite(STDERR,json_encode(array('schema'=>'native-oauth-authorization-mysql-runtime-failure-v1','reason'=>$reason))."\n");return 1;
  }
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){exit(oauth_authorization_mysql84_main($argv));}
