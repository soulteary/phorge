<?php
/** Actual reviewed quiesce function/owned files; service stop is a collaborator. */
require dirname(__DIR__).'/calendar-wire-quiesce.php';
function governance_completion_stop_gorge($queue){$GLOBALS['stopped']=$queue;return$GLOBALS['stop_result'];}
$directory=sys_get_temp_dir().'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));mkdir($directory,0700);$directory=realpath($directory);
$owner=str_repeat('a',64);$digest=str_repeat('b',64);$revision=str_repeat('c',40);$process=tmpfile();
$fixture=new class {function assertOwned(){}};
foreach(array('owner','history-queue-quiesce')as$name){file_put_contents($directory.'/'.$name,$owner);chmod($directory.'/'.$name,0600);}
$old_revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');$old_digest=getenv('PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256');
putenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.$revision);putenv('PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256='.$digest);
$base=array('kind'=>'taskqueue','binary_sha256'=>$digest,'process'=>$process);$good=array('binary_sha256'=>$digest,'kind'=>'taskqueue','private_storage_removed'=>true,'process_stopped'=>true);
$tests=0;$ok=static function($v)use(&$tests){if($v!==true)throw new RuntimeException('quiesce_host_failed');$tests++;};
$deny=static function(callable $fn)use($ok){try{$fn();}catch(Throwable$error){$ok(true);return;}throw new RuntimeException('quiesce_host_missing_denial');};
try{
 $queue=$base;$cleanup=[];$out=[];$GLOBALS['stop_result']=$good;
 $write=static function($name,$value)use(&$out){$out=[$name,$value];};
 $ok(governance_calendar_wire_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$write)===true);
 $ok($queue===null&&$cleanup===['queue'=>$good]&&$GLOBALS['stopped']===$base);
 $ok($out===['history-queue-quiesced.json',['scope'=>'owned-calendar-wire-queue-quiescence','owner'=>$owner,'source_revision'=>$revision,'queue_cleanup'=>$good,'independent_SQL_zero_proved'=>false]]);
 $queue=$base;$cleanup=[];governance_notice_history_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$write);
 $ok($out[1]['scope']==='owned-notice-history-queue-quiescence');
 foreach([array('process_stopped'=>true),array_replace($good,['process_stopped'=>false]),array_replace($good,['private_storage_removed'=>1])]as$bad){
  $queue=$base;$cleanup=[];$GLOBALS['stop_result']=$bad;$out=[];
  $deny(static function()use($fixture,$directory,$owner,&$queue,&$cleanup,$write){governance_calendar_wire_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$write);});
  $ok($out===[]&&$queue===$base);
 }
 $GLOBALS['stop_result']=$good;$queue=$base;$cleanup=[];file_put_contents($directory.'/history-queue-quiesce',str_repeat('d',64));
 $deny(static function()use($fixture,$directory,$owner,&$queue,&$cleanup,$write){governance_calendar_wire_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$write);});
 echo json_encode(['passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_owned_request_FS'=>true,'actual_service_stop'=>false],JSON_THROW_ON_ERROR)."\n";
}finally{
 putenv($old_revision===false?'PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION':'PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.$old_revision);
 putenv($old_digest===false?'PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256':'PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256='.$old_digest);
 fclose($process);foreach(scandir($directory)as$n){if($n!=='.'&&$n!=='..'){unlink($directory.'/'.$n);}}rmdir($directory);
}
