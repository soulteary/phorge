<?php

/** Owned exec workers. No fork, inherited SQL socket or arbitrary command. */
$GLOBALS['governance_native_workers']=array();

function governance_native_worker_atomic($path,$name,$value) {
  if (!in_array($name,array('owner','payload.json','ready.json','result.json','observe-release','producer-ready.json','producer-identity-started.json','producer-continue','covering-control-ready.json','fence-continue'),true)) {
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
  if(!in_array($action,array('measurement.api-token-auth','recovery.oauth.authenticate'),true)){
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
  if (!in_array($action['action']??null,array('recovery.oauth.authenticate','recovery.api.current','recovery.org.approval.propose','recovery.org.member.insert','recovery.policy.insert','recovery.session.issue','recovery.stop.record','recovery.stop.history','measurement.api-token-auth','measurement.project-query','org.list','org.count','media.redeem','media.prepare','org.notice.create','org.members.edit','org.compound.execute','org.compound.execute-approved','org.transfer.execute','org.handover.execute','legalpad.verify','legalpad.observe'),true)) {
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
  if(in_array($action['action'],array('measurement.api-token-auth','recovery.oauth.authenticate'),true)){
    $asset=array('namespace'=>$fixture->getNamespace(),'uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),
      'token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    governance_native_measurement_file_source($action['action'],$asset,$fixture->getNamespace(),$fixture_nonce,$payload['source_sha256']);
    $payload['measurement_file']=$asset;
  }
  if(in_array($action['action'],array('recovery.session.issue','recovery.org.member.insert','recovery.org.approval.propose'),true)){
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
  if (!is_file($path.'/ready.json')) {
    if(in_array($action['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)){
      try{$GLOBALS['governance_api_concurrency_child_failure']=governance_native_api_startup_diagnostic(
        $GLOBALS['governance_native_workers'][$index],$fixture);}
      catch(Throwable$diagnostic_error){$GLOBALS['governance_api_concurrency_child_failure']=array(
        'diagnostic_unavailable'=>true,'error_type'=>get_class($diagnostic_error));}
    }
    throw new RuntimeException('native_worker_not_ready');
  }
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

/** Failure-only safe projection. Invalid diagnostics never become denial or success. */
function governance_native_session_failure_location(array $result) {
  $keys=array_keys($result);sort($keys);$with_code=array_key_exists('query_code',$result);
  $expected=array('error_type','source_file','source_line','status');
  if($with_code){$expected[]='query_code';sort($expected);}
  if($keys!==$expected||$result['status']!=='failed'||
    !is_string($result['error_type'])||!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,159}\z/D',$result['error_type'])||
    !is_string($result['source_file'])||!preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,159}\z/D',$result['source_file'])||
    !is_int($result['source_line'])||$result['source_line']<1||$result['source_line']>1000000||
    ($with_code&&(!is_int($result['query_code'])||$result['query_code']<0||$result['query_code']>65535))){
    return array('diagnostic_unavailable'=>true);
  }
  $out=array('error_type'=>$result['error_type'],'source_file'=>$result['source_file'],'source_line'=>$result['source_line']);
  if($with_code){$out['query_code']=$result['query_code'];}return $out;
}

function governance_native_finish_worker(array &$handle) {
  $index=$handle['index']; $worker=&$GLOBALS['governance_native_workers'][$index];
  if(in_array($worker['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)){governance_native_api_concurrency_owner($handle);}
  if($worker['action']==='recovery.session.issue'){governance_native_session_producer_handle($handle);}
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
  if(in_array($worker['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)){
    governance_native_api_concurrency_owner($handle);
    if((fileperms($path)&0777)!==0600){throw new RuntimeException('recovery_api_result_mode_refused');}
  }
  if(in_array($worker['action'],array('recovery.stop.record','recovery.stop.history'),true)){
    return governance_native_stop_worker_result($worker['action'],$result);
  }
  if($worker['action']==='recovery.session.issue'){
    governance_native_session_producer_handle($handle);
    if((fileperms($path)&0777)!==0600){throw new RuntimeException('native_session_producer_result_refused');}
    if(($result['status']??null)==='failed'){
      $GLOBALS['governance_session_producer_child_failure']=governance_native_session_failure_location($result);
    }
    // Failed is still rejected by the original exact decoder below.
    return governance_native_session_producer_result($result);
  }
  if($worker['action']==='recovery.org.approval.propose'){return governance_native_lifecycle_producer_result($result);}
  if($worker['action']==='recovery.org.member.insert'){return governance_native_organization_producer_result($result);}
  if($worker['action']==='recovery.policy.insert'){return governance_native_policy_producer_result($result);}
  if(in_array($worker['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)&&
    ($result['status']??null)==='failed'){
    $GLOBALS['governance_api_concurrency_child_failure']=governance_native_session_failure_location($result);
  }
  if($worker['action']==='recovery.oauth.authenticate'){return governance_native_oauth_concurrency_result($result);}
  if($worker['action']==='recovery.api.current'){return governance_native_api_concurrency_result($result);}
  if($worker['action']==='legalpad.observe'){return governance_native_validate_legalpad_observation($result);}
  $keys=array_keys($result); sort($keys);
  if (($result['status']??null)==='rejected') {
    if ($keys!==array('reason','status') || !is_string($result['reason']) ||
        strlen($result['reason'])>128 ||
        !preg_match('/\A(?:organization|governance|media)_[a-z_]+\z/',$result['reason'])) {
      throw new RuntimeException('native_worker_result_refused');
    }
  } else if (($result['status']??null)==='committed') {
    if(in_array($worker['action'],array('org.notice.create','org.members.edit','org.compound.execute','org.compound.execute-approved','org.transfer.execute','org.handover.execute'),true)){
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

/** Fixed owned lifecycle proposal worker; no general method or SQL dispatch. */
function governance_native_lifecycle_producer_result(array $result) {
  $keys=array_keys($result);sort($keys,SORT_STRING);
  if($keys!==array('native_approval_digest','restoration_authorized','status')||
    $result['status']!=='committed'||$result['restoration_authorized']!==false||
    !is_string($result['native_approval_digest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$result['native_approval_digest'])){
    throw new RuntimeException('native_lifecycle_producer_result_refused');}
  return $result;
}

function governance_native_lifecycle_producer_handle(array $handle) {
  $index=$handle['index']??null;$worker=is_int($index)?($GLOBALS['governance_native_workers'][$index]??null):null;
  if(!$worker||$worker['action']!=='recovery.org.approval.propose'||$worker['cleaned']||
    ($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||
    !is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){
    throw new RuntimeException('native_lifecycle_producer_handle_refused');}
  $stat=lstat($worker['private']);
  $owner=$worker['private'].'/owner';
  if(!$stat||is_link($worker['private'])||($stat['mode']&0777)!==0700||
    $stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||
    !is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||
    !hash_equals($worker['nonce'],file_get_contents($owner))){
    throw new RuntimeException('native_lifecycle_producer_handle_refused');}
  return $worker;
}
function governance_native_wait_lifecycle_producer_ready(array $handle) {
  $worker=governance_native_lifecycle_producer_handle($handle);
  $path=$worker['private'].'/producer-ready.json';$deadline=microtime(true)+8;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>512){
    throw new RuntimeException('native_lifecycle_producer_ready_missing');}
  $value=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if($value!==array('status'=>'producer-ready','mysql_thread'=>$worker['mysql_thread'])){
    throw new RuntimeException('native_lifecycle_producer_ready_refused');}
}
function governance_native_continue_lifecycle_producer(array $handle) {
  $worker=governance_native_lifecycle_producer_handle($handle);
  governance_native_worker_atomic($worker['private'],'producer-continue',$worker['nonce']);
}

/** Observe the original covering SHARE returning before allowing native INSERT. */
function governance_native_wait_session_covering_control(array $handle) {
  $worker=governance_native_session_producer_handle($handle);
  $path=$worker['private'].'/covering-control-ready.json';$deadline=microtime(true)+8;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>512){
    throw new RuntimeException('native_session_covering_control_missing');}
  $value=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if($value!==array('status'=>'covering-control-returned','mysql_thread'=>$worker['mysql_thread'])){
    throw new RuntimeException('native_session_covering_control_refused');}
}
function governance_native_continue_session_fence(array $handle) {
  $worker=governance_native_session_producer_handle($handle);
  governance_native_worker_atomic($worker['private'],'fence-continue',$worker['nonce']);
}

/** Fixed credential stress decoders; failure/unknown never means denial. */
function governance_native_oauth_concurrency_result(array $result) {
    $keys=array_keys($result);sort($keys);
    if($keys!==array('authentication_code','outcome','restoration_authorized','status')||
      $result['status']!=='committed'||$result['restoration_authorized']!==false||
      !(($result['outcome']==='allowed'&&$result['authentication_code']==='native-success')||
        ($result['outcome']==='denied'&&$result['authentication_code']==='ERR-INVALID-AUTH'))){
      throw new RuntimeException('recovery_oauth_auth_result_refused');}
    return $result;
}

function governance_native_api_concurrency_result(array $result) {
    $keys=array_keys($result);sort($keys);
    $allowed=$keys===array('current_token_verified','restoration_authorized','status')&&
      $result['status']==='committed'&&$result['current_token_verified']===true&&
      $result['restoration_authorized']===false;
    $denied=$keys===array('reason','status')&&$result['status']==='rejected'&&
      $result['reason']==='governance_native_credential_ineligible';
    if(!$allowed&&!$denied){throw new RuntimeException('recovery_api_current_result_refused');}
    return $result;
}

/** Recheck only the existing registered credential worker's owned directory. */
function governance_native_api_concurrency_owner(array $handle) {
  $index=$handle['index']??null;
  if(!is_int($index)||!isset($GLOBALS['governance_native_workers'][$index])){
    throw new RuntimeException('recovery_api_worker_owner_refused');}
  $worker=$GLOBALS['governance_native_workers'][$index];
  if(!in_array($worker['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)||
    ($handle['private']??null)!==$worker['private']){throw new RuntimeException('recovery_api_worker_owner_refused');}
  $stat=lstat($worker['private']);$owner=$worker['private'].'/owner';
  if(!$stat||is_link($worker['private'])||$stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||
    ($stat['mode']&0777)!==0700||!is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||
    filesize($owner)!==64||!hash_equals($worker['nonce'],file_get_contents($owner))){
    throw new RuntimeException('recovery_api_worker_owner_refused');}
}

/** Failure-only startup observation. Never a ready, denial or success substitute. */
function governance_native_api_startup_diagnostic(array$worker,$fixture) {
  global$control;
  $fixture->assertOwned();governance_native_api_concurrency_owner($worker);
  $path=$worker['private'].'/api-phase.json';clearstatcache(true,$path);
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>2048){
    throw new RuntimeException('recovery_api_startup_phase_unavailable');}
  $phase=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
  $phase=governance_native_api_startup_phase($phase,$worker['nonce']);
  $out=array('startup_phase'=>$phase['phase'],'process_running'=>is_resource($worker['process'])&&proc_get_status($worker['process'])['running'],
    'threads'=>array());
  if(!($control instanceof mysqli)){throw new RuntimeException('recovery_api_startup_observer_unavailable');}
  foreach(array('proof_thread','writer_thread','reader_thread','credential_thread')as$role){
    $id=$phase[$role];if($id===null){continue;}
    $row=$control->query('SELECT PROCESSLIST_COMMAND c FROM performance_schema.threads WHERE PROCESSLIST_ID='.(int)$id);
    if(!$row){throw new RuntimeException('recovery_api_startup_observation_failed');}
    $value=$row->fetch_assoc();$row->free();$command=$value===null?'absent':($value['c']==='Sleep'?'sleep':($value['c']==='Query'?'query':'other'));
    $entry=array('role'=>$role,'command'=>$command,'event_creator_is_transaction_owner_proved'=>false,'creator_wait_reference_to_owned_user'=>false,'creator_wait_reference_to_owned_conduit'=>false,'creator_wait_reference_to_owned_oauth'=>false);
    foreach(array('user'=>'creator_wait_reference_to_owned_user','conduit'=>'creator_wait_reference_to_owned_conduit','oauth_server'=>'creator_wait_reference_to_owned_oauth')as$app=>$key){
      $schema=$fixture->getNamespace().'_'.$app;
      $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
        'JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID '.
        'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE '.
        'WHERE t.PROCESSLIST_ID='.(int)$id." AND l.OBJECT_SCHEMA='".$control->real_escape_string($schema)."'";
      $query=$control->query($sql);if(!$query){throw new RuntimeException('recovery_api_startup_observation_failed');}
      $entry[$key]=(int)$query->fetch_assoc()['n']>0;$query->free();
    }
    $out['threads'][]=$entry;
  }
  $result_path=$worker['private'].'/result.json';clearstatcache(true,$result_path);
  if(is_file($result_path)&&!is_link($result_path)&&(fileperms($result_path)&0777)===0600&&filesize($result_path)<=2048){
    $result=json_decode(file_get_contents($result_path),true,8,JSON_THROW_ON_ERROR);
    if(is_array($result)&&($result['status']??null)==='failed'){
      $out['child_failure_location']=governance_native_session_failure_location($result);
    }
  }
  return$out;
}

function governance_native_api_startup_phase(array$phase,$owner) {
  $keys=array_keys($phase);sort($keys);
  if($keys!==array('credential_thread','owner','phase','proof_thread','reader_thread','writer_thread')||
    !is_string($phase['owner'])||!hash_equals($owner,$phase['owner'])||!in_array($phase['phase'],array(
      'payload-verified','bootstrap-loaded','library-loaded','proof-connected','config-default-start','config-default-ready',
      'config-installed','writer-connect-start','writer-connected','user-reader-connect-start','user-reader-connected',
      'user-load-start','user-loaded','context-ready','ready-published','before-current-read','current-read-returned',
      'oauth-reader-connect-start','oauth-reader-connected','before-native-oauth-auth','native-oauth-auth-returned'),true)){
    throw new RuntimeException('recovery_api_startup_phase_refused');}
  foreach(array('proof_thread','writer_thread','reader_thread','credential_thread')as$key){
    if($phase[$key]!==null&&(!is_int($phase[$key])||$phase[$key]<1)){throw new RuntimeException('recovery_api_startup_phase_refused');}}
  return$phase;
}
