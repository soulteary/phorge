<?php
/** Actual native grant producer + source transaction + queue lease; no SMTP claim. */
function governance_notice_admin_grant_cases($fixture,callable$check){
  global $control;
  $fixture->assertOwned();require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
  require_once dirname(__DIR__).'/native-organization/native-cases.php';
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once __DIR__.'/governance-notice-cases.php';
  $queue=null;$scope=null;$enabled_scope=null;$trigger=null;$hidden=false;$engine_changed=false;
  $meta_schema=$fixture->getNamespace().'_metamta';$failure=null;$result=null;$cleanup_failed=false;$cleanup_errors=array();$queue_cleanup=null;
  try{
    if((new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){throw new RuntimeException('notice_admin_inprocess_refused');}
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);
    $service_token=bin2hex(random_bytes(32));
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled',false)
      ->overrideEnvConfig('governance.private-mail.enabled',true)->overrideEnvConfig('governance.account-recovery.enabled',false)
      ->overrideEnvConfig('governance.recovery-stop-ledger.enabled',false)->overrideEnvConfig('metamta.gorge-delivery-mode','native')
      ->overrideEnvConfig('gorge.taskqueue.owner','gorge')->overrideEnvConfig('gorge.taskqueue.uri',$queue['uri'])
      ->overrideEnvConfig('gorge.taskqueue.token',$queue['token'])->overrideEnvConfig('gorge.conduit.uri',$queue['uri'])
      ->overrideEnvConfig('gorge.conduit.token',$service_token);
    require_once dirname(__DIR__).'/native-organization/schema-cases.php';$schema=governance_native_schema_cases($fixture,$check);
    $actor=(new PhabricatorUser())->setUsername('gnadminactor'.substr($fixture->getNamespace(),-10))->setRealName('Synthetic native administrator')
      ->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
    $subject=(new PhabricatorUser())->setUsername('gnadminsubject'.substr($fixture->getNamespace(),-8))->setRealName('PRIVATE_NATIVE_ADMIN_SUBJECT')
      ->setIsAdmin(0)->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($subject->getPHID())->setAddress($subject->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
    $auth=governance_native_request($actor,new PhabricatorAuthSessionEngine());$user=$subject->establishConnection('w');
    $meta=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
    $edit=static function($admin)use($subject,$auth){
      $target=(new PhabricatorUser())->load($subject->getID());
      $editor=(new PhabricatorUserTransactionEditor())->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])
        ->setCancelURI('/people/')->setContinueOnNoEffect(true);
      $editor->applyTransactions($target,array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue($admin)));
      return array($editor,$target);
    };
    $count=static function()use($meta){return(int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_event')['n'];};$before=$count();
    $edit(true);$edit(false);
    $check($count()===$before&&(new PhabricatorUser())->load($subject->getID())->getIsAdmin()==0,
      'notice-admin-grant-flagoff-native-grant-and-removal-remain-original');
    $enabled_scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled',true);
    list($editor,$target)=$edit(true);
    $row=queryfx_one($user,'SELECT id,phid,objectPHID,authorPHID,transactionType,oldValue,newValue,dateCreated FROM %R '.
      'WHERE objectPHID=%s AND transactionType=%s ORDER BY id DESC LIMIT 1',new PhabricatorUserTransaction(),$subject->getPHID(),PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE);
    $event=PhorgeGovernanceNoticeEventValue::fromAudit(PhorgeGovernanceNoticeSource::nativeAdminGrantAudit($row));
    $stored=queryfx_one($meta,'SELECT eventID,metadataJSON,state FROM governance_notice_event WHERE eventID=%s',$event['event_id']);
    $relay=queryfx_one($meta,'SELECT payload FROM metamta_gorgeoutbox WHERE eventID=%s','governance.notice/'.$event['event_id'].'/1');
    $primary=queryfx_one($user,'SELECT CONNECTION_ID() thread,@@server_uuid uuid');
    $control->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $observer=$control->query('SELECT CONNECTION_ID() thread,@@server_uuid uuid,@@autocommit ac')->fetch_assoc();
    if((string)$observer['thread']===(string)$primary['thread']||$observer['uuid']!==$primary['uuid']||
      !in_array($observer['ac'],array(1,'1'),true)){throw new RuntimeException('notice_admin_commit_observer_refused');}
    $observe=$control->prepare('SELECT u.isAdmin,x.phid,e.eventID FROM '.$fixture->table('user','user').' u JOIN '.
      $fixture->table('user','user_transaction').' x ON x.objectPHID=u.phid JOIN '.$fixture->table('metamta','governance_notice_event').
      ' e ON e.sourceID=x.id AND e.sourceKind=? WHERE u.id=? AND x.phid=? AND e.eventID=?');
    $kind='native-admin-grant';$subject_id=$subject->getID();$native_phid=$row['phid'];$event_id=$event['event_id'];
    $observe->bind_param('siss',$kind,$subject_id,$native_phid,$event_id);
    try{$observe->execute();$committed=$observe->get_result()->fetch_all(MYSQLI_ASSOC);}finally{$observe->close();}
    $independent=count($committed)===1&&in_array($committed[0]['isAdmin'],array(1,'1'),true)&&
      $committed[0]['phid']===$native_phid&&$committed[0]['eventID']===$event_id;
    $check($stored&&$relay&&$stored['state']==='pending'&&$count()===$before+1&&!$user->isInsideTransaction()&&$independent,
      'notice-admin-grant-real-native-USER-event-and-qualified-outbox-commit-together');
    $check(!str_contains($stored['metadataJSON'],'PRIVATE_NATIVE_ADMIN_SUBJECT')&&!str_contains($stored['metadataJSON'],$subject->getPHID())&&
      !str_contains($stored['metadataJSON'],$actor->getPHID())&&$event['metadata']['source_kind']==='native-admin-grant',
      'notice-admin-grant-source-metadata-contains-no-name-recipient-or-role-authority');
    $loaded=PhorgeGovernanceNoticeSource::load($event['metadata']);
    $check($loaded['event']===$event&&$loaded['target']===null&&$loaded['native_subject']===$subject->getPHID()&&PhorgeGovernanceNoticeSource::path($loaded)==='/people/',
      'notice-admin-grant-fresh-source-reads-exact-native-transaction-and-safe-link');
    $native_before=(int)queryfx_one($user,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorUserTransaction(),$subject->getPHID())['n'];$edit(true);
    $check($count()===$before+1&&(int)queryfx_one($user,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorUserTransaction(),$subject->getPHID())['n']===$native_before,
      'notice-admin-grant-native-no-effect-replay-creates-no-event-or-native-transaction');
    $closed=false;try{PhorgeGovernanceNoticeOutbox::nativeAdminGrant($editor,$target,(new PhabricatorUserTransaction())->load($row['id']));}
    catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='notice_native_admin_phase_ended'){throw$error;}$closed=true;}
    $check($closed&&$count()===$before+1,'notice-admin-grant-ended-editor-phase-cannot-enqueue-a-stale-source');
    $owner='notice-'.bin2hex(random_bytes(16));$payload=json_decode($relay['payload'],true,16,JSON_THROW_ON_ERROR);
    $task=governance_notice_queue_http($queue,'enqueue-event',$payload,$owner);$duplicate=governance_notice_queue_http($queue,'enqueue-event',$payload,$owner);
    $check(is_int($task['id']??null)&&$task['id']>0&&$duplicate['id']===$task['id'],
      'notice-admin-grant-real-Gorge-event-inbox-deduplicates');
    $leases=governance_notice_queue_http($queue,'lease',array('limit'=>1,'taskClasses'=>array(PhorgeGovernanceNoticeWorker::class)),$owner);
    if(count($leases)!==1||$leases[0]['id']!==$task['id']||$leases[0]['leaseOwner']!==$owner||$leases[0]['taskClass']!==PhorgeGovernanceNoticeWorker::class||$leases[0]['data']!==$payload['task']['data']){throw new RuntimeException('notice_admin_lease_refused');}
    $lease=$leases[0];$prior=$_SERVER['HTTP_X_SERVICE_TOKEN']??null;$_SERVER['HTTP_X_SERVICE_TOKEN']=$service_token;
    try{$handled=(new ConduitCall('worker.execute',array('taskID'=>$lease['id'],'taskClass'=>PhorgeGovernanceNoticeWorker::class,'phase'=>'execute',
      'executionVersion'=>1,'failureCount'=>(int)$lease['failureCount'],'priority'=>(int)$lease['priority'],'leaseOwner'=>$owner,
      'leaseExpires'=>$lease['leaseExpires'],'data'=>$payload['task']['data'])))->execute();}
    finally{if($prior===null){unset($_SERVER['HTTP_X_SERVICE_TOKEN']);}else{$_SERVER['HTTP_X_SERVICE_TOKEN']=$prior;}}
    $check(($handled['result']??null)==='success'&&($handled['followups']??null)===array(),
      'notice-admin-grant-real-Gorge-lease-runs-native-worker-handler');
    $finished=governance_notice_queue_http($queue,'finalize',array('taskID'=>$lease['id'],'leaseOwner'=>$owner,'leaseExpires'=>$lease['leaseExpires'],'duration'=>0,'followups'=>array()),$owner);
    $check($finished===array('status'=>'finalized')&&(new PhabricatorWorkerActiveTask())->load($lease['id'])===null&&
      (new PhabricatorWorkerArchiveTask())->load($lease['id'])!==null,'notice-admin-grant-real-lease-finalize-is-fenced');
    $mapping=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',$event['event_id'],$subject->getPHID());
    $mail=$mapping?(new PhabricatorMetaMTAMail())->loadOneWhere('BINARY phid=BINARY %s',$mapping['mailPHID']):null;
    $check($mail&&$mail->getPrivateMailPolicyValue('governance.mail-producer')===PhorgeGovernanceNoticeWorker::class&&PhorgePrivateMailPolicy::authorize($mail),
      'notice-admin-grant-native-handler-prepares-current-qualified-recipient-mail');
    $params=json_encode($mail->getParameters());
    $check(!str_contains($params,'PRIVATE_NATIVE_ADMIN_SUBJECT')&&!str_contains($mail->getBody(),$subject->getUsername()),
      'notice-admin-grant-native-notification-has-no-private-business-body');
    $mail_count=(int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_delivery WHERE eventID=%s',$event['event_id'])['n'];
    (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$event['event_id'])))->executeTask();
    $check((int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_delivery WHERE eventID=%s',$event['event_id'])['n']===$mail_count,
      'notice-admin-grant-native-worker-replay-does-not-duplicate-mail');
    // Removal must not depend on notice-only storage: exact missing-table profile.
    governance_native_setup_query($fixture,'RENAME TABLE `'.$meta_schema.'`.`governance_notice_event` TO `'.$meta_schema.'`.`notice_admin_hidden_event`');$hidden=true;
    try{$edit(false);}finally{governance_native_setup_query($fixture,'RENAME TABLE `'.$meta_schema.'`.`notice_admin_hidden_event` TO `'.$meta_schema.'`.`governance_notice_event`');$hidden=false;}
    $check((new PhabricatorUser())->load($subject->getID())->getIsAdmin()==0&&!$user->isInsideTransaction(),
      'notice-admin-grant-native-removal-does-not-acquire-new-notice-table-dependency');
    $check(!PhorgePrivateMailPolicy::authorize($mail),'notice-admin-grant-current-role-revocation-denies-old-mail');
    // A real AFTER INSERT fault follows event and relay insertion in the source TX.
    $trigger='`'.$meta_schema.'`.`notice_admin_grant_owned_fault`';
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' AFTER INSERT ON '.$fixture->table('metamta','metamta_gorgeoutbox').
      " FOR EACH ROW BEGIN IF NEW.eventID LIKE 'governance.notice/%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-native-admin-grant-fault'; END IF; END");
    $fingerprint=static function()use($fixture){$all=array();foreach(array('user'=>array('user','user_transaction','governance_notice_identityepoch'),
      'metamta'=>array('governance_notice_event','metamta_gorgeoutbox'))as$app=>$tables){foreach($tables as$table){
        $rows=governance_native_setup_query($fixture,'SELECT * FROM '.$fixture->table($app,$table))->fetch_all(MYSQLI_ASSOC);
        usort($rows,static function($a,$b){return strcmp(json_encode($a),json_encode($b));});$all[$app.'.'.$table]=$rows;}}
      return hash('sha256',json_encode($all,JSON_THROW_ON_ERROR));};
    $prior_state=$fingerprint();$fault=false;
    try{$edit(true);}catch(AphrontQueryException$error){if($error->getCode()!==1644||!str_contains($error->getMessage(),'owned-native-admin-grant-fault')){throw$error;}$fault=true;}
    $check($fault&&$fingerprint()===$prior_state&&!$user->isInsideTransaction()&&!$meta->isInsideTransaction(),
      'notice-admin-grant-real-late-relay-fault-rolls-back-User-XACT-generation-event-and-relay');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$trigger=null;
    $edit(true);$check((new PhabricatorUser())->load($subject->getID())->getIsAdmin()==1&&$count()===$before+2,
      'notice-admin-grant-native-retry-after-rollback-commits-one-new-source');
    $edit(false);$before_engine_fault=$fingerprint();$source_engine_refused=false;
    governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('user','user_transaction').' ENGINE=MyISAM');$engine_changed=true;
    try{
      try{$edit(true);}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='notice_native_admin_innodb_required'){throw$error;}$source_engine_refused=true;}
      $check($source_engine_refused&&$fingerprint()===$before_engine_fault&&!$user->isInsideTransaction()&&
        (new PhabricatorUser())->load($subject->getID())->getIsAdmin()==0,
        'notice-admin-grant-nontransactional-native-source-is-refused-before-role-or-XACT-write');
    }finally{governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('user','user_transaction').' ENGINE=InnoDB');$engine_changed=false;}
    $result=array('schema_tests'=>$schema,'native_admin_grant_producer'=>true,'same_physical_transaction_outbox'=>true,'independent_committed_observer'=>true,'actual_Gorge_queue_and_native_handler'=>true,
      'late_fault_rollback'=>true,'source_engines_checked_before_persistence'=>true,'noeffect_and_worker_replay'=>true,'flagoff_original'=>true,'removal_notice_storage_independent'=>true,
      'recipient_current_role_checked'=>true,'native_subject_VIEW_checked'=>true,'link_only'=>true,'HTTP_gateway_proved'=>false,'Go_daemon_proved'=>false,'SMTP_proved'=>false,
      'all_global_role_changes_complete'=>false,'Omni_actor_supported'=>false,'OFF_ON_proved'=>false,'all_producers_complete'=>false);
  }catch(Throwable$error){$failure=$error;}
  finally{
    try{if($engine_changed){governance_native_setup_query($fixture,'ALTER TABLE '.$fixture->table('user','user_transaction').' ENGINE=InnoDB');}}catch(Throwable$error){$cleanup_failed=true;$cleanup_errors[]='source-engine';}
    try{if($trigger!==null){governance_native_setup_query($fixture,'DROP TRIGGER IF EXISTS '.$trigger);}}catch(Throwable$error){$cleanup_failed=true;$cleanup_errors[]='trigger';}
    try{if($hidden){governance_native_setup_query($fixture,'RENAME TABLE `'.$meta_schema.'`.`notice_admin_hidden_event` TO `'.$meta_schema.'`.`governance_notice_event`');}}catch(Throwable$error){$cleanup_failed=true;$cleanup_errors[]='table';}
    try{unset($enabled_scope);unset($scope);}catch(Throwable$error){$cleanup_failed=true;$cleanup_errors[]='flags';}
    if($queue!==null){try{$stopped=governance_completion_stop_gorge($queue);$queue_cleanup=$stopped;if(!$stopped['process_stopped']||!$stopped['private_storage_removed']){$cleanup_failed=true;$cleanup_errors[]='queue';}}catch(Throwable$error){$cleanup_failed=true;$cleanup_errors[]='queue';}}
    $GLOBALS['governance_notice_admin_grant_cleanup_projection']=array('queue_cleanup_proved'=>$queue_cleanup!==null&&$queue_cleanup['process_stopped']===true&&$queue_cleanup['private_storage_removed']===true,'cleanup_failures'=>$cleanup_errors);
  }
  if($result!==null){$result['owned_queue_cleanup']=$queue_cleanup;}
  if($failure!==null){throw$failure;}if($cleanup_failed){throw new RuntimeException('notice_admin_owned_cleanup_failed');}return$result;
}
