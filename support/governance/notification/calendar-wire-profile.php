<?php
/** Fixed private Calendar profile, selected only by the owned server directory. */
function governance_calendar_wire_private_json($directory,$name) {
  if(!in_array($name,array('calendar-browser.json','calendar-runtime.json'),true)){
    throw new RuntimeException('calendar_wire_json_role_refused');}
  require_once __DIR__.'/calendar-wire-hold.php';$parent=lstat($directory);
  if(!is_array($parent)||realpath($directory)!==$directory||realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))||($parent['mode']&0170000)!==0040000||
    ($parent['mode']&07777)!==0700||$parent['uid']!==posix_geteuid()||$parent['gid']!==posix_getegid()){
    throw new RuntimeException('calendar_wire_json_parent_refused');}
  $owner=governance_calendar_wire_read_role($directory,'owner',$parent);$path=$directory.'/'.$name;clearstatcache(true,$path);$entry=lstat($path);
  if(!is_array($entry)||($entry['mode']&0170000)!==0100000||($entry['mode']&07777)!==0600||$entry['uid']!==$parent['uid']||
    $entry['gid']!==$parent['gid']||$entry['nlink']!==1||$entry['size']<1||$entry['size']>32768){throw new RuntimeException('calendar_wire_json_refused');}
  $stamp=static fn($v)=>array_intersect_key($v,array_flip(array('dev','ino','uid','gid','mode','nlink','size','mtime','ctime')));
  $stream=fopen($path,'r+b');if(!is_resource($stream)){throw new RuntimeException('calendar_wire_json_open_refused');}
  try{$held=fstat($stream);if(($held['mode']&0170000)!==0100000||$stamp($held)!==$stamp($entry)){throw new RuntimeException('calendar_wire_json_held_refused');}
    $raw=stream_get_contents($stream,$entry['size']+1);clearstatcache(true,$path);$last=lstat($path);
    if(!is_string($raw)||strlen($raw)!==$entry['size']||!is_array($last)||$stamp($held)!==$stamp(fstat($stream))||$stamp($held)!==$stamp($last)){
      throw new RuntimeException('calendar_wire_json_changed');}
  }finally{fclose($stream);}
  $now=lstat($directory);foreach(array('dev','ino','uid','gid','mode')as$key){if($now[$key]!==$parent[$key]){throw new RuntimeException('calendar_wire_json_parent_changed');}}
  $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
  if(!is_array($data)||($data['owner']??null)!==$owner){throw new RuntimeException('calendar_wire_json_owner_refused');}return$data;
}
function governance_calendar_wire_selected_profile($directory) {
  if(!is_string($directory)||$directory===''){return null;}
  if(!file_exists($directory.'/calendar-browser.json')&&!is_link($directory.'/calendar-browser.json')){return null;}
  $v=governance_calendar_wire_private_json($directory,'calendar-browser.json');$keys=array_keys($v);sort($keys);
  if($keys!==array('browser_cookie_injected','events','fixture_writer_close_proof','login_path','owner','roles','scope','source_revision','zero_preseeded_sessions')||
    $v['scope']!=='owned-calendar-native-browser'||!is_string($v['source_revision'])||!preg_match('/\A[a-f0-9]{40}\z/D',$v['source_revision'])||
    $v['browser_cookie_injected']!==false||$v['zero_preseeded_sessions']!==true||
    !is_string($v['login_path'])||!preg_match('@\A/auth/login/password:[a-zA-Z0-9._-]+/\z@D',$v['login_path'])||
    !is_array($v['roles'])||array_keys($v['roles'])!==array('recipient','ordinary')||!is_array($v['events'])||count($v['events'])!==2){throw new RuntimeException('calendar_wire_profile_refused');}
  $phids=array();foreach($v['roles']as$role){$keys=array_keys($role);sort($keys);
    if($keys!==array('password','phid','username')||!is_string($role['phid'])||!preg_match('/\APHID-USER-[a-z0-9]{20}\z/D',$role['phid'])||
      isset($phids[$role['phid']])||!is_string($role['username'])||!preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/D',$role['username'])||
      !is_string($role['password'])||!preg_match('/\A[a-f0-9]{48}!Synthetic\z/D',$role['password'])){throw new RuntimeException('calendar_wire_profile_role_refused');}$phids[$role['phid']]=true;}
  $paths=array();$events=array();foreach($v['events']as$i=>$event){$keys=array_keys($event);sort($keys);
    if($keys!==array('event_phid','label','path')||$event['label']!==array('A','B')[$i]||!is_string($event['event_phid'])||
      !preg_match('/\APHID-CEVT-[a-z0-9]{20}\z/D',$event['event_phid'])||isset($events[$event['event_phid']])||!is_string($event['path'])||
      !preg_match('@\A/E[1-9][0-9]*\z@D',$event['path'])||isset($paths[$event['path']])){throw new RuntimeException('calendar_wire_profile_event_refused');}
    $events[$event['event_phid']]=true;$paths[$event['path']]=true;}
  $proof=$v['fixture_writer_close_proof'];$keys=is_array($proof)?array_keys($proof):array();sort($keys);
  if($keys!==array('all_native_writer_sessions_zero_not_claimed','closed_thread_private','exact_original_thread_absent','owned_fixture_writer_connection_closed','same_primary_observer')||
    $proof['all_native_writer_sessions_zero_not_claimed']!==true||$proof['exact_original_thread_absent']!==true||
    $proof['owned_fixture_writer_connection_closed']!==true||$proof['same_primary_observer']!==true||
    !is_string($proof['closed_thread_private'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$proof['closed_thread_private'])){throw new RuntimeException('calendar_wire_profile_writer_refused');}
  return$v;
}
function governance_calendar_wire_config($directory,array $base,array $profile) {
  $runtime=governance_calendar_wire_private_json($directory,'calendar-runtime.json');$keys=array_keys($runtime);sort($keys);
  if($keys!==array('owner','queue_token','queue_uri','scope','source_revision')||$runtime['scope']!=='owned-calendar-wire-runtime'||
    $runtime['owner']!==$base['owner']||$profile['owner']!==$base['owner']||$runtime['source_revision']!==$profile['source_revision']||
    !is_string($runtime['queue_token'])||!preg_match('/\A[a-f0-9]{64}\z/D',$runtime['queue_token'])||
    !is_string($runtime['queue_uri'])||!preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$runtime['queue_uri'],$port)||(int)$port[1]>65535){throw new RuntimeException('calendar_wire_runtime_refused');}
  return array('base'=>$base,'directory'=>$directory,'profile_kind'=>'calendar-native-browser-v1','calendar'=>$profile,
    'notice'=>array('queue_uri'=>$runtime['queue_uri'],'queue_token'=>$runtime['queue_token']));
}
function governance_calendar_wire_install_env(array $config) {
  governance_private_mail_install_env($config['base']);
  foreach(array('phabricator.show-prototypes'=>true,'phabricator.timezone'=>'UTC','governance.notifications.enabled'=>false,
    'governance.organization.enabled'=>false,'governance.media-exchange.enabled'=>false,'governance.media-exchange.http-enabled'=>false,
    'gorge.taskqueue.owner'=>'gorge','gorge.taskqueue.uri'=>$config['notice']['queue_uri'],'gorge.taskqueue.token'=>$config['notice']['queue_token'],
    'gorge.service-policies'=>array('file'=>'required','mailer'=>'required','conduit'=>'required','taskqueue'=>'required'))as$key=>$value){PhabricatorEnv::overrideConfig($key,$value);}
}
