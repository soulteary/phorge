<?php

/** Owned audit/outbox/queue/native-handler profile. No Go daemon or SMTP claim. */
function governance_notice_queue_http(array $queue,$operation,array $body,$owner){
  if(!in_array($operation,array('enqueue-event','lease','finalize'),true)||
    !preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$queue['uri']??'',$port)||(int)$port[1]>65535||
    !preg_match('/\A[a-f0-9]{64}\z/D',$queue['token']??'')||!preg_match('/\Anotice-[a-f0-9]{32}\z/D',$owner)){
    throw new RuntimeException('governance_notice_fixture_queue_refused');}
  $future=(new HTTPSFuture($queue['uri'].'/api/queue/'.$operation))->setTimeout(5)->setMethod('POST')
    ->addHeader('Content-Type','application/json')->addHeader('X-Service-Token',$queue['token'])
    ->addHeader('X-Lease-Owner',$owner)->setData(phutil_json_encode($body));
  list($status,$raw)=$future->resolve();
  if(!($status instanceof HTTPFutureHTTPResponseStatus)||$status->getStatusCode()!==200||strlen($raw)>262144){
    throw new RuntimeException('governance_notice_fixture_queue_failed');}
  $envelope=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($envelope)||($envelope['error']??null)!==null||!isset($envelope['data'])){
    throw new RuntimeException('governance_notice_fixture_queue_failed');}
  return $envelope['data'];
}
function governance_notice_fixture_fingerprint($fixture){
  $fixture->assertOwned();$all=array();
  foreach(array('user'=>array('organization_unit','organization_audit','organization_operation','organization_guard'),
    'metamta'=>array('governance_notice_event','governance_notice_delivery','metamta_gorgeoutbox','metamta_mail','edge','edgedata'))as$app=>$tables){
    foreach($tables as$table){$rows=governance_native_setup_query($fixture,'SELECT * FROM '.$fixture->table($app,$table))->fetch_all(MYSQLI_ASSOC);
      usort($rows,static function($a,$b){return strcmp(json_encode($a),json_encode($b));});$all[$app.'.'.$table]=$rows;}
  }
  return hash('sha256',json_encode($all,JSON_THROW_ON_ERROR));
}
/** PROCESSLIST may expose the active trigger statement rather than its INSERT. */
function governance_notice_insert_wait_pattern(){
  return '/(?:INSERT INTO.*\bgovernance_notice_event\b|SELECT.*\bnotice_fixture_gate\b)/is';
}
/** Failure-only fixed classification. Never serialize SQL, reason or payload. */
function governance_notice_wait_projection($info,$result){
  $query='missing';
  if($info!==false){$query=$info===null?'idle':(
    preg_match('/INSERT INTO.*governance_notice_event/is',$info)?'notice_insert':(
      preg_match('/SELECT.*notice_fixture_gate/is',$info)?'gate_select':'other_sql'));}
  $out=array('phase'=>'independent-commit-wait','query_kind'=>$query,'child_status'=>'unavailable');
  if(is_array($result)&&in_array($result['status']??null,array('committed','rejected','failed'),true)){
    $out['child_status']=$result['status'];
    if(($result['status']??null)==='rejected'){
      $out['rejection_kind']=in_array($result['reason']??null,array('organization_account_ineligible',
        'governance_notice_runtime_profile_refused','governance_notice_delivery_not_ready',
        'governance_notice_primary_required','governance_notice_innodb_required'),true)?$result['reason']:'other_fixed_refusal';
    }
    if(($result['status']??null)==='failed'){
      if(is_string($result['error_type']??null)&&preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/D',$result['error_type'])){$out['error_type']=$result['error_type'];}
      if(is_string($result['source_file']??null)&&preg_match('/\A[A-Za-z0-9_.-]{1,128}\.php\z/D',$result['source_file'])){$out['source_file']=$result['source_file'];}
      if(is_int($result['source_line']??null)&&$result['source_line']>0){$out['source_line']=$result['source_line'];}
    }
  }
  return$out;
}
function governance_notice_wait_breadcrumb(mysqli $observer,array $handle,$holder){
  $index=$handle['index']??null;$worker=$GLOBALS['governance_native_workers'][$index]??null;
  if(!is_array($worker)||$worker['action']!=='org.notice.create'||$worker['dev']!==($handle['dev']??null)||
    $worker['ino']!==($handle['ino']??null)||!hash_equals($worker['nonce'],$handle['nonce']??'')){
    throw new RuntimeException('governance_notice_diagnostic_handle_refused');}
  $info=false;
  foreach($observer->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC)as$row){
    if((int)$row['Id']===$worker['mysql_thread']){$info=$row['Info'];break;}}
  $result=null;$path=$worker['private'].'/result.json';
  if(is_file($path)&&!is_link($path)&&(fileperms($path)&0777)===0600&&filesize($path)<=65536){
    $result=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}
  $out=governance_notice_wait_projection($info,$result);
  $out['child_running']=is_resource($worker['process'])&&proc_get_status($worker['process'])['running'];
  $wait=$observer->query('SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
    'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
    'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
    'WHERE r.PROCESSLIST_ID='.(int)$worker['mysql_thread'].' AND b.PROCESSLIST_ID='.(int)$holder);
  if(!$wait){throw new RuntimeException('governance_notice_diagnostic_observer_failed');}
  $out['exact_holder_lock_wait']=(int)$wait->fetch_assoc()['n']>0;$wait->free();return$out;
}
/** A real second PHP process waits inside the qualified INSERT trigger. */
function governance_notice_independent_commit($fixture,callable $check,array $auth){
  global $control;
  if(!($control instanceof mysqli)){throw new RuntimeException('governance_notice_observer_required');}
  $fixture->assertOwned();$ns=$fixture->getNamespace();
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$ns)){throw new RuntimeException('governance_notice_namespace_refused');}
  $gate='`'.$ns.'_metamta`.`notice_fixture_gate`';$trigger='`'.$ns.'_metamta`.`notice_fixture_wait`';
  $setup=$fixture->getAdmin();$owned=false;$worker=null;$created=false;$installed=false;
  $visible=static function()use($fixture,$control){
    $counts=array();
    foreach(array(array('user','organization_unit','unitID'),array('user','organization_audit','operationID'),
      array('user','organization_operation','operationID'),array('metamta','governance_notice_event','operationDigest'))as$entry){
      list($app,$table,$key)=$entry;$result=$control->query('SELECT COUNT(*) n FROM '.$fixture->table($app,$table).
        ' WHERE `'.$key."`='".($table==='governance_notice_event'?hash('sha256','notice-independent'):'notice-independent')."'");if(!$result){throw new RuntimeException('governance_notice_observer_failed');}
      $counts[]=(int)$result->fetch_assoc()['n'];$result->free();
    }
    $result=$control->query('SELECT COUNT(*) n FROM '.$fixture->table('metamta','metamta_gorgeoutbox').' r JOIN '.
      $fixture->table('metamta','governance_notice_event')." e ON r.eventID=CONCAT('governance.notice/',e.eventID,'/1') WHERE e.operationDigest=SHA2('notice-independent',256)");
    if(!$result){throw new RuntimeException('governance_notice_observer_failed');}$counts[]=(int)$result->fetch_assoc()['n'];$result->free();return $counts;
  };
  try{
    governance_native_setup_query($fixture,'CREATE TABLE '.$gate.' (id INT PRIMARY KEY) ENGINE=InnoDB');$created=true;
    governance_native_setup_query($fixture,'INSERT INTO '.$gate.' (id) VALUES (1)');
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('metamta','governance_notice_event').
      " FOR EACH ROW BEGIN DECLARE gate_id INT; IF NEW.operationDigest=SHA2('notice-independent',256) THEN SELECT id INTO gate_id FROM ".$gate.' WHERE id=1 FOR UPDATE; END IF; END');$installed=true;
    if(!$setup->begin_transaction()){throw new RuntimeException('governance_notice_parent_lock_failed');}$owned=true;
    if(!$setup->query('SELECT id FROM '.$gate.' WHERE id=1 FOR UPDATE')){throw new RuntimeException('governance_notice_parent_lock_failed');}
    $worker=governance_native_start_worker($fixture,array('action'=>'org.notice.create','token'=>$auth['token'],
      'payload'=>array('operation_id'=>'notice-independent','unit_id'=>'notice-independent','kind'=>'team','name'=>'PRIVATE_NOTICE_INDEPENDENT','parent_id'=>null)));
    try{governance_native_wait_worker_query($control,array($worker),governance_notice_insert_wait_pattern());}
    catch(Throwable $error){
      try{$GLOBALS['governance_notice_wait_failure']=governance_notice_wait_breadcrumb($control,$worker,$setup->thread_id);}
      catch(Throwable $diagnostic){$GLOBALS['governance_notice_wait_failure']=array('phase'=>'independent-commit-wait','diagnostic_failed'=>true);}
      throw$error;
    }
    $deadline=microtime(true)+5;$waited=false;
    do{
      $result=$control->query('SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
        'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
        'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
        'WHERE r.PROCESSLIST_ID='.(int)$worker['mysql_thread'].' AND b.PROCESSLIST_ID='.(int)$setup->thread_id);
      if(!$result){throw new RuntimeException('governance_notice_wait_observation_failed');}
      $waited=(int)$result->fetch_assoc()['n']>0;$result->free();if($waited){break;}usleep(20000);
    }while(microtime(true)<$deadline);
    $check($waited,'notice-independent-native-process-real-insert-lock-wait-proved');
    $check($visible()===array(0,0,0,0,0),'notice-independent-reader-sees-no-business-audit-event-or-relay-before-commit');
    if(!$setup->commit()){throw new RuntimeException('governance_notice_parent_release_failed');}$owned=false;
    $result=governance_native_finish_worker($worker);
    $check($result===array('status'=>'committed','replayed'=>false)&&$visible()===array(1,1,1,1,1),
      'notice-independent-reader-sees-business-audit-event-and-relay-only-after-real-commit');
  }finally{
    $cleanup_failed=false;
    try{if($owned&&!$setup->rollback()){$cleanup_failed=true;}}catch(Throwable $error){$cleanup_failed=true;}
    try{if($worker!==null){governance_native_stop_fixture_handles(array($worker));}}catch(Throwable $error){$cleanup_failed=true;}
    try{if($installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}}catch(Throwable $error){$cleanup_failed=true;}
    try{if($created){governance_native_setup_query($fixture,'DROP TABLE '.$gate);}}catch(Throwable $error){$cleanup_failed=true;}
    if($cleanup_failed){throw new RuntimeException('governance_notice_fixture_cleanup_failed');}
  }
}
function governance_notice_cases($fixture,callable $check,array $queue){
  $fixture->assertOwned();$saved_cookies=$_COOKIE;$saved_method=$_SERVER['REQUEST_METHOD']??null;$scope=null;$trigger=null;
  if(PhorgeGovernanceNoticeOutbox::enabled()){throw new RuntimeException('governance_notice_default_not_off');}
  $user=(new PhabricatorUser())->setUsername('gnnotice'.substr($fixture->getNamespace(),-12))
    ->setRealName('Synthetic notice administrator')->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
  $email=(new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
    ->setIsPrimary(1)->setIsVerified(1)->save();
  $ordinary=(new PhabricatorUser())->setUsername('gnnoticeordinary'.substr($fixture->getNamespace(),-12))
    ->setRealName('Synthetic ordinary notice user')->setIsApproved(1)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($ordinary->getPHID())->setAddress($ordinary->getUsername().'@example.invalid')
    ->setIsPrimary(1)->setIsVerified(1)->save();
  try{
    $auth=governance_native_request($user,new PhabricatorAuthSessionEngine());
    $service=PhorgeOrganizationService::newForActor($auth['context']);
    $event_dao=new PhabricatorMetaMTAGorgeOutbox();$meta=$event_dao->establishConnection('w');
    $count=static function()use($meta){return (int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_event')['n'];};
    $start=$count();$input=array('operation_id'=>'notice-off','unit_id'=>'notice-off','kind'=>'team','name'=>'PRIVATE_NOTICE_NAME','parent_id'=>null);
    $service->createUnit($input);$check($count()===$start,'notice-disabled-real-organization-write-creates-no-event');
    $service_token=bin2hex(random_bytes(32));
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled',true)
      ->overrideEnvConfig('governance.recovery-stop-ledger.enabled',false)
      ->overrideEnvConfig('gorge.conduit.uri',$queue['uri'])->overrideEnvConfig('gorge.conduit.token',$service_token);
    $input['operation_id']='notice-on';$input['unit_id']='notice-on';$service->createUnit($input);
    $rows=queryfx_all($meta,'SELECT eventID,metadataJSON,metadataDigest FROM governance_notice_event WHERE operationDigest=%s',hash('sha256','notice-on'));
    $check(count($rows)===1&&$count()===$start+1,'notice-real-native-audit-and-qualified-outbox-commit-together');$event=head($rows);
    $metadata=json_decode($event['metadataJSON'],true,16,JSON_THROW_ON_ERROR);$source=PhorgeGovernanceNoticeSource::load($metadata);
    $check($source['event']['event_id']===$event['eventID']&&!str_contains($event['metadataJSON'],'PRIVATE_NOTICE')&&
      !str_contains($event['metadataJSON'],'notice-on')&&!str_contains($event['metadataJSON'],$user->getPHID())&&
      !array_intersect(array('name','beforeJSON','afterJSON','reason','email','allowed','actor_phid','operation_id'),array_keys($metadata)),
      'notice-event-binds-actual-audit-without-private-body-name-or-authority');
    $replay=$service->createUnit($input);$check($replay['replayed']===true&&$count()===$start+1,'notice-native-operation-replay-does-not-duplicate-event');
    $relay=queryfx_one($meta,'SELECT eventID,payload FROM metamta_gorgeoutbox WHERE eventID=%s','governance.notice/'.$event['eventID'].'/1');
    $payload=json_decode($relay['payload'],true,16,JSON_THROW_ON_ERROR);$owner='notice-'.bin2hex(random_bytes(16));
    $task=governance_notice_queue_http($queue,'enqueue-event',$payload,$owner);
    $duplicate=governance_notice_queue_http($queue,'enqueue-event',$payload,$owner);
    $check(is_int($task['id']??null)&&$task['id']>0&&$duplicate['id']===$task['id'],
      'notice-real-gorge-event-inbox-replay-has-one-task');
    $leases=governance_notice_queue_http($queue,'lease',array('limit'=>1,'taskClasses'=>array(PhorgeGovernanceNoticeWorker::class)),$owner);
    if(count($leases)!==1||$leases[0]['id']!==$task['id']||$leases[0]['leaseOwner']!==$owner||
      ($leases[0]['taskClass']??null)!==PhorgeGovernanceNoticeWorker::class||($leases[0]['data']??null)!==$payload['task']['data']){throw new RuntimeException('governance_notice_fixture_lease_refused');}
    $lease=$leases[0];$prior_token=$_SERVER['HTTP_X_SERVICE_TOKEN']??null;$_SERVER['HTTP_X_SERVICE_TOKEN']=$service_token;
    try{$result=(new ConduitCall('worker.execute',array('taskID'=>$lease['id'],'taskClass'=>PhorgeGovernanceNoticeWorker::class,
      'phase'=>'execute','executionVersion'=>1,'failureCount'=>(int)$lease['failureCount'],'priority'=>(int)$lease['priority'],
      'leaseOwner'=>$owner,'leaseExpires'=>$lease['leaseExpires'],'data'=>$payload['task']['data'])))->execute();}
    finally{if($prior_token===null){unset($_SERVER['HTTP_X_SERVICE_TOKEN']);}else{$_SERVER['HTTP_X_SERVICE_TOKEN']=$prior_token;}}
    $check(($result['result']??null)==='success'&&($result['followups']??null)===array(),
      'notice-real-gorge-lease-to-native-conduit-handler-prepares-mail-outbox');
    $finalized=governance_notice_queue_http($queue,'finalize',array('taskID'=>$lease['id'],'leaseOwner'=>$owner,'leaseExpires'=>$lease['leaseExpires'],
      'duration'=>0,'followups'=>array()),$owner);
    if($finalized!==array('status'=>'finalized')||(new PhabricatorWorkerActiveTask())->load($lease['id'])!==null||
      !(new PhabricatorWorkerArchiveTask())->load($lease['id'])){throw new RuntimeException('governance_notice_fixture_finalize_refused');}
    $map=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',$event['eventID'],$user->getPHID());
    $check($map!==null,'notice-current-native-admin-receives-one-durable-mail-mapping');
    $check(queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',
      $event['eventID'],$ordinary->getPHID())===null,'notice-ordinary-user-is-not-implicitly-added-to-admin-audience');
    $mail=(new PhabricatorMetaMTAMail())->loadOneWhere('phid=%s',$map['mailPHID']);
    $mail_count=(int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_delivery WHERE eventID=%s',$event['eventID'])['n'];
    $mail_rows=(int)queryfx_one($meta,'SELECT COUNT(*) n FROM metamta_mail')['n'];
    $relay_rows=(int)queryfx_one($meta,'SELECT COUNT(*) n FROM metamta_gorgeoutbox')['n'];
    (new PhorgeGovernanceNoticeWorker(json_decode($payload['task']['data'],true)))->executeTask();
    $check((int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_delivery WHERE eventID=%s',$event['eventID'])['n']===$mail_count&&
      (int)queryfx_one($meta,'SELECT COUNT(*) n FROM metamta_mail')['n']===$mail_rows&&
      (int)queryfx_one($meta,'SELECT COUNT(*) n FROM metamta_gorgeoutbox')['n']===$relay_rows,
      'notice-native-worker-retry-does-not-duplicate-fanout');
    $snapshot=$mail->prepareGorgeDelivery();$raw=json_encode($snapshot,JSON_THROW_ON_ERROR);
    $check(isset($snapshot['authorization'])&&!str_contains($raw,'PRIVATE_NOTICE')&&$mail->getHTMLBody()===null&&!$mail->getAttachments(),
      'notice-native-prepared-snapshot-is-private-link-only');
    $physical=static function()use(&$mail,$snapshot){return $mail->authorizeGorgePhysicalDelivery($snapshot['deliveryID'],
      $snapshot['authorization']['messageHash'],phutil_json_encode($snapshot['message']));};
    $check($physical()===array('allowed'=>true),'notice-physical-attempt-rechecks-current-admin-source-and-primary-email');
    $user->setIsDisabled(1)->save();$check($physical()===array('allowed'=>false),'notice-disabled-recipient-refused-before-physical-send');
    $user->setIsDisabled(0)->save();$mail=(new PhabricatorMetaMTAMail())->load($mail->getID());
    $check($physical()===array('allowed'=>false)&&$mail->getParameters()['gorge.delivery-snapshot']===$snapshot,
      'notice-reenabled-recipient-cannot-revive-quarantined-snapshot');
    $input['operation_id']='notice-email';$input['unit_id']='notice-email';$service->createUnit($input);
    $email_event=queryfx_one($meta,'SELECT eventID FROM governance_notice_event WHERE operationDigest=%s',hash('sha256','notice-email'));
    (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$email_event['eventID'])))->executeTask();
    $email_map=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',
      $email_event['eventID'],$user->getPHID());
    $email_mail=(new PhabricatorMetaMTAMail())->loadOneWhere('phid=%s',$email_map['mailPHID']);
    $email_snapshot=$email_mail->prepareGorgeDelivery();
    if(!isset($email_snapshot['authorization'])){throw new RuntimeException('governance_notice_email_positive_missing');}
    $old_address=$email->getAddress();$email->setAddress('changed-'.$old_address)->save();
    try{$denied=$email_mail->authorizeGorgePhysicalDelivery($email_snapshot['deliveryID'],
      $email_snapshot['authorization']['messageHash'],phutil_json_encode($email_snapshot['message']));}
    finally{$email->setAddress($old_address)->save();}
    $email_mail=(new PhabricatorMetaMTAMail())->load($email_mail->getID());
    $check($denied===array('allowed'=>false)&&$email_mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===true&&
      $email_mail->authorizeGorgePhysicalDelivery($email_snapshot['deliveryID'],$email_snapshot['authorization']['messageHash'],
      phutil_json_encode($email_snapshot['message']))===array('allowed'=>false),
      'notice-primary-email-change-denies-and-restore-cannot-revive-delivery');
    $operator=(new PhabricatorUser())->setUsername('gnnoticeop'.substr($fixture->getNamespace(),-12))
      ->setRealName('Synthetic notice operator')->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($operator->getPHID())->setAddress($operator->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $operator_auth=governance_native_request($operator,new PhabricatorAuthSessionEngine());
    $fresh_index=0;
    $fresh=static function()use(&$fresh_index,$service,$meta,$user){
      $fresh_index++;$id='notice-fresh-'.$fresh_index;
      $service->createUnit(array('operation_id'=>$id,'unit_id'=>$id,'kind'=>'team','name'=>'PRIVATE_NOTICE_FRESH','parent_id'=>null));
      $event=queryfx_one($meta,'SELECT eventID FROM governance_notice_event WHERE operationDigest=%s',hash('sha256',$id));
      (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$event['eventID'])))->executeTask();
      $map=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',$event['eventID'],$user->getPHID());
      $mail=(new PhabricatorMetaMTAMail())->loadOneWhere('phid=%s',$map['mailPHID']);$snapshot=$mail->prepareGorgeDelivery();
      if(!isset($snapshot['authorization'])){throw new RuntimeException('governance_notice_fresh_positive_required');}
      return array($mail,$snapshot);
    };
    $try_physical=static function($pair){list($mail,$snapshot)=$pair;return $mail->authorizeGorgePhysicalDelivery($snapshot['deliveryID'],
      $snapshot['authorization']['messageHash'],phutil_json_encode($snapshot['message']));};
    $aba=$fresh();
    $native_user_writer=(new PhabricatorUser())->establishConnection('w');
    $prior_user_event=(int)queryfx_one($native_user_writer,'SELECT COALESCE(MAX(id),0) n FROM %R WHERE objectPHID=%s',new PhabricatorUserTransaction(),$user->getPHID())['n'];
    $stop_count=(int)queryfx_one($native_user_writer,'SELECT COUNT(*) n FROM recovery_stop')['n'];
    foreach(array(true,false)as$disabled){
      $target=(new PhabricatorUser())->load($user->getID());
      (new PhabricatorUserTransactionEditor())->setActor($operator_auth['viewer'])
        ->setContentSourceFromRequest($operator_auth['request'])->setCancelURI('/people/')
        ->applyTransactions($target,array((new PhabricatorUserTransaction())
          ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled)));
    }
    $native_disable_events=(int)queryfx_one($native_user_writer,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s AND transactionType=%s AND id>%d',new PhabricatorUserTransaction(),$user->getPHID(),PhabricatorUserDisableTransaction::TRANSACTIONTYPE,$prior_user_event)['n'];
    $check($native_disable_events===2&&PhabricatorEnv::getEnvConfig('governance.recovery-stop-ledger.enabled')===false&&
      (int)queryfx_one($native_user_writer,'SELECT COUNT(*) n FROM recovery_stop')['n']===$stop_count&&$try_physical($aba)===array('allowed'=>false),
      'notice-native-disable-enable-without-intermediate-callback-cannot-revive-account-binding');
    $renamed=$fresh();$target=(new PhabricatorUser())->load($user->getID());
    (new PhabricatorUserTransactionEditor())->setActor($operator_auth['viewer'])
      ->setContentSourceFromRequest($operator_auth['request'])->setCancelURI('/people/')
      ->applyTransactions($target,array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserUsernameTransaction::TRANSACTIONTYPE)
        ->setNewValue('gnnoticerenamed'.substr($fixture->getNamespace(),-12))));
    $check($try_physical($renamed)===array('allowed'=>true),'notice-native-benign-username-edit-does-not-discard-current-eligible-delivery');
    $unverified=$fresh();$email->setIsVerified(0)->save();
    try{$unverified_result=$try_physical($unverified);}finally{$email->setIsVerified(1)->save();}
    $check($unverified_result===array('allowed'=>false),'notice-unverified-primary-refused-at-physical-attempt');
    $duplicate=$fresh();$second=(new PhabricatorUserEmail())->setUserPHID($user->getPHID())
      ->setAddress('duplicate-'.$old_address)->setIsPrimary(1)->setIsVerified(1)->save();
    try{$duplicate_result=$try_physical($duplicate);}finally{$second->delete();}
    $check($duplicate_result===array('allowed'=>false),'notice-duplicate-primary-is-unknown-and-refused');
    $rotated=$fresh();$email->delete();$email=(new PhabricatorUserEmail())->setUserPHID($user->getPHID())
      ->setAddress($old_address)->setIsPrimary(1)->setIsVerified(1)->save();
    $check($try_physical($rotated)===array('allowed'=>false),'notice-same-address-new-native-email-row-refuses-old-binding');
    $malformed=$fresh();$malformed_id=$malformed[0]->getPrivateMailPolicyValue('governance.notice-binding')['event_id'];
    $saved_event=queryfx_one($meta,'SELECT metadataJSON,metadataDigest FROM governance_notice_event WHERE eventID=%s',$malformed_id);
    queryfx($meta,'UPDATE governance_notice_event SET metadataJSON=%s,metadataDigest=%s WHERE eventID=%s','{}',hash('sha256','{}'),$malformed_id);
    try{$malformed_result=$try_physical($malformed);}finally{queryfx($meta,
      'UPDATE governance_notice_event SET metadataJSON=%s,metadataDigest=%s WHERE eventID=%s',
      $saved_event['metadataJSON'],$saved_event['metadataDigest'],$malformed_id);}
    $check($malformed_result===array('allowed'=>false),'notice-malformed-event-metadata-with-valid-digest-is-not-authority');
    $changed_source=$fresh();$source_event=$changed_source[0]->getPrivateMailPolicyValue('governance.notice-binding')['event_id'];
    $source_row=queryfx_one($meta,'SELECT sourceID FROM governance_notice_event WHERE eventID=%s',$source_event);
    $user_conn=(new PhabricatorUser())->establishConnection('w');
    queryfx($user_conn,'UPDATE organization_audit SET action=%s WHERE id=%s','unit.update',$source_row['sourceID']);
    try{$changed_result=$try_physical($changed_source);}finally{
      queryfx($user_conn,'UPDATE organization_audit SET action=%s WHERE id=%s','unit.create',$source_row['sourceID']);}
    $check($changed_result===array('allowed'=>false),'notice-actual-audit-row-change-refuses-bound-physical-delivery');
    $project_scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
    try{
      $project=PhabricatorProject::initializeNewProject($auth['viewer'])->setName('PRIVATE_NOTICE_PROJECT')
        ->setViewPolicy($user->getPHID())->setEditPolicy($user->getPHID())
        ->setSubtype(PhabricatorEditEngineSubtype::SUBTYPE_DEFAULT)->save();
      $project->attachMemberPHIDs(array())->attachParentProject(null);
      $project_scope->overrideEnvConfig('governance.project-approval.enabled',true);
      $proposal=PhorgeProjectPolicyApprovalService::call($auth['context'],'submit',array('target_phid'=>$project->getPHID(),
        'new_policy'=>PhabricatorPolicies::POLICY_USER,'reason'=>'PRIVATE_NOTICE_APPROVAL_REASON','ttl_seconds'=>120));
      $project_conn=$project->establishConnection('w');
      $audit=queryfx_one($project_conn,'SELECT id FROM governance_executionaudit WHERE approvalID=%s AND action=%s',$proposal['approval_id'],'submitted');
      $project_event=queryfx_one($meta,'SELECT eventID,metadataJSON FROM governance_notice_event WHERE sourceKind=%s AND sourceID=%s',
        'project-approval',(string)$audit['id']);
      $check($project_event!==null&&!str_contains($project_event['metadataJSON'],'PRIVATE_NOTICE'),
        'notice-real-project-approval-producer-commits-metadata-only-event');
      (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$project_event['eventID'])))->executeTask();
      $own_map=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',
        $project_event['eventID'],$user->getPHID());
      $other_map=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',
        $project_event['eventID'],$operator->getPHID());
      $check($own_map!==null&&$other_map===null,'notice-project-audience-requires-real-view-even-for-system-admin');
      $project_mail=(new PhabricatorMetaMTAMail())->loadOneWhere('phid=%s',$own_map['mailPHID']);$project_snapshot=$project_mail->prepareGorgeDelivery();
      if(!isset($project_snapshot['authorization'])){throw new RuntimeException('governance_notice_project_positive_required');}
      // Fixture-owned source transition, explicitly not an approved VIEW-route proof.
      $project_scope->overrideEnvConfig('governance.project-approval.enabled',false);
      $project->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
      $project_scope->overrideEnvConfig('governance.project-approval.enabled',true);
      $check($project_mail->authorizeGorgePhysicalDelivery($project_snapshot['deliveryID'],$project_snapshot['authorization']['messageHash'],
        phutil_json_encode($project_snapshot['message']))===array('allowed'=>false),
        'notice-project-current-view-revocation-stops-existing-prepared-mail');
    }finally{unset($project_scope);}
    $stop_scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.recovery-stop-ledger.enabled',true);
    try{
      $target=(new PhabricatorUser())->load($ordinary->getID());
      (new PhabricatorUserTransactionEditor())->setActor($operator_auth['viewer'])
        ->setContentSourceFromRequest($operator_auth['request'])->setCancelURI('/people/')
        ->applyTransactions($target,array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true)));
      $transaction=queryfx_one((new PhabricatorUser())->establishConnection('w'),
        'SELECT id FROM %R WHERE objectPHID=%s AND transactionType=%s ORDER BY id DESC LIMIT 1',new PhabricatorUserTransaction(),
        $ordinary->getPHID(),PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
      $stopped=PhorgeRecoveryStopService::recordAlreadyStopped($operator_auth['context'],$ordinary->getPHID(),(string)$transaction['id'],
        'technical-pause','PRIVATE_NOTICE_STOP_REASON','notice-stop-operation');
      $stop_event=queryfx_one($meta,'SELECT eventID,metadataJSON FROM governance_notice_event WHERE operationDigest=%s',hash('sha256','notice-stop-operation'));
      $check($stop_event!==null&&!str_contains($stop_event['metadataJSON'],'PRIVATE_NOTICE')&&$stopped['recovery_authorized']===false,
        'notice-real-stop-classification-producer-is-not-recovery-authority');
      $replayed_stop=PhorgeRecoveryStopService::recordAlreadyStopped($operator_auth['context'],$ordinary->getPHID(),(string)$transaction['id'],
        'technical-pause','PRIVATE_NOTICE_STOP_REASON','notice-stop-operation');
      $check($replayed_stop['replayed']===true&&(int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_event WHERE operationDigest=%s',hash('sha256','notice-stop-operation'))['n']===1,
        'notice-stop-classification-replay-does-not-duplicate-event');
      (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$stop_event['eventID'])))->executeTask();
      $check((new PhabricatorUser())->load($ordinary->getID())->getIsDisabled()&&
        queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',$stop_event['eventID'],$operator->getPHID())!==null,
        'notice-stop-worker-prepares-admin-mail-without-enabling-subject');
    }finally{unset($stop_scope);}
    // A native SQL fault must roll back organization rows and both cross-schema writes.
    $trigger='`'.$fixture->getNamespace().'_user`.`notice_owned_fault`';
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('user','organization_operation').
      " FOR EACH ROW BEGIN IF NEW.operationID='notice-fault' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-notice-event-fault'; END IF; END");
    $installed=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$fixture->getNamespace()."_user' AND TRIGGER_NAME='notice_owned_fault'")->fetch_assoc();
    if((int)$installed['n']!==1){throw new RuntimeException('governance_notice_fault_not_installed');}
    $before=governance_notice_fixture_fingerprint($fixture);$input['operation_id']='notice-fault';$input['unit_id']='notice-fault';$fault=false;
    try{$service->createUnit($input);}catch(AphrontQueryException $error){
      if($error->getCode()!==1644||!str_contains($error->getMessage(),'owned-notice-event-fault')){throw $error;}$fault=true;}
    $check($fault&&governance_notice_fixture_fingerprint($fixture)===$before&&!(new PhabricatorUser())->establishConnection('w')->isInsideTransaction(),
      'notice-real-post-outbox-sql-fault-rolls-back-business-audit-event-and-relay');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$trigger=null;
    governance_notice_independent_commit($fixture,$check,$auth);
    $input['operation_id']='notice-worker-fault';$input['unit_id']='notice-worker-fault';$service->createUnit($input);
    $worker_event=queryfx_one($meta,'SELECT eventID FROM governance_notice_event WHERE operationDigest=%s',hash('sha256','notice-worker-fault'));
    $before=governance_notice_fixture_fingerprint($fixture);$worker_fault=false;
    $trigger='`'.$fixture->getNamespace().'_metamta`.`notice_owned_worker_fault`';
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('metamta','governance_notice_delivery').
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-notice-worker-fault'");
    try{(new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$worker_event['eventID'])))->executeTask();}
    catch(AphrontQueryException $error){
      if($error->getCode()!==1644||!str_contains($error->getMessage(),'owned-notice-worker-fault')){throw $error;}$worker_fault=true;}
    $check($worker_fault&&governance_notice_fixture_fingerprint($fixture)===$before&&!$meta->isInsideTransaction(),
      'notice-worker-sql-fault-rolls-back-native-mail-recipient-edges-mapping-and-mail-outbox');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$trigger=null;
    (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$worker_event['eventID'])))->executeTask();
    $check(queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',
      $worker_event['eventID'],$user->getPHID())!==null,'notice-worker-retry-after-rollback-prepares-one-eligible-recipient-mapping');
    $before=governance_notice_fixture_fingerprint($fixture);$refused=false;$drifted=false;
    try{
      governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('metamta','governance_notice_event').' ENGINE=MyISAM');$drifted=true;
      $input['operation_id']='notice-engine';$input['unit_id']='notice-engine';
      try{$service->createUnit($input);}catch(RuntimeException $error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='governance_notice_innodb_required'){throw $error;}$refused=true;}
    }finally{if($drifted){governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('metamta','governance_notice_event').' ENGINE=InnoDB');}}
    $check($refused&&governance_notice_fixture_fingerprint($fixture)===$before,
      'notice-actual-nontransactional-outbox-refused-with-business-rollback');
    return array('actual_native_producer'=>true,'qualified_source_transaction'=>true,'actual_gorge_inbox_and_lease'=>true,
      'actual_native_handler'=>true,'per_physical_current_eligibility'=>true,'link_only'=>true,
      'automatic_go_outbox_relay_proved'=>false,'Go_daemon_proved'=>false,'HTTP_gateway_proved'=>false,'SMTP_proved'=>false,
      'all_governance_producers_complete'=>false);
  }finally{
    $cleanup_failed=false;
    try{if($trigger!==null){governance_native_setup_query($fixture,'DROP TRIGGER IF EXISTS '.$trigger);}}
    catch(Throwable $error){$cleanup_failed=true;}
    try{unset($scope);}catch(Throwable $error){$cleanup_failed=true;}
    $_COOKIE=$saved_cookies;if($saved_method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$saved_method;}
    if($cleanup_failed){throw new RuntimeException('governance_notice_fixture_cleanup_failed');}
  }
}
