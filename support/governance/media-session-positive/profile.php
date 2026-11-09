<?php
function media_positive_profile(array $p) {
  $keys=array_keys($p);sort($keys);
  if ($keys!==array('acknowledge_synthetic_DDL','host','password','port','user') ||
      $p['acknowledge_synthetic_DDL']!==true || $p['host']!=='127.0.0.1' ||
      !is_int($p['port']) || $p['port']<1 || $p['port']>65535 ||
      !is_string($p['user']) || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/',$p['user']) ||
      !is_string($p['password']) || strlen($p['password'])>4096) {
    throw new RuntimeException('media_positive_profile_refused');
  }
  return $p;
}
function media_positive_profile_file($path) {
  if (!is_string($path) || realpath($path)!==$path) { throw new RuntimeException('media_positive_profile_refused'); }
  $a=lstat($path);
  if (!$a || ($a['mode']&0170000)!==0100000 || ($a['mode']&07777)!==0600 || $a['nlink']!==1 ||
      $a['uid']!==posix_geteuid() || $a['gid']!==posix_getegid() || $a['size']<1 || $a['size']>8192) {
    throw new RuntimeException('media_positive_profile_refused');
  }
  // r+b avoids a FIFO replacement blocking an open; never writes this FD.
  $h=fopen($path,'r+b');if (!$h) { throw new RuntimeException('media_positive_profile_refused'); }
  try {
    $b=fstat($h);foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$k){if($a[$k]!==$b[$k]){throw new RuntimeException('media_positive_profile_changed');}}
    $raw=stream_get_contents($h,8193);$c=fstat($h);clearstatcache(true,$path);$d=lstat($path);
    foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$k){if($a[$k]!==$c[$k]||$a[$k]!==$d[$k]){throw new RuntimeException('media_positive_profile_changed');}}
    if(strlen($raw)!==$a['size']){throw new RuntimeException('media_positive_profile_changed');}
    $p=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($p)){throw new RuntimeException('media_positive_profile_refused');}
    $p=media_positive_profile($p);$canonical=$p;ksort($canonical,SORT_STRING);
    if($raw!==json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"){throw new RuntimeException('media_positive_profile_canonical_required');}
    return $p;
  } finally { fclose($h); }
}
