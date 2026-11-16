<?php
/** Exact private parent protocol, no network routes or arbitrary fixture writes. */
require_once __DIR__.'/calendar-wire-hold.php';
function governance_task_wire_parent($directory,$owner) {
  static $identities=array();
  if(!is_string($directory)||realpath($directory)!==$directory||realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))||
    !is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)){
    throw new RuntimeException('task_wire_parent_scope_refused');}
  clearstatcache(true,$directory);$actual=lstat($directory);
  if(!is_array($actual)||($actual['mode']&0170000)!==0040000||($actual['mode']&07777)!==0700){
    throw new RuntimeException('task_wire_parent_identity_refused');}
  $identity=array_intersect_key($actual,array_flip(array('dev','ino','uid','gid','mode')));
  if(isset($identities[$directory])&&$identities[$directory]!==$identity){throw new RuntimeException('task_wire_parent_replaced');}
  if(governance_calendar_wire_read_role($directory,'owner',$actual)!==$owner){throw new RuntimeException('task_wire_parent_owner_refused');}
  $identities[$directory]=$identity;
}
function governance_task_wire_command($directory,$owner,$sequence,$browser_mode=false) {
  if(!is_bool($browser_mode)){throw new RuntimeException('task_browser_mode_refused');}
  governance_task_wire_parent($directory,$owner);
  if(!is_int($sequence)||$sequence<1||$sequence>64){throw new RuntimeException('task_wire_sequence_refused');}
  $path=$directory.'/task-command-'.$sequence.'.json';clearstatcache(true,$path);$before=@lstat($path);
  if($before===false){return null;}
  $stamp=static function($v){return array_intersect_key($v,array_flip(array('dev','ino','uid','gid','mode','nlink','size','mtime','ctime')));};
  if(($before['mode']&0170000)!==0100000||($before['mode']&07777)!==0600||$before['nlink']!==1||
    $before['uid']!==lstat($directory)['uid']||$before['size']>512){throw new RuntimeException('task_wire_command_file_refused');}
  $fd=fopen($path,'r+b');if(!$fd){throw new RuntimeException('task_wire_command_open_refused');}
  try{if($stamp(fstat($fd))!==$stamp($before)){throw new RuntimeException('task_wire_command_changed');}
    $raw=stream_get_contents($fd,513);clearstatcache(true,$path);$after=lstat($path);
    if(!is_array($after)||$stamp(fstat($fd))!==$stamp($before)||$stamp($after)!==$stamp($before)){
      throw new RuntimeException('task_wire_command_changed');}
  }finally{fclose($fd);}
  governance_task_wire_parent($directory,$owner);
  $data=json_decode($raw,true,4,JSON_THROW_ON_ERROR);
  if(!is_array($data)||array_keys($data)!==array('owner','sequence','action')||$data['owner']!==$owner||
    $data['sequence']!==$sequence||!in_array($data['action'],$browser_mode?array('inspect','revoke','browser-prepare'):array('inspect','revoke'),true)){
    throw new RuntimeException('task_wire_command_binding_refused');}
  return $data['action'];
}
function governance_task_wire_writer($directory,callable $assert_parent) {
  return static function($name,array$data)use($directory,$assert_parent){
    if(!in_array($name,array('router.json','ready.json','queue.json','task-browser-native.json','history-queue-quiesced.json'),true)&&!preg_match('/\Atask-observation-([1-9]|[1-5][0-9]|6[0-4])\.json\z/D',$name)){throw new RuntimeException('calendar_wire_role_refused');}
    $assert_parent();$destination=$directory.'/'.$name;$parent=lstat($directory);
    $path=$directory.'/.calendar-write-'.bin2hex(random_bytes(16));$mask=umask(0077);
    try{$stream=fopen($path,'x+b');}finally{umask($mask);}
    if($stream===false){throw new RuntimeException('calendar_wire_role_exists');}
    $stamp=static function(array $v){return array_intersect_key($v,array_flip(array('dev','ino','uid','gid','mode','nlink','size','mtime','ctime')));};
    try{$created=fstat($stream);
      if(($created['mode']&0170000)!==0100000||($created['mode']&07777)!==0600||$created['nlink']!==1||
        $created['uid']!==$parent['uid']||$created['gid']!==$parent['gid']||$created['size']!==0){throw new RuntimeException('calendar_wire_role_mode');}
      $raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
      if(strlen($raw)>1048576||fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||!fsync($stream)){
        throw new RuntimeException('calendar_wire_role_write');}
      $written=fstat($stream);
      $verify=static function($role_path,$expected_stamp=null)use($stream,$created,$raw,$stamp){
        $before=fstat($stream);clearstatcache(true,$role_path);$entry=lstat($role_path);
        if(($before['mode']&0170000)!==0100000||($before['mode']&07777)!==0600||$before['nlink']!==1||
          $before['uid']!==$created['uid']||$before['gid']!==$created['gid']||$before['dev']!==$created['dev']||
          $before['ino']!==$created['ino']||$before['size']!==strlen($raw)||!is_array($entry)||$stamp($before)!==$stamp($entry)||
          ($expected_stamp!==null&&$stamp($before)!==$expected_stamp)||fseek($stream,0)!==0){
          throw new RuntimeException('calendar_wire_role_changed');}
        $actual=stream_get_contents($stream,strlen($raw)+1);$after=fstat($stream);clearstatcache(true,$role_path);$last=lstat($role_path);
        if($actual!==$raw||$stamp($before)!==$stamp($after)||!is_array($last)||$stamp($after)!==$stamp($last)){
          throw new RuntimeException('calendar_wire_role_bytes_changed');}
      };
      $assert_parent();$verify($path,$stamp($written));
      if(!@link($path,$destination)){throw new RuntimeException('calendar_wire_role_publish_refused');}
      if(!unlink($path)){throw new RuntimeException('calendar_wire_temporary_retained');}
      $assert_parent();$verify($destination);
      clearstatcache(true,$directory);$last_parent=lstat($directory);
      foreach(array('dev','ino','uid','gid','mode')as$key){if($last_parent[$key]!==$parent[$key]){
        throw new RuntimeException('calendar_wire_publish_parent_changed');}}
    }finally{fclose($stream);}};
}
function governance_task_wire_publish($directory,$owner,$name,array$data) {
  $assert_parent=static function()use($directory,$owner){governance_task_wire_parent($directory,$owner);};
  $write=governance_task_wire_writer($directory,$assert_parent);$write($name,$data);
}
