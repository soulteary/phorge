<?php
/** Callable physical SQL cases; requires the owned native fixture/observer.
 * Not wired into normal934 or any accepted receipt. SQL execution is pending. */
function governance_recovery_session_lease_cases($fixture,AphrontDatabaseConnection $observer,array $state,callable $check) {
  $fixture->assertOwned();require_once dirname(__DIR__).'/native-organization/workers.php';
  $conn=(new PhabricatorUser())->establishConnection('w');$handles=array();$scope=null;
  $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.session-lease.enabled',true);
  try {
    $actor=governance_native_request($state['users']['admin'],$state['sessions']);
    // Actual actor source initialization must finish before the measured lease.
    $conn->openTransaction();try{PhorgeGovernanceNativeIdentitySource::lockCurrent($actor['context'],$conn);$conn->saveTransaction();}
    catch(Throwable $error){$conn->killTransaction();throw$error;}
    foreach(array('commit','rollback')as$ending){
      $subject=(new PhabricatorUser())->setUsername('sessionlease'.$ending.substr($fixture->getNamespace(),-10))
        ->setRealName('Synthetic Session Lease Subject')->setIsApproved(1)->save();
      $handle=governance_native_start_worker($fixture,array('action'=>'recovery.session.issue','subject'=>$subject->getPHID(),'token'=>$actor['token']));$handles[]=$handle;
      governance_native_wait_session_producer_ready($handle);
      $scope=PhorgeRecoverySessionProducerFence::beginLease($observer,$subject->getPHID());$before=$scope->leaseGetFacts();
      governance_native_continue_session_producer($handle);governance_native_wait_session_covering_control($handle);
      governance_native_continue_session_fence($handle);
      $writer=queryfx_one($conn,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];$deadline=microtime(true)+5;$observed=false;
      do {
        $rows=queryfx_all($observer,'SELECT CAST(r.PROCESSLIST_ID AS CHAR) requester,CAST(b.PROCESSLIST_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_STATUS status,l.LOCK_MODE mode,l.LOCK_DATA lock_data '.
          'FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
          'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
          'WHERE r.PROCESSLIST_ID=%s AND b.PROCESSLIST_ID=%s',$handle['mysql_thread'],$writer);
        $observed=count($rows)===1&&$rows[0]['requester']===(string)$handle['mysql_thread']&&$rows[0]['blocker']===$writer&&
          $rows[0]['schema_name']===$fixture->getNamespace().'_user'&&$rows[0]['table_name']==='user'&&$rows[0]['index_name']==='PRIMARY'&&
          $rows[0]['lock_data']===(string)$subject->getID()&&$rows[0]['status']==='WAITING'&&str_starts_with($rows[0]['mode'],'S');
        if($observed){break;}usleep(20000);
      }while(microtime(true)<$deadline);
      $count=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',new PhabricatorAuthSession(),$subject->getPHID())['n'];
      $check($observed&&$count===0&&$scope->leaseGetFacts()===$before,'session-lease-real-INSERT-waits-through-protected-capture-'.$ending);
      if($ending==='commit'){$scope->leaseCommit();}else{$scope->leaseRollback();}
      $terminal=$scope->leaseGetTerminalFacts();$scope=null;
      $result=governance_native_finish_worker($handle);
      $count=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',new PhabricatorAuthSession(),$subject->getPHID())['n'];
      $check($terminal['cleanup_proved']===true&&$terminal['state']===($ending==='commit'?'committed':'rolled-back')&&
        $result['status']==='committed'&&$count===1,'session-lease-real-INSERT-resumes-after-observed-'.$ending);
      $next=PhorgeRecoverySessionProducerFence::beginLease($observer,$subject->getPHID());
      try{$after=$next->leaseGetFacts();$check($before['source_binding']!==$after['source_binding']&&$after['all_sources_complete']===false&&$after['restoration_authorized']===false,
        'session-lease-new-native-producer-transition-invalidates-selected-binding-'.$ending);}finally{$next->leaseAbort();}
    }
  }finally{try{if($scope!==null){$scope->leaseAbort();}}finally{governance_native_stop_fixture_handles($handles);unset($flags);}}
}
