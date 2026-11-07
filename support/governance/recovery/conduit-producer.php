<?php
/** Isolated Conduit native producer; no business provider authority. */
require_once __DIR__.'/borrowed-session-producer.php';
function governance_recovery_conduit_native_producer($conn,array$action,$path,array$payload,$thread){
  $keys=array_keys($action);sort($keys);if($keys!==array('action','subject','token')||$action['action']!=='recovery.conduit.borrowed-owner.issue'||!is_string($action['token'])||strlen($action['token'])>256){throw new RuntimeException('native_worker_action_refused');}
  PhorgeRecoveryStopRequest::user($action['subject']);
  $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
  $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
  $request=(new AphrontRequest('fixture.invalid','/people/recovery/session-fixture/'))->setUser($viewer)->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
  $request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
  if(strcmp($context->getUserPHID(),$action['subject'])>=0){throw new RuntimeException('recovery_session_worker_lock_order_unknown');}
  governance_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread));
  governance_native_worker_atomic($path,'producer-identity-started.json',array('status'=>'identity-started','mysql_thread'=>$thread));
  $snapshot=governance_recovery_borrowed_native_transaction($conn,static function()use($context,$conn){
    $current=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);if(!$current->isSystemAdmin()){throw new RuntimeException('recovery_restore_admin_required');}return$current;
  },$thread);
  // No SQL after this COMMIT until the owned parent continue. The observer
  // independently proves the completed RC event, no active trx and no locks.
  governance_native_worker_atomic($path,'borrowed-preflight-ready.json',array('status'=>'borrowed-preflight-committed','mysql_thread'=>$thread));
  $deadline=microtime(true)+12;$released=false;
  do{
    $directory=lstat($path);$owner=$path.'/owner';
    if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||$directory['ino']!==$payload['directory_ino']||($directory['mode']&0777)!==0700||!is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||!hash_equals($payload['owner_token'],file_get_contents($owner))){throw new RuntimeException('recovery_session_producer_owner_changed');}
    $continue=$path.'/producer-continue';if(is_file($continue)&&!is_link($continue)&&(fileperms($continue)&0777)===0600&&filesize($continue)===64&&hash_equals($payload['owner_token'],file_get_contents($continue))){$released=true;break;}usleep(20000);
  }while(microtime(true)<$deadline);
  if(!$released){throw new RuntimeException('recovery_session_producer_handshake_timeout');}
  // Initial native administrator preflight ended before parent capture.
  // No broad issuer query while Store holds its secondary X locks. This
  // source-only test proves neutral INSERT serialization, not an admin lease.
  $token=PhabricatorConduitToken::initializeNewToken($action['subject'],PhabricatorConduitToken::TYPE_STANDARD);
  $token->save();
  if($conn->isInsideTransaction()||$token->establishConnection('w')->isInsideTransaction()){
    throw new RuntimeException('recovery_conduit_terminal_unknown');}
  return array('status'=>'committed','token_id_digest'=>hash('sha256',(string)$token->getID()),
    'restoration_authorized'=>false,'profile'=>'borrowed-owner-native-conduit-v1',
    'native_admin_preflight_before_owner'=>true);

}
/** Separate fixed action; the original Lease handle/decoder remains unchanged. */
function governance_native_borrowed_conduit_handle(array$handle){
  $index=$handle['index']??null;$worker=is_int($index)?($GLOBALS['governance_native_workers'][$index]??null):null;
  if(!$worker||$worker['action']!=='recovery.conduit.borrowed-owner.issue'||$worker['cleaned']||($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||!is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){throw new RuntimeException('borrowed_producer_handle_refused');}
  $stat=lstat($worker['private']);$owner=$worker['private'].'/owner';if(!$stat||is_link($worker['private'])||($stat['mode']&0777)!==0700||$stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||!is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||!hash_equals($worker['nonce'],file_get_contents($owner))){throw new RuntimeException('borrowed_producer_handle_refused');}return$worker;
}
function governance_native_wait_borrowed_conduit_preflight(array$handle,$observer){
  $worker=governance_native_borrowed_conduit_handle($handle);$path=$worker['private'].'/borrowed-preflight-ready.json';$deadline=microtime(true)+8;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  $observe=static function(callable$query)use($deadline){if(microtime(true)>=$deadline){throw new RuntimeException('borrowed_preflight_deadline');}$value=$query();if(microtime(true)>=$deadline){throw new RuntimeException('borrowed_preflight_deadline');}return$value;};
  require_once __DIR__.'/borrowed-session-diagnostic.php';
  $marker=governance_recovery_borrowed_worker_record($worker['private'],'borrowed-preflight-ready.json');
  if($marker!==array('status'=>'borrowed-preflight-committed','mysql_thread'=>$worker['mysql_thread'])){throw new RuntimeException('borrowed_producer_preflight_missing');}
  $consumers=$observe(static fn()=>queryfx_all($observer,'SELECT NAME,ENABLED FROM performance_schema.setup_consumers WHERE NAME IN (%Ls)',array('global_instrumentation','thread_instrumentation','events_transactions_current')));
  $seen=array();foreach($consumers as$row){if(array_keys($row)!==array('NAME','ENABLED')||$row['ENABLED']!=='YES'||isset($seen[$row['NAME']])){throw new RuntimeException('borrowed_preflight_instrumentation_unknown');}$seen[$row['NAME']]=true;}
  $names=array_keys($seen);sort($names);if($names!==array('events_transactions_current','global_instrumentation','thread_instrumentation')){throw new RuntimeException('borrowed_preflight_instrumentation_unknown');}
  $instrument=$observe(static fn()=>queryfx_one($observer,'SELECT ENABLED,TIMED FROM performance_schema.setup_instruments WHERE NAME=%s','transaction'));if($instrument!==array('ENABLED'=>'YES','TIMED'=>'YES')){throw new RuntimeException('borrowed_preflight_instrumentation_unknown');}
  $thread=$observe(static fn()=>queryfx_one($observer,'SELECT INSTRUMENTED FROM performance_schema.threads WHERE PROCESSLIST_ID=%s',$worker['mysql_thread']));if($thread!==array('INSTRUMENTED'=>'YES')){throw new RuntimeException('borrowed_preflight_thread_unknown');}
  $events=$observe(static fn()=>queryfx_all($observer,'SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,CAST(e.END_EVENT_ID AS CHAR) END_EVENT_ID,e.ISOLATION_LEVEL FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=%s',$worker['mysql_thread']));
  $expected=array('thread'=>$events[0]['THREAD_ID']??null,'event'=>$events[0]['EVENT_ID']??null);
  if(PhorgeRecoverySessionProducerFence::leaseClassifyEvent($events,$expected)!=='committed-observed'){throw new RuntimeException('borrowed_preflight_terminal_unproved');}
  $zero=$observe(static fn()=>queryfx_one($observer,'SELECT (SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE TRX_MYSQL_THREAD_ID=%s) active_transactions,(SELECT COUNT(*) FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.THREAD_ID WHERE t.PROCESSLIST_ID=%s AND l.LOCK_TYPE=%s) record_locks',$worker['mysql_thread'],$worker['mysql_thread'],'RECORD'));
  if(!$zero||array_keys($zero)!==array('active_transactions','record_locks')||!in_array($zero['active_transactions'],array(0,'0'),true)||!in_array($zero['record_locks'],array(0,'0'),true)){throw new RuntimeException('borrowed_preflight_locks_unproved');}return true;
}
function governance_native_continue_borrowed_conduit(array$handle){$worker=governance_native_borrowed_conduit_handle($handle);governance_native_worker_atomic($worker['private'],'producer-continue',$worker['nonce']);}
function governance_native_borrowed_conduit_result(array$result){
  $keys=array_keys($result);sort($keys);if($keys!==array('native_admin_preflight_before_owner','profile','restoration_authorized','status','token_id_digest')||$result['status']!=='committed'||$result['profile']!=='borrowed-owner-native-conduit-v1'||$result['native_admin_preflight_before_owner']!==true||$result['restoration_authorized']!==false||!is_string($result['token_id_digest'])||preg_match('/\A[a-f0-9]{64}\z/D',$result['token_id_digest'])!==1){throw new RuntimeException('borrowed_producer_result_refused');}return$result;
}
