<?php
/** Fixed isolated browser fixture helpers. Never used by production routing. */
function governance_notice_history_need($ok){if($ok!==true){throw new RuntimeException('notice_history_fixture_refused');}}
function governance_notice_history_manifest($directory,array $base){
  $path=$directory.'/history-browser.json';clearstatcache(true,$path);$entry=lstat($path);$parent=lstat($directory);
  governance_notice_history_need(is_array($entry)&&is_array($parent)&&($entry['mode']&0170000)===0100000&&
    ($entry['mode']&0777)===0600&&$entry['nlink']===1&&$entry['uid']===posix_geteuid()&&$entry['size']>0&&$entry['size']<=16384);
  // RDWR prevents a regular-to-FIFO replacement from blocking before fstat(). No writes occur.
  $fp=fopen($path,'r+b');governance_notice_history_need(is_resource($fp));
  try{
    $fd=fstat($fp);clearstatcache(true,$path);$now=lstat($path);
    foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_notice_history_need(is_array($now)&&$entry[$key]===$fd[$key]&&$fd[$key]===$now[$key]);}
    $raw=stream_get_contents($fp,$entry['size']+1);$after=fstat($fp);clearstatcache(true,$path);$now=lstat($path);$dir=lstat($directory);
    foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_notice_history_need(is_array($now)&&$fd[$key]===$after[$key]&&$after[$key]===$now[$key]);}
    foreach(array('dev','ino','mode','uid','gid')as$key){governance_notice_history_need(is_array($dir)&&$parent[$key]===$dir[$key]);}
    governance_notice_history_need(is_string($raw)&&strlen($raw)===$entry['size']);
  }finally{fclose($fp);}
  $value=json_decode($raw,true,12,JSON_THROW_ON_ERROR);governance_notice_history_need(is_array($value));
  governance_notice_history_need(($value['scope']??null)==='owned-notice-native-history-browser'&&
    ($value['owner']??null)===$base['owner']&&($value['origin']??null)===$base['callback_origin']&&
    ($value['history_path']??null)==='/people/organization/history/'&&
    ($value['browser_cookie_injected']??null)===false&&($value['producer_native_session_is_separate']??null)===true&&
    ($value['SMTP_proved_by_preparation']??null)===false&&($value['browser_proved_by_preparation']??null)===false);
  return $value;
}
function governance_notice_history_route($host,$uri,$origin,$method){
  governance_notice_history_need(is_string($origin)&&preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$origin,$port)===1&&(int)$port[1]<=65535);
  governance_notice_history_need(is_string($host)&&$host===substr($origin,7)&&is_string($uri)&&strlen($uri)<=4096&&$uri!==''&&$uri[0]==='/'&&substr($uri,0,2)!=='//');
  governance_notice_history_need(in_array($method,array('GET','POST'),true)&&strpos($uri,"\0")===false);
  $path=parse_url($uri,PHP_URL_PATH);governance_notice_history_need(is_string($path));$decoded=rawurldecode($path);
  governance_notice_history_need(strpos($decoded,"\0")===false&&strpos($decoded,'\\')===false&&strpos($decoded,'%')===false&&!array_intersect(explode('/',$decoded),array('.','..')));
  // No organization mutation, arbitrary Conduit method, public object or setup route.
  $history=$path==='/people/organization/history/';$home=$path==='/';
  $auth=preg_match('@\A/(?:auth/(?:start/|validate/|finish/|loggedout/|login/password:[^/]+/)|logout/)\z@D',$path)===1;
  $asset=preg_match('@\A/(?:res/[^\x{00}\x{5c}]*|file/(?:data|xform)/[^\x{00}\x{5c}]*|favicon\.ico)\z@D',$path)===1;
  governance_notice_history_need($home||$history||$auth||$asset);
  governance_notice_history_need($method==='GET'||$auth);
  return $path;
}
function governance_notice_history_prepare_browser($fixture,PhabricatorUser $recipient,callable $check){
  $fixture->assertOwned();
  governance_notice_history_need(PhabricatorEnv::getEnvConfig('governance.notifications.enabled')===true&&
    PhabricatorEnv::getEnvConfig('governance.organization.enabled')===true&&in_array($recipient->getIsAdmin(),array(1,'1',true),true));
  $ordinary=(new PhabricatorUser())->setUsername('noticeordinary'.substr($fixture->getNamespace(),-10))
    ->setRealName('Synthetic ordinary notice viewer')->setIsApproved(1)->setIsAdmin(0)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($ordinary->getPHID())
    ->setAddress($ordinary->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
  // The ordinary account may log in normally, but cannot enter the current-admin mail audience.
  $roles=array();foreach(array('recipient'=>$recipient,'ordinary'=>$ordinary)as$role=>$user){
    $password=bin2hex(random_bytes(24)).'!Synthetic';
    PhabricatorAuthPassword::initializeNewPassword($user,PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
      ->setPassword(new PhutilOpaqueEnvelope($password),$user)->save();
    $engine=(new PhabricatorAuthPasswordEngine())->setViewer($user)->setObject($user)
      ->setPasswordType(PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()));
    $check($engine->isValidPassword(new PhutilOpaqueEnvelope($password)),'notice-history-'.$role.'-native-password');
    $roles[$role]=array('username'=>$user->getUsername(),'password'=>$password,'phid'=>$user->getPHID());
  }
  $provider=new PhabricatorPasswordAuthProvider();
  $configuration=(new PhabricatorAuthProviderConfig())->setProviderClass(get_class($provider))
    ->setProviderType($provider->getProviderType())->setProviderDomain($provider->getProviderDomain())
    ->setIsEnabled(1)->setShouldAllowLogin(1)->setShouldAllowRegistration(0)->save();
  $provider->attachProviderConfig($configuration);
  return array('scope'=>'owned-notice-native-history-browser','roles'=>$roles,'login_path'=>$provider->getLoginURI(),
    'browser_cookie_injected'=>false,'producer_native_session_is_separate'=>true,
    'SMTP_proved_by_preparation'=>false,'browser_proved_by_preparation'=>false);
}

/** History-only owned service shutdown. This never proves independent SQL0. */
function governance_notice_history_queue_quiesce($fixture,$directory,$owner,&$queue,array &$cleanup,callable $write){
  governance_notice_history_need(is_string($owner)&&preg_match('/\A[a-f0-9]{64}\z/D',$owner)===1&&
    is_string($directory)&&realpath($directory)===$directory&&realpath(dirname($directory))===realpath(sys_get_temp_dir())&&
    preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))===1);
  $fixture->assertOwned();$before=lstat($directory);
  governance_notice_history_need(is_array($before)&&($before['mode']&0170000)===0040000&&($before['mode']&07777)===0700&&$before['uid']===posix_geteuid()&&$before['gid']===posix_getegid());
  foreach(array('owner','history-queue-quiesce')as$name){
    $path=$directory.'/'.$name;clearstatcache(true,$path);$entry=lstat($path);
    governance_notice_history_need(is_array($entry)&&($entry['mode']&0170000)===0100000&&($entry['mode']&07777)===0600&&$entry['uid']===posix_geteuid()&&$entry['gid']===posix_getegid()&&$entry['nlink']===1&&$entry['size']===64);
    $stream=fopen($path,'r+b');governance_notice_history_need(is_resource($stream));
    try{
      $fd=fstat($stream);clearstatcache(true,$path);$now=lstat($path);
      foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_notice_history_need(is_array($now)&&$entry[$key]===$fd[$key]&&$fd[$key]===$now[$key]);}
      $bytes=stream_get_contents($stream,65);governance_notice_history_need(is_string($bytes)&&strlen($bytes)===64&&hash_equals($owner,$bytes));
      $after=fstat($stream);clearstatcache(true,$path);$now=lstat($path);
      foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key){governance_notice_history_need(is_array($now)&&$fd[$key]===$after[$key]&&$after[$key]===$now[$key]);}
    }finally{fclose($stream);}
  }
  clearstatcache(true,$directory);$after=lstat($directory);
  foreach(array('dev','ino','mode','uid','gid')as$key){governance_notice_history_need(is_array($after)&&$before[$key]===$after[$key]);}
  $expected=getenv('PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256');$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  governance_notice_history_need(is_string($expected)&&preg_match('/\A[a-f0-9]{64}\z/D',$expected)===1&&is_string($revision)&&preg_match('/\A[a-f0-9]{40}\z/D',$revision)===1&&
    is_array($queue)&&($queue['kind']??null)==='taskqueue'&&($queue['binary_sha256']??null)===$expected&&is_resource($queue['process']??null)&&!isset($cleanup['queue']));
  $proof=governance_completion_stop_gorge($queue);$keys=array_keys($proof);sort($keys,SORT_STRING);
  governance_notice_history_need($keys===array('binary_sha256','kind','private_storage_removed','process_stopped')&&$proof['kind']==='taskqueue'&&$proof['binary_sha256']===$expected&&$proof['process_stopped']===true&&$proof['private_storage_removed']===true);
  $cleanup['queue']=$proof;$queue=null;
  // Publish only after the actual keeper-owned process/storage cleanup returned.
  // Parent must separately re-prove all writer SQL sessions absent after this ACK.
  $write('history-queue-quiesced.json',array('scope'=>'owned-notice-history-queue-quiescence','owner'=>$owner,'source_revision'=>$revision,'queue_cleanup'=>$proof,'independent_SQL_zero_proved'=>false));
  return true;
}
