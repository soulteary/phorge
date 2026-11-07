<?php
/** Actual filesystem publication controls. No fixture, SQL, service or network. */
require dirname(__DIR__).'/calendar-wire-hold.php';
$directory=sys_get_temp_dir().'/calendar-wire-host-'.bin2hex(random_bytes(12));
if(!mkdir($directory,0700)){throw new RuntimeException('host_directory_failed');}
$tests=0;$ok=static function($v)use(&$tests){if($v!==true){throw new RuntimeException('publication_host_failed');}$tests++;};
$deny=static function(callable $operation)use($ok){try{$operation();}catch(Throwable $error){$ok(true);return;}
 throw new RuntimeException('publication_host_missing_denial');};
$initial=lstat($directory);$assert=static function()use($directory,$initial){$now=lstat($directory);
 if($now['dev']!==$initial['dev']||$now['ino']!==$initial['ino']||($now['mode']&0777)!==0700){throw new RuntimeException('parent_changed');}};
$previous=set_error_handler(static function($code,$message,$file,$line){throw new ErrorException('host_expected_filesystem_failure',0,$code,$file,$line);});
try{
 $count=0;$observed=static function()use(&$count,$directory,$assert,$ok){$assert();$count++;
  if($count<=2){$ok(!file_exists($directory.'/ready.json'));}
  else{$raw=file_get_contents($directory.'/ready.json');$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$ok($data===array('value'=>str_repeat('x',100000)));}};
 $writer=governance_calendar_wire_writer($directory,$observed);$writer('ready.json',array('value'=>str_repeat('x',100000)));
 $ready=lstat($directory.'/ready.json');$ok(($ready['mode']&0777)===0600&&$ready['nlink']===1);
 $ok(count(glob($directory.'/.calendar-write-*'))===0);
 $before=hash_file('sha256',$directory.'/ready.json');
 $writer=governance_calendar_wire_writer($directory,$assert);
 $deny(static fn()=>$writer('ready.json',array('value'=>'overwrite')));
 $ok(hash_file('sha256',$directory.'/ready.json')===$before);
 $deny(static fn()=>$writer('../foreign.json',array()));
 $deny(static fn()=>$writer('unknown.json',array()));
 symlink('ready.json',$directory.'/router.json');
 $deny(static fn()=>$writer('router.json',array('value'=>'foreign')));
 $ok(is_link($directory.'/router.json')&&hash_file('sha256',$directory.'/ready.json')===$before);
 $seen=0;$failure=new RuntimeException('owned-primary');
 $broken=governance_calendar_wire_writer($directory,static function()use(&$seen,$failure){if(++$seen===2){throw$failure;}});
 try{$broken('calendar-control.json',array('value'=>'not-published'));throw new RuntimeException('host_expected_primary');}
 catch(Throwable $error){$ok($error===$failure);}
 $ok(!file_exists($directory.'/calendar-control.json'));
 // An encoder failure cannot publish an incomplete role.
 $cycle=array();$cycle['self']=&$cycle;$deny(static fn()=>$writer('calendar-control.json',$cycle));
 $ok(!file_exists($directory.'/calendar-control.json'));
 // Actual same-inode tampering at both assertion boundaries must refuse.
 foreach(array(2,3)as$phase){foreach(array('content','mode','hardlink')as$kind){
  $sub=$directory.'/mutation-'.$phase.'-'.$kind;mkdir($sub,0700);$calls=0;$changed=false;$same_inode=false;
  $tamper=static function()use(&$calls,&$changed,&$same_inode,$phase,$kind,$sub){if(++$calls!==$phase){return;}
   $path=$phase===2?glob($sub.'/.calendar-write-*')[0]:$sub.'/ready.json';$before=lstat($path);
   if($kind==='content'){file_put_contents($path,json_encode(array('value'=>'ghijkl')));}
   elseif($kind==='mode'){chmod($path,0644);}else{link($path,$sub.'/extra');}
   clearstatcache(true,$path);$after=lstat($path);$same_inode=$before['dev']===$after['dev']&&$before['ino']===$after['ino'];$changed=true;
  };
  $modified=governance_calendar_wire_writer($sub,$tamper);
  $deny(static fn()=>$modified('ready.json',array('value'=>'abcdef')));
  $ok($changed&&$same_inode);
  if($phase===2){$ok(!file_exists($sub.'/ready.json'));}
  foreach(scandir($sub)as$name){if($name!=='.'&&$name!=='..'){unlink($sub.'/'.$name);}}rmdir($sub);
 }}
 $sub=$directory.'/umask';mkdir($sub,0700);$old_mask=umask(0000);
 try{$masked=governance_calendar_wire_writer($sub,static function(){});
  $masked('ready.json',array('value'=>'private'));
  $ok(umask()===0000&&(lstat($sub.'/ready.json')['mode']&07777)===0600);
  $cycle=array();$cycle['self']=&$cycle;$deny(static fn()=>$masked('calendar-control.json',$cycle));
  $ok(umask()===0000);
 }finally{umask($old_mask);foreach(scandir($sub)as$name){if($name!=='.'&&$name!=='..'){unlink($sub.'/'.$name);}}rmdir($sub);}
 echo json_encode(array('passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_filesystem'=>true),JSON_THROW_ON_ERROR)."\n";
}finally{
 restore_error_handler();
 // Only files beneath the exact directory created by this process, no recursion.
 foreach(scandir($directory)as$name){if($name!=='.'&&$name!=='..'){unlink($directory.'/'.$name);}}
 rmdir($directory);
}
