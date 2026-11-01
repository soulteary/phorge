<?php

// Privileged setup/restore on one owned disposable namespace only. This is
// not the read-only audit collector and is never a production application route.
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
try {
 $private=getenv('PERMISSION_ROUTES_PRIVATE');$namespace=getenv('PERMISSION_ROUTES_NAMESPACE');
 if(PHP_SAPI!=='cli'||getenv('ATOMIC_TEST_DISPOSABLE')!=='1'||!preg_match('/\Aatomic_routes_[a-f0-9]{16}\z/',$namespace?:'')||!is_string($private)){throw new Exception('scope');}
 $o=json_decode(file_get_contents($private.'/owner.json'),true);
 $root=dirname(__DIR__,2);
 if($o['namespace']!==$namespace||$o['root']!==$root||!hash_equals($o['configurationHash'],hash_file('sha256',$root.'/conf/local/local.json'))||!hash_equals($o['deploymentHash'],hash_file('sha256',$root.'/conf/local/deployment.json'))){throw new Exception('ownership');}
 $db=new mysqli('127.0.0.1','root',getenv('GORGE_TEST_MYSQL_PASSWORD'),'',(int)getenv('GORGE_TEST_MYSQL_PORT'));
 $db->set_charset('utf8mb4');$mode=$argv[1]??'';
 if($mode==='dump'){
  $path=$private.'/enriched.sql';$handle=fopen($path,'x');if(!$handle){throw new Exception('exclusive');}chmod($path,0600);
  $databases=array_column($db->query('SHOW DATABASES')->fetch_all(MYSQLI_NUM),0);
  $databases=array_values(array_filter($databases,static fn($n)=>str_starts_with($n,$namespace.'_')));sort($databases);
  if(count($databases)<20){throw new Exception('incomplete-native-schema');}
  $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
  $rows=0;$tables=0;
  foreach($databases as $database){
   if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$database)){throw new Exception('identifier');}
   fwrite($handle,'CREATE DATABASE `'.$database.'`;'."\n");
   foreach($db->query('SHOW FULL TABLES FROM `'.$database.'`')->fetch_all(MYSQLI_NUM) as $entry){
    list($table,$kind)=$entry;if($kind!=='BASE TABLE'||!preg_match('/\A[a-zA-Z0-9_]+\z/',$table)){throw new Exception('table-kind');}
    $qualified='`'.$database.'`.`'.$table.'`';$create=$db->query('SHOW CREATE TABLE '.$qualified)->fetch_row()[1];
    $create=preg_replace('/\ACREATE TABLE `'.preg_quote($table,'/').'`/','CREATE TABLE '.$qualified,$create);
    fwrite($handle,$create.';'."\n");$tables++;
    $result=$db->query('SELECT * FROM '.$qualified,MYSQLI_USE_RESULT);
    while($row=$result->fetch_row()){
     if(++$rows>100000){throw new Exception('row-budget');}
     $values=array_map(static fn($v)=>$v===null?'NULL':"'".$db->real_escape_string($v)."'",$row);
     fwrite($handle,'INSERT INTO '.$qualified.' VALUES ('.implode(',',$values).');'."\n");
     if(ftell($handle)>256*1024*1024){throw new Exception('size-budget');}
    }
    $result->free();
   }
  }
  $db->rollback();fclose($handle);
  echo json_encode(array('snapshotSHA256'=>hash_file('sha256',$path),'tables'=>$tables,'rows'=>$rows,'databases'=>count($databases)))."\n";exit;
 }
 if($mode==='restore'||$mode==='import-base'){
  $path=$private.'/'.($mode==='restore'?'enriched.sql':'base-remapped.sql');
  if(!is_file($path)||filesize($path)>256*1024*1024){throw new Exception('snapshot');}
  $sql=file_get_contents($path);
  if(!hash_equals($o[$mode==='restore'?'snapshotSHA256':'baseSnapshotSHA256'],hash('sha256',$sql))){throw new Exception('snapshot-hash');}
  // CREATE/USE database identifiers must stay in the exact owned namespace.
  preg_match_all('/^(?:CREATE DATABASE[^`]*|USE\s+)`([^`]+)`/m',$sql,$m);
  foreach($m[1] as $database){if(!str_starts_with($database,$namespace.'_')){throw new Exception('foreign-database');}}
  if(!$m[1]){throw new Exception('missing-database-scope');}
  $db->multi_query($sql);do{if($result=$db->store_result()){$result->free();}}while($db->more_results()&&$db->next_result());
  echo '{"restored":true}'."\n";exit;
 }
 if($mode==='reader'){
  $u='route_audit_'.bin2hex(random_bytes(6));$p=bin2hex(random_bytes(24));
  $db->query("CREATE USER '$u'@'%' IDENTIFIED BY '$p'");
  foreach(array('project','user','spaces','policy') as $app){$name=str_replace('_','\\_',$namespace.'_'.$app);$db->query("GRANT SELECT ON `$name`.* TO '$u'@'%'");}
  echo json_encode(array('user'=>$u,'password'=>$p))."\n";exit;
 }
 if($mode==='drop-reader'){
  $r=json_decode(file_get_contents($private.'/reader.json'),true);$u=$r['user'];
  if(!preg_match('/\Aroute_audit_[a-f0-9]{12}\z/',$u)){throw new Exception('reader');}
  $db->query("DROP USER '$u'@'%'");echo '{"removed":true}'."\n";exit;
 }
 throw new Exception('operation');
}catch(Throwable $error){fwrite(STDERR,"Disposable snapshot operation refused or failed.\n");exit(2);}
