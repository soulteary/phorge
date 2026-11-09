<?php
/** Fixed container helper. No application/authentication or arbitrary command. */
function media_helper_need($v){if(!$v){throw new RuntimeException('container_helper_refused');}}
function media_helper_caps(){
  $d=array();foreach(explode("\n",file_get_contents('/proc/1/status'))as$l){if(preg_match('/^(CapEff|CapPrm|CapInh|CapAmb|CapBnd|NoNewPrivs):\s*(\S+)$/',$l,$m)){$d[$m[1]]=$m[2];}}return $d;
}
function media_helper_inventory($root){
  media_helper_need(is_dir($root)&&!is_link($root));$d=array();$total=0;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
  foreach($it as$f){$p=$f->getPathname();$n=substr($p,strlen($root)+1);$s=lstat($p);media_helper_need($s!==false);
    if(is_link($p)){$d[$n]=array('kind'=>'symlink','target'=>readlink($p));}
    else if(($s['mode']&0170000)===0100000){$total+=$s['size'];media_helper_need($s['size']<=33554432&&$total<=134217728&&$s['nlink']===1);$hash=hash_file('sha256',$p);clearstatcache(true,$p);$z=lstat($p);foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$k){media_helper_need($s[$k]===$z[$k]);}$d[$n]=array('kind'=>'file','sha256'=>$hash,'size'=>$s['size'],'mode'=>$s['mode']&0777);}
    else{media_helper_need(($s['mode']&0170000)===0040000);}
    media_helper_need(count($d)<=12000);
  }ksort($d,SORT_STRING);return $d;
}
function media_helper_inject($directory,$raw){
  media_helper_need(is_dir($directory)&&!is_link($directory)&&is_string($raw)&&strlen($raw)>0&&strlen($raw)<=8192&&is_array(json_decode($raw,true,8,JSON_THROW_ON_ERROR)));
  $p=$directory.'/profile.json';$old=umask(0077);try{$h=fopen($p,'x+b');}finally{umask($old);}media_helper_need(is_resource($h));
  try{$a=fstat($h);media_helper_need(($a['mode']&0177777)===0100600&&$a['nlink']===1&&$a['uid']===posix_geteuid()&&$a['gid']===posix_getegid());
    $off=0;while($off<strlen($raw)){$n=fwrite($h,substr($raw,$off));media_helper_need(is_int($n)&&$n>0);$off+=$n;}
    media_helper_need(fflush($h)&&fsync($h)&&fseek($h,0)===0);media_helper_need(stream_get_contents($h,8193)===$raw);$a=fstat($h);clearstatcache(true,$p);$b=lstat($p);foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$k){media_helper_need($a[$k]===$b[$k]);}media_helper_need(($a['mode']&0177777)===0100600&&$a['nlink']===1&&$a['size']===strlen($raw));
  }finally{fclose($h);}
  $old=umask(0077);try{$m=fopen($directory.'/ready','x+b');}finally{umask($old);}media_helper_need(is_resource($m));try{media_helper_need(fflush($m)&&fsync($m));}finally{fclose($m);}
}
function media_helper_main(array $argv){
 $mode=$argv[1]??null;
 if($mode==='prerequisite'){
  echo json_encode(array('version'=>PHP_VERSION,'mysqli'=>extension_loaded('mysqli'),'mbstring'=>extension_loaded('mbstring'),'jit'=>ini_get('opcache.jit'),'buffer'=>ini_get('opcache.jit_buffer_size'),'cli'=>ini_get('opcache.enable_cli'),'caps'=>media_helper_caps(),'pcntl_exec'=>function_exists('pcntl_exec'),'posix_identity'=>function_exists('posix_geteuid')&&function_exists('posix_getegid'),'fsync'=>function_exists('fsync')),JSON_THROW_ON_ERROR);return;
 }
 if($mode==='seed'||$mode==='worker'){
  media_helper_need(mkdir('/run/owned',0700));
  if($mode==='seed'){sleep(900);return;}
  $end=microtime(true)+600;while(!is_file('/run/owned/ready')){media_helper_need(microtime(true)<$end);usleep(100000);}
  media_helper_need(function_exists('pcntl_exec'));pcntl_exec('/usr/local/bin/php',array('-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0','-d','opcache.enable_cli=0','/source/support/governance/media-session-positive/run.php','--execute','/run/owned/profile.json'));throw new RuntimeException('container_helper_exec_returned');
 }
 if($mode==='inject'){media_helper_inject('/run/owned',stream_get_contents(STDIN,8193));return;}
 if($mode==='readback'){echo json_encode(media_helper_inventory('/source'),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return;}
 if($mode==='namespace'){echo json_encode(array(readlink('/proc/1/ns/net'),readlink('/proc/1/ns/pid')),JSON_THROW_ON_ERROR);return;}
 if($mode==='caps'){echo json_encode(media_helper_caps(),JSON_THROW_ON_ERROR);return;}
 throw new RuntimeException('container_helper_mode');
}
if(!defined('MEDIA_HELPER_LIBRARY_ONLY')){media_helper_main($argv);}
