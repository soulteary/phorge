<?php
require_once __DIR__.'/native-writer-rr-principals.php';
require_once __DIR__.'/native-writer-rr-parent.php';

function governance_native_rr_focused($fixture,callable$check){
  global$control;
  $fixture->assertOwned();$GLOBALS['governance_native_rr_workers']=array();$GLOBALS['governance_native_rr_cleanup']=null;$principals=null;$revoker=null;$pending=false;$primary=null;$localCleanup=array();$activeWorker=null;
  if(PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')!==false){throw new RuntimeException('native_rr_initial_feature_profile_refused');}
  try{
    // Owner artifact/kernel preflight is before extra privileges or a protected write.
    $first=GovernanceNativeRRWorker::create($control);$activeWorker=$first;
    $principals=GovernanceNativeRRPrincipals::prepare($fixture,$control,'candidate');$config=$principals->privateConfiguration();
    $subject=(new PhabricatorUser())->setUsername('natrr'.substr($fixture->getNamespace(),-12))->setRealName('Synthetic RR Baseline')->setIsApproved(1)->save();
    $phid=$subject->getPHID();if(!is_string($phid)||!preg_match('/\APHID-USER-[a-z0-9]{20}\z/D',$phid)){throw new RuntimeException('native_rr_subject_refused');}
    $table='`'.$fixture->getNamespace().'_user`.`user`';$meta=$config['observer']['user'];
    $name=static function()use($control,$table,$phid){$row=$control->query("SELECT realName FROM ".$table." WHERE phid='".$phid."'")->fetch_assoc();if(!$row){throw new RuntimeException('native_rr_committed_subject_missing');}return$row['realName'];};
    $privilege=static function()use($control,$meta){$row=$control->query("SELECT Trigger_priv FROM mysql.user WHERE Host='%' AND User='".$meta."'")->fetch_assoc();if(!$row){throw new RuntimeException('native_rr_metadata_principal_missing');}return$row['Trigger_priv'];};
    $writer=$fixture->getWriterConfig();$revoker=new mysqli($writer['host'],getenv('PHORGE_GOVERNANCE_FIXTURE_USER'),getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD'),'',$writer['port']);$revoker->set_charset('utf8mb4');
    $a=$control->query('SELECT @@server_uuid uuid,@@autocommit ac')->fetch_assoc();$b=$revoker->query('SELECT @@server_uuid uuid')->fetch_assoc();
    if($a['uuid']!==$b['uuid']||(string)$a['ac']!=='1'||$revoker->thread_id===$control->thread_id){throw new RuntimeException('native_rr_control_primary_unknown');}
    foreach(array('commit','rollback')as$i=>$ending){
      if($revoker->query("GRANT TRIGGER ON *.* TO '".$meta."'@'%'")!==true||$privilege()!=='Y'){throw new RuntimeException('native_rr_metadata_regrant_unproved');}
      $worker=$i===0?$first:GovernanceNativeRRWorker::create($control);$activeWorker=$worker;$before=$name();$nonce=(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture);
      $worker->start($config,$nonce,$phid,$ending,'candidate');
      $check($name()===$before,'native-rr-mdl-'.$ending.'-actual-native-writer-RR-marker-uncommitted');
      if($revoker->query("REVOKE TRIGGER ON *.* FROM '".$meta."'@'%'",MYSQLI_ASYNC)!==true){throw new RuntimeException('native_rr_revoke_start_failed');}$pending=true;
      $proof=governance_native_rr_wait_pending(static function()use($worker,$revoker){return$worker->pendingReady($revoker);},
        static function()use($worker,$revoker){return$worker->pendingProof($revoker);},static function(){return hrtime(true);},static function($us){usleep($us);});
      $check($proof!==null&&$privilege()==='Y','native-rr-mdl-'.$ending.'-real-revoke-waits-unique-same-native-transaction');
      // Re-observation must retain the exact ticket/event/physical owner, not a boolean latch.
      $check($worker->pendingProof($revoker)===$proof,'native-rr-mdl-'.$ending.'-same-native-private-identity-before-own-termination');
      $result=$worker->finish();$wanted=$ending==='commit'?'committed':'rolled-back';
      $check($result['terminal']['state']===$wanted&&$result['terminal']['cleanup_proved']===true,'native-rr-mdl-'.$ending.'-native-scope-terminal-outcome-observed');
      $gone=true;$tickets=governance_recovery_grant_mdl_tickets($control);if(count($tickets)>64){$gone=false;}foreach($tickets as$ticket){if($ticket['OBJECT_INSTANCE_BEGIN']===$proof['holder']['OBJECT_INSTANCE_BEGIN']||$ticket['PROCESSLIST_ID']===$proof['holder']['PROCESSLIST_ID']){$gone=false;}}
      $expected=$ending==='commit'?'Synthetic RR Commit Marker':$before;
      $read=array($revoker);$errors=$reject=array();if(mysqli_poll($read,$errors,$reject,4,0)!==1){throw new RuntimeException('native_rr_revoke_reap_timeout');}$pending=false;$reaped=$revoker->reap_async_query();
      $check($gone&&$name()===$expected&&$reaped===true&&$privilege()==='N','native-rr-mdl-'.$ending.'-same-commit-or-rollback-publishes-data-and-releases-MDL');
      $clean=$worker->cleanup();$localCleanup[]=$clean;
      $check($clean['cleanup_proved']===true&&$clean['owned_SQL_sessions_ended']===true&&$clean['private_directory_removed']===true,'native-rr-mdl-'.$ending.'-owned-child-and-exact-SQL-sessions-clean');
    }
    return array('profile'=>'isolated-same-native-User-writer-RR-MDL-v1','source_revision'=>getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION'),'component_SQL_obligations'=>12,'local_children'=>2,'local_cleanup'=>$localCleanup,'innocuous_native_User_write'=>true,'native_enable_executed'=>false,'minimal_grants_negative_SQL_complete'=>false,'unknown_transport_fault_matrix_complete'=>false,'production_companion_fence_installed'=>false,'role_proxy_dynamic_privileges_complete'=>false,'all13_complete'=>false,'restoration_authorized'=>false);
  }catch(Throwable$error){$primary=$error;if($activeWorker&&!$activeWorker->isClosed()){try{$GLOBALS['governance_native_rr_failure']=$activeWorker->failureProjection();}catch(Throwable$projection_error){$GLOBALS['governance_native_rr_failure']=array('result_available'=>false);}}throw$error;}
  finally{
    $errors=array();$clean=true;$principalCleanup=null;$revokerClosed=false;
    foreach($GLOBALS['governance_native_rr_workers']as$worker){
      // Completed children were already cleaned in the successful branch.
      try{$closed=$worker->isClosed();if($closed){continue;}$value=$worker->cleanup();if($value['cleanup_proved']!==true){$clean=false;$errors[]='child-cleanup-unproved';}}
      catch(Throwable$error){$clean=false;$errors[]='child-cleanup-unproved';}
    }
    if($revoker){try{if($pending){$read=array($revoker);$reject=$failure=array();if(mysqli_poll($read,$failure,$reject,4,0)===1){$revoker->reap_async_query();}else{$clean=false;$errors[]='revoke-reap-unproved';}}$revoker->close();$revokerClosed=true;}catch(Throwable$error){$clean=false;$errors[]='revoker-close-unproved';}}
    if($principals===null){$principals=$GLOBALS['governance_native_rr_principal_setup']??null;}
    if($principals&&$clean){try{$value=$principals->cleanup();$principalCleanup=$value;if($value['cleanup_proved']!==true){$clean=false;$errors[]='principal-cleanup-unproved';}}catch(Throwable$error){$clean=false;$errors[]='principal-cleanup-unproved';}}
    $GLOBALS['governance_native_rr_cleanup']=array('cleanup_proved'=>$clean,'local_children_registered'=>count($GLOBALS['governance_native_rr_workers']),'failures'=>$errors,'revoker_closed'=>$revokerClosed,'principal_cleanup'=>$principalCleanup);
    if(!$clean&&$primary===null){throw new RuntimeException('native_rr_owned_cleanup_unproved');}
  }
}
