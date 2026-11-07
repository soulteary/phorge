<?php
/** Actual private filesystem publication, never SQL or cleanup authority. */
require dirname(__DIR__).'/calendar-wire-cleanup-identity.php';
$tests=0;$ok=static function($v)use(&$tests){if($v!==true){throw new RuntimeException('cleanup_identity_host_failed');}$tests++;};
$deny=static function(callable $fn)use($ok){try{$fn();}catch(Throwable$error){$ok(true);return;}throw new RuntimeException('cleanup_identity_missing_denial');};
$keys=array('PHORGE_GOVERNANCE_ISOLATED_TEST','PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR','PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
$env=array();foreach($keys as$key){$env[$key]=getenv($key);}
$directory=realpath(sys_get_temp_dir()).'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));mkdir($directory,0700);
$owner=str_repeat('a',64);$revision=str_repeat('b',40);$ns='governance_native_'.str_repeat('c',16);
$schemas=array($ns.'_governance',$ns.'_user',$ns.'_calendar',$ns.'_metamta',$ns.'_worker');for($i=0;$i<50;$i++){$schemas[]=$ns.'_app'.chr(97+intdiv($i,26)).chr(97+$i%26);}sort($schemas,SORT_STRING);
$identity=array('namespace'=>$ns,'owner_nonce'=>str_repeat('d',64),'quickstart_sha256'=>str_repeat('e',64),
 'writer_user'=>'gov_c_writer_'.str_repeat('a',12),'setup_user'=>'gov_c_setup_'.str_repeat('b',12),'reader_user'=>'gov_native_ro_'.str_repeat('c',12),'schemas'=>$schemas);
file_put_contents($directory.'/owner',$owner);chmod($directory.'/owner',0600);
foreach(array_combine($keys,array('1',$directory,$owner,$revision))as$key=>$value){putenv($key.'='.$value);}
set_error_handler(static function($code,$message,$file,$line){throw new ErrorException('expected_filesystem_refusal',0,$code,$file,$line);});
try{
 $payload=governance_calendar_wire_cleanup_identity_payload($identity,$owner,$revision);
 $ok(array_keys($payload)===array('scope','owner','source_revision','stage','cleanup_authorized','fixture'));
 $ok($payload['scope']==='owned-calendar-pre-ready-cleanup-identity-v1'&&$payload['cleanup_authorized']===false&&$payload['stage']==='created-before-services');
 $raw=json_encode($payload,JSON_THROW_ON_ERROR);$ok(!preg_match('/password|DSN|dsn|credential|realName|email/',$raw));
 foreach(array('owner_nonce'=>str_repeat('z',64),'namespace'=>'production','writer_user'=>'root','setup_user'=>'gov_c_writer_'.str_repeat('b',12),'reader_user'=>'reader','quickstart_sha256'=>true)as$key=>$bad){
  $changed=$identity;$changed[$key]=$bad;$deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($changed,$owner,$revision));}
 foreach(array('password'=>'secret','unknown'=>true)as$key=>$value){$changed=$identity;$changed[$key]=$value;$deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($changed,$owner,$revision));}
 $changed=$identity;$changed['schemas'][1]=$changed['schemas'][0];$deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($changed,$owner,$revision));
 $changed=$identity;array_pop($changed['schemas']);$deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($changed,$owner,$revision));
 $changed=$identity;$changed['schemas'][0]='foreign_schema';$deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($changed,$owner,$revision));
 $changed=$identity;$changed['schemas']=array_reverse($schemas);$deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($changed,$owner,$revision));
 $changed=$identity;$changed['schemas'][array_search($ns.'_calendar',$changed['schemas'],true)]=$ns.'_replacement';sort($changed['schemas'],SORT_STRING);$deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($changed,$owner,$revision));
 $deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($identity,'wrong',$revision));
 $deny(static fn()=>governance_calendar_wire_cleanup_identity_payload($identity,$owner,'wrong'));
 putenv($keys[0].'=0');$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));putenv($keys[0].'=1');
 file_put_contents($directory.'/owner',str_repeat('f',64));$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));file_put_contents($directory.'/owner',$owner);
 chmod($directory.'/owner',0644);$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));chmod($directory.'/owner',0600);
 link($directory.'/owner',$directory.'/alias');$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));unlink($directory.'/alias');
 rename($directory.'/owner',$directory.'/original-owner');symlink('original-owner',$directory.'/owner');$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));unlink($directory.'/owner');rename($directory.'/original-owner',$directory.'/owner');
 chmod($directory,0755);$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));chmod($directory,0700);
 $link=$directory.'-alias';symlink($directory,$link);putenv($keys[1].'='.$link);$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));unlink($link);putenv($keys[1].'='.$directory);
 $old=umask(0000);try{$actual=governance_calendar_wire_publish_cleanup_identity($identity);$ok(umask()===0000);}finally{umask($old);}
 $path=$directory.'/calendar-cleanup-identity.json';$s=lstat($path);
 $ok($actual===$payload&&json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR)===$payload);
 $ok(($s['mode']&07777)===0600&&$s['nlink']===1&&$s['uid']===posix_geteuid());
 $ok(count(glob($directory.'/.calendar-identity-*'))===0);
 $hash=hash_file('sha256',$path);$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));$ok(hash_file('sha256',$path)===$hash);
 unlink($path);symlink('owner',$path);$deny(static fn()=>governance_calendar_wire_publish_cleanup_identity($identity));$ok(is_link($path)&&file_get_contents($directory.'/owner')===$owner);
 echo json_encode(array('passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_filesystem'=>true,'cleanup_authorized'=>false),JSON_THROW_ON_ERROR)."\n";
}finally{
 restore_error_handler();foreach($env as$key=>$value){putenv($value===false?$key:$key.'='.$value);}
 // Exact directory created above; all children are our own regular files or symlinks.
 foreach(scandir($directory)as$name){if($name!=='.'&&$name!=='..'){unlink($directory.'/'.$name);}}rmdir($directory);
}
