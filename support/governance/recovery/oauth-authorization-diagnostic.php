<?php
/** Fixed failure metadata only: no SQL, messages, identities or credential reads. */
function governance_recovery_oauth_authorization_failure_projection($failure){
  $out=array('scope'=>'native-conduit-producer-failure-v1','passed'=>false,'diagnostic_available'=>is_array($failure),
    'stage'=>'other','attempt_stage'=>'other','error_type'=>'other','source_file'=>null,'source_line'=>null,'query_code'=>null,
    'child_available'=>false,'child_status'=>'other','child_identity_started'=>false,'child_result_available'=>false);
  if(!is_array($failure)){return$out;}
  $stages=array('preflight','full-native-source-restore','owned-real-gorge-file-service','focused-native-oauth-authorization-producer-owner','unexpected-native-runtime-output');
  $attempts=array('preflight','prepare-users','prepare-extra-avatar','prepare-roles','flags','environment','default-writer','issuer-session','owner-session','prewarm','physical-cases','final-cleanup');
  $types=array('RuntimeException','LogicException','TypeError','Error','Exception','mysqli_sql_exception','PhutilAggregateException','AphrontQueryException','AphrontObjectMissingQueryException','InvalidArgumentException');
  $files=array('PhabricatorOAuthServer.php','PhabricatorOAuthClientAuthorization.php','PhabricatorOAuthServerClient.php','PhabricatorOAuthServerDAO.php','oauth-authorization-focused.php','oauth-authorization-producer.php','oauth-authorization-cases.php','oauth-authorization-diagnostic.php','borrowed-session-focused.php','borrowed-session-producer.php','borrowed-session-observer.php','borrowed-session-diagnostic.php','session-lease-cases.php','session-lease-observer.php','account-cases.php','task-owner-source-cases.php','project-source-focused.php','PhorgeRecoveryConduitProducerFence.php','PhorgeRecoveryRestoreService.php','PhorgeRecoverySessionProducerFence.php','PhorgeRecoveryRestoreProfile.php','PhorgeRecoveryNativeCaptureScope.php','PhorgeGovernanceNativeRuntimeProfile.php','PhorgeGovernanceNativeIdentitySource.php','PhabricatorAuthSessionEngine.php','PhabricatorAuthSession.php','PhabricatorUser.php','PhabricatorEnv.php','PhabricatorScopedEnv.php','fixture.php','pipeline-gorge.php','oauth-authorization-worker.php','oauth-authorization-workers.php','LiskDAO.php','AphrontDatabaseConnection.php','AphrontBaseMySQLDatabaseConnection.php','AphrontMySQLiDatabaseConnection.php');
  foreach(array('stage'=>$stages,'attempt_stage'=>$attempts,'error_type'=>$types,'source_file'=>$files)as$key=>$values){if(in_array($failure[$key]??null,$values,true)){$out[$key]=$failure[$key];}}
  if(is_int($failure['source_line']??null)&&$failure['source_line']>0&&$failure['source_line']<=1000000){$out['source_line']=$failure['source_line'];}
  $code=$failure['query_code']??($failure['sql_errno']??null);if(is_int($code)&&$code>=0&&$code<=65535){$out['query_code']=$code;}
  foreach(array('child_available','child_identity_started','child_result_available')as$key){if(is_bool($failure[$key]??null)){$out[$key]=$failure[$key];}}
  if(in_array($failure['child_status']??null,array('failed','rejected','committed'),true)){$out['child_status']=$failure['child_status'];}
  // Reproject only fixed metadata, never arbitrary nested diagnostic fields.
  if(is_array($failure['primary']??null)){$p=$failure['primary'];unset($p['primary'],$p['child']);$out['primary']=governance_recovery_oauth_authorization_failure_projection($p);}
  if(is_array($failure['child']??null)){$p=$failure['child'];unset($p['primary'],$p['child']);$out['child']=governance_recovery_oauth_authorization_failure_projection($p);}
  return$out;
}
function governance_recovery_oauth_authorization_throwable_projection($stage,Throwable$error,$depth=0){
  $row=array('stage'=>'focused-native-oauth-authorization-producer-owner','attempt_stage'=>$stage,'error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  if($error instanceof mysqli_sql_exception){$row['sql_errno']=$error->getCode();}
  elseif($error instanceof AphrontQueryException){$row['query_code']=$error->getCode();}
  if($depth<2&&$error instanceof PhutilAggregateException){$errors=$error->getExceptions();if(($errors['primary']??null) instanceof Throwable){$row['primary']=governance_recovery_oauth_authorization_throwable_projection($stage,$errors['primary'],$depth+1);}}
  return governance_recovery_oauth_authorization_failure_projection($row);
}
function governance_recovery_oauth_authorization_emit_failure(array$diagnostic){
  // A broken diagnostic stream must never replace the actual primary Throwable.
  try{@fwrite(STDERR,json_encode(governance_recovery_oauth_authorization_failure_projection($diagnostic),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");}catch(Throwable$ignored){}
}

/** No SQL: read only bounded owned child markers through the existing FD reader. */
function governance_recovery_oauth_authorization_child_diagnostic(array$handle){
  $out=array('child_available'=>false,'child_status'=>'other','child_identity_started'=>false,'child_result_available'=>false);
  try{
    $worker=governance_oauth_authorization_native_borrowed_oauth_authorization_handle($handle);
    $identity=governance_recovery_borrowed_worker_record($worker['private'],'producer-identity-started.json');
    $result=governance_recovery_borrowed_worker_record($worker['private'],'result.json');
    $out['child_available']=true;$out['child_identity_started']=$identity===array('status'=>'identity-started','mysql_thread'=>$worker['mysql_thread']);
    $out['child_result_available']=is_array($result);
    if(is_array($result)){$out['child_status']=$result['status']??'other';$out['child']=array('error_type'=>$result['error_type']??null,'source_file'=>$result['source_file']??null,'source_line'=>$result['source_line']??null,'query_code'=>$result['query_code']??null);}
  }catch(Throwable$unavailable){}
  return governance_recovery_oauth_authorization_failure_projection($out);
}
