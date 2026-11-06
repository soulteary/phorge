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
