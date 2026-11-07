<?php
/** Actual keeper loop/owned files, explicit service-stop and SQL collaborators. */
require dirname(__DIR__).'/calendar-wire-hold.php';require dirname(__DIR__).'/calendar-wire-quiesce.php';
require dirname(__DIR__,2).'/native-organization/media-remote-profile.php';
function governance_completion_stop_gorge($queue){$GLOBALS['stop_calls']++;return$GLOBALS['stop_result'];}
class QueryControl {
 function prepare($sql){if($sql!=='SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?')throw new RuntimeException('wrong_SQL');$GLOBALS['SQL_calls']++;return new QueryStatement();}
}
class QueryStatement {function bind_param($type,$name){if($type!=='s'||$name!=='owned_writer')throw new RuntimeException('wrong_writer');}function execute(){}function get_result(){return$this;}function fetch_assoc(){return['n'=>$GLOBALS['remaining']];}function close(){$GLOBALS['closed']=true;}}
$fixture=new class {function assertOwned(){}function getWriterConfig(){return['user'=>'owned_writer'];}};
$source=file_get_contents(dirname(__DIR__).'/calendar-wire-hold.php');$start=strpos($source,'    do{$fixture->assertOwned();$assert_parent();');$end=strpos($source,'    $result=array(',$start);
if($start===false||$end===false)throw new RuntimeException('release_loop_source_absent');$block=substr($source,$start,$end-$start);
$tests=0;$ok=static function($v)use(&$tests){if($v!==true)throw new RuntimeException('release_host_failed');$tests++;};
$old_rev=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');$old_sha=getenv('PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256');
putenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.str_repeat('b',40));putenv('PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256='.str_repeat('c',64));
try{
 foreach(array('early-release','allow','nonzero','wrong-owner-request','expiry','noncanonical-zero')as$mode){
  $directory=sys_get_temp_dir().'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));mkdir($directory,0700);$directory=realpath($directory);$identity=lstat($directory);$owner=str_repeat('a',64);
  file_put_contents($directory.'/owner',$owner);chmod($directory.'/owner',0600);
  $request=$mode==='wrong-owner-request'?str_repeat('d',64):$owner;
  if($mode!=='early-release'){file_put_contents($directory.'/history-queue-quiesce',$request);chmod($directory.'/history-queue-quiesce',0600);}
  if($mode!=='expiry'){file_put_contents($directory.'/release',$owner);chmod($directory.'/release',0600);}
  $process=tmpfile();$queue=['kind'=>'taskqueue','binary_sha256'=>str_repeat('c',64),'process'=>$process];$cleanup=[];
  $GLOBALS['stop_result']=['kind'=>'taskqueue','binary_sha256'=>str_repeat('c',64),'process_stopped'=>true,'private_storage_removed'=>true];
  $GLOBALS['control']=new QueryControl();$GLOBALS['remaining']=$mode==='nonzero'?1:($mode==='noncanonical-zero'?'00':0);
  $GLOBALS['stop_calls']=0;$GLOBALS['SQL_calls']=0;$GLOBALS['closed']=false;$GLOBALS['governance_workbench_http_cleanup_permitted']=false;
  $assert_parent=static function()use($directory,$identity){$now=lstat($directory);if($now['dev']!==$identity['dev']||$now['ino']!==$identity['ino'])throw new RuntimeException('parent_changed');};
  $write=governance_calendar_wire_writer($directory,$assert_parent);$deadline=microtime(true)+0.01;$released=false;$queue_quiesced=false;$error=null;
  try{eval($block);}catch(Throwable$failure){$error=$failure->getMessage();}
  $ack=file_exists($directory.'/history-queue-quiesced.json');
  if($mode==='allow'){$ok($error===null&&$released&&$queue_quiesced&&$ack&&$GLOBALS['governance_workbench_http_cleanup_permitted']===true&&$GLOBALS['SQL_calls']===1&&$GLOBALS['closed']);}
  else{$ok($error!==null&&!$released&&$GLOBALS['governance_workbench_http_cleanup_permitted']===false);}
  if($mode==='early-release'||$mode==='wrong-owner-request'){$ok(!$ack&&$GLOBALS['stop_calls']===0&&$GLOBALS['SQL_calls']===0);}
  if($mode==='nonzero'||$mode==='noncanonical-zero'){$ok($ack&&$queue_quiesced&&$GLOBALS['SQL_calls']===1&&$GLOBALS['closed']);}
  if($mode==='expiry'){$ok($ack&&$queue_quiesced&&$GLOBALS['SQL_calls']===0);}
  fclose($process);foreach(scandir($directory)as$n){if($n!=='.'&&$n!=='..'){unlink($directory.'/'.$n);}}rmdir($directory);
 }
 echo json_encode(['passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_keeper_loop'=>true,'service_SQL_collaborators'=>true],JSON_THROW_ON_ERROR)."\n";
}finally{
 putenv($old_rev===false?'PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION':'PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.$old_rev);
 putenv($old_sha===false?'PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256':'PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256='.$old_sha);
}
