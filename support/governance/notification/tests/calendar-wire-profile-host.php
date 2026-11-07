<?php
/** Actual private filesystem/profile/route functions; no DB/auth/browser proof. */
require dirname(__DIR__).'/calendar-wire-hold.php';
require dirname(__DIR__).'/notice-wire-config.php';
require dirname(__DIR__).'/calendar-wire-profile.php';
require dirname(__DIR__).'/notice-history-browser.php';
$directory=sys_get_temp_dir().'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));mkdir($directory,0700);$directory=realpath($directory);
$owner=str_repeat('a',64);file_put_contents($directory.'/owner',$owner);chmod($directory.'/owner',0600);
$source=str_repeat('b',40);$role=static fn($l)=>array('phid'=>'PHID-USER-'.str_repeat($l,20),'username'=>'synthetic'.$l,'password'=>str_repeat($l,48).'!Synthetic');
$profile=array('scope'=>'owned-calendar-native-browser','owner'=>$owner,'source_revision'=>$source,
 'roles'=>array('recipient'=>$role('c'),'ordinary'=>$role('d')),'login_path'=>'/auth/login/password:self/',
 'browser_cookie_injected'=>false,'zero_preseeded_sessions'=>true,
 'events'=>array(array('label'=>'A','event_phid'=>'PHID-CEVT-'.str_repeat('e',20),'path'=>'/E1'),array('label'=>'B','event_phid'=>'PHID-CEVT-'.str_repeat('f',20),'path'=>'/E2')),
 'fixture_writer_close_proof'=>array('owned_fixture_writer_connection_closed'=>true,'same_primary_observer'=>true,'exact_original_thread_absent'=>true,'closed_thread_private'=>'123','all_native_writer_sessions_zero_not_claimed'=>true));
$runtime=array('scope'=>'owned-calendar-wire-runtime','owner'=>$owner,'source_revision'=>$source,'queue_uri'=>'http://127.0.0.1:12346','queue_token'=>str_repeat('a',64));
$base=array('owner'=>$owner,'fixture_nonce'=>str_repeat('b',64),'source_sha256'=>str_repeat('c',64),
 'writer'=>array('host'=>'127.0.0.1','port'=>12345,'namespace'=>'governance_native_'.str_repeat('a',16),'user'=>'gov_c_writer_'.str_repeat('b',12),'password'=>str_repeat('c',48)),
 'callback_origin'=>'http://127.0.0.1:12347','service_token'=>str_repeat('d',64),'mailer_uri'=>'http://127.0.0.1:12348','mailer_token'=>str_repeat('e',64),'file_uri'=>'http://127.0.0.1:12349','file_token'=>str_repeat('f',64));
$save=static function($name,$value)use($directory){$p=$directory.'/'.$name;file_put_contents($p,json_encode($value,JSON_THROW_ON_ERROR));chmod($p,0600);clearstatcache();};
$tests=0;$ok=static function($v)use(&$tests){if($v!==true)throw new RuntimeException('profile_host_failed');$tests++;};
$deny=static function(callable $fn)use($ok){try{$fn();}catch(Throwable$error){$ok(true);return;}throw new RuntimeException('profile_host_missing_denial');};
$prior=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');putenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR='.$directory);
try{
 $save('router.json',$base);$save('calendar-runtime.json',$runtime);$save('calendar-browser.json',$profile);
 $selected=governance_calendar_wire_selected_profile($directory);$ok($selected===$profile);
 $config=governance_notice_wire_config($directory);$ok($config['profile_kind']==='calendar-native-browser-v1'&&$config['calendar']===$profile&&$config['notice']['queue_uri']===$runtime['queue_uri']);
 foreach(array('/E1','/E2','/object/PHID-CEVT-'.str_repeat('e',20).'/','/object/PHID-CEVT-'.str_repeat('f',20).'/','/auth/login/password:self/')as$path){
  $ok(governance_notice_history_route('127.0.0.1:12347',$path,$base['callback_origin'],'GET')===$path);}
 foreach(array('/E3','/people/organization/history/','/object/PHID-CEVT-'.str_repeat('a',20).'/','/api/user.search','//foreign.invalid/E1')as$path){
  $deny(static fn()=>governance_notice_history_route('127.0.0.1:12347',$path,$base['callback_origin'],'GET'));}
 $deny(static fn()=>governance_notice_history_route('127.0.0.1:12347','/E1',$base['callback_origin'],'POST'));
 $bad=$profile;$bad['events'][1]=$bad['events'][0];$save('calendar-browser.json',$bad);$deny(static fn()=>governance_calendar_wire_selected_profile($directory));
 $bad=$profile;$bad['roles']['ordinary']=$bad['roles']['recipient'];$save('calendar-browser.json',$bad);$deny(static fn()=>governance_calendar_wire_selected_profile($directory));
 $bad=$profile;$bad['browser_cookie_injected']=true;$save('calendar-browser.json',$bad);$deny(static fn()=>governance_calendar_wire_selected_profile($directory));
 $bad=$profile;$bad['fixture_writer_close_proof']['exact_original_thread_absent']=false;$save('calendar-browser.json',$bad);$deny(static fn()=>governance_calendar_wire_selected_profile($directory));
 $save('calendar-browser.json',$profile);$bad=$runtime;$bad['source_revision']=str_repeat('a',40);$save('calendar-runtime.json',$bad);$deny(static fn()=>governance_notice_wire_config($directory));
 $save('calendar-runtime.json',$runtime);chmod($directory.'/calendar-browser.json',0644);$deny(static fn()=>governance_calendar_wire_selected_profile($directory));chmod($directory.'/calendar-browser.json',0600);
 unlink($directory.'/calendar-browser.json');posix_mkfifo($directory.'/calendar-browser.json',0600);$start=hrtime(true);$deny(static fn()=>governance_calendar_wire_selected_profile($directory));$ok(hrtime(true)-$start<1000000000);unlink($directory.'/calendar-browser.json');
 // No file/profile: original history routes remain original; query/cookie input
 // cannot select Calendar in the absence of a server-owned private profile.
 $_GET['profile']='calendar';$_COOKIE['profile']='calendar';
 $ok(governance_notice_history_route('127.0.0.1:12347','/people/organization/history/',$base['callback_origin'],'GET')==='/people/organization/history/');
 $deny(static fn()=>governance_notice_history_route('127.0.0.1:12347','/E1',$base['callback_origin'],'GET'));
 echo json_encode(array('passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'actual_private_FS_and_route'=>true),JSON_THROW_ON_ERROR)."\n";
}finally{
 if($prior===false){putenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');}else{putenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR='.$prior);}
 foreach(scandir($directory)as$n){if($n!=='.'&&$n!=='..'){unlink($directory.'/'.$n);}}rmdir($directory);
}
