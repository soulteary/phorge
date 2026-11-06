<?php
/** Failure-only fixed metadata; no raw messages, account identities or SQL. */
function governance_recovery_session_lease_failure_projection($phase,$passed,$failure,$child=null){
  if($phase!=='session-producer-lease'||$passed!==false){return null;}
  require_once __DIR__.'/session-lease-focused.php';
  $stages=array_merge(array('preflight','full-native-source-restore','focused-native-session-producer-lease','owned-real-gorge-file-service','unexpected-native-runtime-output','completion-real-gorge-file-ready-loopback'),governance_recovery_session_lease_obligations());
  $project=static function($value){
    $out=array('error_type'=>'other','source_file'=>null,'source_line'=>null,'query_code'=>null);
    if(!is_array($value)){return$out;}
    $types=array('RuntimeException','LogicException','TypeError','Error','mysqli_sql_exception','PhutilAggregateException','AphrontQueryException','AphrontObjectMissingQueryException','InvalidArgumentException','RuntimeWarning');
    $files=array('session-lease-cases.php','session-lease-observer.php','session-lease-focused.php','PhorgeRecoverySessionProducerFence.php','PhorgeGovernanceRecoveryGuard.php','PhabricatorAuthSession.php','PhabricatorAuthSessionEngine.php','PhabricatorUser.php','PhabricatorFile.php','PhorgeGovernanceNativeIdentitySource.php','fixture.php','pipeline-gorge.php','native-worker.php','workers.php','LiskDAO.php','AphrontDatabaseConnection.php','AphrontBaseMySQLDatabaseConnection.php','AphrontMySQLiDatabaseConnection.php');
    if(in_array($value['error_type']??null,$types,true)){$out['error_type']=$value['error_type'];}
    if(in_array($value['source_file']??null,$files,true)){$out['source_file']=$value['source_file'];}
    if(is_int($value['source_line']??null)&&$value['source_line']>0&&$value['source_line']<=1000000){$out['source_line']=$value['source_line'];}
    if(is_int($value['query_code']??null)&&$value['query_code']>=0&&$value['query_code']<=65535){$out['query_code']=$value['query_code'];}
    return$out;
  };
  return array('schema'=>'native-session-lease-failure-v1','passed'=>false,
    'stage'=>is_array($failure)&&in_array($failure['stage']??null,$stages,true)?$failure['stage']:($failure===null?'cleanup-unproved':'other'),
    'failure'=>$project($failure),'child_failure'=>$project($child));
}
