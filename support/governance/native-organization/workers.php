<?php

/** Owned exec workers. No fork, inherited SQL socket or arbitrary command. */
$GLOBALS['governance_native_workers']=array();

function governance_native_worker_atomic($path,$name,$value) {
  if (!in_array($name,array('owner','payload.json','ready.json','result.json'),true)) {
    throw new RuntimeException('native_worker_file_refused');
  }
  $temporary=$path.'/.write-'.bin2hex(random_bytes(8));
  $stream=fopen($temporary,'x');
  if (!$stream) { throw new RuntimeException('native_worker_write_failed'); }
  try {
    chmod($temporary,0600);
    $bytes=is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR);
    if (fwrite($stream,$bytes)!==strlen($bytes) || !fflush($stream)) {
      throw new RuntimeException('native_worker_write_failed');
    }
  } finally { fclose($stream); }
  if (!rename($temporary,$path.'/'.$name)) { throw new RuntimeException('native_worker_write_failed'); }
}

function governance_native_start_worker($fixture,array $action) {
  $fixture->assertOwned();
  if (!in_array($action['action']??null,array('org.list','org.count','media.redeem','media.prepare','org.members.edit','org.compound.execute','org.compound.execute-approved','org.transfer.execute'),true)) {
    throw new RuntimeException('native_worker_action_refused');
  }
  $path=sys_get_temp_dir().'/governance-native-worker-'.bin2hex(random_bytes(12));
  if (!mkdir($path,0700)) { throw new RuntimeException('native_worker_directory_failed'); }
  // Register immediately: later credential-file/proc failures must be cleaned.
  $stat=lstat($path); $index=count($GLOBALS['governance_native_workers']);
  $nonce=bin2hex(random_bytes(32));
  $GLOBALS['governance_native_workers'][$index]=array('index'=>$index,'private'=>$path,
    'dev'=>$stat['dev'],'ino'=>$stat['ino'],'nonce'=>$nonce,'owner_ready'=>false,
    'process'=>null,'cleaned'=>false,'action'=>$action['action']);
  governance_native_worker_atomic($path,'owner',$nonce);
  $GLOBALS['governance_native_workers'][$index]['owner_ready']=true;
  $fixture_nonce=(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture);
  governance_native_worker_atomic($path,'payload.json',array('owner_token'=>$nonce,
    'directory_dev'=>$stat['dev'],'directory_ino'=>$stat['ino'],
    'config'=>$fixture->getWriterConfig(),'fixture_nonce'=>$fixture_nonce,
    'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),
    'media_issuer'=>array(
      'key'=>PhabricatorEnv::getEnvConfig('governance.media-exchange.issuer-key'),
      'incarnation'=>PhabricatorEnv::getEnvConfig('governance.media-exchange.incarnation')),
    'action'=>$action));
  $process=proc_open(array(PHP_BINARY,__DIR__.'/native-worker.php',$path),
    array(0=>array('file','/dev/null','r'),1=>array('file',$path.'/stdout.log','a'),
      2=>array('file',$path.'/stderr.log','a')),$pipes,null,
    array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1'));
  if (!is_resource($process)) { throw new RuntimeException('native_worker_start_failed'); }
  $GLOBALS['governance_native_workers'][$index]['process']=$process;
  foreach (array('stdout.log','stderr.log') as $log) { if (is_file($path.'/'.$log)) { chmod($path.'/'.$log,0600); } }
  $deadline=microtime(true)+8;
  while (!is_file($path.'/ready.json') && microtime(true)<$deadline && proc_get_status($process)['running']) {
    usleep(20000);
  }
  if (!is_file($path.'/ready.json')) { throw new RuntimeException('native_worker_not_ready'); }
  $ready=json_decode(file_get_contents($path.'/ready.json'),true,8,JSON_THROW_ON_ERROR);
  if (!is_int($ready['mysql_thread']??null) || $ready['mysql_thread']<1 ||
      ($ready['status']??null)!=='ready') { throw new RuntimeException('native_worker_ready_refused'); }
  $GLOBALS['governance_native_workers'][$index]['mysql_thread']=$ready['mysql_thread'];
  return $GLOBALS['governance_native_workers'][$index];
}

function governance_native_finish_worker(array &$handle) {
  $index=$handle['index']; $worker=&$GLOBALS['governance_native_workers'][$index];
  $deadline=microtime(true)+15;
  while (is_resource($worker['process']) && proc_get_status($worker['process'])['running'] && microtime(true)<$deadline) { usleep(20000); }
  if (is_resource($worker['process']) && proc_get_status($worker['process'])['running']) {
    throw new RuntimeException('native_worker_timeout');
  }
  if (is_resource($worker['process'])) { proc_close($worker['process']); }
  $worker['process']=null; $handle['process']=null;
  $path=$worker['private'].'/result.json';
  if (!is_file($path) || is_link($path) || filesize($path)>65536) {
    throw new RuntimeException('native_worker_result_missing');
  }
  $result=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
  if (!is_array($result)) { throw new RuntimeException('native_worker_result_refused'); }
  $keys=array_keys($result); sort($keys);
  if (($result['status']??null)==='rejected') {
    if ($keys!==array('reason','status') || !is_string($result['reason']) ||
        strlen($result['reason'])>128 ||
        !preg_match('/\A(?:organization|governance|media)_[a-z_]+\z/',$result['reason'])) {
      throw new RuntimeException('native_worker_result_refused');
    }
  } else if (($result['status']??null)==='committed') {
    if(in_array($worker['action'],array('org.members.edit','org.compound.execute','org.compound.execute-approved','org.transfer.execute'),true)){
      if($keys!==array('replayed','status')||!is_bool($result['replayed'])){throw new RuntimeException('native_worker_result_refused');}
    }else if($worker['action']==='media.prepare'){
      if($keys!==array('prepared','status')||$result['prepared']!==true){throw new RuntimeException('native_worker_result_refused');}
    }else if ($worker['action']==='media.redeem') {
      if ($keys!==array('credential_authenticated','status') || $result['credential_authenticated']!==true) {
        throw new RuntimeException('native_worker_result_refused');
      }
    } else {
      if ($keys!==array('status','value')) { throw new RuntimeException('native_worker_result_refused'); }
      if ($worker['action']==='org.count') {
        if (!is_int($result['value']) || $result['value']<0) { throw new RuntimeException('native_worker_result_refused'); }
      } else {
        if (!is_array($result['value']) || !array_is_list($result['value']) || count($result['value'])>100) {
          throw new RuntimeException('native_worker_result_refused');
        }
        foreach ($result['value'] as $row) {
          $row_keys=is_array($row)?array_keys($row):array(); sort($row_keys);
          if ($row_keys!==array('contact_user_phid','department_id','kind','name','parent_id','unit_id','version','visibility_model_version') ||
              !PhorgeGovernanceOrganizationRequest::identifier($row['unit_id'],64) ||
              !in_array($row['kind'],array('team','department'),true) ||
              !is_string($row['name']) || strlen($row['name'])>255 || !phutil_is_utf8($row['name']) ||
              !is_int($row['version']) || $row['version']<1 ||
              $row['visibility_model_version']!==1 ||
              ($row['department_id']!==null && !PhorgeGovernanceOrganizationRequest::identifier($row['department_id'],64)) ||
              ($row['contact_user_phid']!==null && (!is_string($row['contact_user_phid']) || !preg_match('/\APHID-USER-[a-z0-9]{20}\z/',$row['contact_user_phid']))) ||
              ($row['parent_id']!==null && !PhorgeGovernanceOrganizationRequest::identifier($row['parent_id'],64))) {
            throw new RuntimeException('native_worker_result_refused');
          }
        }
      }
    }
  } else { throw new RuntimeException('native_worker_result_refused'); }
  return $result;
}

function governance_native_wait_worker_query(mysqli $observer,array $handles,$pattern) {
  $ids=array(); foreach ($handles as $handle) { $ids[$handle['mysql_thread']]=true; }
  $deadline=microtime(true)+5;
  do {
    $matched=array();
    foreach ($observer->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $row) {
      if (isset($ids[(int)$row['Id']]) && is_string($row['Info']) && preg_match($pattern,$row['Info'])) {
        $matched[(int)$row['Id']]=true;
      }
    }
    if (count($matched)===count($ids)) { return true; }
    usleep(20000);
  } while (microtime(true)<$deadline);
  throw new RuntimeException('native_worker_real_query_wait_unobserved');
}

function governance_native_cleanup_workers() {
  $removed=0; $stopped=0; $failures=0;
  foreach ($GLOBALS['governance_native_workers'] as &$worker) {
    if ($worker['cleaned']) { continue; }
    try {
    if (is_resource($worker['process'])) {
      if (proc_get_status($worker['process'])['running']) {
        $stopped++;
        proc_terminate($worker['process']);
        $deadline=microtime(true)+2;
        while (proc_get_status($worker['process'])['running'] && microtime(true)<$deadline) { usleep(20000); }
        if (proc_get_status($worker['process'])['running']) { proc_terminate($worker['process'],9); }
      }
      proc_close($worker['process']); $worker['process']=null;
    }
    $path=$worker['private']; clearstatcache(true,$path); $stat=lstat($path);
    if (!$stat || is_link($path) || $stat['dev']!==$worker['dev'] || $stat['ino']!==$worker['ino'] ||
        realpath(dirname($path))!==realpath(sys_get_temp_dir()) ||
        !preg_match('/\Agovernance-native-worker-[a-f0-9]{24}\z/',basename($path)) ||
        ($worker['owner_ready'] && (!is_file($path.'/owner') || is_link($path.'/owner') ||
          !hash_equals($worker['nonce'],file_get_contents($path.'/owner'))))) {
      throw new RuntimeException('native_worker_cleanup_owner_refused');
    }
    foreach (glob($path.'/*') as $file) {
      if (is_dir($file) && !is_link($file)) { throw new RuntimeException('native_worker_cleanup_directory_refused'); }
      if (!unlink($file)) { throw new RuntimeException('native_worker_cleanup_file_failed'); }
    }
    foreach (glob($path.'/.write-*') as $file) { if (!unlink($file)) { throw new RuntimeException('native_worker_cleanup_file_failed'); } }
    if (!rmdir($path)) { throw new RuntimeException('native_worker_cleanup_failed'); }
    $worker['cleaned']=true; $removed++;
    } catch (Throwable $error) { $failures++; }
  }
  unset($worker);
  $remaining=0; $live=0;
  foreach ($GLOBALS['governance_native_workers'] as $worker) {
    if (!$worker['cleaned']) { $remaining++; }
    if (is_resource($worker['process']) && proc_get_status($worker['process'])['running']) { $live++; }
  }
  return array('private_directories_removed'=>$removed,
    'owned_processes_stopped'=>$stopped,'cleanup_failures'=>$failures,
    'private_directories_remaining'=>$remaining,'live_processes_after_cleanup'=>$live,
    'workers_registered'=>count($GLOBALS['governance_native_workers']));
}
