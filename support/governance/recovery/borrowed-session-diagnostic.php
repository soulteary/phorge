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
  return$out;
}
