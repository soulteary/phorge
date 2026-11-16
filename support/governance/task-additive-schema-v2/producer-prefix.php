<?php
const N05_TASK_DIRECTORY_RUN_PROFILE_SHA256=null;
if(!is_string(N05_TASK_DIRECTORY_RUN_PROFILE_SHA256)||preg_match('/\A[a-f0-9]{64}\z/D',N05_TASK_DIRECTORY_RUN_PROFILE_SHA256)!==1){fwrite(STDERR,"Task_directory_v2_unbound_before_SQL\n");exit(1);}
$task_run_path=getenv('PHORGE_TASK_DIRECTORY_RUN_PROFILE');
if(!is_string($task_run_path)||realpath($task_run_path)!==$task_run_path||is_link($task_run_path)){fwrite(STDERR,"Task_directory_v2_profile_refused\n");exit(1);}
$task_run_stat=lstat($task_run_path);
if(!$task_run_stat||($task_run_stat['mode']&0177777)!==0100600||$task_run_stat['uid']!==posix_geteuid()||$task_run_stat['nlink']!==1){fwrite(STDERR,"Task_directory_v2_profile_refused\n");exit(1);}
$task_run_raw=file_get_contents($task_run_path);
if(hash('sha256',$task_run_raw)!==N05_TASK_DIRECTORY_RUN_PROFILE_SHA256){fwrite(STDERR,"Task_directory_v2_profile_refused\n");exit(1);}
$task_run_seed=json_decode($task_run_raw,true,32,JSON_THROW_ON_ERROR);$root=$task_run_seed['source_root'];
$task_helper='support/governance/task-additive-schema-v2/run.php';
if(realpath($root)!==$root||!isset($task_run_seed['source_pins'][$task_helper])||hash_file('sha256',$root.'/'.$task_helper)!==$task_run_seed['source_pins'][$task_helper]){fwrite(STDERR,"Task_directory_v2_source_refused\n");exit(1);}
require_once $root.'/'.$task_helper;
$task_run=PhorgeTaskDirectoryRunV2::runProfile($task_run_path,N05_TASK_DIRECTORY_RUN_PROFILE_SHA256);
$task_self=PhorgeTaskDirectoryRunV2::privateFile(__FILE__);
$task_bound_marker="const N05_TASK_DIRECTORY_RUN_PROFILE_SHA256='".N05_TASK_DIRECTORY_RUN_PROFILE_SHA256."';";
PhorgeTaskDirectoryRunV2::need(substr_count($task_self,$task_bound_marker)===1);
$task_original=str_replace($task_bound_marker,'const N05_TASK_DIRECTORY_RUN_'.'PROFILE_SHA256=null;',$task_self);
PhorgeTaskDirectoryRunV2::need(hash('sha256',$task_original)===$task_run['producer_template_sha256']);
$native_directory=$root.'/support/governance/native-organization';
$task_binding=null;$task_before=null;$task_after=null;


// The original producer owns its inner native-output capture and all cleanup.
// This outer buffer captures only its final unmodified JSON receipt.
$task_outer_level=ob_get_level();ob_start();
register_shutdown_function(static function()use(&$task_run,&$task_binding,&$task_before,&$task_after,$task_outer_level){
  if(ob_get_level()!==$task_outer_level+1){fwrite(STDERR,"Task_native_outer_capture_refused\n");return;}
  $raw=ob_get_clean();
  try {
    $native=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    PhorgeTaskDirectoryRunV2::need(is_array($native)&&isset($native['passed'])&&is_bool($native['passed']));
    PhorgeTaskDirectoryRunV2::put($task_run['output'].'-native-body.private.json',$raw);
    if($native['passed']===true&&$task_binding!==null&&$task_before!==null&&$task_after!==null){$output=PhorgeTaskAdditiveSchemaCaptureV2::envelope($task_binding['profile'],$native,$task_before,$task_after);}
    else{$output=array('schema'=>'Task-native-failure-v2','passed'=>false,'native_receipt'=>$native);}
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
  }catch(Throwable $error){fwrite(STDERR,"Task_native_envelope_refused\n");}
});
