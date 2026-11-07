<?php
/** Private early fixture lineage; publication never grants cleanup authority. */
function governance_calendar_wire_cleanup_identity_payload(array $identity,$owner,$revision) {
  $keys=array('namespace','owner_nonce','quickstart_sha256','writer_user','setup_user','reader_user','schemas');
  if(array_keys($identity)!==$keys||!is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)||
    !is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
    throw new RuntimeException('calendar_cleanup_identity_shape_refused');}
  $ns=$identity['namespace'];
  if(!is_string($ns)||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$ns)){
    throw new RuntimeException('calendar_cleanup_identity_namespace_refused');}
  foreach(array('owner_nonce','quickstart_sha256')as$key){if(!is_string($identity[$key])||
    !preg_match('/\A[a-f0-9]{64}\z/D',$identity[$key])){throw new RuntimeException('calendar_cleanup_identity_digest_refused');}}
  foreach(array('writer_user'=>'gov_c_writer_','setup_user'=>'gov_c_setup_','reader_user'=>'gov_native_ro_')as$key=>$prefix){
    if(!is_string($identity[$key])||!preg_match('/\A'.preg_quote($prefix,'/').'[a-f0-9]{12}\z/D',$identity[$key])){
      throw new RuntimeException('calendar_cleanup_identity_principal_refused');}}
  $schemas=$identity['schemas'];
  if(!is_array($schemas)||!array_is_list($schemas)||count($schemas)!==55){throw new RuntimeException('calendar_cleanup_identity_schemas_refused');}
  $seen=array();foreach($schemas as$name){if(!is_string($name)||!preg_match('/\A'.preg_quote($ns,'/').'_[a-z][a-z_]*\z/D',$name)||isset($seen[$name])){
      throw new RuntimeException('calendar_cleanup_identity_schema_refused');}$seen[$name]=true;}
  $sorted=$schemas;sort($sorted,SORT_STRING);
  if($sorted!==$schemas){throw new RuntimeException('calendar_cleanup_identity_schema_order_refused');}
  foreach(array('governance','user','calendar','metamta','worker')as$app){if(!isset($seen[$ns.'_'.$app])){
    throw new RuntimeException('calendar_cleanup_identity_required_schema_refused');}}
  return array('scope'=>'owned-calendar-pre-ready-cleanup-identity-v1','owner'=>$owner,'source_revision'=>$revision,
    'stage'=>'created-before-services','cleanup_authorized'=>false,'fixture'=>$identity);
}
function governance_calendar_wire_publish_cleanup_identity(array $identity) {
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){throw new RuntimeException('calendar_cleanup_identity_isolation_refused');}
  $owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  $payload=governance_calendar_wire_cleanup_identity_payload($identity,$owner,$revision);
  $directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');
  if(!is_string($directory)||realpath($directory)!==$directory||realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))){throw new RuntimeException('calendar_cleanup_identity_directory_refused');}
  clearstatcache(true,$directory);$parent=lstat($directory);
  $stamp=static fn(array $s)=>array_intersect_key($s,array_flip(array('dev','ino','uid','gid','mode','nlink','size','mtime','ctime')));
  $assert=static function()use($directory,$parent,$owner,$stamp){
    clearstatcache(true,$directory);$now=lstat($directory);
    if(!is_array($now)||($now['mode']&0170000)!==0040000||($now['mode']&07777)!==0700||
      realpath($directory)!==$directory||$now['uid']!==posix_geteuid()) {throw new RuntimeException('calendar_cleanup_identity_parent_refused');}
    foreach(array('dev','ino','uid','gid','mode')as$key){if($now[$key]!==$parent[$key]){throw new RuntimeException('calendar_cleanup_identity_parent_changed');}}
    $path=$directory.'/owner';clearstatcache(true,$path);$before=lstat($path);
    if(!is_array($before)||($before['mode']&0170000)!==0100000||($before['mode']&07777)!==0600||$before['nlink']!==1||
      $before['uid']!==$parent['uid']||$before['gid']!==$parent['gid']||$before['size']!==64){throw new RuntimeException('calendar_cleanup_identity_owner_refused');}
    // O_RDWR avoids blocking if an attacker substitutes a FIFO before open; no write is issued.
    $f=fopen($path,'r+b');if(!is_resource($f)){throw new RuntimeException('calendar_cleanup_identity_owner_open_refused');}
    try{if($stamp(fstat($f))!==$stamp($before)){throw new RuntimeException('calendar_cleanup_identity_owner_changed');}
      $raw=stream_get_contents($f,65);clearstatcache(true,$path);$after=lstat($path);
      if($raw!==$owner||$stamp(fstat($f))!==$stamp($before)||!is_array($after)||$stamp($after)!==$stamp($before)){
        throw new RuntimeException('calendar_cleanup_identity_owner_changed');}
    }finally{fclose($f);}
  };
  $assert();$destination=$directory.'/calendar-cleanup-identity.json';$path=$directory.'/.calendar-identity-'.bin2hex(random_bytes(16));
  $mask=umask(0077);try{$f=fopen($path,'x+b');}finally{umask($mask);}
  if(!is_resource($f)){throw new RuntimeException('calendar_cleanup_identity_create_refused');}
  try{$created=fstat($f);$raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    if(($created['mode']&0170000)!==0100000||($created['mode']&07777)!==0600||$created['nlink']!==1||$created['size']!==0||
      $created['uid']!==$parent['uid']||$created['gid']!==$parent['gid']||strlen($raw)>32768||
      fwrite($f,$raw)!==strlen($raw)||!fflush($f)||!fsync($f)){throw new RuntimeException('calendar_cleanup_identity_write_refused');}
    $verify=static function($name)use($f,$created,$raw,$stamp){$held=fstat($f);clearstatcache(true,$name);$entry=lstat($name);
      if(!is_array($held)||!is_array($entry)||$held['dev']!==$created['dev']||$held['ino']!==$created['ino']||
        $held['uid']!==$created['uid']||$held['gid']!==$created['gid']||($held['mode']&0170000)!==0100000||
        ($held['mode']&07777)!==0600||$held['nlink']!==1||$held['size']!==strlen($raw)||$stamp($held)!==$stamp($entry)||fseek($f,0)!==0){
        throw new RuntimeException('calendar_cleanup_identity_file_changed');}
      $bytes=stream_get_contents($f,strlen($raw)+1);clearstatcache(true,$name);$last=lstat($name);
      if($bytes!==$raw||$stamp(fstat($f))!==$stamp($held)||!is_array($last)||$stamp($last)!==$stamp($held)){
        throw new RuntimeException('calendar_cleanup_identity_bytes_changed');}};
    $assert();$verify($path);
    if(!link($path,$destination)){throw new RuntimeException('calendar_cleanup_identity_publish_refused');}
    if(!unlink($path)){throw new RuntimeException('calendar_cleanup_identity_temporary_retained');}
    $assert();$verify($destination);return$payload;
  }finally{fclose($f);}
}
