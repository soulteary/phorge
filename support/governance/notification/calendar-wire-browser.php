<?php
/** Test-only native password bootstrap; private credentials, no sessions created. */
function governance_calendar_wire_prepare_browser($fixture,array $users,callable $check) {
  $fixture->assertOwned();
  if(array_keys($users)!==array('recipient','ordinary')||!PhorgePrivateMailPolicy::enabled()){
    throw new RuntimeException('calendar_wire_browser_scope_refused');}
  $roles=array();$seen=array();
  foreach($users as$role=>$user){
    if(!($user instanceof PhabricatorUser)||!is_string($user->getPHID())||
      !preg_match('/\APHID-USER-[a-z0-9]{20}\z/D',$user->getPHID())||isset($seen[$user->getPHID()])||
      $user->getIsAdmin()||$user->getIsDisabled()||!$user->getIsApproved()||$user->getIsSystemAgent()){
      throw new RuntimeException('calendar_wire_browser_actor_refused');}
    $seen[$user->getPHID()]=true;
    $password=bin2hex(random_bytes(24)).'!Synthetic';
    PhabricatorAuthPassword::initializeNewPassword($user,PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
      ->setPassword(new PhutilOpaqueEnvelope($password),$user)->save();
    $engine=(new PhabricatorAuthPasswordEngine())->setViewer($user)->setObject($user)
      ->setPasswordType(PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()));
    $check($engine->isValidPassword(new PhutilOpaqueEnvelope($password)),'calendar-wire-'.$role.'-native-password');
    // Same native zero-session observation as the accepted media wire fixture.
    // A valid password is not a session; only later native browser login creates one.
    $row=queryfx_one($user->establishConnection('w'),'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',
      new PhabricatorAuthSession(),$user->getPHID());
    $check(is_array($row)&&in_array($row['n']??null,array(0,'0'),true),'calendar-wire-'.$role.'-zero-native-sessions');
    $roles[$role]=array('username'=>$user->getUsername(),'password'=>$password,'phid'=>$user->getPHID());
  }
  $provider=new PhabricatorPasswordAuthProvider();
  $configuration=(new PhabricatorAuthProviderConfig())->setProviderClass(get_class($provider))
    ->setProviderType($provider->getProviderType())->setProviderDomain($provider->getProviderDomain())
    ->setIsEnabled(1)->setShouldAllowLogin(1)->setShouldAllowRegistration(0)->save();
  $provider->attachProviderConfig($configuration);
  return array('scope'=>'owned-calendar-native-browser','roles'=>$roles,
    'login_path'=>$provider->getLoginURI(),'browser_cookie_injected'=>false,'zero_preseeded_sessions'=>true);
}
