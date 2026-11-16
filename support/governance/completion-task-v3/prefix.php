<?php
const N05_COMPLETION_RUN_PROFILE_SHA256=null;
if(!is_string(N05_COMPLETION_RUN_PROFILE_SHA256)||preg_match('/\A[a-f0-9]{64}\z/D',N05_COMPLETION_RUN_PROFILE_SHA256)!==1){fwrite(STDERR,"Task_directory_v2_unbound_before_SQL\n");exit(1);}
$task_run_path=getenv('PHORGE_COMPLETION_RUN_PROFILE');
if(!is_string($task_run_path)||realpath($task_run_path)!==$task_run_path||is_link($task_run_path)){fwrite(STDERR,"Task_directory_v2_profile_refused\n");exit(1);}
$task_run_stat=lstat($task_run_path);
if(!$task_run_stat||($task_run_stat['mode']&0177777)!==0100600||$task_run_stat['uid']!==posix_geteuid()||$task_run_stat['nlink']!==1){fwrite(STDERR,"Task_directory_v2_profile_refused\n");exit(1);}
$task_run_raw=file_get_contents($task_run_path);
if(hash('sha256',$task_run_raw)!==N05_COMPLETION_RUN_PROFILE_SHA256){fwrite(STDERR,"Task_directory_v2_profile_refused\n");exit(1);}
$task_run_seed=json_decode($task_run_raw,true,32,JSON_THROW_ON_ERROR);$root=$task_run_seed['source_root'];
$task_helper='support/governance/completion-task-v3/run.php';
if(realpath($root)!==$root||!isset($task_run_seed['source_pins'][$task_helper])||hash_file('sha256',$root.'/'.$task_helper)!==$task_run_seed['source_pins'][$task_helper]){fwrite(STDERR,"Task_directory_v2_source_refused\n");exit(1);}
require_once $root.'/'.$task_helper;
$task_run=PhorgeCompletionRunV3::runProfile($task_run_path,N05_COMPLETION_RUN_PROFILE_SHA256);
$task_self=PhorgeCompletionRunV3::privateFile(__FILE__);
$task_bound_marker="const N05_COMPLETION_RUN_PROFILE_SHA256='".N05_COMPLETION_RUN_PROFILE_SHA256."';";
PhorgeCompletionRunV3::need(substr_count($task_self,$task_bound_marker)===1);
$task_original=str_replace($task_bound_marker,'const N05_COMPLETION_RUN_'.'PROFILE_SHA256=null;',$task_self);
PhorgeCompletionRunV3::need(hash('sha256',$task_original)===$task_run['producer_template_sha256']);
$completion_directory=$root.'/support/governance/completion';
$task_binding=null;$task_before=null;$task_after=null;$completion_primary_exit=null;


// The original producer owns its inner native-output capture and all cleanup.
// This outer buffer captures only its final unmodified JSON receipt.
$task_outer_level=ob_get_level();ob_start();
$completion_failure_status=static function($primary,$last_error) {
  if(is_int($primary)&&$primary>0&&$primary<=255)return $primary;
  if(is_array($last_error)&&in_array($last_error['type']??null,array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR),true))return 255;
  return 1;
};
register_shutdown_function(static function()use(&$task_run,&$task_binding,&$task_before,&$task_after,&$completion_primary_exit,$completion_failure_status,$task_outer_level){
  if(ob_get_level()!==$task_outer_level+1){try{fwrite(STDERR,"Completion_outer_capture_refused\n");}finally{exit($completion_failure_status($completion_primary_exit,error_get_last()));}}
  $raw=ob_get_clean();
  try {
    $native=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    PhorgeCompletionRunV3::need(is_array($native)&&isset($native['passed'])&&is_bool($native['passed']));
    PhorgeCompletionRunV3::put($task_run['output'].'-native-body.private.json',$raw);
    if($native['passed']===true&&$task_binding!==null&&$task_before!==null&&$task_after!==null){$output=PhorgeCompletionSchemaCaptureV3::envelope($task_binding['profile'],$native,$task_before,$task_after);}
    else{$output=array('schema'=>'Completion-Task-failure-v3','passed'=>false,'native_receipt'=>$native);}
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
  }catch(Throwable $error){try{fwrite(STDERR,"Completion_envelope_refused\n");}finally{exit($completion_failure_status($completion_primary_exit,error_get_last()));}}
});
