<?php
/** Fixed isolated Task browser preparation; no browser or SMTP acceptance. */
require_once __DIR__.'/task-wire-protocol.php';
function governance_task_browser_need($ok){if($ok!==true){throw new RuntimeException('task_browser_fixture_refused');}}
function governance_task_browser_no_transactions(){
  governance_task_wire_assert_no_inherited_writes();
  foreach(array(new PhabricatorUser(),new PhabricatorUserEmail(),new PhabricatorAuthPassword(),new PhabricatorAuthProviderConfig())as$dao){
    governance_task_browser_need(!$dao->establishConnection('w')->isInsideTransaction());
  }
}
function governance_task_browser_prepare($fixture,array $enqueued,PhabricatorUser $recipient,callable $check,$directory,$owner,$origin){
  $fixture->assertOwned();governance_task_wire_parent($directory,$owner);PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  governance_task_browser_need(($enqueued['mode']??null)===GovernanceTaskWireMode::QUEUED&&
    PhabricatorEnv::getEnvConfig('governance.organization.enabled')===false&&PhorgePrivateMailPolicy::enabled()&&
    $recipient->getPHID()===$enqueued['recipient_phid']&&!$recipient->getIsAdmin()&&!$recipient->getIsDisabled()&&
    $enqueued['task']->getOwnerPHID()!==$recipient->getPHID());
  // Once browser preparation begins, an unknown parent may not drop these DBs.
  $history=governance_task_wire_history($fixture,$enqueued,$recipient);
  governance_task_browser_need(is_bool($history['retained']));
  $GLOBALS['governance_workbench_http_cleanup_permitted']=false;
  governance_task_browser_no_transactions();
  $ordinary=(new PhabricatorUser())->setUsername('taskordinary'.substr($fixture->getNamespace(),-10))
    ->setRealName('Synthetic ordinary Task viewer')->setIsApproved(1)->setIsAdmin(0)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($ordinary->getPHID())->setAddress($ordinary->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
  $roles=array();foreach(array('recipient'=>$recipient,'ordinary'=>$ordinary)as$role=>$user){
    $password=bin2hex(random_bytes(24)).'!Synthetic';
    PhabricatorAuthPassword::initializeNewPassword($user,PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
      ->setPassword(new PhutilOpaqueEnvelope($password),$user)->save();
    $engine=(new PhabricatorAuthPasswordEngine())->setViewer($user)->setObject($user)->setPasswordType(PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()));
    $check($engine->isValidPassword(new PhutilOpaqueEnvelope($password)),'task-browser-'.$role.'-native-password');
    $roles[$role]=array('username'=>$user->getUsername(),'password'=>$password,'phid'=>$user->getPHID());
  }
  $provider=new PhabricatorPasswordAuthProvider();$configuration=(new PhabricatorAuthProviderConfig())->setProviderClass(get_class($provider))
    ->setProviderType($provider->getProviderType())->setProviderDomain($provider->getProviderDomain())
    ->setIsEnabled(1)->setShouldAllowLogin(1)->setShouldAllowRegistration(0)->save();$provider->attachProviderConfig($configuration);
  governance_task_browser_no_transactions();LiskDAO::closeAllConnections();
  $closed=$fixture->closeOwnedWriterConnectionForHTTP();
  $check(($closed['owned_fixture_writer_connection_closed']??null)===true&&($closed['exact_original_thread_absent']??null)===true,'task-browser-owned-writer-closed');
  return array('scope'=>'owned-native-task-browser','owner'=>$owner,'source_revision'=>getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION'),
    'notification_key'=>$history['key'],'notification_path'=>$history['retained']?'/notification/individual/?key='.$history['key']:null,
    'history_retained_does_not_prove_VIEW'=>true,'origin'=>$origin,'task_phid'=>$enqueued['task_phid'],'task_path'=>$enqueued['task']->getURI(),'publish_task_id'=>$enqueued['worker_id'],
    'roles'=>$roles,'login_path'=>$provider->getLoginURI(),'fixture_writer_close_proof'=>$closed,
    'browser_cookie_injected'=>false,'SMTP_proved_by_preparation'=>false,'browser_proved_by_preparation'=>false);
}
function governance_task_browser_read($directory,$owner,$role){
  governance_task_browser_need(in_array($role,array('task-browser-native.json','ready.json'),true));
  governance_task_wire_parent($directory,$owner);$actual=lstat($directory);
  $path=$directory.'/'.$role;clearstatcache(true,$path);$before=lstat($path);
  governance_task_browser_need(is_array($before)&&($before['mode']&0170000)===0100000&&($before['mode']&07777)===0600&&
    $before['uid']===$actual['uid']&&$before['gid']===$actual['gid']&&$before['nlink']===1&&$before['size']>0&&$before['size']<=16384);
  $fp=fopen($path,'r+b');governance_task_browser_need(is_resource($fp));
  try{$stamp=static function($s){return array_intersect_key($s,array_flip(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')));};
    clearstatcache(true,$path);$entry=lstat($path);governance_task_browser_need(is_array($entry)&&$stamp($before)===$stamp(fstat($fp))&&$stamp($before)===$stamp($entry));
    $raw=stream_get_contents($fp,$before['size']+1);clearstatcache(true,$path);$entry=lstat($path);
    governance_task_browser_need(is_string($raw)&&strlen($raw)===$before['size']&&is_array($entry)&&$stamp($before)===$stamp(fstat($fp))&&$stamp($before)===$stamp($entry));
  }finally{fclose($fp);}
  governance_task_wire_parent($directory,$owner);return json_decode($raw,true,12,JSON_THROW_ON_ERROR);
}
function governance_task_browser_manifest($directory,array $base){
  $value=governance_task_browser_read($directory,$base['owner'],'task-browser-native.json');
  $ready=governance_task_browser_read($directory,$base['owner'],'ready.json');
  governance_task_browser_need(is_array($ready)&&($ready['owner']??null)===$base['owner']&&
    ($value['task_phid']??null)===($ready['task_phid']??null)&&($value['task_path']??null)===($ready['task_path']??null)&&
    ($value['publish_task_id']??null)===($ready['publish_task_id']??null)&&
    is_string($value['source_revision']??null)&&preg_match('/\A[a-f0-9]{40}\z/D',$value['source_revision'])===1&&
    $value['source_revision']===getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION'));
  governance_task_browser_need(is_array($value)&&($value['scope']??null)==='owned-native-task-browser'&&
    ($value['owner']??null)===$base['owner']&&($value['origin']??null)===$base['callback_origin']&&
    preg_match('/\APHID-TASK-[A-Za-z0-9]{20}\z/D',$value['task_phid']??'')===1&&
    preg_match('@\A/T[1-9][0-9]*\z@D',$value['task_path']??'')===1&&
    (((($value['notification_key']??null)===null)&&(($value['notification_path']??null)===null))||
      (is_string($value['notification_key']??null)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$value['notification_key'])===1&&
      ($value['notification_path']??null)==='/notification/individual/?key='.$value['notification_key']))&&
    ($value['browser_cookie_injected']??null)===false&&($value['browser_proved_by_preparation']??null)===false);
  return$value;
}
function governance_task_browser_route($host,$uri,$origin,$method,array $browser){
  governance_task_browser_need(is_string($origin)&&preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$origin,$port)===1&&(int)$port[1]<=65535&&
    is_string($host)&&$host===substr($origin,7)&&is_string($uri)&&strlen($uri)<=4096&&str_starts_with($uri,'/')&&!str_starts_with($uri,'//')&&
    strpos($uri,"\0")===false&&in_array($method,array('GET','POST'),true));
  $path=parse_url($uri,PHP_URL_PATH);governance_task_browser_need(is_string($path));$decoded=rawurldecode($path);
  governance_task_browser_need(strpos($decoded,"\0")===false&&strpos($decoded,'\\')===false&&strpos($decoded,'%')===false&&
    !array_intersect(explode('/',$decoded),array('.','..')));
  $object='/object/'.$browser['task_phid'].'/';
  $notification=false;
  if($path==='/notification/individual/'){
    $key=$browser['notification_key']??null;
    governance_task_browser_need(is_string($key)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$key)===1&&
      ($browser['notification_path']??null)==='/notification/individual/?key='.$key&&
      $uri===$browser['notification_path']&&$method==='GET');
    $notification=true;
  }
  $auth=in_array($path,array('/auth/start/','/auth/validate/','/auth/finish/','/auth/loggedout/','/logout/',$browser['login_path']),true);
  $asset=preg_match('@\A/(?:res/[^\x{00}\x{5c}]*|favicon\.ico)\z@D',$path)===1;
  governance_task_browser_need($path==='/'||$path===$browser['task_path']||$path===$object||$notification||$auth||$asset);
  governance_task_browser_need($method==='GET'||$auth);return$path;
}
