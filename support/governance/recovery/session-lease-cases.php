<?php
/** Callable physical SQL cases; requires the owned native fixture/observer.
 * Not wired into normal934 or any accepted receipt. SQL execution is pending. */
function governance_recovery_session_lease_cases($fixture,AphrontDatabaseConnection $observer,array $state,callable $check) {
  $GLOBALS['governance_session_lease_physical_cleanup_proved']=true;
  $fixture->assertOwned();require_once dirname(__DIR__).'/native-organization/workers.php';
  $conn=(new PhabricatorUser())->establishConnection('w');$handles=array();$scope=null;$primary=null;
  $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.session-lease.enabled',true);
  try {
    $actor=governance_native_request($state['users']['admin'],$state['sessions']);
    // Actual actor source initialization must finish before the measured lease.
    $conn->openTransaction();try{PhorgeGovernanceNativeIdentitySource::lockCurrent($actor['context'],$conn);$conn->saveTransaction();}
    catch(Throwable $error){$conn->killTransaction();throw$error;}
    foreach(array('commit','rollback')as$ending){
      $subject=$state['subjects'][$ending]??null;
      if(!is_object($subject)||get_class($subject)!==PhabricatorUser::class||!$subject->getID()||$subject->getIsApproved()!==true||$subject->getIsDisabled()||$subject->getIsSystemAgent()||$subject->getIsMailingList()){throw new RuntimeException('session_lease_prepared_subject_required');}
      if((int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',new PhabricatorAuthSession(),$subject->getPHID())['n']!==0){throw new RuntimeException('session_lease_fresh_subject_required');}
      $handle=governance_native_start_worker($fixture,array('action'=>'recovery.session.issue','subject'=>$subject->getPHID(),'token'=>$actor['token']));$handles[]=$handle;
      governance_native_wait_session_producer_ready($handle);
      $GLOBALS['governance_session_lease_physical_cleanup_proved']=false;
      $scope=PhorgeRecoverySessionProducerFence::beginLease($observer,$subject->getPHID());$before=$scope->leaseGetFacts();
      governance_native_continue_session_producer($handle);governance_native_wait_session_covering_control($handle);
      governance_native_continue_session_fence($handle);
      $writer=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];$deadline=microtime(true)+5;$observed=false;
      do {
        $rows=queryfx_all($observer,'SELECT CAST(r.PROCESSLIST_ID AS CHAR) requester,CAST(b.PROCESSLIST_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_STATUS status,l.LOCK_MODE mode,l.LOCK_DATA lock_data '.
          'FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
          'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
          'WHERE l.ENGINE=%s AND w.ENGINE=%s AND r.PROCESSLIST_ID=%s AND b.PROCESSLIST_ID=%s','INNODB','INNODB',$handle['mysql_thread'],$writer);
        $observed=count($rows)===1&&$rows[0]['requester']===(string)$handle['mysql_thread']&&$rows[0]['blocker']===$writer&&
          $rows[0]['schema_name']===$fixture->getNamespace().'_user'&&$rows[0]['table_name']==='user'&&$rows[0]['index_name']==='PRIMARY'&&
          $rows[0]['lock_data']===(string)$subject->getID()&&$rows[0]['status']==='WAITING'&&str_starts_with($rows[0]['mode'],'S');
        if($observed){break;}usleep(20000);
      }while(microtime(true)<$deadline);
      $count=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',new PhabricatorAuthSession(),$subject->getPHID())['n'];
      $check($observed&&$count===0&&$scope->leaseGetFacts()===$before,'session-lease-real-INSERT-waits-through-protected-capture-'.$ending);
      if($ending==='commit'){$scope->leaseCommit();}else{$scope->leaseRollback();}
      $terminal=$scope->leaseGetTerminalFacts();
      if(($terminal['cleanup_proved']??null)!==true){throw new RuntimeException('session_lease_cleanup_unproved');}
      $GLOBALS['governance_session_lease_physical_cleanup_proved']=true;$scope=null;
      $result=governance_native_finish_worker($handle);
      $deadline=microtime(true)+3;$gone=false;do{$rows=queryfx_all($observer,'SELECT CAST(PROCESSLIST_ID AS CHAR) id FROM performance_schema.threads WHERE PROCESSLIST_ID=%s',$handle['mysql_thread']);if($rows===array()){$gone=true;break;}usleep(20000);}while(microtime(true)<$deadline);
      if(!$gone){throw new RuntimeException('session_lease_worker_SQL_session_remaining');}
      $count=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',new PhabricatorAuthSession(),$subject->getPHID())['n'];
      $persisted=queryfx_all($conn,'SELECT id FROM %R WHERE userPHID=%s ORDER BY id LIMIT 2',new PhabricatorAuthSession(),$subject->getPHID());
      $check(count($persisted)===1&&hash('sha256',(string)$persisted[0]['id'])===($result['session_id_digest']??null)&&$terminal['cleanup_proved']===true&&$terminal['state']===($ending==='commit'?'committed':'rolled-back')&&
        $result['status']==='committed'&&$count===1,'session-lease-real-INSERT-resumes-after-observed-'.$ending);
      $GLOBALS['governance_session_lease_physical_cleanup_proved']=false;
      $next=PhorgeRecoverySessionProducerFence::beginLease($observer,$subject->getPHID());
      $nextPrimary=null;try{$after=$next->leaseGetFacts();$check($before['source_binding']!==$after['source_binding']&&$after['all_sources_complete']===false&&$after['restoration_authorized']===false,
        'session-lease-new-native-producer-transition-invalidates-selected-binding-'.$ending);}catch(Throwable $error){$nextPrimary=$error;throw$error;}finally{governance_recovery_session_lease_cleanup($next,$nextPrimary);}
    }
  }catch(Throwable $error){$primary=$error;throw$error;}finally{
    $errors=array();
    try{if($scope!==null){governance_recovery_session_lease_cleanup($scope);}}catch(Throwable $error){$errors['lease']=$error;}
    try{governance_native_stop_fixture_handles($handles);}catch(Throwable $error){$errors['workers']=$error;}
    try{governance_recovery_session_lease_workers_ended($observer,$handles);}catch(Throwable $error){$GLOBALS['governance_session_lease_physical_cleanup_proved']=false;$errors['worker-SQL']=$error;}
    unset($flags);
    if($errors){if($primary!==null){$errors=array('primary'=>$primary)+$errors;}throw new PhutilAggregateException('session_lease_cases_cleanup_failed',$errors);}
  }
}

/** Reaped child processes alone do not prove their native SQL sessions ended. */
function governance_recovery_session_lease_workers_ended(AphrontDatabaseConnection $observer,array $handles){
  foreach($handles as$handle){
    $id=$handle['mysql_thread']??null;if((!is_int($id)&&!is_string($id))||!preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$id)){throw new RuntimeException('session_lease_worker_SQL_identity_unknown');}
    $deadline=microtime(true)+3;$gone=false;
    do{$rows=queryfx_all($observer,'SELECT CAST(PROCESSLIST_ID AS CHAR) id FROM performance_schema.threads WHERE PROCESSLIST_ID=%s',(string)$id);if($rows===array()){$gone=true;break;}usleep(20000);}while(microtime(true)<$deadline);
    if(!$gone){throw new RuntimeException('session_lease_worker_SQL_session_remaining');}
  }
}

/** Every extra capture must have proved terminal cleanup, not merely six checks. */
function governance_recovery_session_lease_cleanup($scope,?Throwable $primary=null) {
  $GLOBALS['governance_session_lease_physical_cleanup_proved']=false;
  try{$terminal=$scope->leaseAbort();if(($terminal['cleanup_proved']??null)!==true){$GLOBALS['governance_session_lease_physical_cleanup_proved']=false;throw new RuntimeException('session_lease_cleanup_unproved');}$GLOBALS['governance_session_lease_physical_cleanup_proved']=true;}
  catch(Throwable $error){if($primary!==null){throw new PhutilAggregateException('session_lease_cleanup_failed',array('primary'=>$primary,'cleanup'=>$error));}throw$error;}
}

/** SQL6 callable entry using an already owned, separately provisioned observer.
 * Does not invoke RRPrincipals::prepare: that helper widens the native writer.
 * No CREATE USER, GRANT, REVOKE, or fixture account mutation is performed here. */
function governance_recovery_session_lease_owned_entry($fixture,array $state,array $configuration,callable $check) {
  $GLOBALS['governance_session_lease_observer_close']=array('attempted'=>false,'returned'=>false);
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||get_class($fixture)!==PhorgeGovernanceCompletionFixture::class){
    throw new RuntimeException('session_lease_entry_scope_unknown');}
  $fixture->assertOwned();$writer=$fixture->getWriterConfig();
  $keys=array_keys($configuration);sort($keys);
  if($keys!==array('metadata_owner','observer')||!is_array($configuration['observer'])||!is_array($configuration['metadata_owner'])){
    throw new RuntimeException('session_lease_entry_configuration_unknown');}
  $meta=$configuration['observer'];$keys=array_keys($meta);sort($keys);$owner=$configuration['metadata_owner'];$ownerKeys=array_keys($owner);sort($ownerKeys);
  if($keys!==array('host','namespace','password','port','user')||$meta['host']!=='127.0.0.1'||$meta['host']!==$writer['host']||
    !is_int($meta['port'])||$meta['port']<1||$meta['port']>65535||$meta['port']!==$writer['port']||$meta['namespace']!==$fixture->getNamespace()||
    !is_string($meta['user'])||!preg_match('/\Agov_c_metadata_[a-f0-9]{16}\z/D',$meta['user'])||!is_string($meta['password'])||
    !preg_match('/\A[a-f0-9]{48}\z/D',$meta['password'])||$ownerKeys!==array('governance_fixture_namespace','governance_fixture_owner','governance_run_nonce','governance_source_revision')||
    $owner['governance_fixture_namespace']!==$fixture->getNamespace()||!is_string($owner['governance_fixture_owner'])||
    !preg_match('/\A[a-f0-9]{64}\z/D',$owner['governance_fixture_owner'])||!is_string($owner['governance_run_nonce'])||
    !preg_match('/\A[a-f0-9]{32}\z/D',$owner['governance_run_nonce'])||!is_string($owner['governance_source_revision'])||
    !preg_match('/\A[a-f0-9]{40}\z/D',$owner['governance_source_revision'])||$owner['governance_source_revision']!==getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION')){
    throw new RuntimeException('session_lease_entry_configuration_unknown');}
  $observer=new AphrontMySQLiDatabaseConnection(array('host'=>$meta['host'],'port'=>$meta['port'],'database'=>'',
    'user'=>$meta['user'],'pass'=>new PhutilOpaqueEnvelope($meta['password']),'timeout'=>3));
  $primary=null;try {
    $identity=queryfx_one($observer,'SELECT CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy');
    if(!$identity||$identity['principal']!==$meta['user'].'@%'||$identity['role']!=='NONE'||$identity['proxy']!==null){
      throw new RuntimeException('session_lease_entry_observer_unknown');}
    $rows=queryfx_all($observer,'SELECT ATTRIBUTE attributes FROM information_schema.USER_ATTRIBUTES WHERE USER=%s AND HOST=%s',$meta['user'],'%');
    if(count($rows)!==1||!is_string($rows[0]['attributes'])||strlen($rows[0]['attributes'])>4096){throw new RuntimeException('session_lease_entry_owner_unknown');}
    $actual=json_decode($rows[0]['attributes'],true,8,JSON_THROW_ON_ERROR);
    if(!is_array($actual)||count($actual)!==count($owner)){throw new RuntimeException('session_lease_entry_owner_unknown');}
    foreach($owner as$key=>$value){if(($actual[$key]??null)!==$value){throw new RuntimeException('session_lease_entry_owner_unknown');}}
    governance_recovery_session_lease_cases($fixture,$observer,$state,$check);
  }catch(Throwable $error){$primary=$error;throw$error;}finally{governance_recovery_session_lease_close_observer($observer,$primary);}
}

/** A public close return is not independent proof that the SQL session ended. */
function governance_recovery_session_lease_close_observer(AphrontDatabaseConnection $observer,?Throwable $primary=null) {
  $GLOBALS['governance_session_lease_observer_close']=array('attempted'=>true,'returned'=>false);
  try{$observer->close();$GLOBALS['governance_session_lease_observer_close']['returned']=true;}
  catch(Throwable $error){if($primary!==null){throw new PhutilAggregateException('session_lease_observer_close_failed',array('primary'=>$primary,'cleanup'=>$error));}throw$error;}
}
