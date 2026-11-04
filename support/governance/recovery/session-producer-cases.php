<?php

/** Actual SessionEngine producer tests; no restoration or global closure claim. */
function governance_recovery_session_producer_cases($fixture,callable $check,array $state) {
  global $control;
  $fixture->assertOwned();
  if(!($control instanceof mysqli)){throw new RuntimeException('recovery_session_observer_required');}
  $setup=$fixture->getAdmin();$conn=(new PhabricatorUser())->establishConnection('w');
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  $flags=null;$environment=null;$handles=array();$locked=false;
  try {
    $suffix=substr($fixture->getNamespace(),-10);$identities=array();
    foreach(array('one','two','subject')as$label){
      $identities[]=(new PhabricatorUser())->setUsername('sessionfence'.$label.$suffix)
        ->setRealName('Synthetic Session Fence Identity')->setIsApproved(1)->save();
    }
    usort($identities,static function($left,$right){return strcmp($left->getPHID(),$right->getPHID());});
    $subject=$identities[2];
    foreach(array($identities[0],$identities[1])as$actor){$actor->setIsAdmin(1)->saveWithoutIndex();}
    $one=governance_native_request($identities[0],$state['sessions']);
    $two=governance_native_request($identities[1],$state['sessions']);
    if($one['token']===$two['token']||$identities[0]->getPHID()===$identities[1]->getPHID()){
      throw new RuntimeException('recovery_session_independent_credentials_required');}
    // Actual current-source initialization is committed before concurrency.
    // User locks are shared; this removes same-actor epoch INSERT competition
    // from the observation, which must prove the subject's producer fence only.
    foreach(array($one,$two)as$web){
      $conn->openTransaction();
      try{
        $current=PhorgeGovernanceNativeIdentitySource::lockCurrent($web['context'],$conn);
        if(!$current->isSystemAdmin()){throw new RuntimeException('recovery_session_current_admin_required');}
        $conn->saveTransaction();
      }catch(Throwable$error){$conn->killTransaction();throw$error;}
    }
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    $count=static function()use($conn,$subject){
      return (int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',
        new PhabricatorAuthSession(),$subject->getPHID())['n'];
    };
    if($count()!==0){throw new RuntimeException('recovery_session_empty_subject_required');}
    $payload=array('action'=>'recovery.session.issue','subject'=>$subject->getPHID(),'token'=>$one['token']);
    $handles[]=governance_native_start_worker($fixture,$payload);
    $payload['token']=$two['token'];$handles[]=governance_native_start_worker($fixture,$payload);
    // Current-actor checks finish before the blocker. The worker never prelocks
    // the target: its actual SessionEngine -> DAO fence must cause this wait.
    foreach($handles as$handle){governance_native_wait_session_producer_ready($handle);}
    if(!$setup->begin_transaction()){throw new RuntimeException('recovery_session_blocker_begin_failed');}$locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$fixture->table('user','user').
      ' WHERE id=? FOR UPDATE',array($subject->getID()));
    foreach($handles as$handle){governance_native_continue_session_producer($handle);}
    governance_native_wait_worker_query($control,$handles,
      '/SELECT phid FROM .*[\x60]user[\x60].*'.preg_quote($subject->getPHID(),'/').'.*LOCK IN SHARE MODE/is');
    $threads=array_map(static function($h){return (int)$h['mysql_thread'];},$handles);sort($threads);
    $deadline=microtime(true)+5;$seen=array();
    do {
      $sql='SELECT DISTINCT r.PROCESSLIST_ID id FROM performance_schema.data_lock_waits w '.
        'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
        'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
        'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
        'WHERE r.PROCESSLIST_ID IN ('.implode(',',$threads).') AND b.PROCESSLIST_ID='.(int)$setup->thread_id.
        " AND l.OBJECT_SCHEMA='".$control->real_escape_string($fixture->getNamespace()."_user").
        "' AND l.OBJECT_NAME='user'";
      $result=$control->query($sql);if(!$result){throw new RuntimeException('recovery_session_wait_observation_failed');}
      $seen=array();foreach($result->fetch_all(MYSQLI_ASSOC)as$row){$seen[]=(int)$row['id'];}$result->free();sort($seen);
      if($seen===$threads){break;}usleep(20000);
    }while(microtime(true)<$deadline);
    $check($seen===$threads&&$count()===0,'recovery-session-two-real-native-inserts-wait-on-user-fence-before-source-write');
    if(!$setup->commit()){throw new RuntimeException('recovery_session_blocker_commit_failed');}$locked=false;
    $results=array();foreach($handles as&$handle){$results[]=governance_native_finish_worker($handle);}unset($handle);
    $rows=queryfx_all($conn,'SELECT id,type,isPartial FROM %R WHERE userPHID=%s ORDER BY id',
      new PhabricatorAuthSession(),$subject->getPHID());
    $expected=array();foreach($rows as$row){$expected[]=hash('sha256',(string)$row['id']);}
    $actual=array();foreach($results as$result){$actual[]=$result['session_id_digest']??null;}sort($expected);sort($actual);
    $check(count($rows)===2&&$expected===$actual&&$results[0]['status']==='committed'&&
      $results[1]['status']==='committed'&&$results[0]['restoration_authorized']===false&&
      $results[1]['restoration_authorized']===false,
      'recovery-session-release-persists-two-independent-native-sessions-without-restoration');
    $conn->openTransaction();
    try {
      $secret=$state['sessions']->establishSession(PhabricatorAuthSession::TYPE_WEB,$subject->getPHID(),false);
      unset($secret);$inside=$count();
    }finally{$conn->killTransaction();}
    $check($inside===3&&$count()===2&&!$conn->isInsideTransaction(),
      'recovery-session-nested-producer-savepoint-preserves-real-outer-rollback');
    queryfx($conn,'START TRANSACTION');
    try {
      queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),'Synthetic raw caller write',$subject->getID());
      $refused=false;
      try{$state['sessions']->establishSession(PhabricatorAuthSession::TYPE_WEB,$subject->getPHID(),false);}
      catch(AphrontQueryException$error){if($error->getCode()!==1568){throw$error;}$refused=true;}
      $row=queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID());
      $check($refused&&!$conn->isInsideTransaction()&&$row['realName']==='Synthetic raw caller write'&&$count()===2,
        'recovery-session-untracked-raw-transaction-1568-preserves-caller-write');
    }finally{queryfx($conn,'ROLLBACK');}
    return array('selected_native_session_insert_serialization'=>true,
      'independent_current_actor_credentials'=>true,'actor_epoch_initialization_outside_observed_fence'=>true,
      'all_session_producers_closed'=>false,'all_sources_complete'=>false,'restoration_executed'=>false);
  }finally{
    try{if($locked&&!$setup->rollback()){throw new RuntimeException('recovery_session_blocker_cleanup_failed');}}
    finally{
      try{governance_native_stop_fixture_handles($handles);}
      finally{
        try{if($environment!==null){governance_recovery_account_restore_fixture_environment($environment);}}
        finally{unset($flags);$_COOKIE=$cookies;
          if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}}
      }
    }
  }
}
