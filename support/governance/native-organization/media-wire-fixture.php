<?php

/** Seed real native password login and hold owned schemas for parent-run TLS/CUA. */
function governance_native_media_wire_hold($fixture,callable $check){
  $fixture->assertOwned();$directory=getenv('PHORGE_GOVERNANCE_HTTP_FIXTURE_DIR');$owner=getenv('PHORGE_GOVERNANCE_HTTP_FIXTURE_OWNER');
  $transport_proof=getenv('PHORGE_GOVERNANCE_HTTP_TRANSPORT_PROOF');
  if(!is_string($transport_proof)||!preg_match('/\A[a-f0-9]{64}\z/',$transport_proof)){throw new RuntimeException('media_wire_transport_proof_refused');}
  $port=getenv('PHORGE_GOVERNANCE_HTTP_BROWSER_PORT');$lease=getenv('PHORGE_GOVERNANCE_HTTP_HOLD_SECONDS');
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||!is_string($directory)||
    is_link($directory)||realpath($directory)!==$directory||realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-media-wire-[a-f0-9]{24}\z/',basename($directory))||(fileperms($directory)&0777)!==0700||
    !is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/',$owner)||!is_file($directory.'/owner')||is_link($directory.'/owner')||
    (fileperms($directory.'/owner')&0777)!==0600||file_get_contents($directory.'/owner')!==$owner||
    !is_string($port)||!preg_match('/\A[1-9][0-9]{0,4}\z/',$port)||(int)$port>65535){
    throw new RuntimeException('media_wire_parent_scope_refused');}
  if(function_exists('posix_geteuid')&&fileowner($directory)!==posix_geteuid()){throw new RuntimeException('media_wire_directory_owner_refused');}
  $seconds=600;if($lease!==false&&$lease!==''){
    if(!preg_match('/\A[1-9][0-9]{2,3}\z/',$lease)||(int)$lease<120||(int)$lease>1800){throw new RuntimeException('media_wire_lease_refused');}$seconds=(int)$lease;}
  $image_uri=getenv('PHORGE_GOVERNANCE_GORGE_IMAGE_URI');$image_token=getenv('PHORGE_GOVERNANCE_GORGE_IMAGE_TOKEN');
  if(!is_string($image_uri)||!preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$image_uri,$match)||
    (int)$match[1]>65535||!is_string($image_token)||!preg_match('/\A[a-f0-9]{64}\z/',$image_token)){
    throw new RuntimeException('media_wire_owned_image_service_required');}
  $write=static function($name,array $data) use($directory){
    if(!in_array($name,array('router.json','issuer.json','fixture-ready.json'),true)){throw new RuntimeException('media_wire_file_scope_refused');}
    $temporary=$directory.'/.'.bin2hex(random_bytes(16)).'.tmp';$stream=fopen($temporary,'x');
    if($stream===false){throw new RuntimeException('media_wire_private_write_failed');}chmod($temporary,0600);
    try{$raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(fwrite($stream,$raw)!==strlen($raw)){throw new RuntimeException('media_wire_private_write_failed');}}
    finally{fclose($stream);}
    if(file_exists($directory.'/'.$name)||!rename($temporary,$directory.'/'.$name)){unlink($temporary);throw new RuntimeException('media_wire_private_write_failed');}
  };
  // Native account/password/provider storage. No HTTP test-login endpoint and
  // no browser session token is fabricated or preinserted for this account.
  $user=(new PhabricatorUser())->setUsername('mediawire'.substr($fixture->getNamespace(),-12))
    ->setRealName('Synthetic media browser user')->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
    ->setIsPrimary(1)->setIsVerified(1)->save();
  $password=bin2hex(random_bytes(24)).'!Synthetic';
  PhabricatorAuthPassword::initializeNewPassword($user,PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
    ->setPassword(new PhutilOpaqueEnvelope($password),$user)->save();
  $engine=(new PhabricatorAuthPasswordEngine())->setViewer($user)->setObject($user)
    ->setPasswordType(PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()));
  $check($engine->isValidPassword(new PhutilOpaqueEnvelope($password)),'wire-fixture-native-password-hash-verified');
  $provider=new PhabricatorPasswordAuthProvider();
  $provider_config=(new PhabricatorAuthProviderConfig())->setProviderClass(get_class($provider))
    ->setProviderType($provider->getProviderType())->setProviderDomain($provider->getProviderDomain())
    ->setIsEnabled(1)->setShouldAllowLogin(1)->setShouldAllowRegistration(0)->save();
  $provider->attachProviderConfig($provider_config);
  $conn=(new PhabricatorUser())->establishConnection('w');
  $row=queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE userPHID=%s',new PhabricatorAuthSession(),$user->getPHID());
  $check((int)$row['n']===0,'wire-fixture-browser-account-has-no-seeded-native-session');
  $text='Synthetic native media browser content '.bin2hex(random_bytes(8));
  $file=PhabricatorFile::newFromFileData($text,array('name'=>'synthetic-private.txt','mime-type'=>'text/plain',
    'authorPHID'=>$user->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  $second=PhabricatorFile::newFromFileData('Synthetic second private content '.bin2hex(random_bytes(8)),array('name'=>'second-private.txt',
    'mime-type'=>'text/plain','authorPHID'=>$user->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  $png=PhabricatorFile::newFromFileData(Filesystem::readFile(dirname(phutil_get_library_root('phabricator')).'/resources/builtin/profile.png'),
    array('name'=>'synthetic-private-image.png','mime-type'=>'image/png','authorPHID'=>$user->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  $user->setProfileImagePHID($png->getPHID())->save();
  $path=static function($object){return (new PhutilURI($object->getCDNURI('data')))->getPath();};
  $script='fetch('.json_encode($path($second),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS).', {credentials:"include"}).then(r=>r.text()).then(t=>{window.name="synthetic-exfil:"+t;});';
  $active=PhabricatorFile::newFromFileData('<!doctype html><script>'.$script.'</script>',array('name'=>'synthetic-active.html','mime-type'=>'text/html',
    'authorPHID'=>$user->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  $svg=PhabricatorFile::newFromFileData('<svg xmlns="http://www.w3.org/2000/svg"><script><![CDATA['.$script.']]></script></svg>',
    array('name'=>'synthetic-active.svg','mime-type'=>'image/svg+xml','authorPHID'=>$user->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  $check($file->getStorageEngine()==='gorge'&&$png->getStorageEngine()==='gorge'&&$active->getStorageEngine()==='gorge',
    'wire-fixture-synthetic-private-and-active-bytes-stored-in-real-gorge');
  $app='https://app.media.localhost:'.$port;$files='https://files.media.localhost:'.$port;
  $fixture_nonce=(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture);
  $key=PhabricatorEnv::getEnvConfig('governance.media-exchange.issuer-key');$inc=PhabricatorEnv::getEnvConfig('governance.media-exchange.incarnation');
  $write('issuer.json',array('governance.media-exchange.issuer-key'=>$key,'governance.media-exchange.incarnation'=>$inc));
  $write('router.json',array('owner'=>$owner,'fixture_nonce'=>$fixture_nonce,'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),
    'transport_proof'=>$transport_proof,'writer'=>$fixture->getWriterConfig(),
    'issuer_key'=>$key,'issuer_incarnation'=>$inc,'app_origin'=>$app,'file_origin'=>$files,
    'gorge_file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'gorge_file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'),
    'gorge_image_uri'=>$image_uri,'gorge_image_token'=>$image_token));
  $write('fixture-ready.json',array('scope'=>'private-synthetic-native-password-browser-fixture','owner'=>$owner,
    'app_origin'=>$app,'files_origin'=>$files,'login_uri'=>$app.$provider->getLoginURI(),'login_username'=>$user->getUsername(),'login_password'=>$password,
    'manual_authorize_uri'=>$app.'/auth/media/authorize/?path='.rawurlencode($path($file)),
    'native_image_page_uri'=>$app.'/F'.$png->getID(),'private_file_uri'=>$files.$path($file),
    'private_second_uri'=>$files.$path($second),'active_html_uri'=>$files.$path($active),'active_svg_uri'=>$files.$path($svg),
    'expected_content_sha256'=>hash('sha256',$text),'private_content_marker'=>$text,
    'browser_session_preseeded'=>false,'otp_tested'=>false));
  $check(true,'wire-fixture-private-parent-manifest-written-no-public-login-bypass');
  $deadline=microtime(true)+$seconds;$released=false;
  while(microtime(true)<$deadline){
    $fixture->assertOwned();$release=$directory.'/release';
    if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&file_get_contents($release)===$owner){$released=true;break;}
    usleep(200000);
  }
  if(!$released){throw new RuntimeException('media_wire_parent_lease_expired');}
  $check(true,'wire-fixture-released-by-private-owner-marker');
  return array('native_password_provider_and_hash_verified'=>true,'browser_session_preseeded'=>false,
    'private_parent_fixture_manifest'=>true,'real_gorge_bytes'=>true,'tls_browser_proved_by_this_preparation'=>false,
    'parent_release_observed'=>true,'production_activation'=>false,'otp_tested'=>false);
}
