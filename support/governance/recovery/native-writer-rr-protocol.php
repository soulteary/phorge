<?php
/** Fixed owner-private filesystem protocol; no caller-selected commands. */
function governance_native_rr_directory($path,$dev=null,$ino=null){
  if(!is_string($path)||is_link($path)||realpath(dirname($path))!==realpath(sys_get_temp_dir())||!preg_match('/\Agovernance-native-rr-mdl-[a-f0-9]{24}\z/D',basename($path))){throw new RuntimeException('native_rr_directory_refused');}
  $stat=lstat($path);if(!function_exists('posix_geteuid')||!$stat||$stat['uid']!==posix_geteuid()||($stat['mode']&0777)!==0700||($dev!==null&&($stat['dev']!==$dev||$stat['ino']!==$ino))){throw new RuntimeException('native_rr_directory_refused');}return$stat;
}
function governance_native_rr_read($path,$name,$limit=65536){
  if(!in_array($name,array('owner','payload.json','ready.json','identity.json','finish','result.json'),true)){throw new RuntimeException('native_rr_filename_refused');}
  $file=$path.'/'.$name;$stat=lstat($file);
  if(!function_exists('posix_geteuid')||!$stat||$stat['uid']!==posix_geteuid()||is_link($file)||!is_file($file)||($stat['mode']&0777)!==0600||$stat['nlink']!==1||$stat['size']>$limit){throw new RuntimeException('native_rr_file_refused');}
  $raw=file_get_contents($file);$after=lstat($file);if(!is_string($raw)||strlen($raw)>$limit||!$after||$after['dev']!==$stat['dev']||$after['ino']!==$stat['ino']||$after['size']!==strlen($raw)){throw new RuntimeException('native_rr_file_changed');}return$raw;
}
function governance_native_rr_write($path,$name,$value,$owner,$dev,$ino){
  governance_native_rr_directory($path,$dev,$ino);if(!in_array($name,array('owner','payload.json','ready.json','identity.json','finish','result.json'),true)||!is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)){throw new RuntimeException('native_rr_write_refused');}
  if($name!=='owner'&&!hash_equals($owner,governance_native_rr_read($path,'owner',64))){throw new RuntimeException('native_rr_write_refused');}
  if(file_exists($path.'/'.$name)||is_link($path.'/'.$name)){throw new RuntimeException('native_rr_write_exists');}
  $raw=is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR);if(strlen($raw)>65536){throw new RuntimeException('native_rr_write_budget');}
  $temporary=$path.'/.write-'.bin2hex(random_bytes(8));$stream=fopen($temporary,'x');if(!$stream){throw new RuntimeException('native_rr_write_refused');}
  try{if(!chmod($temporary,0600)||fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||!fsync($stream)){throw new RuntimeException('native_rr_write_unproved');}}finally{fclose($stream);}
  governance_native_rr_directory($path,$dev,$ino);if(!rename($temporary,$path.'/'.$name)){throw new RuntimeException('native_rr_write_unproved');}
}
function governance_native_rr_writer_grants(array$grants,$namespace,$variant){
  if(!is_string($namespace)||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)||!in_array($variant,array('candidate','missing-select','missing-lock'),true)||count($grants)>64){throw new RuntimeException('native_rr_grants_refused');}
  $seen=array();$columns=0;$locking=0;$user=false;$guard=false;
  foreach($grants as$entry){
    if(!is_string($entry)||strlen($entry)>4096||str_contains($entry,'WITH GRANT OPTION')||!preg_match('/\AGRANT (.+) ON (.+) TO /D',$entry,$parts)){throw new RuntimeException('native_rr_grants_refused');}
    $priv=$parts[1];$object=$parts[2];if($priv==='USAGE'&&$object==='*.*'){continue;}
    if($object==='`mysql`.`user`'){
      if(!preg_match('/\ASELECT \(([^)]+)\)\z/D',$priv,$m)){throw new RuntimeException('native_rr_grants_refused');}
      $names=array_map(static function($name){return trim($name," `");},explode(',',$m[1]));sort($names);
      if($names!==array('Host','Process_priv','Select_priv','Trigger_priv','User')||++$columns!==1){throw new RuntimeException('native_rr_grants_refused');}continue;
    }
    if($object==='`mysql`.*'&&$priv==='LOCK TABLES'){if(++$locking!==1){throw new RuntimeException('native_rr_grants_refused');}continue;}
    if(!preg_match('/\A`([^`]+)`\.\*\z/D',$object,$m)){throw new RuntimeException('native_rr_grants_refused');}$db=str_replace('\\_','_',$m[1]);
    if(!preg_match('/\A'.preg_quote($namespace,'/').'_[a-z_]+\z/D',$db)||isset($seen[$db])){throw new RuntimeException('native_rr_grants_refused');}$seen[$db]=true;
    $privs=array_map('trim',explode(',',$priv));sort($privs);$expected=$db===$namespace.'_governance'?array('SELECT'):array('DELETE','INSERT','SELECT','UPDATE');
    if($privs!==$expected){throw new RuntimeException('native_rr_grants_refused');}$user=$user||$db===$namespace.'_user';$guard=$guard||$db===$namespace.'_governance';
  }
  if(!$user||!$guard||$columns!==($variant==='missing-select'?0:1)||$locking!==($variant==='missing-lock'?0:1)){throw new RuntimeException('native_rr_grants_refused');}
  return array('candidate_column_select'=>$columns===1,'candidate_lock_tables'=>$locking===1,'production_grants_provisioned'=>false);
}

/** Existing bounded metadata credential profile, not a read-only claim. */
function governance_native_rr_observer_grants(array$grants){
  if(count($grants)>8){throw new RuntimeException('native_rr_observer_grants_refused');}$seen=0;
  foreach($grants as$grant){if(!is_string($grant)||strlen($grant)>4096||str_contains($grant,'WITH GRANT OPTION')||!preg_match('/\AGRANT (.+) ON \*\.\* TO /D',$grant,$m)){throw new RuntimeException('native_rr_observer_grants_refused');}
    if($m[1]==='USAGE'){continue;}$priv=array_map('trim',explode(',',$m[1]));sort($priv);if($priv!==array('PROCESS','SELECT','TRIGGER')||++$seen!==1){throw new RuntimeException('native_rr_observer_grants_refused');}}
  if($seen!==1){throw new RuntimeException('native_rr_observer_grants_refused');}return true;
}
