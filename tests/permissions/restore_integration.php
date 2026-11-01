<?php

// Setup is privileged only inside explicitly disposable test MySQL. The audit
// subprocess receives a separate SELECT-only principal after dump/restoration.
if (getenv('ATOMIC_TEST_DISPOSABLE') !== '1') {
  fwrite(STDERR, "Disposable MySQL is required.\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
$db = new mysqli('127.0.0.1', 'root', getenv('GORGE_TEST_MYSQL_PASSWORD'), '', (int)getenv('GORGE_TEST_MYSQL_PORT'));
$suffix = bin2hex(random_bytes(6));
$source = 'audit_source_'.$suffix;
$restore = 'audit_restore_'.$suffix;
$user = 'audit_'.$suffix;
$password = bin2hex(random_bytes(20));
$temporary = sys_get_temp_dir().'/permissions-'.$suffix;
mkdir($temporary, 0700);
$databases = array();
$checks = array();
$integration_failed = false;
function verify($name, $value) {
  global $checks;
  $checks[$name] = (bool)$value;
  if (!$value) { throw new Exception($name); }
}
function process(array $command, $input) {
  $pipes = array();
  $handle = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
  fwrite($pipes[0], $input); fclose($pipes[0]);
  $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
  return array(proc_close($handle), $output);
}
function fingerprint($db, $namespace) {
  $values = array();
  foreach (array('project' => array('project', 'edge'), 'user' => array('user'), 'spaces' => array('spaces_namespace'), 'policy' => array('policy')) as $app => $tables) {
    foreach ($tables as $table) {
      $rows = $db->query('SELECT * FROM `'.$namespace.'_'.$app.'`.`'.$table.'`')->fetch_all(MYSQLI_ASSOC);
      usort($rows, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
      $values[$app.'.'.$table] = $rows;
    }
  }
  return hash('sha256', json_encode($values));
}
try {
  $schemas = array('project'=>array('project'=>null,'edge'=>null), 'user'=>array('user'=>null),
    'spaces'=>array('spaces_namespace'=>null), 'policy'=>array('policy'=>null));
  $quickstart=file_get_contents($root.'/resources/sql/quickstart.sql');
  foreach ($schemas as $app => $tables) {
    $database = $source.'_'.$app; $databases[] = $database;
    $db->query('CREATE DATABASE `'.$database.'`');
    foreach ($tables as $table => $_) {
      $pattern='/USE `\{\$NAMESPACE\}_'.preg_quote($app,'/').'`;\s*(CREATE TABLE `'.preg_quote($table,'/').'` \(.*?\) ENGINE=[^;]+;)/s';
      if (!preg_match($pattern,$quickstart,$match)) {throw new Exception('native-schema-not-found');}
      $ddl=strtr($match[1],array('{$CHARSET}'=>'utf8mb4','{$CHARSET_SORT}'=>'utf8mb4','{$CHARSET_FULLTEXT}'=>'utf8mb4','{$COLLATE_TEXT}'=>'utf8mb4_bin','{$COLLATE_SORT}'=>'utf8mb4_general_ci','{$COLLATE_FULLTEXT}'=>'utf8mb4_general_ci'));
      $ddl=preg_replace('/\ACREATE TABLE `'.preg_quote($table,'/').'`/', 'CREATE TABLE `'.$database.'`.`'.$table.'`',$ddl);
      $db->query($ddl);
    }
  }
  verify('uses-native-quickstart-table-definitions',true);
  $seed=static function($app,$table,$values) use ($db,$source) {
    $name='`'.$source.'_'.$app.'`.`'.$table.'`';$row=array();
    foreach($db->query('SHOW COLUMNS FROM '.$name)->fetch_all(MYSQLI_ASSOC) as $column) {
      $field=$column['Field'];
      if(array_key_exists($field,$values)){$row[$field]=$values[$field];}
      else if($column['Default']!==null){$row[$field]=$column['Default'];}
      else if($column['Null']==='YES'){$row[$field]=null;}
      else if(preg_match('/int|decimal|double|float/',$column['Type'])){$row[$field]=0;}
      else{$row[$field]=(string)($values['id']??'fixture');}
    }
    $quoted=array_map(static fn($v)=>$v===null?'NULL':"'".$db->real_escape_string((string)$v)."'",array_values($row));
    $db->query('INSERT INTO '.$name.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',$quoted).')');
  };
  $a = 'PHID-USER-aaaaaaaaaaaaaaaaaaaa'; $b = 'PHID-USER-bbbbbbbbbbbbbbbbbbbb';
  $seed('user','user',array('id'=>1,'phid'=>$a,'isAdmin'=>1,'isDisabled'=>0,'isApproved'=>1));
  $seed('user','user',array('id'=>2,'phid'=>$b,'isAdmin'=>0,'isDisabled'=>0,'isApproved'=>1));
  foreach(array(array(1,'p','no-one','users',null,null,1),array(2,'c','public','no-one','p',null,0),array(3,'m','public','users','c',1,0)) as $p){
    $seed('project','project',array('id'=>$p[0],'phid'=>$p[1],'viewPolicy'=>$p[2],'editPolicy'=>$p[3],'joinPolicy'=>'users','parentProjectPHID'=>$p[4],'milestoneNumber'=>$p[5],'hasSubprojects'=>$p[6],'isMembershipLocked'=>0,'spacePHID'=>null));
  }
  foreach(array(array('p',13,$b),array('c',13,$a),array('p',60,$a),array('c',60,$a)) as $edge){
    $seed('project','edge',array('src'=>$edge[0],'type'=>$edge[1],'dst'=>$edge[2]));
  }
  $before = fingerprint($db, $source);
  // Real logical dump/restore, with private contents kept out of artifacts.
  $dump = '';
  foreach ($schemas as $app => $tables) {
    $database = $restore.'_'.$app; $databases[] = $database;
    $dump .= 'CREATE DATABASE `'.$database.'`;'."\n";
    foreach ($tables as $table => $_) {
      $create = $db->query('SHOW CREATE TABLE `'.$source.'_'.$app.'`.`'.$table.'`')->fetch_row()[1];
      $dump .= preg_replace('/\ACREATE TABLE `'.preg_quote($table, '/').'`/', 'CREATE TABLE `'.$database.'`.`'.$table.'`', $create).';'."\n";
      foreach ($db->query('SELECT * FROM `'.$source.'_'.$app.'`.`'.$table.'`')->fetch_all(MYSQLI_NUM) as $row) {
        $values = array_map(static fn($v) => $v === null ? 'NULL' : "'".$db->real_escape_string($v)."'", $row);
        $dump .= 'INSERT INTO `'.$database.'`.`'.$table.'` VALUES ('.implode(',', $values).');'."\n";
      }
    }
  }
  $db->multi_query($dump);
  do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
  verify('logical-snapshot-restored-identically', fingerprint($db, $restore) === $before);
  $db->query("CREATE USER '$user'@'%' IDENTIFIED BY '$password'");
  foreach ($schemas as $app => $_) { $db->query("GRANT SELECT ON `{$restore}_{$app}`.* TO '$user'@'%'"); }
  $reader = new mysqli('127.0.0.1', $user, $password, '', (int)getenv('GORGE_TEST_MYSQL_PORT'));
  foreach (array('INSERT INTO', 'UPDATE', 'DELETE FROM', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE TABLE') as $operation) {
    $table = '`'.$restore.'_project`.`edge`';
    $sql = match($operation) {
      'INSERT INTO' => "INSERT INTO $table (src,type,dst,dateCreated,seq) VALUES ('x',13,'y',0,0)",
      'UPDATE' => "UPDATE $table SET dst=dst",
      'DELETE FROM' => "DELETE FROM $table WHERE 1=0",
      'ALTER TABLE' => "ALTER TABLE $table ADD COLUMN forbidden INT",
      default => "$operation $table",
    };
    $denied = false;
    try { $reader->query($sql); } catch (mysqli_sql_exception $error) { $denied = in_array($error->getCode(), array(1142, 1044), true); }
    verify('reader-denies-'.$operation, $denied);
  }
  $config = array('restoredSnapshot' => true, 'host' => '127.0.0.1', 'port' => (int)getenv('GORGE_TEST_MYSQL_PORT'),
    'user' => $user, 'password' => $password, 'namespace' => $restore,
    'snapshotSHA256' => hash('sha256', $dump), 'snapshotWatermark' => '2026-11-01T00:00:00Z',
    'sourceCommit' => str_repeat('a',40), 'allowPublic' => true, 'pageSize' => 1);
  list($status, $json) = process(array('php', $root.'/scripts/permissions/collect.php'), json_encode($config));
  $snapshot = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
  verify('select-only-extraction-and-composite-cursors', $status === 0 && $snapshot['complete'] && $snapshot['pageQueries'] > 5);
  $snapshot_file = $temporary.'/snapshot.json'; file_put_contents($snapshot_file, $json); chmod($snapshot_file,0600);
  $key_file = $temporary.'/key.json'; file_put_contents($key_file,json_encode(array('hmacKey'=>bin2hex(random_bytes(32))))); chmod($key_file,0600);
  $run = static function($candidate, $directory) use ($root,$snapshot_file,$key_file) {
    return process(array('python3',$root.'/scripts/permissions/audit.py','--snapshot',$snapshot_file,'--key-file',$key_file,'--candidate',$candidate,'--output',$directory),'');
  };
  foreach (array('independent-view-v1', 'explicit-edit-v1', 'self-descendants-v1', 'direct-members-v1', 'combined-v1') as $model) {
    $directory=$temporary.'/'.$model;
    list($status, $_) = $run($model,$directory);
    verify('native-rule-model-'.$model,$status===0 && is_file($directory.'/summary.json'));
  }
  $summary=json_decode(file_get_contents($temporary.'/self-descendants-v1/summary.json'),true);
  verify('parent-direct-member-missing-old-index-reported',$summary['counts']['member-index']['newly-granted']>0);
  verify('ambiguous-old-edges-preserved',$summary['ambiguousHistoricalDirectEdges']===2 && $summary['writesPerformed']===0);
  verify('unverified-routes-block-migration',$summary['migrationReady']===false && count(array_unique(array_values($summary['routeVerification'])))===1);
  list($status,$_)= $run('self-descendants-v1',$temporary.'/repeat');
  verify('repeat-byte-stable',$status===0 && file_get_contents($temporary.'/repeat/summary.json')===file_get_contents($temporary.'/self-descendants-v1/summary.json') && file_get_contents($temporary.'/repeat/restricted-review.json')===file_get_contents($temporary.'/self-descendants-v1/restricted-review.json'));
  $published=file_get_contents($temporary.'/self-descendants-v1/summary.json').file_get_contents($temporary.'/self-descendants-v1/restricted-review.json');
  verify('shareable-output-redacted',!str_contains($published,$a) && !str_contains($published,$b) && !str_contains($published,$password) && !str_contains($published,$restore));
  foreach (array('maxRows'=>1,'namespace'=>'bad;SELECT','host'=>'192.0.2.1') as $key=>$value) {
    list($status,$failed)=process(array('php',$root.'/scripts/permissions/collect.php'),json_encode(array_replace($config,array($key=>$value))));
    verify('collector-refuses-'.$key,$status===2 && json_decode($failed,true)['complete']===false);
  }
  list($status,$failed)=process(array('php',$root.'/scripts/permissions/collect.php'),json_encode(array_replace($config,array('user'=>'root','password'=>getenv('GORGE_TEST_MYSQL_PASSWORD')))));
  verify('privileged-principal-refused',$status===2);
  $db->query("GRANT SELECT ON *.* TO '$user'@'%'");
  list($status,$denied_global)=process(array('php',$root.'/scripts/permissions/collect.php'),json_encode($config));
  verify('global-read-privilege-refused',$status===2);
  $db->query("REVOKE SELECT ON *.* FROM '$user'@'%'");
  $snapshot['projects'][1]['viewPolicy']='PHID-PLCY-private';file_put_contents($snapshot_file,json_encode($snapshot));
  list($status,$_)= $run('combined-v1',$temporary.'/unknown');
  $unknown=json_decode(file_get_contents($temporary.'/unknown/summary.json'),true);
  verify('unknown-rule-nonzero-incomplete',$status===2 && !$unknown['evaluationComplete']);
  verify('restored-data-unchanged-after-all-audits',fingerprint($db,$restore)===$before);
  echo json_encode(array('passed'=>true,'checks'=>$checks,'checkCount'=>count($checks),'restoredSnapshot'=>true,'readOnlyPrincipal'=>true,'writesByAuditor'=>0))."\n";
} catch (Throwable $error) {
  fwrite(STDERR, "Read-only restored-snapshot integration failed.\n");
  echo json_encode(array('passed'=>false,'checks'=>$checks))."\n";
  $integration_failed=true;
} finally {
  foreach ($databases as $database) { $db->query('DROP DATABASE IF EXISTS `'.$database.'`'); }
  $db->query("DROP USER IF EXISTS '$user'@'%'");
  $remove=static function($path) use (&$remove) { if(is_dir($path)){foreach(scandir($path) as $item){if($item!=='.'&&$item!=='..'){$remove($path.'/'.$item);}}rmdir($path);}else{unlink($path);} };
  $remove($temporary);
}
if ($integration_failed) { exit(1); }
