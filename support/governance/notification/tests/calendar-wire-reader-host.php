<?php
/** Real regular/FIFO files and replacement seam; zero write through reader. */
require dirname(__DIR__).'/calendar-wire-hold.php';
$directory=sys_get_temp_dir().'/calendar-wire-reader-'.bin2hex(random_bytes(12));mkdir($directory,0700);$directory=realpath($directory);$parent=lstat($directory);
$value=str_repeat('a',64);$tests=0;
$ok=static function($v)use(&$tests){if($v!==true){throw new RuntimeException('reader_host_failed');}$tests++;};
$deny=static function(callable $op)use($ok){try{$op();}catch(Throwable$error){$ok(true);return;}throw new RuntimeException('reader_host_missing_denial');};
$save=static function($role,$data)use($directory){file_put_contents($directory.'/'.$role,$data);chmod($directory.'/'.$role,0600);clearstatcache();};
try{
 foreach(array('owner','release')as$role){$path=$directory.'/'.$role;$save($role,$value);$before=hash_file('sha256',$path);
  $ok(governance_calendar_wire_read_role($directory,$role,$parent)===$value&&hash_file('sha256',$path)===$before);
  foreach(array(str_repeat('a',63),str_repeat('a',65),str_repeat('z',64))as$bad){$save($role,$bad);
   $deny(static fn()=>governance_calendar_wire_read_role($directory,$role,$parent));}
  $save($role,$value);chmod($path,0644);$deny(static fn()=>governance_calendar_wire_read_role($directory,$role,$parent));
  chmod($path,01600);$deny(static fn()=>governance_calendar_wire_read_role($directory,$role,$parent));chmod($path,0600);
  link($path,$directory.'/extra');$deny(static fn()=>governance_calendar_wire_read_role($directory,$role,$parent));unlink($directory.'/extra');
  unlink($path);symlink('missing-target',$path);$deny(static fn()=>governance_calendar_wire_read_role($directory,$role,$parent));unlink($path);
  if(!posix_mkfifo($path,0600)){throw new RuntimeException('host_FIFO_create_failed');}
  $start=hrtime(true);$deny(static fn()=>governance_calendar_wire_read_role($directory,$role,$parent));
  $ok(hrtime(true)-$start<1000000000);unlink($path);
  // Exact deterministic race: initial regular lstat, replace by real FIFO,
  // then actual reviewed r+b open/held-fstat stage. No FIFO peer or writes.
  $save($role,$value);$regular=lstat($path);unlink($path);posix_mkfifo($path,0600);
  $start=hrtime(true);$deny(static fn()=>governance_calendar_wire_read_held($directory,$role,$regular));
  $ok(hrtime(true)-$start<1000000000);unlink($path);
  $save($role,$value);$regular=lstat($path);rename($path,$directory.'/old');$save($role,$value);
  $deny(static fn()=>governance_calendar_wire_read_held($directory,$role,$regular));unlink($directory.'/old');unlink($path);
 }
 $ok(governance_calendar_wire_read_role($directory,'release',$parent)===null);
 $deny(static fn()=>governance_calendar_wire_read_role($directory,'unknown',$parent));
 $save('owner',$value);$wrong=$parent;$wrong['ino']++;
 $deny(static fn()=>governance_calendar_wire_read_role($directory,'owner',$wrong));
 echo json_encode(array('passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_regular_FIFO'=>true),JSON_THROW_ON_ERROR)."\n";
}finally{
 foreach(scandir($directory)as$name){if($name!=='.'&&$name!=='..'){unlink($directory.'/'.$name);}}rmdir($directory);
}
