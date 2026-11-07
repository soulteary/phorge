<?php
/** Test-only producer hold; no mail preparation, enqueue, callback or SMTP. */
require_once __DIR__.'/calendar-wire-producer.php';
/** Reviewed r+b zero-write pattern: FIFO replacement cannot block on open. */
function governance_calendar_wire_read_role($directory,$role,array $parent) {
  if(!in_array($role,array('owner','release'),true)){throw new RuntimeException('calendar_wire_read_role_refused');}
  $assert_parent=static function()use($directory,$parent){clearstatcache(true,$directory);$now=lstat($directory);
    if(!is_array($now)||($now['mode']&0170000)!==0040000||($now['mode']&07777)!==0700||
      $now['dev']!==$parent['dev']||$now['ino']!==$parent['ino']||$now['uid']!==$parent['uid']||$now['gid']!==$parent['gid']||
      realpath($directory)!==$directory){throw new RuntimeException('calendar_wire_read_parent_changed');}};
  $assert_parent();$path=$directory.'/'.$role;clearstatcache(true,$path);$before=@lstat($path);
  if($before===false&&$role==='release'){$assert_parent();return null;}
  if(!is_array($before)||($before['mode']&0170000)!==0100000||($before['mode']&07777)!==0600||
    $before['uid']!==$parent['uid']||$before['gid']!==$parent['gid']||$before['nlink']!==1||$before['size']!==64){
    throw new RuntimeException('calendar_wire_private_read_refused');}
  $raw=governance_calendar_wire_read_held($directory,$role,$before);$assert_parent();return$raw;
}
/** Narrow seam makes a real regular-to-FIFO replacement deterministic in tests. */
function governance_calendar_wire_read_held($directory,$role,array $before) {
  if(!in_array($role,array('owner','release'),true)){throw new RuntimeException('calendar_wire_read_role_refused');}
  $path=$directory.'/'.$role;
  $stamp=static function(array $v){return array_intersect_key($v,array_flip(array('dev','ino','uid','gid','mode','nlink','size','mtime','ctime')));};
  // Do not use rb: a regular->FIFO replacement between lstat and fopen can hang.
  // r+b opens O_RDWR but no write operation is issued; held type checked first.
  $stream=fopen($path,'r+b');if(!is_resource($stream)){throw new RuntimeException('calendar_wire_private_open_refused');}
  try{$held=fstat($stream);
    if(!is_array($held)||($held['mode']&0170000)!==0100000||$stamp($before)!==$stamp($held)){
      throw new RuntimeException('calendar_wire_private_held_refused');}
    $raw=stream_get_contents($stream,65);clearstatcache(true,$path);$after=lstat($path);
    if(!is_string($raw)||strlen($raw)!==64||$stamp($before)!==$stamp(fstat($stream))||
      !is_array($after)||$stamp($before)!==$stamp($after)||!preg_match('/\A[a-f0-9]{64}\z/D',$raw)){
      throw new RuntimeException('calendar_wire_private_changed');}
    return$raw;
  }finally{fclose($stream);}
}

function governance_calendar_wire_writer($directory,callable $assert_parent) {
  return static function($name,array$data)use($directory,$assert_parent){
    if(!in_array($name,array('router.json','calendar-control.json','calendar-browser.json','calendar-runtime.json','history-queue-quiesced.json','ready.json'),true)){throw new RuntimeException('calendar_wire_role_refused');}
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
      if(!link($path,$destination)){throw new RuntimeException('calendar_wire_role_publish_refused');}
      if(!unlink($path)){throw new RuntimeException('calendar_wire_temporary_retained');}
      $assert_parent();$verify($destination);
      clearstatcache(true,$directory);$last_parent=lstat($directory);
      foreach(array('dev','ino','uid','gid','mode')as$key){if($last_parent[$key]!==$parent[$key]){
        throw new RuntimeException('calendar_wire_publish_parent_changed');}}
    }finally{fclose($stream);}};
}
/** Keep exact owned handles and every cleanup failure; never mask primary. */
function governance_calendar_wire_cleanup(&$scope,array $services,$primary,callable $stop) {
  $cleanup=array();$errors=array();
  try{$scope=null;}catch(Throwable $error){$errors['scope']=$error;}
  foreach($services as$name=>$service){if($service!==null){try{
    $cleanup[$name]=$stop($service);
    if(!is_array($cleanup[$name])||($cleanup[$name]['process_stopped']??null)!==true||
      ($cleanup[$name]['private_storage_removed']??null)!==true){throw new RuntimeException('calendar_wire_cleanup_unproved');}
  }catch(Throwable $error){$errors[$name]=$error;}}}
  if($errors){if($primary!==null){$errors['primary']=$primary;}throw new PhutilAggregateException('calendar_wire_cleanup_failed',$errors);}
  if($primary!==null){throw$primary;}return$cleanup;
}
function governance_calendar_wire_hold($fixture,callable $check) {
  require_once dirname(__DIR__).'/native-organization/workers.php';
  $fixture->assertOwned();$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('calendar_wire_source_revision_required');}
  $directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');
  $owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');
  if(!is_string($directory)||realpath($directory)!==$directory||is_link($directory)||
    realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))||
    (fileperms($directory)&07777)!==0700||!is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)||
    getenv('PHORGE_GOVERNANCE_OWLMAIL_RELEASE')!=='v0.14.0'){
    throw new RuntimeException('calendar_wire_owned_parent_refused');}
  $identity=lstat($directory);
  if(governance_calendar_wire_read_role($directory,'owner',$identity)!==$owner){throw new RuntimeException('calendar_wire_owner_refused');}
  $ports=array();$tokens=array();
  foreach(array('HTTP','MAILER')as$key){$value=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$key.'_PORT');
    if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$value)||(int)$value>65535){
      throw new RuntimeException('calendar_wire_port_refused');}$ports[$key]=(int)$value;}
  if($ports['HTTP']===$ports['MAILER']){throw new RuntimeException('calendar_wire_port_conflict');}
  foreach(array('MAILER_TOKEN','CALLBACK_TOKEN')as$key){$value=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$key);
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value)){throw new RuntimeException('calendar_wire_token_refused');}$tokens[$key]=$value;}
  $lease=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS');
  if(!is_string($lease)||!preg_match('/\A[1-9][0-9]{2,3}\z/D',$lease)||(int)$lease<120||(int)$lease>1800){
    throw new RuntimeException('calendar_wire_lease_refused');}
  $assert_parent=static function()use($directory,$identity,$owner){clearstatcache(true,$directory);$now=lstat($directory);
    if($now['dev']!==$identity['dev']||$now['ino']!==$identity['ino']||$now['uid']!==$identity['uid']||
      $now['mode']!==$identity['mode']||is_link($directory)||governance_calendar_wire_read_role($directory,'owner',$identity)!==$owner){
      throw new RuntimeException('calendar_wire_parent_changed');}};
  $write=governance_calendar_wire_writer($directory,$assert_parent);
  require_once dirname(__DIR__).'/completion/pipeline-gorge.php';require_once __DIR__.'/owlmail-sink.php';
  $queue=null;$sink=null;$scope=null;$primary=null;$cleanup=array();$errors=array();$result=null;
  try{
    if(PhorgePrivateMailPolicy::enabled()||(new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){
      throw new RuntimeException('calendar_wire_inherited_delivery_refused');}
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);$sink=governance_private_mail_start_sink($fixture,$check);
    require_once __DIR__.'/calendar-reminder-cases.php';$scope=governance_calendar_reminder_scope($queue);
    $origin='http://127.0.0.1:'.$ports['HTTP'];$mailer='http://127.0.0.1:'.$ports['MAILER'];
    $scope->overrideEnvConfig('phabricator.timezone','UTC')
      ->overrideEnvConfig('gorge.service-policies',array('file'=>'required','taskqueue'=>'required','mailer'=>'required','conduit'=>'required'))
      ->overrideEnvConfig('gorge.conduit.uri',$origin)->overrideEnvConfig('gorge.conduit.token',$tokens['CALLBACK_TOKEN'])
      ->overrideEnvConfig('cluster.mailers',array(array('key'=>'isolated-calendar','type'=>'gorge','inbound'=>false,
        'options'=>array('uri'=>$mailer,'token'=>$tokens['MAILER_TOKEN']))));
    $users=array();foreach(array('host','recipient','ordinary')as$role){$user=(new PhabricatorUser())
      ->setUsername('calwire'.$role.substr($fixture->getNamespace(),-10))->setRealName('Synthetic Calendar account')
      ->setIsApproved(1)->setIsEmailVerified(1)->save();
      $email=(new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
        ->setIsPrimary(1)->setIsVerified(1)->save();
      $users[$role]=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($user->getPHID()))->needUserSettings(true)->executeOne();
      if(!$users[$role]){throw new RuntimeException('calendar_wire_native_user_required');}}
    require_once __DIR__.'/calendar-wire-browser.php';
    $browser=governance_calendar_wire_prepare_browser($fixture,array('recipient'=>$users['recipient'],'ordinary'=>$users['ordinary']),$check);
    $produced=governance_calendar_wire_produce($fixture,$check,$users['recipient'],$users['host'],PhabricatorTime::getNow(),true);
    $config=array('owner'=>$owner,'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
      'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'writer'=>$fixture->getWriterConfig(),
      'callback_origin'=>$origin,'service_token'=>$tokens['CALLBACK_TOKEN'],'mailer_uri'=>$mailer,'mailer_token'=>$tokens['MAILER_TOKEN'],
      'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    $write('router.json',$config);$write('calendar-runtime.json',array('scope'=>'owned-calendar-wire-runtime','owner'=>$owner,
      'source_revision'=>$revision,'queue_uri'=>$queue['uri'],'queue_token'=>$queue['token']));
    $write('calendar-control.json',array('owner'=>$owner,'recipient_phid'=>$users['recipient']->getPHID(),
      'bindings'=>$produced['bindings'],'invitee_ids'=>array_map(static fn($v)=>$v->getID(),$produced['invitees']),
      'event_ids'=>$produced['event_ids'],'nativepaths'=>$produced['native_paths']));
    $expected_destination=$users['recipient']->loadPrimaryEmail()->getAddress();
    $GLOBALS['governance_workbench_http_cleanup_permitted']=false;LiskDAO::closeAllConnections();$closed=$fixture->closeOwnedWriterConnectionForHTTP();
    $check(($closed['owned_fixture_writer_connection_closed']??null)===true&&($closed['exact_original_thread_absent']??null)===true,
      'calendar-wire-independent-writer-closed-before-browser-ready');
    $browser+=array('owner'=>$owner,'source_revision'=>$revision,'events'=>$produced['browser_events'],'fixture_writer_close_proof'=>$closed);
    $write('calendar-browser.json',$browser);
    $write('ready.json',array('scope'=>'private-owned-calendar-native-producer-hold','owner'=>$owner,
      'source_revision'=>$revision,'browser'=>$browser,'bindings'=>$produced['bindings'],'marks'=>$produced['marks'],'callback_origin'=>$origin,'queue'=>array('uri'=>$queue['uri'],'token'=>$queue['token']),
      'mailer_database'=>$fixture->createPrivateMailerPrincipal(),'relay_database'=>$fixture->createPrivateNoticeRelayPrincipal(),
      'smtp_port'=>$sink['smtp_port'],'capture_uri'=>$sink['uri'],'capture_authorization'=>$sink['authorization'],
      'capture_binary_sha256'=>$sink['binary_sha256'],'capture_version_required'=>'v0.14.0',
      'expected_destination'=>$expected_destination,'native_source_only'=>true,'real_delivery_proved_by_preparation'=>false));
    $deadline=microtime(true)+(int)$lease;$released=false;$queue_quiesced=false;
    require_once __DIR__.'/calendar-wire-quiesce.php';require_once dirname(__DIR__).'/native-organization/media-remote-profile.php';
    do{$fixture->assertOwned();$assert_parent();
      if(!$queue_quiesced&&(file_exists($directory.'/history-queue-quiesce')||is_link($directory.'/history-queue-quiesce'))){
        governance_calendar_wire_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$write);$queue_quiesced=true;
      }
      if(governance_calendar_wire_read_role($directory,'release',$identity)===$owner){
        if($queue_quiesced!==true){throw new RuntimeException('calendar_wire_queue_not_quiesced');}
        governance_media_remote_release_after_SQL_zero($fixture);$released=true;break;}
      usleep(200000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('calendar_wire_lease_expired');}
    $result=array('native_scheduler_body_proved'=>true,'native_global_lock_or_daemon_proved'=>false,
      'physical_SMTP_or_browser_proved'=>false,'all_calendar_producer_paths_complete'=>false,'parent_release_observed'=>true,
      'queue_quiesced_before_parent_release'=>$queue_quiesced,'keeper_writer_SQL_zero_checked'=>true);
  }catch(Throwable $error){$primary=$error;}
  finally{
    $closed_services=governance_calendar_wire_cleanup($scope,array('queue'=>$queue,'capture'=>$sink),$primary,
      static fn($service)=>governance_completion_stop_gorge($service));$cleanup=array_merge($cleanup,$closed_services);
  }
  $result['owned_service_cleanup']=$cleanup;return$result;
}
