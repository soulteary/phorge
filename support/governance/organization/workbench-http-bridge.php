<?php
/** Fixed plaintext loopback transport for the isolated basic HTTP test, never N09. */
require_once __DIR__.'/workbench-http-common.php';
$socket=null;
try {
  governance_workbench_http_need(PHP_SAPI==='cli'&&getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')==='1'&&count($argv)===2);
  $directory=$argv[1];governance_workbench_http_directory($directory);$owner=getenv('PHORGE_GOVERNANCE_WORKBENCH_HTTP_OWNER');
  governance_workbench_http_need(is_string($owner)&&preg_match('/\A[a-f0-9]{64}\z/D',$owner)===1);
  $path=$directory.'/owner';clearstatcache(true,$path);$s=lstat($path);governance_workbench_http_need(is_array($s)&&($s['mode']&0170000)===0100000&&($s['mode']&0777)===0600&&$s['uid']===posix_geteuid()&&$s['nlink']===1&&$s['size']===64);
  $fp=fopen($path,'r+b');governance_workbench_http_need(is_resource($fp));try{$a=fstat($fp);governance_workbench_http_need($a['dev']===$s['dev']&&$a['ino']===$s['ino']&&hash_equals($owner,fread($fp,65)));}finally{fclose($fp);}
  governance_workbench_http_need(chdir($directory));$socket=stream_socket_client('tcp://127.0.0.1:18981',$errno,$message,5,STREAM_CLIENT_CONNECT);governance_workbench_http_need(is_resource($socket));
  foreach(array(STDIN,STDOUT,$socket)as$stream){governance_workbench_http_need(stream_set_blocking($stream,false));}
  $to_server='';$to_client='';$input_closed=false;$socket_closed=false;$write_shutdown=false;$bytes=0;$deadline=microtime(true)+120;
  while(!$socket_closed||$to_client!==''){
    governance_workbench_http_need(microtime(true)<$deadline&&$bytes<=33554432&&strlen($to_server)<=1048576&&strlen($to_client)<=1048576);
    $read=array();$write=array();$except=null;if(!$input_closed&&strlen($to_server)<65536){$read[]=STDIN;}if(!$socket_closed&&strlen($to_client)<65536){$read[]=$socket;}if($to_server!==''){$write[]=$socket;}if($to_client!==''){$write[]=STDOUT;}
    if(!$read&&!$write){break;}governance_workbench_http_need(stream_select($read,$write,$except,1)!==false);
    foreach($read as$stream){$part=fread($stream,65536);governance_workbench_http_need(is_string($part));$bytes+=strlen($part);if($stream===STDIN){$to_server.=$part;if(feof(STDIN)){$input_closed=true;}}else{$to_client.=$part;if(feof($socket)){$socket_closed=true;}}}
    foreach($write as$stream){$value=$stream===STDOUT?$to_client:$to_server;$n=fwrite($stream,$value);governance_workbench_http_need(is_int($n)&&$n>=0);if($stream===STDOUT){$to_client=substr($to_client,$n);}else{$to_server=substr($to_server,$n);}}
    if($input_closed&&$to_server===''&&!$socket_closed&&!$write_shutdown){governance_workbench_http_need(stream_socket_shutdown($socket,STREAM_SHUT_WR));$write_shutdown=true;}
  }
}catch(Throwable$error){fwrite(STDERR,"workbench_http_bridge_unproved\n");exit(1);}finally{if(is_resource($socket)){fclose($socket);}}
