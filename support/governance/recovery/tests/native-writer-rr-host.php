<?php
require_once dirname(__DIR__).'/native-writer-rr-parent.php';
$checks=0;
function rr_check($v){global$checks;if($v!==true){throw new RuntimeException('native_rr_host_failed');}$checks++;}
function rr_reject(callable$f){try{$f();}catch(Throwable$e){rr_check(true);return;}rr_check(false);}
$namespace='governance_native_'.str_repeat('a',16);$to=' TO `gov_c_writer_'.str_repeat('b',12).'`@`%`';
$base=array('GRANT USAGE ON *.*'.$to,'GRANT SELECT ON `'.$namespace.'_governance`.*'.$to,'GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$namespace.'_user`.*'.$to);
$columns='GRANT SELECT (`Host`, `User`, `Select_priv`, `Trigger_priv`, `Process_priv`) ON `mysql`.`user`'.$to;$lock='GRANT LOCK TABLES ON `mysql`.*'.$to;
$result=governance_native_rr_writer_grants(array_merge($base,array($columns,$lock)),$namespace,'candidate');rr_check($result===array('candidate_column_select'=>true,'candidate_lock_tables'=>true,'production_grants_provisioned'=>false));
foreach(array($base,array_merge($base,array($columns)),array_merge($base,array($lock)))as$bad){rr_reject(fn()=>governance_native_rr_writer_grants($bad,$namespace,'candidate'));}
foreach(array('GRANT ALL PRIVILEGES ON *.*'.$to,'GRANT SELECT ON `mysql`.`user`'.$to,$columns.' WITH GRANT OPTION',str_replace('`Host`','`authentication_string`',$columns),'GRANT UPDATE ON `mysql`.`user`'.$to,'GRANT SYSTEM_USER ON *.*'.$to)as$bad){rr_reject(fn()=>governance_native_rr_writer_grants(array_merge($base,array($bad,$lock)),$namespace,'candidate'));}
rr_check(governance_native_rr_writer_grants(array_merge($base,array($lock)),$namespace,'missing-select')['candidate_column_select']===false);
rr_check(governance_native_rr_writer_grants(array_merge($base,array($columns)),$namespace,'missing-lock')['candidate_lock_tables']===false);
rr_reject(fn()=>governance_native_rr_writer_grants(array_merge($base,array($columns,$columns,$lock)),$namespace,'candidate'));
$before=array('10:20'=>array('thread'=>'10','process'=>'20','principal'=>'gov_c_writer_'.str_repeat('b',12)));
rr_check(governance_native_rr_sessions_ended($before,$before)===true);rr_check(governance_native_rr_sessions_ended($before,array())===true);
foreach(array(null,false,true,'unknown')as$value){rr_check(governance_native_rr_sessions_ended($value,array())===false);}
$after=$before;$after['11:21']=array('thread'=>'11','process'=>'21','principal'=>'gov_c_metadata_'.str_repeat('c',16));rr_check(governance_native_rr_sessions_ended($before,$after)===false);
$after=$before;$after['10:20']['principal']='foreign';rr_check(governance_native_rr_sessions_ended($before,$after)===false);
$dir=sys_get_temp_dir().'/governance-native-rr-mdl-'.bin2hex(random_bytes(12));mkdir($dir,0700);$stat=lstat($dir);$owner=bin2hex(random_bytes(32));
try{
 governance_native_rr_write($dir,'owner',$owner,$owner,$stat['dev'],$stat['ino']);governance_native_rr_write($dir,'payload.json',array('scope'=>'synthetic-host-control'),$owner,$stat['dev'],$stat['ino']);rr_check(json_decode(governance_native_rr_read($dir,'payload.json'),true)===array('scope'=>'synthetic-host-control'));
 rr_reject(fn()=>governance_native_rr_write($dir,'payload.json',array(),$owner,$stat['dev'],$stat['ino']));
 rr_reject(fn()=>governance_native_rr_write($dir,'command.sh','bad',$owner,$stat['dev'],$stat['ino']));
 rr_reject(fn()=>governance_native_rr_directory($dir,$stat['dev'],$stat['ino']+1));
 symlink($dir.'/payload.json',$dir.'/ready.json');rr_reject(fn()=>governance_native_rr_read($dir,'ready.json'));unlink($dir.'/ready.json');
 chmod($dir.'/payload.json',0644);rr_reject(fn()=>governance_native_rr_read($dir,'payload.json'));chmod($dir.'/payload.json',0600);
}finally{foreach(scandir($dir)as$name){if($name!=='.'&&$name!=='..'){unlink($dir.'/'.$name);}}rmdir($dir);}
rr_check((new ReflectionClass(GovernanceNativeRRWorker::class))->getConstant('OWNER_BINARY_SHA256')==='b4656c9c5cd11a0ccede60eee41ee3f22cc6c3f9f9f97908b0187cc3547c74b4');
rr_check(governance_native_rr_pending_state(array(),'31')===false);
$pending=array('OBJECT_INSTANCE_BEGIN'=>'90','LOCK_TYPE'=>'SHARED_READ_ONLY','LOCK_DURATION'=>'TRANSACTION','LOCK_STATUS'=>'PENDING','OWNER_THREAD_ID'=>'30','OWNER_EVENT_ID'=>'40','PROCESSLIST_ID'=>'31');
rr_check(governance_native_rr_pending_state(array($pending),'31')===true);
rr_reject(fn()=>governance_native_rr_pending_state(array($pending,$pending),'31'));
$bad=$pending;$bad['LOCK_TYPE']='UNKNOWN';rr_reject(fn()=>governance_native_rr_pending_state(array($bad),'31'));
$bad=$pending;$bad['PROCESSLIST_ID']='32';rr_reject(fn()=>governance_native_rr_pending_state(array($bad),'31'));
$time=0;$attempts=0;$proofs=0;$proof=governance_native_rr_wait_pending(static function()use(&$attempts){return++$attempts===3;},static function()use(&$proofs){$proofs++;return array('synthetic_scheduler_control'=>true);},static function()use(&$time){return$time;},static function($us)use(&$time){$time+=$us*1000;});
rr_check($attempts===3&&$proofs===1&&$proof===array('synthetic_scheduler_control'=>true));
foreach(array('identity-changed','metadata-malformed')as$reason){$error=new RuntimeException($reason);$attempts=0;$caught=null;try{governance_native_rr_wait_pending(static function()use(&$attempts,$error){$attempts++;throw$error;},fn()=>array('unreachable'=>true),fn()=>0,fn($us)=>null);}catch(Throwable$e){$caught=$e;}rr_check($caught===$error&&$attempts===1);}
$time=0;rr_reject(function()use(&$time){governance_native_rr_wait_pending(fn()=>true,static function()use(&$time){$time=4000000000;return array('late'=>true);},static function()use(&$time){return$time;},fn($us)=>null);});
$time=0;$attempts=0;rr_reject(function()use(&$time,&$attempts){governance_native_rr_wait_pending(static function()use(&$time,&$attempts){$attempts++;$time+=300000000;return false;},fn()=>array('unreachable'=>true),static function()use(&$time){return$time;},static function($us)use(&$time){$time+=50000000;});});rr_check($attempts===1);
echo $checks." native RR host controls passed\n";
