<?php
/** Fixed failure metadata only: no SQL, messages, identities or credential reads. */
function governance_recovery_oauth_client_lifecycle_trace_projection($frames){
  $allowed=array('support/runtime/src/error/PhutilErrorHandler.php','support/governance/recovery/oauth-client-lifecycle-cases.php','support/governance/recovery/restore-review-cases.php','src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php','src/infrastructure/governance/recovery/PhorgeRecoveryStopService.php','src/applications/oauthserver/storage/PhabricatorOAuthServerClient.php','src/applications/oauthserver/server/PhabricatorOAuthServer.php','src/applications/people/editor/PhabricatorUserTransactionEditor.php','src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php');$out=array();
  if(!is_array($frames)){return$out;}
  foreach(array_slice($frames,0,8)as$f){if(is_array($f)&&in_array($f['source']??null,$allowed,true)&&is_int($f['line']??null)&&$f['line']>0&&$f['line']<=1000000){$out[]=array('source'=>$f['source'],'line'=>$f['line']);}}return$out;
}
function governance_recovery_oauth_client_lifecycle_failure_projection($failure){
  $out=array('scope'=>'native-oauth-client-lifecycle-failure-v1','passed'=>false,'diagnostic_available'=>is_array($failure),
    'stage'=>'other','attempt_stage'=>'other','error_type'=>'other','source_file'=>null,'source_line'=>null,'query_code'=>null,
    'trace'=>array(),'child_available'=>false,'child_status'=>'other','child_identity_started'=>false,'child_result_available'=>false);
  if(!is_array($failure)){return$out;}
  $stages=array('preflight','full-native-source-restore','owned-real-gorge-file-service','focused-native-oauth-client-lifecycle-producer-owner','unexpected-native-runtime-output');
  $attempts=array('durable-prepare-client','durable-disable-subject','durable-record-stop','durable-prime-spaces','durable-submit','durable-replay-preview','durable-review','durable-trust-save','durable-stale-review','durable-fresh-submit-review','durable-execute-incomplete','preflight','prepare-users','prepare-extra-avatar','prepare-roles','flags','environment','default-writer','issuer-session','owner-session','prewarm','physical-cases','final-cleanup');
  foreach(array('disabled','redirect','trust','secret','authorization-delete','client-cascade')as$op){foreach(array('commit','rollback')as$end){foreach(array('client-first-and-granted-record','native-writer-exact-record-wait','owner-terminal-writer-native-postcondition','next-store-api-binding-changed')as$kind){$attempts[]='oauth-client-lifecycle-'.$op.'-'.$end.'-'.$kind;}}}
  $attempts=array_merge($attempts,array('oauth-client-lifecycle-durable-submit-five-sources','oauth-client-lifecycle-durable-replay-review-blocked','oauth-client-lifecycle-native-trust-save-invalidates-review','oauth-client-lifecycle-execute-requires-complete-sources'));
  $types=array('RuntimeException','LogicException','TypeError','Error','Exception','mysqli_sql_exception','PhutilAggregateException','AphrontQueryException','AphrontObjectMissingQueryException','InvalidArgumentException');
  $files=array('PhabricatorOAuthServer.php','PhabricatorOAuthClientAuthorization.php','PhabricatorOAuthServerClient.php','PhabricatorOAuthServerDAO.php','oauth-client-lifecycle-focused.php','oauth-client-lifecycle-producer.php','oauth-client-lifecycle-cases.php','oauth-client-lifecycle-query-trace.php','test-oauth-client-lifecycle-mysql.php','oauth-client-lifecycle-diagnostic.php','borrowed-session-focused.php','borrowed-session-producer.php','borrowed-session-observer.php','borrowed-session-diagnostic.php','session-lease-cases.php','session-lease-observer.php','account-cases.php','task-owner-source-cases.php','project-source-focused.php','PhorgeRecoveryConduitProducerFence.php','PhorgeRecoveryRestoreService.php','PhorgeRecoverySessionProducerFence.php','PhorgeRecoveryRestoreProfile.php','PhorgeRecoveryNativeCaptureScope.php','PhorgeGovernanceNativeRuntimeProfile.php','PhorgeGovernanceNativeIdentitySource.php','PhabricatorAuthSessionEngine.php','PhabricatorAuthSession.php','PhabricatorUser.php','PhabricatorEnv.php','PhabricatorScopedEnv.php','fixture.php','pipeline-gorge.php','oauth-client-lifecycle-worker.php','oauth-client-lifecycle-workers.php','LiskDAO.php','AphrontDatabaseConnection.php','AphrontBaseMySQLDatabaseConnection.php','AphrontMySQLiDatabaseConnection.php');
  foreach(array('stage'=>$stages,'attempt_stage'=>$attempts,'error_type'=>$types,'source_file'=>$files)as$key=>$values){if(in_array($failure[$key]??null,$values,true)){$out[$key]=$failure[$key];}}
  if(is_int($failure['source_line']??null)&&$failure['source_line']>0&&$failure['source_line']<=1000000){$out['source_line']=$failure['source_line'];}
  $code=$failure['query_code']??($failure['sql_errno']??null);if(is_int($code)&&$code>=0&&$code<=65535){$out['query_code']=$code;}
  foreach(array('child_available','child_identity_started','child_result_available')as$key){if(is_bool($failure[$key]??null)){$out[$key]=$failure[$key];}}
  if(in_array($failure['child_status']??null,array('failed','rejected','committed'),true)){$out['child_status']=$failure['child_status'];}
  $out['trace']=governance_recovery_oauth_client_lifecycle_trace_projection($failure['trace']??null);
  // Reproject only fixed metadata, never arbitrary nested diagnostic fields.
  if(is_array($failure['primary']??null)){$p=$failure['primary'];unset($p['primary'],$p['child']);$out['primary']=governance_recovery_oauth_client_lifecycle_failure_projection($p);}
  if(is_array($failure['child']??null)){$p=$failure['child'];unset($p['primary'],$p['child']);$out['child']=governance_recovery_oauth_client_lifecycle_failure_projection($p);}
  return$out;
}
function governance_recovery_oauth_client_lifecycle_throwable_projection($stage,Throwable$error,$depth=0){
  $row=array('stage'=>'focused-native-oauth-client-lifecycle-producer-owner','attempt_stage'=>$stage,'error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  $frames=array();$root=dirname(__DIR__,3);
  foreach(array_slice($error->getTrace(),0,8)as$f){$file=$f['file']??null;$line=$f['line']??null;if(is_string($file)&&str_starts_with($file,$root.'/')){$frames[]=array('source'=>substr($file,strlen($root)+1),'line'=>$line);}}
  $row['trace']=governance_recovery_oauth_client_lifecycle_trace_projection($frames);
  if($error instanceof mysqli_sql_exception){$row['sql_errno']=$error->getCode();}
  elseif($error instanceof AphrontQueryException){$row['query_code']=$error->getCode();}
  if($depth<2&&$error instanceof PhutilAggregateException){$errors=$error->getExceptions();if(($errors['primary']??null) instanceof Throwable){$row['primary']=governance_recovery_oauth_client_lifecycle_throwable_projection($stage,$errors['primary'],$depth+1);}}
  return governance_recovery_oauth_client_lifecycle_failure_projection($row);
}
function governance_recovery_oauth_client_lifecycle_emit_failure(array$diagnostic){
  // A broken diagnostic stream must never replace the actual primary Throwable.
  try{@fwrite(STDERR,json_encode(governance_recovery_oauth_client_lifecycle_failure_projection($diagnostic),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");}catch(Throwable$ignored){}
}

/** No SQL: read only bounded owned child markers through the existing FD reader. */
function governance_recovery_oauth_client_lifecycle_child_diagnostic(array$handle){
  $out=array('child_available'=>false,'child_status'=>'other','child_identity_started'=>false,'child_result_available'=>false);
  try{
    $worker=governance_oauth_client_lifecycle_native_borrowed_oauth_client_lifecycle_handle($handle);
    $identity=governance_recovery_borrowed_worker_record($worker['private'],'producer-identity-started.json');
    $result=governance_recovery_borrowed_worker_record($worker['private'],'result.json');
    $out['child_available']=true;$out['child_identity_started']=$identity===array('status'=>'identity-started','mysql_thread'=>$worker['mysql_thread']);
    $out['child_result_available']=is_array($result);
    if(is_array($result)){$out['child_status']=$result['status']??'other';$out['child']=array('error_type'=>$result['error_type']??null,'source_file'=>$result['source_file']??null,'source_line'=>$result['source_line']??null,'query_code'=>$result['query_code']??null);}
  }catch(Throwable$unavailable){}
  return governance_recovery_oauth_client_lifecycle_failure_projection($out);
}
