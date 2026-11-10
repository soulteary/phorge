<?php
/** Fixed isolated native lifecycle writer. Never a production grant/admission. */
function governance_oauth_client_lifecycle_operations(){return array('disabled','redirect','trust','secret','authorization-delete','client-cascade');}
function governance_oauth_client_lifecycle_row($conn,$id){
  return queryfx_one($conn,'SELECT id,phid,name,secret,redirectURI,creatorPHID,isTrusted,viewPolicy,editPolicy,dateCreated,dateModified,isDisabled FROM %R WHERE id=%d',new PhabricatorOAuthServerClient(),$id);
}
function governance_recovery_oauth_client_lifecycle_native_producer($conn,array$action,$path,array$payload,$thread){
  $keys=array_keys($action);sort($keys,SORT_STRING);
  if($keys!==array('action','client','operation','subject','token')||$action['action']!=='recovery.oauth-client-lifecycle.borrowed-owner.save'||
    !in_array($action['operation'],governance_oauth_client_lifecycle_operations(),true)||!is_string($action['token'])||$action['token']===''||strlen($action['token'])>256||
    !is_string($action['client'])||preg_match('/\APHID-OASC-[a-zA-Z0-9]{20}\z/D',$action['client'])!==1){throw new RuntimeException('native_worker_action_refused');}
  PhorgeRecoveryStopRequest::user($action['subject']);
  $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$action['token']);
  if(!$viewer){throw new RuntimeException('governance_native_credential_ineligible');}
  $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$action['token'];
  $request=(new AphrontRequest('fixture.invalid','/people/recovery/session-fixture/'))->setUser($viewer)->setRequestData(array('__form__'=>true,AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
  $request->validateCSRF();$context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
  $subject=(new PhabricatorUser())->loadOneWhere('phid=%s',$action['subject']);
  $client=(new PhabricatorOAuthServerClient())->loadOneWhere('phid=%s',$action['client']);
  if(!$subject||!$client||$client->getCreatorPHID()!==$viewer->getPHID()||!str_starts_with($client->getName(),'Synthetic lifecycle ')||
    !str_starts_with($client->getRedirectURI(),'https://fixture.invalid/lifecycle/')||PhabricatorOAuthServerScope::getScopeMap()!==array()){
    throw new RuntimeException('oauth_client_lifecycle_fixture_identity_unknown');}
  $oauth=$client->establishConnection('w');$stateReader=new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState');
  if(get_class($oauth)!==AphrontMySQLiDatabaseConnection::class||$oauth===$conn||$oauth->isInsideTransaction()||$stateReader->invoke($oauth)->getDepth()!==0){throw new RuntimeException('oauth_client_lifecycle_writer_unknown');}
  $oauthId=queryfx_one($oauth,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u');$userId=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u');
  if(!$oauthId||!$userId||$userId['id']!==(string)$thread||$oauthId['id']===$userId['id']||$oauthId['u']!==$userId['u']){throw new RuntimeException('oauth_client_lifecycle_primary_unknown');}
  $clientID=(int)$client->getID();$old=governance_oauth_client_lifecycle_row($oauth,$clientID);$rows=array();
  foreach(array(new PhabricatorOAuthClientAuthorization(),new PhabricatorOAuthServerAccessToken(),new PhabricatorOAuthServerAuthorizationCode())as$table){
    $owned=$table->loadAllWhere('clientPHID=%s',$client->getPHID());
    if(count($owned)!==1){throw new RuntimeException('oauth_client_lifecycle_fixture_rows_unknown');}
    $row=head($owned);if($row->getUserPHID()!==$subject->getPHID()){throw new RuntimeException('oauth_client_lifecycle_foreign_subject');}$rows[]=$row;
  }
  $rowIDs=array_map(static fn($row)=>(int)$row->getID(),$rows);
  queryfx($oauth,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
  $oauth->openTransaction();
  if(queryfx_one($oauth,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u')!==$oauthId){throw new RuntimeException('oauth_client_lifecycle_preflight_identity_changed');}
  $oauth->saveTransaction();
  if($oauth->isInsideTransaction()||$stateReader->invoke($oauth)->getDepth()!==0){throw new RuntimeException('oauth_client_lifecycle_preflight_unknown');}
  // Existing native administrator validation ends before parent Store locks.
  if(get_class($conn)!==AphrontMySQLiDatabaseConnection::class||$conn->isInsideTransaction()||$stateReader->invoke($conn)->getDepth()!==0){throw new RuntimeException('oauth_client_lifecycle_preflight_unknown');}
  queryfx($conn,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
  $conn->openTransaction();
  $current=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
  if(!$current->isSystemAdmin()){throw new RuntimeException('recovery_restore_admin_required');}
  // Any Throwable before or during COMMIT leaves this new fixture writer
  // unproved. Do not issue ROLLBACK/close from depth bookkeeping alone.
  $conn->saveTransaction();
  if($conn->isInsideTransaction()||$stateReader->invoke($conn)->getDepth()!==0){throw new RuntimeException('oauth_client_lifecycle_preflight_unknown');}
  governance_oauth_client_lifecycle_native_worker_atomic($path,'ready.json',array('status'=>'ready','mysql_thread'=>$thread,'oauth_mysql_thread'=>(int)$oauthId['id']));
  governance_oauth_client_lifecycle_native_worker_atomic($path,'borrowed-preflight-ready.json',array('status'=>'borrowed-preflight-committed','mysql_thread'=>$thread,'oauth_mysql_thread'=>(int)$oauthId['id']));
  $deadline=microtime(true)+20;$released=false;
  do{
    $directory=lstat($path);$owner=$path.'/owner';
    if(!$directory||is_link($path)||$directory['dev']!==$payload['directory_dev']||$directory['ino']!==$payload['directory_ino']||($directory['mode']&07777)!==0700||
      !is_file($owner)||is_link($owner)||(fileperms($owner)&07777)!==0600||filesize($owner)!==64||!hash_equals($payload['owner_token'],file_get_contents($owner))){throw new RuntimeException('oauth_client_lifecycle_worker_owner_changed');}
    $continue=$path.'/producer-continue';
    if(is_file($continue)&&!is_link($continue)&&(fileperms($continue)&07777)===0600&&filesize($continue)===64&&hash_equals($payload['owner_token'],file_get_contents($continue))){$released=true;break;}
    usleep(20000);
  }while(microtime(true)<$deadline);
  if(!$released){throw new RuntimeException('oauth_client_lifecycle_handshake_timeout');}
  $operation=$action['operation'];
  // No identity/credential read after continue: the parent may hold those rows.
  // This is an isolated synthetic administrator preflight, not a continuous lease.
  if($operation==='authorization-delete'){$rows[0]->delete();}
  else if($operation==='client-cascade'){$client->destroyObjectPermanently(new PhabricatorDestructionEngine());}
  else{
    if($operation==='disabled'){$client->setIsDisabled((int)$old['isDisabled']===0);$field='isDisabled';}
    else if($operation==='trust'){$client->setIsTrusted((int)$old['isTrusted']===0);$field='isTrusted';}
    else if($operation==='redirect'){$client->setRedirectURI('https://fixture.invalid/lifecycle/changed/');$field='redirectURI';}
    else{$client->setSecret(str_repeat($old['secret']===str_repeat('e',32)?'f':'e',32));$field='secret';}
    $client->save();
  }
  $nowOAuth=queryfx_one($oauth,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u');$nowUser=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u');
  if($nowOAuth!==$oauthId||$nowUser!==$userId||$oauth->isInsideTransaction()||$conn->isInsideTransaction()||
    $stateReader->invoke($oauth)->getDepth()!==0||$stateReader->invoke($conn)->getDepth()!==0){throw new RuntimeException('oauth_client_lifecycle_terminal_unknown');}
  $after=governance_oauth_client_lifecycle_row($oauth,$clientID);$counts=array();
  foreach(array(new PhabricatorOAuthClientAuthorization(),new PhabricatorOAuthServerAccessToken(),new PhabricatorOAuthServerAuthorizationCode())as$index=>$table){$counts[]=$table->load($rowIDs[$index])?1:0;}
  if($operation==='client-cascade'){
    if($after!==null||$counts!==array(0,0,0)){throw new RuntimeException('oauth_client_lifecycle_cascade_postcondition');}
  }else if($operation==='authorization-delete'){
    if($after!==$old||$counts!==array(0,1,1)){throw new RuntimeException('oauth_client_lifecycle_authorization_delete_postcondition');}
  }else{
    if(!$after||$counts!==array(1,1,1)||$after[$field]===$old[$field]){throw new RuntimeException('oauth_client_lifecycle_update_postcondition');}
    foreach($old as$key=>$value){if(!in_array($key,array($field,'dateModified'),true)&&$after[$key]!==$value){throw new RuntimeException('oauth_client_lifecycle_unexpected_update');}}
  }
  return array('status'=>'committed','native_terminal_known'=>true,'profile'=>'native-oauth-client-lifecycle-v1','operation'=>$operation,
    'subject_id_digest'=>hash('sha256',(string)$subject->getID()),'subject_phid_digest'=>hash('sha256',$subject->getPHID()),
    'client_id_digest'=>hash('sha256',(string)$clientID),'client_phid_digest'=>hash('sha256',$client->getPHID()),
    'authorization_id_digest'=>hash('sha256',(string)$rowIDs[0]),'before_client_digest'=>hash('sha256',json_encode($old,JSON_THROW_ON_ERROR)),
    'after_client_digest'=>$after===null?null:hash('sha256',json_encode($after,JSON_THROW_ON_ERROR)),'remaining_rows'=>$counts,
    'native_admin_preflight_before_owner'=>true,'continuous_admin_lease_proved'=>false,'restoration_authorized'=>false);
}

/** Separate fixed action; the original Lease handle/decoder remains unchanged. */
function governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_handle(array$handle){
  $index=$handle['index']??null;$worker=is_int($index)?($GLOBALS['governance_oauth_client_lifecycle_native_workers'][$index]??null):null;
  if(!$worker||$worker['action']!=='recovery.oauth-client-lifecycle.borrowed-owner.save'||$worker['cleaned']||($handle['dev']??null)!==$worker['dev']||($handle['ino']??null)!==$worker['ino']||!is_string($handle['nonce']??null)||!hash_equals($worker['nonce'],$handle['nonce'])){throw new RuntimeException('borrowed_producer_handle_refused');}
  $stat=lstat($worker['private']);$owner=$worker['private'].'/owner';if(!$stat||is_link($worker['private'])||($stat['mode']&0777)!==0700||$stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||!is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||filesize($owner)!==64||!hash_equals($worker['nonce'],file_get_contents($owner))){throw new RuntimeException('borrowed_producer_handle_refused');}return$worker;
}
function governance_oauth_client_lifecycle_native_wait_borrowed_oauth_client_lifecycle_preflight(array$handle,$observer){
  $worker=governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_handle($handle);$path=$worker['private'].'/borrowed-preflight-ready.json';$deadline=microtime(true)+8;
  while(!is_file($path)&&microtime(true)<$deadline&&is_resource($worker['process'])&&proc_get_status($worker['process'])['running']){usleep(20000);}
  $observe=static function(callable$query)use($deadline){if(microtime(true)>=$deadline){throw new RuntimeException('borrowed_preflight_deadline');}$value=$query();if(microtime(true)>=$deadline){throw new RuntimeException('borrowed_preflight_deadline');}return$value;};
  require_once __DIR__.'/borrowed-session-diagnostic.php';
  $marker=governance_recovery_borrowed_worker_record($worker['private'],'borrowed-preflight-ready.json');
  if($marker!==array('status'=>'borrowed-preflight-committed','mysql_thread'=>$worker['mysql_thread'],'oauth_mysql_thread'=>$worker['oauth_mysql_thread'])){throw new RuntimeException('borrowed_producer_preflight_missing');}
  foreach(governance_oauth_client_lifecycle_native_preflight_thread_ids($worker) as $observed_thread){
  $consumers=$observe(static fn()=>queryfx_all($observer,'SELECT NAME,ENABLED FROM performance_schema.setup_consumers WHERE NAME IN (%Ls)',array('global_instrumentation','thread_instrumentation','events_transactions_current')));
  $seen=array();foreach($consumers as$row){if(array_keys($row)!==array('NAME','ENABLED')||$row['ENABLED']!=='YES'||isset($seen[$row['NAME']])){throw new RuntimeException('borrowed_preflight_instrumentation_unknown');}$seen[$row['NAME']]=true;}
  $names=array_keys($seen);sort($names);if($names!==array('events_transactions_current','global_instrumentation','thread_instrumentation')){throw new RuntimeException('borrowed_preflight_instrumentation_unknown');}
  $instrument=$observe(static fn()=>queryfx_one($observer,'SELECT ENABLED,TIMED FROM performance_schema.setup_instruments WHERE NAME=%s','transaction'));if($instrument!==array('ENABLED'=>'YES','TIMED'=>'YES')){throw new RuntimeException('borrowed_preflight_instrumentation_unknown');}
  $thread=$observe(static fn()=>queryfx_one($observer,'SELECT INSTRUMENTED FROM performance_schema.threads WHERE PROCESSLIST_ID=%s',$observed_thread));if($thread!==array('INSTRUMENTED'=>'YES')){throw new RuntimeException('borrowed_preflight_thread_unknown');}
  $events=$observe(static fn()=>queryfx_all($observer,'SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,CAST(e.END_EVENT_ID AS CHAR) END_EVENT_ID,e.ISOLATION_LEVEL FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=%s',$observed_thread));
  $expected=array('thread'=>$events[0]['THREAD_ID']??null,'event'=>$events[0]['EVENT_ID']??null);
  if(PhorgeRecoverySessionProducerFence::leaseClassifyEvent($events,$expected)!=='committed-observed'){throw new RuntimeException('borrowed_preflight_terminal_unproved');}
  $zero=$observe(static fn()=>queryfx_one($observer,'SELECT (SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE TRX_MYSQL_THREAD_ID=%s) active_transactions,(SELECT COUNT(*) FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.THREAD_ID WHERE t.PROCESSLIST_ID=%s AND l.LOCK_TYPE=%s) record_locks',$observed_thread,$observed_thread,'RECORD'));
  if(!$zero||array_keys($zero)!==array('active_transactions','record_locks')||!in_array($zero['active_transactions'],array(0,'0'),true)||!in_array($zero['record_locks'],array(0,'0'),true)){throw new RuntimeException('borrowed_preflight_locks_unproved');}  }
  return true;
}
function governance_oauth_client_lifecycle_native_continue_borrowed_oauth_client_lifecycle(array$handle){$worker=governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_handle($handle);governance_oauth_client_lifecycle_native_worker_atomic($worker['private'],'producer-continue',$worker['nonce']);}
function governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_result(array$result){
  $keys=array_keys($result);sort($keys,SORT_STRING);
  $wanted=array('status','native_terminal_known','profile','operation','subject_id_digest','subject_phid_digest','client_id_digest','client_phid_digest','authorization_id_digest','before_client_digest','after_client_digest','remaining_rows','native_admin_preflight_before_owner','continuous_admin_lease_proved','restoration_authorized');sort($wanted,SORT_STRING);
  if($keys!==$wanted||$result['status']!=='committed'||$result['native_terminal_known']!==true||$result['profile']!=='native-oauth-client-lifecycle-v1'||
    !in_array($result['operation'],governance_oauth_client_lifecycle_operations(),true)||$result['native_admin_preflight_before_owner']!==true||
    $result['continuous_admin_lease_proved']!==false||$result['restoration_authorized']!==false){throw new RuntimeException('oauth_client_lifecycle_result_refused');}
  foreach(array('subject_id_digest','subject_phid_digest','client_id_digest','client_phid_digest','authorization_id_digest','before_client_digest')as$key){
    if(!is_string($result[$key])||preg_match('/\A[a-f0-9]{64}\z/D',$result[$key])!==1){throw new RuntimeException('oauth_client_lifecycle_result_refused');}}
  $operation=$result['operation'];
  if($operation==='client-cascade'){
    if($result['after_client_digest']!==null||$result['remaining_rows']!==array(0,0,0)){throw new RuntimeException('oauth_client_lifecycle_result_refused');}
  }else{
    if(!is_string($result['after_client_digest'])||preg_match('/\A[a-f0-9]{64}\z/D',$result['after_client_digest'])!==1){throw new RuntimeException('oauth_client_lifecycle_result_refused');}
    if($operation==='authorization-delete'){
      if($result['after_client_digest']!==$result['before_client_digest']||$result['remaining_rows']!==array(0,1,1)){throw new RuntimeException('oauth_client_lifecycle_result_refused');}
    }else if($result['after_client_digest']===$result['before_client_digest']||$result['remaining_rows']!==array(1,1,1)){throw new RuntimeException('oauth_client_lifecycle_result_refused');}
  }
  return$result;
}

/** Exact physical IDs; no caller-selected or duplicated thread may enter proof. */
function governance_oauth_client_lifecycle_native_preflight_thread_ids(array $worker){
  $user=$worker['mysql_thread']??null;$oauth=$worker['oauth_mysql_thread']??null;
  if(!is_int($user)||$user<1||!is_int($oauth)||$oauth<1||$user===$oauth){throw new RuntimeException('oauth_client_lifecycle_physical_threads_unknown');}
  return array($user,$oauth);
}
function governance_oauth_client_lifecycle_native_threads_ended($observer,array $handles){
  $both=array();foreach($handles as$handle){foreach(governance_oauth_client_lifecycle_native_preflight_thread_ids($handle)as$id){$both[]=array('mysql_thread'=>$id);}}
  governance_recovery_session_lease_workers_ended($observer,$both);
}
