<?php

/** Owned exec workers. No fork, inherited SQL socket or arbitrary command. */
$GLOBALS['governance_native_workers']=array();

function governance_native_worker_atomic($path,$name,$value) {
  if (!in_array($name,array('owner','payload.json','ready.json','result.json','observe-release','producer-ready.json','producer-continue'),true)) {
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

/** Fixed measurement-only cold-cache dependency; never an authority grant. */
function governance_native_measurement_file_source($action,$value,$namespace,$owner,$source) {
  if($action!=='measurement.api-token-auth'){
    if($value!==null){throw new RuntimeException('native_worker_file_scope_refused');}
    return null;
  }
  if(!is_array($value)){throw new RuntimeException('native_worker_file_scope_refused');}
  $keys=array_keys($value);sort($keys);
  if($keys!==array('namespace','token','uri')||!is_string($namespace)||
    !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)||$value['namespace']!==$namespace||
    !is_string($value['uri'])||!preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$value['uri'],$port)||(int)$port[1]>65535||
    !is_string($value['token'])||!preg_match('/\A[a-f0-9]{64}\z/D',$value['token'])||
    !is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)||
    !is_string($source)||!preg_match('/\A[a-f0-9]{64}\z/D',$source)){
    throw new RuntimeException('native_worker_file_scope_refused');
  }
  return $value+array('binding'=>hash('sha256',json_encode(array($namespace,$owner,$source,$value['uri'],$value['token']),JSON_THROW_ON_ERROR)));
}

function governance_native_start_worker($fixture,array $action) {
  $fixture->assertOwned();
  if (!in_array($action['action']??null,array('recovery.org.member.insert','recovery.policy.insert','recovery.session.issue','recovery.stop.record','recovery.stop.history','measurement.api-token-auth','measurement.project-query','org.list','org.count','media.redeem','media.prepare','org.members.edit','org.compound.execute','org.compound.execute-approved','org.transfer.execute','org.handover.execute','legalpad.verify','legalpad.observe'),true)) {
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
  $payload=array('owner_token'=>$nonce,
    'directory_dev'=>$stat['dev'],'directory_ino'=>$stat['ino'],
    'config'=>$fixture->getWriterConfig(),'fixture_nonce'=>$fixture_nonce,
    'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),
    'media_issuer'=>array(
      'key'=>PhabricatorEnv::getEnvConfig('governance.media-exchange.issuer-key'),
      'incarnation'=>PhabricatorEnv::getEnvConfig('governance.media-exchange.incarnation')),
    'action'=>$action);
  if($action['action']==='measurement.api-token-auth'){
    $asset=array('namespace'=>$fixture->getNamespace(),'uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),
      'token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    governance_native_measurement_file_source($action['action'],$asset,$fixture->getNamespace(),$fixture_nonce,$payload['source_sha256']);
    $payload['measurement_file']=$asset;
  }
  if(in_array($action['action'],array('recovery.session.issue','recovery.org.member.insert'),true)){
    $incarnation=PhabricatorEnv::getEnvConfig('governance.account-recovery.incarnation');
    if(!is_string($incarnation)||!preg_match('/\A[a-f0-9]{32}\z/D',$incarnation)){throw new RuntimeException('native_session_producer_incarnation_refused');}
    $payload['recovery_incarnation']=$incarnation;
  }
  governance_native_worker_atomic($path,'payload.json',$payload);
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

/** Exact results for the two isolated stop-ledger actions, never generic reasons. */
function governance_native_stop_worker_result($action, array $result) {
  if (!in_array($action,array('recovery.stop.record','recovery.stop.history'),true)) {
    throw new RuntimeException('native_stop_worker_action_refused');
  }
  $keys=array_keys($result);sort($keys);
  if (($result['status']??null)==='rejected') {
    if ($keys!==array('reason','status') || !in_array($result['reason'],
        array('recovery_stop_admin_required','governance_native_credential_ineligible'),true)) {
      throw new RuntimeException('native_stop_worker_result_refused');
    }
  } else if ($action==='recovery.stop.record') {
    if ($keys!==array('recovery_authorized','replayed','status') || $result['status']!=='committed' ||
        !is_bool($result['replayed']) || $result['recovery_authorized']!==false) {
      throw new RuntimeException('native_stop_worker_result_refused');
    }
  } else if ($keys!==array('count','status') || $result['status']!=='committed' ||
      !is_int($result['count']) || $result['count']<0 || $result['count']>1) {
    throw new RuntimeException('native_stop_worker_result_refused');
  }
  return $result;
}

/** Fixed session-producer test action: no generic error-as-denial fallback. */
function governance_native_session_producer_result(array $result) {
  $keys=array_keys($result);sort($keys);
  if(($result['status']??null)==='committed'){
    if($keys!==array('restoration_authorized','session_id_digest','status')||
      $result['restoration_authorized']!==false||!is_string($result['session_id_digest'])||
      !preg_match('/\A[a-f0-9]{64}\z/D',$result['session_id_digest'])){
      throw new RuntimeException('native_session_producer_result_refused');}
  }else if(($result['status']??null)==='rejected'){
    if($keys!==array('reason','status')||!in_array($result['reason'],
      array('recovery_restore_admin_required','governance_native_credential_ineligible'),true)){
      throw new RuntimeException('native_session_producer_result_refused');}
  }else{throw new RuntimeException('native_session_producer_result_refused');}
  return $result;
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
  if(in_array($worker['action'],array('recovery.stop.record','recovery.stop.history'),true)){
    return governance_native_stop_worker_result($worker['action'],$result);
  }
  if($worker['action']==='recovery.session.issue'){return governance_native_session_producer_result($result);}
  if($worker['action']==='recovery.org.member.insert'){return governance_native_organization_producer_result($result);}
  if($worker['action']==='recovery.policy.insert'){return governance_native_policy_producer_result($result);}
  if($worker['action']==='legalpad.observe'){return governance_native_validate_legalpad_observation($result);}
  $keys=array_keys($result); sort($keys);
  if (($result['status']??null)==='rejected') {
    if ($keys!==array('reason','status') || !is_string($result['reason']) ||
        strlen($result['reason'])>128 ||
        !preg_match('/\A(?:organization|governance|media)_[a-z_]+\z/',$result['reason'])) {
      throw new RuntimeException('native_worker_result_refused');
    }
  } else if (($result['status']??null)==='committed') {
    if(in_array($worker['action'],array('org.members.edit','org.compound.execute','org.compound.execute-approved','org.transfer.execute','org.handover.execute'),true)){
      if($keys!==array('replayed','status')||!is_bool($result['replayed'])){throw new RuntimeException('native_worker_result_refused');}
    }else if($worker['action']==='legalpad.verify'){
      if($keys!==array('already_verified','status','verified')||!is_bool($result['already_verified'])||$result['verified']!==true){throw new RuntimeException('native_worker_result_refused');}
    }else if($worker['action']==='measurement.project-query'){
      if($keys!==array('boot_id_sha256','outcome','source_binding','status','target_binding')||
        !in_array($result['outcome'],array('allowed','denied'),true)){
        throw new RuntimeException('native_worker_result_refused');}
      foreach(array('boot_id_sha256','source_binding','target_binding')as$key){
        if(!is_string($result[$key])||!preg_match('/\A[a-f0-9]{64}\z/',$result[$key])){
          throw new RuntimeException('native_worker_result_refused');}}
    }else if($worker['action']==='measurement.api-token-auth'){
      if($keys!==array('asset_binding','authentication_code','boot_id_sha256','outcome','owner_binding','source_binding','status','token_present')||
        !in_array($result['outcome'],array('allowed','denied','unknown'),true)||
        !in_array($result['authentication_code'],array('native-success','ERR-INVALID-AUTH','unknown'),true)||
        !is_bool($result['token_present'])){throw new RuntimeException('native_worker_result_refused');}
      foreach(array('asset_binding','boot_id_sha256','source_binding','owner_binding')as$key){
        if(!is_string($result[$key])||!preg_match('/\A[a-f0-9]{64}\z/',$result[$key])){
          throw new RuntimeException('native_worker_result_refused');}}
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

/** Only the fixed service observation action uses this pre-close handshake. */
function governance_native_validate_legalpad_observation($result){
  if(!is_array($result)){throw new RuntimeException('native_legalpad_observation_refused');}
  $keys=array_keys($result);sort($keys);
  if($keys!==array('exception_class','mail_delta','outcome','status','transaction_open','verified')||
    $result['status']!=='observed'||!in_array($result['outcome'],array('verified','already-verified','source-refused','deadlock'),true)||
    !is_bool($result['transaction_open'])||!is_bool($result['verified'])||!is_int($result['mail_delta'])||$result['mail_delta']<0||$result['mail_delta']>64){
    throw new RuntimeException('native_legalpad_observation_refused');}
  $exception=$result['outcome']==='deadlock'?'AphrontDeadlockQueryException':($result['outcome']==='source-refused'?'PhorgeLegalpadVerificationException':null);
  if($result['exception_class']!==$exception){throw new RuntimeException('native_legalpad_observation_refused');}
  return$result;
}
function governance_native_observation_handle(array $handle){
  $index=$handle['index']??null;$worker=is_int($index)?($GLOBALS['governance_native_workers'][$index]??null):null;
  if(!$worker||$worker['action']!=='legalpad.observe'||$worker['cleaned']||
    ($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||
    !is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){throw new RuntimeException('native_legalpad_observation_refused');}
  return$worker;
}
function governance_native_peek_legalpad_observation(array $handle){
  $worker=governance_native_observation_handle($handle);
  $path=$worker['private'].'/result.json';$deadline=microtime(true)+12;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>4096){throw new RuntimeException('native_legalpad_observation_missing');}
  return governance_native_validate_legalpad_observation(json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR));
}
function governance_native_release_legalpad_observation(array $handle){
  $worker=governance_native_observation_handle($handle);
  governance_native_worker_atomic($worker['private'],'observe-release',$worker['nonce']);
}

/** Exact private handshake for the one native session INSERT probe. */
function governance_native_session_producer_handle(array $handle) {
  $index=$handle['index']??null;$worker=is_int($index)?($GLOBALS['governance_native_workers'][$index]??null):null;
  if(!$worker||$worker['action']!=='recovery.session.issue'||$worker['cleaned']||
    ($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||
    !is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){
    throw new RuntimeException('native_session_producer_handle_refused');}
  $stat=lstat($worker['private']);
  $owner=$worker['private'].'/owner';
  if(!$stat||is_link($worker['private'])||($stat['mode']&0777)!==0700||
    $stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||
    !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
    !hash_equals($worker['nonce'],file_get_contents($owner))){
    throw new RuntimeException('native_session_producer_handle_refused');}
  return $worker;
}
function governance_native_wait_session_producer_ready(array $handle) {
  $worker=governance_native_session_producer_handle($handle);
  $path=$worker['private'].'/producer-ready.json';$deadline=microtime(true)+8;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>512){
    throw new RuntimeException('native_session_producer_ready_missing');}
  $value=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if($value!==array('status'=>'producer-ready','mysql_thread'=>$worker['mysql_thread'])){
    throw new RuntimeException('native_session_producer_ready_refused');}
}
function governance_native_continue_session_producer(array $handle) {
  $worker=governance_native_session_producer_handle($handle);
  governance_native_worker_atomic($worker['private'],'producer-continue',$worker['nonce']);
}

/** Reap only this helper's exact registered handles; outer fixture owns directories. */
function governance_native_stop_fixture_handles(array $handles){
  foreach($handles as$handle){
    $index=$handle['index']??null;
    if(!is_int($index)||!isset($GLOBALS['governance_native_workers'][$index])){throw new RuntimeException('native_worker_handle_refused');}
    $worker=&$GLOBALS['governance_native_workers'][$index];
    if(($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||
      !is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){throw new RuntimeException('native_worker_handle_refused');}
    if(is_resource($worker['process'])){
      if(proc_get_status($worker['process'])['running']){
        proc_terminate($worker['process']);$deadline=microtime(true)+2;
        while(proc_get_status($worker['process'])['running']&&microtime(true)<$deadline){usleep(20000);}
        if(proc_get_status($worker['process'])['running']){proc_terminate($worker['process'],9);}
      }
      proc_close($worker['process']);$worker['process']=null;
    }
    unset($worker);
  }
}

/** Only the fixed native custom-policy INSERT probe, not arbitrary SQL or PHIDs. */
function governance_native_policy_producer_result(array $result) {
  $keys=array_keys($result);sort($keys,SORT_STRING);
  if($keys!==array('native_policy_digest','restoration_authorized','status')||
    $result['status']!=='committed'||$result['restoration_authorized']!==false||
    !is_string($result['native_policy_digest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$result['native_policy_digest'])){
    throw new RuntimeException('native_policy_producer_result_refused');}
  return $result;
}

function governance_native_policy_producer_handle(array $handle) {
  $index=$handle['index']??null;$worker=is_int($index)?($GLOBALS['governance_native_workers'][$index]??null):null;
  if(!$worker||$worker['action']!=='recovery.policy.insert'||$worker['cleaned']||
    ($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||
    !is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){
    throw new RuntimeException('native_policy_producer_handle_refused');}
  $stat=lstat($worker['private']);
  $owner=$worker['private'].'/owner';
  if(!$stat||is_link($worker['private'])||($stat['mode']&0777)!==0700||
    $stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||
    !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
    !hash_equals($worker['nonce'],file_get_contents($owner))){
    throw new RuntimeException('native_policy_producer_handle_refused');}
  return $worker;
}
function governance_native_wait_policy_producer_ready(array $handle) {
  $worker=governance_native_policy_producer_handle($handle);
  $path=$worker['private'].'/producer-ready.json';$deadline=microtime(true)+8;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>512){
    throw new RuntimeException('native_policy_producer_ready_missing');}
  $value=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if($value!==array('status'=>'producer-ready','mysql_thread'=>$worker['mysql_thread'])){
    throw new RuntimeException('native_policy_producer_ready_refused');}
}
function governance_native_continue_policy_producer(array $handle) {
  $worker=governance_native_policy_producer_handle($handle);
  governance_native_worker_atomic($worker['private'],'producer-continue',$worker['nonce']);
}

/** Only the fixed native organization-member INSERT probe, not arbitrary SQL or PHIDs. */
function governance_native_organization_producer_result(array $result) {
  $keys=array_keys($result);sort($keys,SORT_STRING);
  if($keys!==array('native_member_digest','restoration_authorized','status')||
    $result['status']!=='committed'||$result['restoration_authorized']!==false||
    !is_string($result['native_member_digest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$result['native_member_digest'])){
    throw new RuntimeException('native_organization_producer_result_refused');}
  return $result;
}

function governance_native_organization_producer_handle(array $handle) {
  $index=$handle['index']??null;$worker=is_int($index)?($GLOBALS['governance_native_workers'][$index]??null):null;
  if(!$worker||$worker['action']!=='recovery.org.member.insert'||$worker['cleaned']||
    ($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||
    !is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){
    throw new RuntimeException('native_organization_producer_handle_refused');}
  $stat=lstat($worker['private']);
  $owner=$worker['private'].'/owner';
  if(!$stat||is_link($worker['private'])||($stat['mode']&0777)!==0700||
    $stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||
    !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
    !hash_equals($worker['nonce'],file_get_contents($owner))){
    throw new RuntimeException('native_organization_producer_handle_refused');}
  return $worker;
}
function governance_native_wait_organization_producer_ready(array $handle) {
  $worker=governance_native_organization_producer_handle($handle);
  $path=$worker['private'].'/producer-ready.json';$deadline=microtime(true)+8;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>512){
    throw new RuntimeException('native_organization_producer_ready_missing');}
  $value=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if($value!==array('status'=>'producer-ready','mysql_thread'=>$worker['mysql_thread'])){
    throw new RuntimeException('native_organization_producer_ready_refused');}
}
function governance_native_continue_organization_producer(array $handle) {
  $worker=governance_native_organization_producer_handle($handle);
  governance_native_worker_atomic($worker['private'],'producer-continue',$worker['nonce']);
}
