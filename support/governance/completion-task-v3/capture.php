<?php
/** Read-only actual current Task additive schema. No migration or raw user rows. */
const N05_COMPLETION_PROFILE_SHA256=null;
final class PhorgeCompletionSchemaCaptureV3 {
  const SQL='resources/sql/autopatches/20261112.task.contentgrants.sql';
  const SQL_SHA='632b4412c18ea61787ca6b38afc9d3c16b62f76591e9a99d624401eeac117236';
  const SPEC='src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php';
  const SPEC_SHA='7cac65fe600e2681eed05618fd584a02f37e27b7f532d6ca2b59244bf5045ccb';
  const SELF_PATH='support/governance/completion-task-v3/capture.php';
  private static function need($ok) { if ($ok!==true) throw new RuntimeException('Task_additive_schema_capture_refused'); }
  private static function canonical($value) {
    if (is_array($value)) {
      if (!array_is_list($value)) { ksort($value,SORT_STRING); }
      foreach ($value as $key=>$item) { $value[$key]=self::canonical($item); }
    } else { self::need($value===null||is_string($value)||is_int($value)||is_bool($value)); }
    return $value;
  }
  public static function bytes($value) { return json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
  private static function keys(array $value,array $keys) { $got=array_keys($value);sort($got,SORT_STRING);sort($keys,SORT_STRING);self::need($got===$keys); }
  private static function privateFile($path) {
    self::need(is_string($path)&&realpath($path)===$path&&!is_link($path));$a=lstat($path);
    self::need($a!==false&&($a['mode']&0177777)===0100600&&$a['uid']===posix_geteuid()&&$a['nlink']===1&&$a['size']>0&&$a['size']<=2097152);
    $f=fopen($path,'rb');self::need($f!==false);
    try { $raw=stream_get_contents($f,2097153);$b=fstat($f);clearstatcache(true,$path);$c=lstat($path);
      foreach (array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime') as $key) self::need($a[$key]===$b[$key]&&$a[$key]===$c[$key]);
      self::need(strlen($raw)===$a['size']);return $raw;
    } finally { fclose($f); }
  }
  public static function boundProfile($path) {
    // This guard runs BEFORE fixture methods, app configuration or any DB I/O.
    self::need(is_string(N05_COMPLETION_PROFILE_SHA256)&&preg_match('/\A[a-f0-9]{64}\z/D',N05_COMPLETION_PROFILE_SHA256)===1);
    $raw=self::privateFile($path);self::need(hash('sha256',$raw)===N05_COMPLETION_PROFILE_SHA256);$p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    self::keys($p,array('schema','runtime_authorized','mode','run_id','hmac_key','source_root','source_revision','source_pins','namespace','server_uuid','fixture_nonce_sha256','standard_upgrade_receipt_sha256','patch_discovery','parent_table_names','parent_patch_keys','parent_migration_hashes','mysql_version'));
    self::need($p['schema']==='Completion-private-schema-v3'&&$p['runtime_authorized']===true&&in_array($p['mode'],array('completion-v3'),true)&&$p['mysql_version']==='8.4.13');
    self::need(preg_match('/\A[a-f0-9]{32}\z/D',$p['run_id'])===1&&preg_match('/\A[a-f0-9]{64}\z/D',$p['hmac_key'])===1);
    self::need(preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$p['namespace'])===1&&preg_match('/\A[a-f0-9]{40}\z/D',$p['source_revision'])===1&&realpath($p['source_root'])===$p['source_root']);
    self::need(preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$p['server_uuid'])===1);
    foreach(array('fixture_nonce_sha256','standard_upgrade_receipt_sha256')as$key)self::need(preg_match('/\A[a-f0-9]{64}\z/D',$p[$key])===1&&$p[$key]!==str_repeat('0',64));
    self::keys($p['source_pins'],array(self::SQL,self::SPEC,'support/governance/completion/fixture.php',self::SELF_PATH,'resources/sql/quickstart.sql'));
    foreach ($p['source_pins']as$relative=>$hash) { $file=$p['source_root'].'/'.$relative;self::need(is_file($file)&&!is_link($file)&&hash_file('sha256',$file)===$hash); }
    self::need($p['source_pins'][self::SQL]===self::SQL_SHA&&$p['source_pins'][self::SPEC]===self::SPEC_SHA);
    // Root binds ONE literal in a private copy, never changes the source template.
    $running=file_get_contents(__FILE__);$needle="const N05_COMPLETION_PROFILE_SHA256='".N05_COMPLETION_PROFILE_SHA256."';";
    self::need(substr_count($running,$needle)===1);$template=str_replace($needle,'const N05_COMPLETION_PROFILE_SHA256=null;',$running);
    self::need(hash('sha256',$template)===$p['source_pins'][self::SELF_PATH]);
    return $p;
  }
  private static function gitValue(array $p,$argument) {
    $env=array('PATH'=>'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM'=>'1','GIT_CONFIG_GLOBAL'=>'/dev/null','GIT_NO_REPLACE_OBJECTS'=>'1','GIT_NO_LAZY_FETCH'=>'1','GIT_TERMINAL_PROMPT'=>'0');
    $process=proc_open(array('git','--no-replace-objects','-C',$p['source_root'],'rev-parse',$argument),array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,$p['source_root'],$env);
    self::need(is_resource($process));$out=stream_get_contents($pipes[1],4097);$err=stream_get_contents($pipes[2],4097);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);self::need($code===0&&strlen($out)<4097&&$err==='');return trim($out);
  }
  private static function discovery(array $p) {
    $all=PhabricatorSQLPatchList::buildAllPatches();$matches=array();$keys=array();
    foreach($all as $key=>$patch) {
      self::need($key===$patch->getFullKey());$keys[]=$patch->getFullKey();
      if($patch->getName()===$p['source_root'].'/'.self::SQL) {
        self::need($patch->getType()==='sql'&&!$patch->isDead()&&$patch->getKey()===basename(self::SQL));
        $matches[]=array('full_key'=>$patch->getFullKey(),'leaf_key'=>$patch->getKey(),'path'=>self::SQL,'sha256'=>hash_file('sha256',$patch->getName()),'discovered_by'=>'PhabricatorSQLPatchList.buildAllPatches/getFullKey');
      }
    }
    self::need(count($matches)===1&&self::bytes($matches[0])===self::bytes($p['patch_discovery']));sort($keys,SORT_STRING);self::need(count(array_unique($keys))===count($keys));return array($matches[0],$keys);
  }
  private static function type($type) { return preg_replace('/\b(bigint|int)\([0-9]+\)/','$1',strtolower($type)); }
  private static function nativeSchema(array $p) {
    $server=new PhabricatorConfigServerSchema();$spec=(new PhorgeTaskContentGrantSchemaSpec())->setServer($server)->setUTF8Charset('utf8mb4')->setUTF8BinaryCollation('utf8mb4_bin')->setUTF8SortingCollation('utf8mb4_unicode_ci');$spec->buildSchemata();self::need((new ReflectionClass(PhorgeTaskContentGrantSchemaSpec::class))->getFileName()===$p['source_root'].'/'.self::SPEC);
    $database=$server->getDatabase($p['namespace'].'_maniphest');self::need($database!==null);$result=array();
    foreach($database->getTables()as$name=>$table) {
      $columns=array();$ordinal=0;
      foreach($table->getColumns()as$column_name=>$column)$columns[]=array('name'=>$column_name,'type'=>self::type($column->getColumnType()),'nullable'=>(bool)$column->getNullable(),'auto_increment'=>(bool)$column->getAutoIncrement(),'default'=>null,'charset'=>$column->getCharacterSet(),'collation'=>$column->getCollation(),'ordinal'=>++$ordinal);
      $indexes=array();foreach($table->getKeys()as$key=>$index)$indexes[$key]=array('columns'=>$index->getColumnNames(),'unique'=>$index->getUnique(),'index_type'=>$index->getIndexType(),'prefixes'=>array_fill(0,count($index->getColumnNames()),null),'visible'=>true);
      $result[$name]=array('engine'=>$table->getEngine(),'columns'=>$columns,'indexes'=>$indexes,'constraints'=>array(array('name'=>'PRIMARY','type'=>'PRIMARY KEY')),'triggers'=>array());
    }
    $names=array_keys($result);sort($names,SORT_STRING);self::need($names===array('governance_taskcontentapproval','governance_taskcontentaudit','governance_taskcontentgrant'));return $result;
  }
  private static function readState(mysqli $c,array $p,array $source_schema,array $source_keys) {
    $ns=$p['namespace'];$db=$ns.'_maniphest';$quoted="'".$c->real_escape_string($db)."'";$names=array_keys($source_schema);sort($names,SORT_STRING);
    $rows=$c->query("SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=".$quoted." AND TABLE_NAME LIKE 'governance\\_taskcontent%' ORDER BY BINARY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
    self::need(array_column($rows,'TABLE_NAME')===$names);$observed=array();$counts=array();
    foreach($rows as$row) {
      $name=$row['TABLE_NAME'];self::need($row['TABLE_TYPE']==='BASE TABLE');$table="'".$c->real_escape_string($name)."'";
      $data=$c->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='.$quoted.' AND TABLE_NAME='.$table.' ORDER BY ORDINAL_POSITION')->fetch_all(MYSQLI_ASSOC);$columns=array();
      foreach($data as$column) { self::need($column['EXTRA']===''||$column['EXTRA']==='auto_increment');$columns[]=array('name'=>$column['COLUMN_NAME'],'type'=>self::type($column['COLUMN_TYPE']),'nullable'=>$column['IS_NULLABLE']==='YES','auto_increment'=>$column['EXTRA']==='auto_increment','default'=>$column['COLUMN_DEFAULT'],'charset'=>$column['CHARACTER_SET_NAME'],'collation'=>$column['COLLATION_NAME'],'ordinal'=>(int)$column['ORDINAL_POSITION']); }
      $data=$c->query('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART,INDEX_TYPE,SEQ_IN_INDEX,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='.$quoted.' AND TABLE_NAME='.$table.' ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX')->fetch_all(MYSQLI_ASSOC);$indexes=array();
      foreach($data as$index) { $key=$index['INDEX_NAME'];if(!isset($indexes[$key]))$indexes[$key]=array('columns'=>array(),'unique'=>$index['NON_UNIQUE']==='0','index_type'=>$index['INDEX_TYPE'],'prefixes'=>array(),'visible'=>$index['IS_VISIBLE']==='YES');self::need((int)$index['SEQ_IN_INDEX']===count($indexes[$key]['columns'])+1);$indexes[$key]['columns'][]=$index['COLUMN_NAME'];$indexes[$key]['prefixes'][]=$index['SUB_PART']===null?null:(int)$index['SUB_PART']; }
      $constraints=$c->query('SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='.$quoted.' AND TABLE_NAME='.$table.' ORDER BY BINARY CONSTRAINT_NAME')->fetch_all(MYSQLI_ASSOC);$constraint_rows=array();foreach($constraints as$r)$constraint_rows[]=array('name'=>$r['CONSTRAINT_NAME'],'type'=>$r['CONSTRAINT_TYPE']);
      $triggers=$c->query('SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='.$quoted.' AND EVENT_OBJECT_TABLE='.$table.' ORDER BY BINARY TRIGGER_NAME')->fetch_all(MYSQLI_ASSOC);
      $observed[$name]=array('engine'=>$row['ENGINE'],'columns'=>$columns,'indexes'=>$indexes,'constraints'=>$constraint_rows,'triggers'=>$triggers);$count=$c->query('SELECT COUNT(*) n FROM `'.$db.'`.`'.$name.'`')->fetch_assoc();$counts[$name]=(int)$count['n'];self::need($counts[$name]===0);
    }
    self::need(self::bytes($observed)===self::bytes($source_schema));
    $prefix=$ns.'_';$tables=$c->query("SELECT TABLE_SCHEMA,TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE LEFT(TABLE_SCHEMA,".strlen($prefix).")='".$c->real_escape_string($prefix)."' ORDER BY BINARY TABLE_SCHEMA,BINARY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);$inventory=array();$guards=0;
    foreach($tables as$table) { self::need($table['TABLE_TYPE']==='BASE TABLE');$app=substr($table['TABLE_SCHEMA'],strlen($prefix));if($app==='governance'&&$table['TABLE_NAME']==='fixture_guard'){$guards++;continue;}$inventory[]=$app.'.'.$table['TABLE_NAME']; }self::need($guards===1);sort($inventory,SORT_STRING);
    $expected=$p['parent_table_names'];foreach($names as$name)$expected[]='maniphest.'.$name;sort($expected,SORT_STRING);self::need($inventory===$expected);
    $ledger=array_column($c->query('SELECT patch FROM `'.$ns.'_meta_data`.`patch_status` ORDER BY BINARY patch')->fetch_all(MYSQLI_ASSOC),'patch');self::need($ledger===$source_keys);
    $identity=$c->query('SELECT @@server_uuid uuid,VERSION() version')->fetch_assoc();$guard=$c->query('SELECT id,marker,quickstart_sha256,SHA2(CAST(owner_nonce AS BINARY),256) nonce_sha FROM `'.$ns.'_governance`.`fixture_guard` ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    self::need($identity['uuid']===$p['server_uuid']&&$identity['version']===$p['mysql_version']&&count($guard)===1&&(string)$guard[0]['id']==='1'&&$guard[0]['marker']===PhorgeGovernanceNativeFixture::MARKER&&$guard[0]['quickstart_sha256']===$p['source_pins']['resources/sql/quickstart.sql']&&$guard[0]['nonce_sha']===$p['fixture_nonce_sha256']);
    $epochs=$c->query("SELECT namespace,configKey,epoch FROM `".$ns."_config`.`governance_configepoch` WHERE configKey='governance.task-content-grants.enabled' ORDER BY BINARY namespace")->fetch_all(MYSQLI_ASSOC);$epoch=array();foreach($epochs as$r)$epoch[]=array('namespace'=>$r['namespace'],'config_key'=>$r['configKey'],'epoch'=>(int)$r['epoch']);self::need($epoch===array(array('namespace'=>'default','config_key'=>'governance.task-content-grants.enabled','epoch'=>1)));
    return array('observed_schema'=>$observed,'row_counts'=>$counts,'table_inventory'=>$inventory,'patch_ledger'=>$ledger,'config_epoch'=>$epoch);
  }
  public static function capture(PhorgeGovernanceCompletionFixture $fixture,$profile_path,$phase) {
    $p=self::boundProfile($profile_path);self::need(in_array($phase,array('before','after'),true));
    self::need(PHP_VERSION==='8.5.12'&&ini_get('opcache.jit')==='disable'&&ini_get('opcache.jit_buffer_size')==='0'&&ini_get('opcache.enable_cli')==='0');
    self::need(dirname(phutil_get_library_root('phabricator'))===$p['source_root']&&self::gitValue($p,'--show-toplevel')===$p['source_root']&&self::gitValue($p,'HEAD')===$p['source_revision']);
    self::need((new ReflectionClass($fixture))->getName()===PhorgeGovernanceCompletionFixture::class&&(new ReflectionClass($fixture))->getFileName()===$p['source_root'].'/support/governance/completion/fixture.php');
    self::need($fixture->getNamespace()===$p['namespace']&&PhabricatorEnv::getEnvConfig('storage.default-namespace')===$p['namespace']&&PhabricatorEnv::getEnvConfig('governance.task-content-grants.enabled')===false);
    list($patch,$source_keys)=self::discovery($p);$expected_keys=$p['parent_patch_keys'];$expected_keys[]=$patch['full_key'];sort($expected_keys,SORT_STRING);self::need($source_keys===$expected_keys);$schema=self::nativeSchema($p);$c=$fixture->newOwnedSetupConnection();$transaction=false;
    try {
      $c->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');self::need($c->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT));$transaction=true;
      $first=self::readState($c,$p,$schema,$source_keys);$restore=$fixture->getSummary();$second=self::readState($c,$p,$schema,$source_keys);self::need(self::bytes($first)===self::bytes($second));
      // COMMIT acknowledgement loss never becomes a successful capture or a
      // blind rollback. The fixture still owns/cleans the registered socket.
      $transaction=false;self::need($c->commit());
    } catch(Throwable $error) { if($transaction)$c->rollback();throw$error; }
    self::need(self::boundProfile($profile_path)===$p&&self::gitValue($p,'HEAD')===$p['source_revision']);
    $payload=array('schema'=>'Completion-schema-capture-v3','profile_sha256'=>N05_COMPLETION_PROFILE_SHA256,'run_id'=>$p['run_id'],'phase'=>$phase,'capture_id'=>bin2hex(random_bytes(16)),'source_root'=>$p['source_root'],'source_revision'=>$p['source_revision'],'source_pins'=>$p['source_pins'],'namespace'=>$p['namespace'],'server_uuid'=>$p['server_uuid'],'fixture_nonce_sha256'=>$p['fixture_nonce_sha256'],'task_feature_enabled'=>false,'standard_upgrade_receipt_sha256'=>$p['standard_upgrade_receipt_sha256'],'patch_discovery'=>$patch,'source_schema_spec'=>$schema,'observed_schema'=>$first['observed_schema'],'row_counts'=>$first['row_counts'],'table_inventory'=>$first['table_inventory'],'source_patch_keys'=>$source_keys,'patch_ledger'=>$first['patch_ledger'],'restore'=>$restore,'read_only_transaction'=>true,'raw_user_rows_exported'=>false,'php_version'=>PHP_VERSION,'mysql_version'=>$p['mysql_version'],'config_epoch'=>$first['config_epoch']);
    return array('payload'=>$payload,'mac'=>hash_hmac('sha256',self::bytes($payload),hex2bin($p['hmac_key'])));
  }
  public static function envelope($profile_path,array $native,array $before,array $after) {
    $p=self::boundProfile($profile_path);
    foreach(array('before'=>$before,'after'=>$after)as$phase=>$sealed) { self::keys($sealed,array('payload','mac'));self::need(hash_equals(hash_hmac('sha256',self::bytes($sealed['payload']),hex2bin($p['hmac_key'])),$sealed['mac'])&&$sealed['payload']['profile_sha256']===N05_COMPLETION_PROFILE_SHA256&&$sealed['payload']['phase']===$phase&&self::bytes($sealed['payload']['restore'])===self::bytes($native['source_restore'])); }
    self::need($before['payload']['capture_id']!==$after['payload']['capture_id']);$envelope=array('schema'=>'Completion-Task-envelope-v3','profile_sha256'=>N05_COMPLETION_PROFILE_SHA256,'native_receipt'=>$native,'before'=>$before,'after'=>$after);
    $envelope['mac']=hash_hmac('sha256',self::bytes($envelope),hex2bin($p['hmac_key']));return $envelope;
  }
}
if (PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
  // Not an old producer shim: real fixture/matrix integration must call the
  // before/after hooks and envelope explicitly. No synthetic current proof.
  if(N05_COMPLETION_PROFILE_SHA256===null){fwrite(STDERR,"Task_additive_schema_unbound_before_DB\n");exit(1);}
  fwrite(STDERR,"Task_additive_schema_requires_actual_owned_fixture_v2_wrapper\n");exit(1);
}
