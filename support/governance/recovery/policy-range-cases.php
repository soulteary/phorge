<?php

/** Fixture-only comparison of actual service RC and a separate RR control. */
function governance_recovery_policy_refuse_changed(array $before,array $after) {
  foreach(array($before,$after)as$facts){
    foreach(array('complete','restoration_authorized','empty_range_absence_proved',
      'policy_interpreter_complete','automatic_object_grants_complete','producer_lineage_complete')as$key){
      if(!array_key_exists($key,$facts)||$facts[$key]!==false){
        throw new RuntimeException('recovery_policy_partial_facts_required');}}
    if(($facts['current_rows_locked']??null)!==true||!is_array($facts['selected_source_rows']??null)){
      throw new RuntimeException('recovery_policy_partial_facts_required');}
  }
  if(!hash_equals(PhorgeRecoveryRestoreRequest::digest($before),PhorgeRecoveryRestoreRequest::digest($after))){
    throw new RuntimeException('recovery_policy_current_source_changed');}
  // Equal partial facts are not absence or execution authority.
}
function governance_recovery_policy_range_cases($fixture,callable $check,array $state) {
  global $control;
  $fixture->assertOwned();
  if(!($control instanceof mysqli)){throw new RuntimeException('recovery_policy_observer_required');}
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  $flags=null;$environment=null;$handles=array();$rr_digests=array();
  $conn=(new PhabricatorUser())->establishConnection('w');
  try {
    if($conn->isInsideTransaction()){throw new RuntimeException('recovery_policy_fixture_outer_transaction');}
    $count=static function()use($conn){return (int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R',new PhabricatorPolicy())['n'];};
    // Must run in fresh fixture before other custom-policy creators. This is a
    // real empty native table, not a DELETE-held substitute for a gap fence.
    if($count()!==0){throw new RuntimeException('recovery_policy_empty_native_table_required');}
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    $context=$state['admin']['context'];$token=$state['admin']['token'];
    $transaction=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction');
    $session_level=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation v')['v'];
    foreach(array(false,true)as$rollback){
      $handle=governance_native_start_worker($fixture,array('action'=>'recovery.policy.insert','token'=>$token));
      $handles[]=$handle;governance_native_wait_policy_producer_ready($handle);
      $prior=$count();$released_by_rollback=false;
      try {
        // This RR transaction is an isolated reader/producer comparison only.
        // It does not invoke or describe the service's retained RC transaction.
        $rr_work=static function($writer,$actor,$subjects)use(
          $fixture,$check,$handle,$prior,$rollback,$control){
          $source=PhorgeRecoveryRestorePolicySource::capture($writer);
          if(count($source['selected_source_rows']['policy'])!==$prior){throw new RuntimeException('recovery_policy_fixture_source_count');}
          $parent=(int)queryfx_one($writer,'SELECT CONNECTION_ID() n')['n'];
          governance_native_continue_policy_producer($handle);
          governance_native_wait_worker_query($control,array($handle),'/INSERT INTO .*policy/is');
          $deadline=microtime(true)+3;$waiting=false;
          do {
            $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
              'JOIN performance_schema.data_locks l ON l.ENGINE=w.ENGINE AND l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
              'JOIN information_schema.INNODB_TRX r ON r.TRX_REQUESTED_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
              'JOIN information_schema.INNODB_TRX b ON b.TRX_ID=CAST(w.BLOCKING_ENGINE_TRANSACTION_ID AS CHAR) '.
              "WHERE w.ENGINE='INNODB' AND r.TRX_STATE='LOCK WAIT' AND r.TRX_MYSQL_THREAD_ID=".(int)$handle['mysql_thread'].
              ' AND b.TRX_MYSQL_THREAD_ID='.$parent.
              " AND l.OBJECT_SCHEMA='".$control->real_escape_string($fixture->getNamespace().'_policy').
              "' AND l.OBJECT_NAME='policy' AND l.INDEX_NAME='PRIMARY'";
            $result=$control->query($sql);if(!$result){throw new RuntimeException('recovery_policy_wait_observation_failed');}
            $waiting=(int)$result->fetch_assoc()['n']>0;$result->free();
            if($waiting){break;}usleep(20000);
          }while(microtime(true)<$deadline);
          $again=PhorgeRecoveryRestorePolicySource::capture($writer);
          $check($waiting&&PhorgeRecoveryRestoreRequest::digest($source)===PhorgeRecoveryRestoreRequest::digest($again)&&
            $writer->isInsideTransaction(),$rollback?
              'recovery-policy-real-populated-primary-range-blocks-native-dao-insert-until-rollback':
              'recovery-policy-real-empty-primary-gap-blocks-native-dao-insert-until-commit');
          if($rollback){throw new RuntimeException('recovery_policy_fixture_release_by_rollback');}
          return array('restoration_authorized'=>false);
        };
        queryfx($conn,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $conn->openTransaction();
        try{$rr_work($conn,null,array());$conn->saveTransaction();}
        finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}

      }catch(RuntimeException$error){
        if(!$rollback||get_class($error)!==RuntimeException::class||
          $error->getMessage()!=='recovery_policy_fixture_release_by_rollback'){throw$error;}
        $released_by_rollback=true;
      }
      $result=governance_native_finish_worker($handle);$rr_digests[]=$result['native_policy_digest'];
      $stored=queryfx_all($conn,'SELECT phid FROM %R',new PhabricatorPolicy());
      $digests=array_map(static function($row){return hash('sha256',$row['phid']);},$stored);
      $check(in_array($result['native_policy_digest'],$digests,true)&&$result['status']==='committed'&&$result['restoration_authorized']===false&&
        $count()===$prior+1&&!$conn->isInsideTransaction()&&$released_by_rollback===$rollback,
        $rollback?'recovery-policy-rollback-releases-native-producer-without-restoration':
          'recovery-policy-commit-releases-native-producer-without-restoration');
    }
    // Remove only the two native rows created by the known RR workers. Never
    // treat DELETE-held gaps as the empty-range control: each delete commits.
    $policies=(new PhabricatorPolicy())->loadAll();$observed=array();
    foreach($policies as$policy){$observed[]=hash('sha256',$policy->getPHID());}
    sort($observed,SORT_STRING);sort($rr_digests,SORT_STRING);
    if(count($policies)!==2||$observed!==$rr_digests){throw new RuntimeException('recovery_policy_rr_cleanup_identity');}
    foreach($policies as$policy){$policy->delete();}
    $check($count()===0&&!$conn->isInsideTransaction(),
      'recovery-policy-only-owned-rr-native-rows-deleted-and-committed-before-rc');
    foreach(array('empty','populated')as$shape){
      $handle=governance_native_start_worker($fixture,array('action'=>'recovery.policy.insert','token'=>$token));
      $handles[]=$handle;governance_native_wait_policy_producer_ready($handle);
      $prior=$count();$changed_refused=false;$producer=null;
      try{
        $transaction->invoke(null,$context,array(),static function($writer,$actor,$subjects)use(
          $check,$handle,$shape,$prior,&$producer){
          $before=PhorgeRecoveryRestorePolicySource::capture($writer);
          if(count($before['selected_source_rows']['policy'])!==$prior){throw new RuntimeException('recovery_policy_rc_source_count');}
          governance_native_continue_policy_producer($handle);
          // Actual native DAO completion while the real service transaction is
          // still open is the positive control: RC is not an absence fence.
          $producer=governance_native_finish_worker($handle);
          $after=PhorgeRecoveryRestorePolicySource::capture($writer);
          $check($writer->isInsideTransaction()&&$producer['status']==='committed'&&
            $producer['restoration_authorized']===false&&
            count($after['selected_source_rows']['policy'])===$prior+1&&
            PhorgeRecoveryRestoreRequest::digest($before)!==PhorgeRecoveryRestoreRequest::digest($after)&&
            $after['complete']===false&&$after['empty_range_absence_proved']===false,
            $shape==='empty'?'recovery-policy-real-service-rc-empty-range-allows-native-commit-and-current-read-change':
              'recovery-policy-real-service-rc-populated-range-allows-native-commit-and-current-read-change');
          governance_recovery_policy_refuse_changed($before,$after);
          throw new RuntimeException('recovery_policy_changed_source_unexpectedly_accepted');
        });
      }catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_policy_current_source_changed'){throw$error;}
        $changed_refused=true;
      }
      $stored=queryfx_all($conn,'SELECT phid FROM %R',new PhabricatorPolicy());
      $digests=array_map(static function($row){return hash('sha256',$row['phid']);},$stored);
      $check($changed_refused&&!$conn->isInsideTransaction()&&$count()===$prior+1&&
        $producer!==null&&in_array($producer['native_policy_digest'],$digests,true),
        $shape==='empty'?'recovery-policy-real-service-rc-empty-source-change-refused-with-producer-commit-retained':
          'recovery-policy-real-service-rc-populated-source-change-refused-with-producer-commit-retained');
    }
    $check(queryfx_one($conn,'SELECT @@SESSION.transaction_isolation v')['v']===$session_level,
      'recovery-policy-next-transaction-rr-does-not-change-session-default');
  }finally{
    if($conn->isInsideTransaction()){$conn->killTransaction();}
    governance_native_stop_fixture_handles($handles);
    try{if($environment){governance_recovery_account_restore_fixture_environment($environment);}}
    finally{unset($flags);$_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}}
  }
}
