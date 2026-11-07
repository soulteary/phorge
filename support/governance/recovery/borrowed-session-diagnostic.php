<?php
/** Fixed metadata only. Never expose messages, SQL, identities or credentials. */
function governance_recovery_borrowed_failure_projection($failure){
  $out=array('scope'=>'native-borrowed-session-failure-v1','passed'=>false,'stage'=>'other','error_type'=>'other','source_file'=>null,'source_line'=>null,'query_code'=>null);
  if(!is_array($failure)){return$out;}
  $stages=array('preflight','full-native-source-restore','owned-real-gorge-file-service','focused-native-borrowed-session-owner','unexpected-native-runtime-output','borrowed-parent-or-observer-terminal-unproved');
  $types=array('RuntimeException','LogicException','TypeError','Error','mysqli_sql_exception','PhutilAggregateException','AphrontQueryException','AphrontObjectMissingQueryException','InvalidArgumentException');
  $files=array('borrowed-session-focused.php','borrowed-session-cases.php','borrowed-session-observer.php','session-lease-observer.php','account-cases.php','PhorgeRecoveryRestoreService.php','PhorgeRecoverySessionProducerFence.php','PhorgeRecoveryRestoreProfile.php','PhorgeRecoveryNativeCaptureScope.php','PhorgeGovernanceNativeRuntimeProfile.php','PhorgeGovernanceNativeIdentitySource.php','PhabricatorAuthSessionEngine.php','PhabricatorAuthSession.php','PhabricatorUser.php','fixture.php','pipeline-gorge.php','native-worker.php','workers.php');
  foreach(array('stage'=>$stages,'error_type'=>$types,'source_file'=>$files)as$key=>$values){if(in_array($failure[$key]??null,$values,true)){$out[$key]=$failure[$key];}}
  if(is_int($failure['source_line']??null)&&$failure['source_line']>0&&$failure['source_line']<=1000000){$out['source_line']=$failure['source_line'];}
  $code=$failure['query_code']??($failure['sql_errno']??null);if(is_int($code)&&$code>=0&&$code<=65535){$out['query_code']=$code;}
  if(isset($failure['borrowed_worker'])){$out['borrowed_worker']=governance_recovery_borrowed_worker_projection($failure['borrowed_worker']);}
  return$out;
}

/** One fixed owned child record; bounded stable FD, never stdout/stderr/payload. */
function governance_recovery_borrowed_worker_record($directory,$name){
  if(!in_array($name,array('producer-identity-started.json','result.json'),true)){throw new RuntimeException('borrowed_diagnostic_record_refused');}
  $parent=lstat($directory);
  $path=$directory.'/'.$name;clearstatcache(true,$path);$before=@lstat($path);if($before===false){return null;}
  if(($before['mode']&0170000)!==0100000||($before['mode']&07777)!==0600||$before['uid']!==posix_geteuid()||$before['nlink']!==1||$before['size']<1||$before['size']>4096){throw new RuntimeException('borrowed_diagnostic_record_refused');}
  // RDWR avoids blocking on a FIFO replacement before the mode comparison.
  $stream=fopen($path,'r+b');if(!is_resource($stream)){throw new RuntimeException('borrowed_diagnostic_record_refused');}
  try{
    $fd=fstat($stream);clearstatcache(true,$path);$now=lstat($path);
    foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){if(!is_array($now)||$before[$key]!==$fd[$key]||$fd[$key]!==$now[$key]){throw new RuntimeException('borrowed_diagnostic_record_changed');}}
    $raw=stream_get_contents($stream,4097);$after=fstat($stream);clearstatcache(true,$path);$now=lstat($path);
    foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){if(!is_array($now)||$fd[$key]!==$after[$key]||$after[$key]!==$now[$key]){throw new RuntimeException('borrowed_diagnostic_record_changed');}}
    clearstatcache(true,$directory);$current=lstat($directory);foreach(array('dev','ino','mode','uid','gid')as$key){if(!is_array($current)||$parent[$key]!==$current[$key]){throw new RuntimeException('borrowed_diagnostic_parent_changed');}}
    if(!is_string($raw)||strlen($raw)!==$fd['size']){throw new RuntimeException('borrowed_diagnostic_record_changed');}
    return json_decode($raw,true,8,JSON_THROW_ON_ERROR);
  }finally{fclose($stream);}
}
function governance_recovery_borrowed_worker_projection($value){
  $out=array('available'=>false,'identity_started'=>false,'result_available'=>false,'child_status'=>'other','child_error_type'=>'other','child_source_file'=>null,'child_source_line'=>null,'child_query_code'=>null,'lock_observation_succeeded'=>false,'parent_user_lock_wait'=>false,'parent_epoch_lock_wait'=>false,'parent_session_lock_wait'=>false);
  if(!is_array($value)){return$out;}
  foreach(array('available','identity_started','result_available','lock_observation_succeeded','parent_user_lock_wait','parent_epoch_lock_wait','parent_session_lock_wait')as$key){if(is_bool($value[$key]??null)){$out[$key]=$value[$key];}}
  if(in_array($value['child_status']??null,array('failed','rejected','committed'),true)){$out['child_status']=$value['child_status'];}
  $child=governance_recovery_borrowed_failure_projection(array('error_type'=>$value['child_error_type']??null,'source_file'=>$value['child_source_file']??null,'source_line'=>$value['child_source_line']??null,'query_code'=>$value['child_query_code']??null));
  foreach(array('error_type','source_file','source_line','query_code')as$key){$out['child_'.$key]=$child[$key];}return$out;
}
function governance_recovery_borrowed_worker_diagnostic(array$handle,$observer,$writer,$namespace){
  $worker=governance_native_session_producer_handle($handle);$out=array('available'=>true);
  $marker=governance_recovery_borrowed_worker_record($worker['private'],'producer-identity-started.json');
  $out['identity_started']=$marker===array('status'=>'identity-started','mysql_thread'=>$worker['mysql_thread']);
  $result=governance_recovery_borrowed_worker_record($worker['private'],'result.json');$out['result_available']=is_array($result);
  if(is_array($result)){foreach(array('status','error_type','source_file','source_line','query_code')as$key){$out['child_'.$key]=$result[$key]??null;}}
  // One bounded observer query, no SQL text or identity values escape projection.
  if(!is_string($namespace)||preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)!==1){throw new RuntimeException('borrowed_diagnostic_scope_unknown');}
  $parent=queryfx_one($writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];
  try{
    $rows=queryfx_all($observer,'SELECT l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE=w.ENGINE AND l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE w.ENGINE=%s AND r.PROCESSLIST_ID=%s AND b.PROCESSLIST_ID=%s LIMIT 9','INNODB',$worker['mysql_thread'],$parent);
    if(count($rows)>8){throw new RuntimeException('borrowed_diagnostic_budget');}foreach($rows as$row){if(!is_array($row)||array_keys($row)!==array('schema_name','table_name')||!is_string($row['schema_name'])||!is_string($row['table_name'])||strlen($row['schema_name'])>128||strlen($row['table_name'])>64){throw new RuntimeException('borrowed_diagnostic_row_unknown');}}$out['lock_observation_succeeded']=true;
    foreach(array('user'=>'user','epoch'=>'organization_identityepoch','session'=>'phabricator_session')as$kind=>$table){$out['parent_'.$kind.'_lock_wait']=false;foreach($rows as$row){if(($row['schema_name']??null)===$namespace.'_user'&&($row['table_name']??null)===$table){$out['parent_'.$kind.'_lock_wait']=true;}}}
  }catch(Throwable$observation){$out['lock_observation_succeeded']=false;}
  return governance_recovery_borrowed_worker_projection($out);
}
