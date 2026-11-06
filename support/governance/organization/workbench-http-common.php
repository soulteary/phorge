<?php
/** Test-only basic HTTP protocol. No server, SQL, or session is started here. */
function governance_workbench_http_need($value,$reason='workbench_http_unproved') {
  if($value!==true){throw new RuntimeException($reason);}
}
function governance_workbench_http_origin($port) {
  governance_workbench_http_need(is_string($port)&&preg_match('/\A[1-9][0-9]{0,4}\z/D',$port)===1&&(int)$port<=65535);
  return 'http://localhost:'.$port;
}
function governance_workbench_http_file_config($value) {
  governance_workbench_http_need(is_array($value));$keys=array_keys($value);sort($keys);
  governance_workbench_http_need($keys===array('binary_sha256','token','uri'));
  foreach(array('binary_sha256','token')as$key){governance_workbench_http_need(is_string($value[$key])&&preg_match('/\A[a-f0-9]{64}\z/D',$value[$key])===1);}
  governance_workbench_http_need(is_string($value['uri'])&&preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$value['uri'],$port)===1&&(int)$port[1]<=65535);
  return $value;
}
function governance_workbench_http_config($raw,$digest,$source_hash) {
  governance_workbench_http_need(is_string($raw)&&strlen($raw)<=32768&&is_string($digest)&&preg_match('/\A[a-f0-9]{64}\z/D',$digest)===1&&hash_equals($digest,hash('sha256',$raw)));
  $value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);governance_workbench_http_need(is_array($value));$keys=array_keys($value);sort($keys);
  governance_workbench_http_need($keys===array('file_service','fixture_nonce','native26_prerequisite_sha256','origin','owner','quickstart_sha256','source_revision','writer'));
  foreach(array('owner','fixture_nonce','quickstart_sha256','native26_prerequisite_sha256')as$key){governance_workbench_http_need(is_string($value[$key])&&preg_match('/\A[a-f0-9]{64}\z/D',$value[$key])===1);}
  governance_workbench_http_need(hash_equals($source_hash,$value['quickstart_sha256'])&&is_string($value['source_revision'])&&preg_match('/\A[a-f0-9]{40}\z/D',$value['source_revision'])===1);
  governance_workbench_http_need(is_string($value['origin'])&&preg_match('@\Ahttp://localhost:([1-9][0-9]{0,4})\z@D',$value['origin'],$port)===1&&governance_workbench_http_origin($port[1])===$value['origin']);
  governance_workbench_http_file_config($value['file_service']);
  $writer=$value['writer'];governance_workbench_http_need(is_array($writer));$keys=array_keys($writer);sort($keys);
  governance_workbench_http_need($keys===array('host','namespace','password','port','user')&&$writer['host']==='127.0.0.1'&&is_int($writer['port'])&&$writer['port']>0&&$writer['port']<=65535);
  foreach(array('namespace'=>'/\Agovernance_native_[a-f0-9]{16}\z/D','user'=>'/\Agov_c_writer_[a-f0-9]{12}\z/D','password'=>'/\A[a-f0-9]{48}\z/D')as$key=>$pattern){governance_workbench_http_need(is_string($writer[$key])&&preg_match($pattern,$writer[$key])===1);}
  return $value;
}
function governance_workbench_http_route($host,$uri,$origin) {
  governance_workbench_http_need(is_string($host)&&$host===substr($origin,7)&&is_string($uri)&&strlen($uri)<=4096&&strpos($uri,"\0")===false);
  $path=parse_url($uri,PHP_URL_PATH);governance_workbench_http_need(is_string($path));$decoded=rawurldecode($path);governance_workbench_http_need(strpos($decoded,"\0")===false&&strpos($decoded,'\\')===false&&strpos($decoded,'%')===false&&!array_intersect(explode('/',$decoded),array('.','..')));governance_workbench_http_need(is_string($path)&&preg_match('@\A/(?:file/(?:data|xform)/[^\x{00}\x{5c}]*|auth/[^\x{00}\x{5c}]*|logout/[^\x{00}\x{5c}]*|people/organization/[^\x{00}\x{5c}]*|typeahead/[^\x{00}\x{5c}]*|res/[^\x{00}\x{5c}]*|favicon\.ico|)\z@D',$path)===1);
  return $path;
}
function governance_workbench_http_directory($directory) {
  governance_workbench_http_need(is_string($directory)&&realpath($directory)===$directory&&realpath(dirname($directory))===realpath(sys_get_temp_dir())&&preg_match('/\Aphorge-workbench-http-[a-f0-9]{24}\z/D',basename($directory))===1);
  clearstatcache(true,$directory);$s=lstat($directory);governance_workbench_http_need(is_array($s)&&($s['mode']&0170000)===0040000&&($s['mode']&0777)===0700&&$s['uid']===posix_geteuid());return $s;
}
function governance_workbench_http_journal($directory,$owner) {
  $dir=governance_workbench_http_directory($directory);governance_workbench_http_need(is_string($owner)&&preg_match('/\A[a-f0-9]{64}\z/D',$owner)===1);
  $path=$directory.'/journal.private.jsonl';clearstatcache(true,$path);$a=lstat($path);governance_workbench_http_need(is_array($a)&&($a['mode']&0170000)===0100000&&($a['mode']&0777)===0600&&$a['uid']===posix_geteuid()&&$a['nlink']===1);
  // Existing private file only. RDWR avoids FIFO read-open blocking on the supported Linux fixture.
  // No write occurs before FD+entry+owner header checks; unknown identities are never created or replaced.
  $fp=fopen($path,'r+b');governance_workbench_http_need(is_resource($fp));
  try {
    $s=fstat($fp);clearstatcache(true,$path);$z=lstat($path);foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_workbench_http_need($a[$key]===$s[$key]&&$s[$key]===$z[$key]);}
    governance_workbench_http_need(fread($fp,256)==='WB_HTTP_JOURNAL_V1 '.$owner."\n"&&$s['size']===strlen('WB_HTTP_JOURNAL_V1 '.$owner."\n"));
    return array('stream'=>$fp,'directory'=>$directory,'directory_dev'=>$dir['dev'],'directory_ino'=>$dir['ino'],'dev'=>$s['dev'],'ino'=>$s['ino'],'stamp'=>$s,'content'=>'WB_HTTP_JOURNAL_V1 '.$owner."\n");
  }catch(Throwable$error){fclose($fp);throw$error;}
}
function governance_workbench_http_snapshot(array $journal) {
  $dir=governance_workbench_http_directory($journal['directory']);$fp=$journal['stream'];$path=$journal['directory'].'/journal.private.jsonl';
  $before=fstat($fp);clearstatcache(true,$path);$entry=lstat($path);
  governance_workbench_http_need($dir['dev']===$journal['directory_dev']&&$dir['ino']===$journal['directory_ino']&&is_array($entry));
  foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_workbench_http_need($before[$key]===$entry[$key]);}
  governance_workbench_http_need($before['dev']===$journal['dev']&&$before['ino']===$journal['ino']&&($before['mode']&0170000)===0100000&&($before['mode']&0777)===0600&&$before['uid']===posix_geteuid()&&$before['nlink']===1&&$before['size']<=2097152);
  governance_workbench_http_need(fseek($fp,0)===0);$content=stream_get_contents($fp,2097153);$after=fstat($fp);clearstatcache(true,$path);$entry=lstat($path);
  foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_workbench_http_need(is_array($entry)&&$before[$key]===$after[$key]&&$after[$key]===$entry[$key]);}
  governance_workbench_http_need(is_string($content)&&strlen($content)===$after['size']);return array($after,$content);
}
function governance_workbench_http_record(array &$journal,array $value) {
  list($s,$content)=governance_workbench_http_snapshot($journal);
  foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_workbench_http_need($s[$key]===$journal['stamp'][$key]);}
  governance_workbench_http_need(hash_equals($journal['content'],$content));
  $raw=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";governance_workbench_http_need(strlen($raw)<=32768&&strlen($content)+strlen($raw)<=2097152);$expected=$content.$raw;governance_workbench_http_need(fseek($journal['stream'],0,SEEK_END)===0);
  while($raw!==''){$n=fwrite($journal['stream'],$raw);governance_workbench_http_need(is_int($n)&&$n>0);$raw=substr($raw,$n);}governance_workbench_http_need(fflush($journal['stream'])&&fsync($journal['stream']));
  list($after,$actual)=governance_workbench_http_snapshot($journal);governance_workbench_http_need(hash_equals($expected,$actual));$journal['stamp']=$after;$journal['content']=$actual;
}
function governance_workbench_http_command($line,$owner) {
  governance_workbench_http_need(is_string($line)&&strlen($line)<=4096);$command=json_decode($line,true,8,JSON_THROW_ON_ERROR);governance_workbench_http_need(is_array($command));$keys=array_keys($command);sort($keys);governance_workbench_http_need($keys===array('action','owner','phase')&&is_string($command['owner'])&&hash_equals($owner,$command['owner']));
  governance_workbench_http_need(in_array($command['action'],array('observe','release'),true)&&in_array($command['phase'],array('initial','requester-created','reviewer-reviewed','reviewer-approved','requester-executed','consumed-reload','ordinary-denied','servers-stopped'),true));
  governance_workbench_http_need(($command['action']==='release')===($command['phase']==='servers-stopped'));return $command;
}
