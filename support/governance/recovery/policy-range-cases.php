<?php

/** Actual owned RR service lease vs native Policy DAO producer. UNRUN. */
function governance_recovery_policy_range_cases($fixture,callable $check,array $state) {
  global $control;
  $fixture->assertOwned();
  if(!($control instanceof mysqli)){throw new RuntimeException('recovery_policy_observer_required');}
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  $flags=null;$environment=null;$handles=array();
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
        // Fixture-only phase seam into the genuine service transaction. The
        // lease, sorted User locks, final current-actor/time checks and commit
        // are production code; this is not an HTTP/controller claim.
        $transaction->invoke(null,$context,array(),static function($writer,$actor,$subjects)use(
          $fixture,$check,$handle,$prior,$rollback,$control){
          $source=PhorgeRecoveryRestorePolicySource::capture($writer);
          if(count($source['selected_source_rows']['policy'])!==$prior){throw new RuntimeException('recovery_policy_fixture_source_count');}
          $parent=(int)queryfx_one($writer,'SELECT CONNECTION_ID() n')['n'];
          governance_native_continue_policy_producer($handle);
          governance_native_wait_worker_query($control,array($handle),'/INSERT INTO .*policy/is');
          $deadline=microtime(true)+3;$waiting=false;
          do {
            $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
              'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
              'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
              'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
              'WHERE r.PROCESSLIST_ID='.(int)$handle['mysql_thread'].' AND b.PROCESSLIST_ID='.$parent.
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
          return array('bindings'=>array(),'deadline'=>null,'result'=>array('restoration_authorized'=>false));
        });
      }catch(RuntimeException$error){
        if(!$rollback||get_class($error)!==RuntimeException::class||
          $error->getMessage()!=='recovery_policy_fixture_release_by_rollback'){throw$error;}
        $released_by_rollback=true;
      }
      $result=governance_native_finish_worker($handle);
      $stored=queryfx_all($conn,'SELECT phid FROM %R',new PhabricatorPolicy());
      $digests=array_map(static function($row){return hash('sha256',$row['phid']);},$stored);
      $check(in_array($result['native_policy_digest'],$digests,true)&&$result['status']==='committed'&&$result['restoration_authorized']===false&&
        $count()===$prior+1&&!$conn->isInsideTransaction()&&$released_by_rollback===$rollback,
        $rollback?'recovery-policy-rollback-releases-native-producer-without-restoration':
          'recovery-policy-commit-releases-native-producer-without-restoration');
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
