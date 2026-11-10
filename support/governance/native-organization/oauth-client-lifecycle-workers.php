<?php
/** Dedicated owned exec registry: only the six fixed native client lifecycle operations.
 * Lifecycle bodies derive from e804 workers; existing Conduit registry is untouched. */
$GLOBALS['governance_oauth_client_lifecycle_native_workers']=array();

function governance_oauth_client_lifecycle_native_worker_atomic($path,$name,$value) {
  if (!in_array($name,array('owner','payload.json','ready.json','result.json','observe-release','producer-ready.json','producer-identity-started.json','borrowed-preflight-ready.json','producer-continue','covering-control-ready.json','fence-continue'),true)) {
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

function governance_oauth_client_lifecycle_native_measurement_file_source($action,$value,$namespace,$owner,$source) {
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

function governance_oauth_client_lifecycle_native_start_worker($fixture,array $action) {
  $fixture->assertOwned();
  $keys=array_keys($action);sort($keys);
  if ($keys!==array('action','client','operation','subject','token')||($action['action']??null)!=='recovery.oauth-client-lifecycle.borrowed-owner.save'||!in_array($action['operation'],array('disabled','redirect','trust','secret','authorization-delete','client-cascade'),true)||!is_string($action['client'])||!preg_match('/\APHID-OASC-[a-zA-Z0-9]{20}\z/D',$action['client'])||!is_string($action['subject'])||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$action['subject'])||!is_string($action['token'])||$action['token']===''||strlen($action['token'])>256) {
    throw new RuntimeException('native_worker_action_refused');
  }
  $path=sys_get_temp_dir().'/governance-native-worker-'.bin2hex(random_bytes(12));
  if (!mkdir($path,0700)) { throw new RuntimeException('native_worker_directory_failed'); }
  // Register immediately: later credential-file/proc failures must be cleaned.
  $stat=lstat($path); $index=count($GLOBALS['governance_oauth_client_lifecycle_native_workers']);
  $nonce=bin2hex(random_bytes(32));
  $GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index]=array('index'=>$index,'private'=>$path,
    'dev'=>$stat['dev'],'ino'=>$stat['ino'],'nonce'=>$nonce,'owner_ready'=>false,
    'process'=>null,'cleaned'=>false,'action'=>$action['action']);
  governance_oauth_client_lifecycle_native_worker_atomic($path,'owner',$nonce);
  $GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index]['owner_ready']=true;
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
    governance_oauth_client_lifecycle_native_measurement_file_source($action['action'],$asset,$fixture->getNamespace(),$fixture_nonce,$payload['source_sha256']);
    $payload['measurement_file']=$asset;
  }
  if(in_array($action['action'],array('recovery.session.issue','recovery.session.borrowed-owner.issue','recovery.oauth-client-lifecycle.borrowed-owner.save','recovery.org.member.insert','recovery.org.approval.propose'),true)){
    $incarnation=PhabricatorEnv::getEnvConfig('governance.account-recovery.incarnation');
    if(!is_string($incarnation)||!preg_match('/\A[a-f0-9]{32}\z/D',$incarnation)){throw new RuntimeException('native_session_producer_incarnation_refused');}
    $payload['recovery_incarnation']=$incarnation;
  }
  governance_oauth_client_lifecycle_native_worker_atomic($path,'payload.json',$payload);
  $process=proc_open(array(PHP_BINARY,__DIR__.'/oauth-client-lifecycle-worker.php',$path),
    array(0=>array('file','/dev/null','r'),1=>array('file',$path.'/stdout.log','a'),
      2=>array('file',$path.'/stderr.log','a')),$pipes,null,
    array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1'));
  if (!is_resource($process)) { throw new RuntimeException('native_worker_start_failed'); }
  $GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index]['process']=$process;
  foreach (array('stdout.log','stderr.log') as $log) { if (is_file($path.'/'.$log)) { chmod($path.'/'.$log,0600); } }
  $deadline=microtime(true)+8;
  while (!is_file($path.'/ready.json') && microtime(true)<$deadline && proc_get_status($process)['running']) {
    usleep(20000);
  }
  if (!is_file($path.'/ready.json')) {
    if(in_array($action['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)){
      try{$GLOBALS['governance_api_concurrency_child_failure']=governance_oauth_client_lifecycle_native_api_startup_diagnostic(
        $GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index],$fixture);}
      catch(Throwable$diagnostic_error){$GLOBALS['governance_api_concurrency_child_failure']=array(
        'diagnostic_unavailable'=>true,'error_type'=>get_class($diagnostic_error));}
    }
    throw new RuntimeException('native_worker_not_ready');
  }
  $ready=json_decode(file_get_contents($path.'/ready.json'),true,8,JSON_THROW_ON_ERROR);
  $keys=array_keys($ready);sort($keys,SORT_STRING);
  if($keys!==array('mysql_thread','oauth_mysql_thread','status')||($ready['status']??null)!=='ready'){throw new RuntimeException('native_worker_ready_refused');}
  require_once dirname(__DIR__).'/recovery/oauth-client-lifecycle-producer.php';
  governance_oauth_client_lifecycle_native_preflight_thread_ids($ready);
  $GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index]['mysql_thread']=$ready['mysql_thread'];
  $GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index]['oauth_mysql_thread']=$ready['oauth_mysql_thread'];
  return $GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index];
}

function governance_oauth_client_lifecycle_native_finish_worker(array &$handle) {
  $index=$handle['index']; $worker=&$GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index];
  require_once dirname(__DIR__).'/recovery/oauth-client-lifecycle-producer.php';
  governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_handle($handle);
  $deadline=microtime(true)+15;$terminal=null;
  if(!is_resource($worker['process'])){throw new RuntimeException('oauth_client_lifecycle_worker_handle_missing');}
  do{
    $status=proc_get_status($worker['process']);
    if(!is_array($status)||!is_bool($status['running']??null)){throw new RuntimeException('oauth_client_lifecycle_worker_terminal_unknown');}
    if($status['running']===false){$terminal=$status;break;}
    usleep(20000);
  }while(microtime(true)<$deadline);
  if($terminal===null){throw new RuntimeException('native_worker_timeout');}
  // Capture the actual terminal status before proc_close may consume/cache it.
  // A cached -1 or an exit7 never becomes zero because JSON claims committed.
  $exit=$terminal['exitcode']??null;$closed=proc_close($worker['process']);
  $worker['process']=null;$handle['process']=null;
  if(!is_int($exit)||$exit!==0||!is_int($closed)||!in_array($closed,array(0,-1),true)){
    throw new RuntimeException('oauth_client_lifecycle_worker_nonzero_or_unknown_exit');
  }
  $path=$worker['private'].'/result.json';
  if (!is_file($path) || is_link($path) || filesize($path)>65536) {
    throw new RuntimeException('native_worker_result_missing');
  }
  $result=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
  if (!is_array($result)) { throw new RuntimeException('native_worker_result_refused'); }
  governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_handle($handle);
  if((fileperms($path)&07777)!==0600){throw new RuntimeException('oauth_client_lifecycle_producer_result_refused');}
  return governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_result($result);
}

function governance_oauth_client_lifecycle_native_cleanup_workers() {
  $removed=0; $stopped=0; $failures=0;
  foreach ($GLOBALS['governance_oauth_client_lifecycle_native_workers'] as &$worker) {
    if ($worker['cleaned']) { continue; }
    try {
    if(($GLOBALS['governance_recovery_oauth_client_lifecycle_cleanup_proved']??null)!==true||!governance_recovery_borrowed_cleanup_ready()){throw new RuntimeException('oauth_client_lifecycle_cleanup_unproved');}
    if(is_resource($worker['process'])){throw new RuntimeException('oauth_client_lifecycle_worker_retained');}
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
  foreach ($GLOBALS['governance_oauth_client_lifecycle_native_workers'] as $worker) {
    if (!$worker['cleaned']) { $remaining++; }
    if (is_resource($worker['process']) && proc_get_status($worker['process'])['running']) { $live++; }
  }
  return array('private_directories_removed'=>$removed,
    'owned_processes_stopped'=>$stopped,'cleanup_failures'=>$failures,
    'private_directories_remaining'=>$remaining,'live_processes_after_cleanup'=>$live,
    'workers_registered'=>count($GLOBALS['governance_oauth_client_lifecycle_native_workers']));
}

function governance_oauth_client_lifecycle_native_stop_fixture_handles(array $handles){
  foreach($handles as$handle){
    $index=$handle['index']??null;
    if(!is_int($index)||!isset($GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index])){throw new RuntimeException('native_worker_handle_refused');}
    $worker=&$GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index];
    if(($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||
      !is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){throw new RuntimeException('native_worker_handle_refused');}
    // No automatic TERM/KILL/close, including an unknown child or owner.
    // The only accepted path is finish_worker's already-consumed known exit0.
    if(is_resource($worker['process'])){throw new RuntimeException('oauth_client_lifecycle_worker_retained');}
    unset($worker);
  }
}
