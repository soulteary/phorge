<?php

/** Owned native writers and storage faults. Not a public recovery endpoint. */
function governance_recovery_stop_missing_audit_error(Throwable $error, $namespace) {
  if(!is_string($namespace)||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    return false;
  }
  // The native common-error mapper deliberately constructs this final subtype
  // without passing errno to Exception. Require its actual class/message, not
  // a generic throwable or an invented nonzero Exception code.
  return get_class($error)===AphrontSchemaQueryException::class&&$error->getCode()===0&&
    $error->getMessage()==="#1146: Table '".$namespace."_user.recovery_stop_audit' doesn't exist";
}

function governance_recovery_stop_wait($fixture, mysqli $observer, array $workers) {
  governance_native_wait_worker_query($observer,$workers,'/SELECT phid FROM .*`user`.*FOR UPDATE/is');
  $threads=array();foreach($workers as$worker){$threads[]=(int)$worker['mysql_thread'];}
  $database=$fixture->getNamespace().'_user';$deadline=microtime(true)+5;
  do {
    $sql='SELECT DISTINCT r.PROCESSLIST_ID id FROM performance_schema.data_lock_waits w '.
      'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
      'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
      'WHERE r.PROCESSLIST_ID IN ('.implode(',',$threads).') AND l.OBJECT_SCHEMA=\''.
      $observer->real_escape_string($database).'\' AND l.OBJECT_NAME=\'user\'';
    $result=$observer->query($sql);
    if(!$result){throw new RuntimeException('recovery_stop_lock_observation_failed');}
    $seen=array();foreach($result->fetch_all(MYSQLI_ASSOC)as$row){$seen[]=(int)$row['id'];}
    $result->free();sort($seen);$expected=$threads;sort($expected);
    if($seen===$expected){return;}
    usleep(20000);
  }while(microtime(true)<$deadline);
  throw new RuntimeException('recovery_stop_actual_lock_wait_missing');
}

function governance_recovery_stop_boundary_cases($fixture, callable $check, array $state) {
  global $control;
  if(!($control instanceof mysqli)){throw new RuntimeException('recovery_stop_observer_required');}
  $fixture->assertOwned();$setup=$fixture->getAdmin();$conn=(new PhabricatorUser())->establishConnection('w');
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;$locked=false;$scope=null;
  $missing=false;$myisam=false;$suffix=substr($fixture->getNamespace(),-10);
  $audit=$fixture->table('user','recovery_stop_audit');
  $users=$fixture->table('user','user');
  $namespace=$fixture->getNamespace();
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    throw new RuntimeException('recovery_stop_owned_namespace_required');}
  $temporary='`'.$namespace.'_user`.`recovery_stop_audit_boundary_saved`';
  $fingerprint=static function()use($conn){return hash('sha256',phutil_json_encode(array(
    queryfx_all($conn,'SELECT * FROM recovery_stop ORDER BY nativeTransactionID'),
    queryfx_all($conn,'SELECT * FROM recovery_stop_audit ORDER BY nativeTransactionID'))));};
  try {
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.recovery-stop-ledger.enabled',true);
    // The subject has a lower primary ID AND sorts before the actor PHID. The
    // parent's primary-key lock is one row, not an unindexed account scan.
    $actor=null;$subject=null;
    for($i=0;$i<32;$i++){
      $candidate=(new PhabricatorUser())->setUsername('stopboundsubject'.$suffix.$i)
        ->setRealName('Synthetic Boundary Subject')->setIsApproved(1)->save();
      $administrator=(new PhabricatorUser())->setUsername('stopboundactor'.$suffix.$i)
        ->setRealName('Synthetic Boundary Administrator')->setIsApproved(1)->setIsAdmin(1)->save();
      if(strcmp($candidate->getPHID(),$administrator->getPHID())<0){$subject=$candidate;$actor=$administrator;break;}
    }
    if(!$actor){throw new RuntimeException('recovery_stop_sorted_pair_unavailable');}
    $one=governance_native_request($actor,$state['sessions']);$two=governance_native_request($actor,$state['sessions']);
    if($one['token']===$two['token']){throw new RuntimeException('recovery_stop_independent_sessions_required');}
    $disable=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true);
    (new PhabricatorUserTransactionEditor())->setActor($state['admin']['viewer'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->applyTransactions($subject,array($disable));
    $event=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s AND transactionType=%s ORDER BY id DESC LIMIT 1',
      new PhabricatorUserTransaction(),$subject->getPHID(),PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
    if(!$event||$conn->isInsideTransaction()){throw new RuntimeException('recovery_stop_committed_fixture_event_required');}
    $id=(string)$event['id'];$operation='native-stop-boundary-'.$suffix;
    $payload=array('action'=>'recovery.stop.record','token'=>$one['token'],'subject'=>$subject->getPHID(),
      'event'=>$id,'operation'=>$operation,'category'=>'technical-pause','reason'=>'Synthetic bounded writer race');
    if(!$setup->begin_transaction()){throw new RuntimeException('recovery_stop_blocker_begin_failed');}$locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$users.' WHERE id=? FOR UPDATE',array($subject->getID()));
    $worker_one=governance_native_start_worker($fixture,$payload);
    $second=$payload;$second['token']=$two['token'];$worker_two=governance_native_start_worker($fixture,$second);
    governance_recovery_stop_wait($fixture,$control,array($worker_one,$worker_two));
    $check(true,'recovery-stop-two-real-sessions-wait-on-native-user-row');
    if(!$setup->commit()){throw new RuntimeException('recovery_stop_blocker_commit_failed');}$locked=false;
    $first=governance_native_finish_worker($worker_one);$second=governance_native_finish_worker($worker_two);
    $replays=array($first['replayed']??null,$second['replayed']??null);sort($replays);
    $check($first['status']==='committed'&&$second['status']==='committed'&&$replays===array(false,true)&&
      $first['recovery_authorized']===false&&$second['recovery_authorized']===false&&
      (int)queryfx_one($conn,'SELECT COUNT(*) n FROM recovery_stop WHERE operationID=%s',$operation)['n']===1&&
      (int)queryfx_one($conn,'SELECT COUNT(*) n FROM recovery_stop_audit WHERE operationID=%s',$operation)['n']===1,
      'recovery-stop-concurrent-same-operation-commits-once-and-replays-once');
    $visible=governance_native_start_worker($fixture,array('action'=>'recovery.stop.history',
      'token'=>$two['token'],'subject'=>$subject->getPHID()));$visible=governance_native_finish_worker($visible);
    $check($visible===array('status'=>'committed','count'=>1),
      'recovery-stop-independent-current-admin-history-before-revocation');

    $before=$fingerprint();
    if(!$setup->begin_transaction()){throw new RuntimeException('recovery_stop_blocker_begin_failed');}$locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$users.' WHERE id=? FOR UPDATE',array($subject->getID()));
    $revoked=governance_native_start_worker($fixture,$payload);
    governance_recovery_stop_wait($fixture,$control,array($revoked));
    $factors=(new PhabricatorAuthFactorConfigQuery())->setViewer($state['admin']['viewer'])
      ->withUserPHIDs(array($state['admin']['viewer']->getPHID()))->withFactorProviderStatuses(array(
        PhabricatorAuthFactorProviderStatus::STATUS_ACTIVE,PhabricatorAuthFactorProviderStatus::STATUS_DEPRECATED))->execute();
    if($factors||$state['admin']['viewer']->getSession()->isHighSecuritySession()){
      throw new RuntimeException('recovery_stop_native_no_factor_precondition_required');}
    (new PhabricatorUserTransactionEditor())->setActor($state['admin']['viewer'])
      ->setContentSourceFromRequest($state['admin']['request'])->setCancelURI('/people/manage/'.$actor->getID().'/')
      ->applyTransactions((new PhabricatorUser())->load($actor->getID()),array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(false)));
    $role=queryfx_one($conn,'SELECT isAdmin FROM %R WHERE id=%d',new PhabricatorUser(),$actor->getID());
    $role_event=queryfx_one($conn,'SELECT objectPHID,authorPHID,oldValue,newValue FROM %R WHERE BINARY objectPHID=BINARY %s AND transactionType=%s ORDER BY id DESC LIMIT 1',
      new PhabricatorUserTransaction(),$actor->getPHID(),PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE);
    if(!$role||!in_array($role['isAdmin'],array(0,'0'),true)||!$role_event||
        $role_event['objectPHID']!==$actor->getPHID()||$role_event['authorPHID']!==$state['admin']['viewer']->getPHID()||
        $role_event['oldValue']!=='true'||$role_event['newValue']!=='false'||$conn->isInsideTransaction()){
      throw new RuntimeException('recovery_stop_native_role_commit_required');}
    $check(true,'recovery-stop-waiting-writer-observes-real-native-admin-removal');
    if(!$setup->commit()){throw new RuntimeException('recovery_stop_blocker_commit_failed');}$locked=false;
    $denial=governance_native_finish_worker($revoked);
    $check($denial===array('status'=>'rejected','reason'=>'recovery_stop_admin_required')&&$fingerprint()===$before,
      'recovery-stop-revoked-current-admin-cannot-replay-after-real-wait');
    $history=governance_native_start_worker($fixture,array('action'=>'recovery.stop.history',
      'token'=>$two['token'],'subject'=>$subject->getPHID()));$history=governance_native_finish_worker($history);
    $check($history===array('status'=>'rejected','reason'=>'recovery_stop_admin_required')&&$fingerprint()===$before,
      'recovery-stop-history-rechecks-native-role-after-revocation');

    // A separate live administrator expires while waiting; no fake clock.
    $expiry_actor=(new PhabricatorUser())->setUsername('stopboundexpiry'.$suffix)->setRealName('Synthetic Expiring Administrator')
      ->setIsApproved(1)->setIsAdmin(1)->save();
    $expiry=governance_native_request($expiry_actor,$state['sessions']);
    if(!$setup->begin_transaction()){throw new RuntimeException('recovery_stop_blocker_begin_failed');}$locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$users.' WHERE id=? FOR UPDATE',array($subject->getID()));
    $expiry_payload=$payload;$expiry_payload['token']=$expiry['token'];
    $expiring_record=governance_native_start_worker($fixture,$expiry_payload);
    governance_recovery_stop_wait($fixture,$control,array($expiring_record));
    // Native login may refresh a short-lived session. Shorten only after the
    // real worker authenticated and is proved blocked before credential locks.
    if($conn->isInsideTransaction()){throw new RuntimeException('recovery_stop_expiry_update_requires_autocommit');}
    $until=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n']+3;
    queryfx($conn,'UPDATE %R SET sessionExpires=%d WHERE BINARY phid=BINARY %s',
      new PhabricatorAuthSession(),$until,$expiry['viewer']->getSession()->getPHID());
    $expiry_row=queryfx_one($conn,'SELECT sessionExpires FROM %R WHERE BINARY phid=BINARY %s',
      new PhabricatorAuthSession(),$expiry['viewer']->getSession()->getPHID());
    if(!$expiry_row||(string)$expiry_row['sessionExpires']!==(string)$until||$conn->isInsideTransaction()){
      throw new RuntimeException('recovery_stop_committed_session_expiry_required');}

    $check(governance_native_concurrency_now($fixture)<$until,
      'recovery-stop-record-expiry-wait-begins-with-real-live-credential');
    governance_native_concurrency_expire($fixture,$until);
    if(!$setup->commit()){throw new RuntimeException('recovery_stop_blocker_commit_failed');}$locked=false;
    $expired_record=governance_native_finish_worker($expiring_record);
    $check(governance_native_concurrency_now($fixture)>=$until&&
      $expired_record===array('status'=>'rejected','reason'=>'governance_native_credential_ineligible')&&$fingerprint()===$before,
      'recovery-stop-record-expired-during-native-user-lock-wait-is-refused');
    $expiry=governance_native_request($expiry_actor,$state['sessions']);
    if(!$setup->begin_transaction()){throw new RuntimeException('recovery_stop_blocker_begin_failed');}$locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$users.' WHERE id=? FOR UPDATE',array($expiry_actor->getID()));
    $expiring=governance_native_start_worker($fixture,array('action'=>'recovery.stop.history','token'=>$expiry['token'],'subject'=>$subject->getPHID()));
    governance_recovery_stop_wait($fixture,$control,array($expiring));
    // Native login may refresh a short-lived session. Shorten only after the
    // real worker authenticated and is proved blocked before credential locks.
    if($conn->isInsideTransaction()){throw new RuntimeException('recovery_stop_expiry_update_requires_autocommit');}
    $until=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n']+3;
    queryfx($conn,'UPDATE %R SET sessionExpires=%d WHERE BINARY phid=BINARY %s',
      new PhabricatorAuthSession(),$until,$expiry['viewer']->getSession()->getPHID());
    $expiry_row=queryfx_one($conn,'SELECT sessionExpires FROM %R WHERE BINARY phid=BINARY %s',
      new PhabricatorAuthSession(),$expiry['viewer']->getSession()->getPHID());
    if(!$expiry_row||(string)$expiry_row['sessionExpires']!==(string)$until||$conn->isInsideTransaction()){
      throw new RuntimeException('recovery_stop_committed_session_expiry_required');}

    $check(governance_native_concurrency_now($fixture)<$until,'recovery-stop-session-expiry-wait-begins-with-real-live-credential');
    governance_native_concurrency_expire($fixture,$until);
    if(!$setup->commit()){throw new RuntimeException('recovery_stop_blocker_commit_failed');}$locked=false;
    $expired=governance_native_finish_worker($expiring);
    $check(governance_native_concurrency_now($fixture)>=$until&&$expired===array('status'=>'rejected','reason'=>'governance_native_credential_ineligible')&&$fingerprint()===$before,
      'recovery-stop-history-expired-during-native-user-lock-wait-is-refused');

    $record=static function()use($state,$subject,$id,$operation){return PhorgeRecoveryStopService::recordAlreadyStopped(
      $state['admin']['context'],$subject->getPHID(),$id,'technical-pause','Synthetic bounded writer race',$operation);};
    governance_native_setup_query($fixture,'RENAME TABLE '.$audit.' TO '.$temporary);$missing=true;
    $missing_shape=governance_native_concurrency_sql($fixture,
      'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (?,?) ORDER BY TABLE_NAME',
      array($namespace.'_user','recovery_stop_audit','recovery_stop_audit_boundary_saved'));
    if($missing_shape!==array(array('TABLE_NAME'=>'recovery_stop_audit_boundary_saved'))){
      throw new RuntimeException('recovery_stop_missing_audit_precondition_failed');}
    $refused=false;
    try{$record();}catch(AphrontSchemaQueryException$error){
      if(!governance_recovery_stop_missing_audit_error($error,$namespace)){throw$error;}$refused=true;}
    finally{governance_native_setup_query($fixture,'RENAME TABLE '.$temporary.' TO '.$audit);$missing=false;}
    $restored_shape=governance_native_concurrency_sql($fixture,
      'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (?,?) ORDER BY TABLE_NAME',
      array($namespace.'_user','recovery_stop_audit','recovery_stop_audit_boundary_saved'));
    $check($refused&&$restored_shape===array(array('TABLE_NAME'=>'recovery_stop_audit'))&&
      !$conn->isInsideTransaction()&&$fingerprint()===$before,
      'recovery-stop-missing-audit-table-refuses-without-ledger-change');
    governance_native_setup_query($fixture,'ALTER TABLE '.$audit.' ENGINE=MyISAM');$myisam=true;
    $refused=false;
    try{$record();}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_stop_schema_unavailable'){throw$error;}$refused=true;}
    finally{governance_native_setup_query($fixture,'ALTER TABLE '.$audit.' ENGINE=InnoDB');$myisam=false;}
    $check($refused&&!$conn->isInsideTransaction()&&$fingerprint()===$before,
      'recovery-stop-nontransactional-audit-table-refuses-without-ledger-change');
    $route=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('cluster.databases',array('unsupported-fixture-cluster'));
    $refused=false;try{$record();}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_stop_profile_unavailable'){throw$error;}$refused=true;}
    finally{unset($route);}
    $check($refused&&!$conn->isInsideTransaction()&&$fingerprint()===$before,
      'recovery-stop-unsupported-cluster-profile-refused-before-ledger-access');
    $old_name=queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName'];
    queryfx($conn,'START TRANSACTION');
    try{
      queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),'Synthetic raw outer stop write',$subject->getID());
      $refused=false;try{$record();}catch(AphrontQueryException$error){if($error->getCode()!==1568){throw$error;}$refused=true;}
      $check($refused&&!$conn->isInsideTransaction()&&queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',
        new PhabricatorUser(),$subject->getID())['realName']==='Synthetic raw outer stop write',
        'recovery-stop-untracked-physical-transaction-remains-owned-by-caller');
    }finally{queryfx($conn,'ROLLBACK');}
    $check(queryfx_one($conn,'SELECT realName FROM %R WHERE id=%d',new PhabricatorUser(),$subject->getID())['realName']===$old_name&&$fingerprint()===$before,
      'recovery-stop-raw-outer-rollback-restores-only-caller-write');
    return array('two_session_same_operation_proof'=>true,'current_admin_revocation_after_wait'=>true,
      'real_session_expiry_during_wait'=>true,'missing_and_nontransactional_table_refused'=>true,
      'unsupported_routing_refused'=>true,'raw_outer_transaction_preserved'=>true,
      'recovery_authorized'=>false,'all_sources_complete'=>false,'production_entry_registered'=>false);
  }finally{
    if($locked){$setup->rollback();}
    if($missing){governance_native_setup_query($fixture,'RENAME TABLE '.$temporary.' TO '.$audit);}
    if($myisam){governance_native_setup_query($fixture,'ALTER TABLE '.$audit.' ENGINE=InnoDB');}
    unset($scope);$_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}
  }
}
