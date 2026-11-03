<?php

/** Real Gorge bytes and native FileQuery; no wire TLS/browser claim. */
function governance_native_media_file_cases($fixture, callable $check, array $state,
  callable $call, callable $cookie, $admin_credential) {
  $fixture->assertOwned();$name=PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE;
  $admin_cookies=array($name=>$admin_credential);$actor=$state['admin']['viewer'];
  $path=static fn($file,$kind='data')=>(new PhutilURI($file->getCDNURI($kind)))->getPath();
  $bytes=static function($response){$body='';foreach($response->getContentIterator() as $chunk){$body.=$chunk;}return $body;};
  $headers=static function($response,$name){$values=array();foreach($response->getHeaders() as $h){if(strcasecmp($h[0],$name)===0){$values[]=$h[1];}}return implode('; ',$values);};
  $text='synthetic private file bytes';
  $private=PhabricatorFile::newFromFileData($text,array('name'=>'private.txt','authorPHID'=>$actor->getPHID(),
    'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE,'mime-type'=>'text/plain'));
  $check($private->getStorageEngine()==='gorge','native-media-file-guard-fixture-uses-real-gorge-storage');
  list($anonymous,$anonymous_io)=governance_native_media_observe_file_io($fixture,static fn()=>$call('files',$path($private),'GET',array()));
  $check($anonymous->getHTTPResponseCode()===403,'native-media-valid-bearer-file-key-alone-never-authorizes-bytes');
  $check(!array_filter($anonymous_io),
    'native-media-anonymous-cookie-absence-refused-before-real-file-metadata-or-storage-events');
  list($allowed,$authorized_io)=governance_native_media_observe_file_io($fixture,static function() use($call,$path,$private,$admin_cookies,$bytes){
    $response=$call('files',$path($private),'GET',array(),$admin_cookies);if($response instanceof AphrontFileResponse){$body=$bytes($response);$response->setContentIterator(null)->setContent($body);}return $response;
  });
  $check($allowed instanceof AphrontFileResponse && $allowed->getHTTPResponseCode()===200&&$bytes($allowed)===$text,
    'native-media-cookie-identity-and-native-author-automatic-file-view');
  $check($authorized_io['file_metadata_events']>0&&$authorized_io['http_events']>0,
    'native-media-real-profiler-observes-authorized-file-query-and-gorge-read-positive-control');
  $foreign=$call('files',str_replace('/file/data/','/file/data/@foreign/',$path($private)),'GET',array(),$admin_cookies);
  $check($foreign->getHTTPResponseCode()===403,'native-media-real-route-foreign-instance-still-refused-with-valid-cookie');
  $check(strpos($headers($allowed,'Cache-Control'),'no-store')!==false&&$headers($allowed,'X-Content-Type-Options')==='nosniff',
    'native-media-private-file-response-disables-shared-cache-and-sniffing');
  $range=$call('files',$path($private),'GET',array(),$admin_cookies,array('HTTP_RANGE'=>'bytes=0-3'));
  $check($range->getHTTPResponseCode()===206&&$bytes($range)===substr($text,0,4),
    'native-media-authorized-real-gorge-range-retains-correct-bytes');
  foreach(array('head'=>'HEAD','conditional'=>'GET','range'=>'GET') as $label=>$method){
    $response=$call('files',$path($private),$method,array(),array(),array('HTTP_RANGE'=>'bytes=0-3',
      'HTTP_IF_NONE_MATCH'=>'*','HTTP_IF_MODIFIED_SINCE'=>'Wed, 01 Jan 2031 00:00:00 GMT'));
    $check($response->getHTTPResponseCode()===403&&$headers($response,'Content-Range')==='',
      'native-media-anonymous-'.$label.'-refused-before-byte-or-cache-metadata');
  }
  $member=governance_native_request($state['users']['member'],$state['sessions']);
  $nonce=bin2hex(random_bytes(32));$target=$path($private);
  $prepared_response=$call('files','prepare','POST',array('payload'=>json_encode(array('path'=>$target,'client_nonce'=>$nonce))));
  $prepared=json_decode((string)$prepared_response->buildResponseString(),true,8,JSON_THROW_ON_ERROR);
  $browser=$cookie($prepared_response,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE);
  $issued_response=$call('app','issue','POST',array('payload'=>json_encode(array('path'=>$target,'client_nonce'=>$nonce,'transaction'=>$prepared['transaction'])),
    AphrontRequest::getCSRFTokenName()=>$member['viewer']->getCSRFToken()),array(PhabricatorCookies::COOKIE_SESSION=>$member['token']));
  $issued=json_decode((string)$issued_response->buildResponseString(),true,8,JSON_THROW_ON_ERROR);
  $redeemed=$call('files','redeem','POST',array('payload'=>json_encode(array('path'=>$target,'client_nonce'=>$nonce,'transaction'=>$prepared['transaction'],'code'=>$issued['code']))),
    array(PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE=>$browser));
  $member_cookies=array($name=>$cookie($redeemed,$name));
  $denied=$call('files',$path($private),'GET',array(),$member_cookies);
  $check($denied->getHTTPResponseCode()===403,'native-media-valid-other-user-cookie-does-not-grant-file-permission');
  // Actual native ANY-visible-attached-object semantics, not a new ALL model.
  $visible=ManiphestTask::initializeNewTask($actor)->setTitle('Synthetic visible file attachment')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
  $hidden=ManiphestTask::initializeNewTask($actor)->setTitle('Synthetic hidden file attachment')
    ->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->setEditPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
  $private->attachToObject($visible->getPHID())->attachToObject($hidden->getPHID());
  $any=$call('files',$path($private),'GET',array(),$member_cookies);
  $check($any instanceof AphrontFileResponse&&$bytes($any)===$text,
    'native-media-native-any-visible-attachment-file-capability-is-preserved');
  $visible->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
  $revoked=$call('files',$path($private),'GET',array(),$member_cookies,array('HTTP_IF_NONE_MATCH'=>'*'));
  $check($revoked->getHTTPResponseCode()===403,'native-media-new-request-rechecks-object-policy-before-conditional-return');
  $owner=$call('files',$path($private),'GET',array(),$admin_cookies);
  $check($owner instanceof AphrontFileResponse&&$bytes($owner)===$text,'native-media-file-author-automatic-capability-remains-native-after-object-revocation');
  // Stock profile objects are used by real People and external-account producers.
  // Even identical repository pixels do not establish an anonymous file purpose.
  $stock=PhabricatorFile::loadBuiltin($actor,'profile.png');
  $state['users']['member']->setProfileImagePHID($stock->getPHID())->save();
  $check($stock->getBuiltinKey()===$stock->getBuiltinName()&&$stock->getAuthorPHID()===null,
    'native-media-real-shared-stock-avatar-object-has-no-unique-public-purpose');
  foreach(array('data','download') as $kind){
    $response=$call('files',$path($stock,$kind),'GET',array());
    $check($response->getHTTPResponseCode()===403,'native-media-stock-avatar-'.$kind.'-still-requires-login');
  }
  $profile=PhabricatorFile::newFromFileData($stock->loadFileData(),array('name'=>'profile.png','authorPHID'=>$actor->getPHID(),
    'profile'=>true,'mime-type'=>'image/png','viewPolicy'=>PhabricatorPolicies::POLICY_PUBLIC));
  foreach(array('GET','HEAD') as $method){
    $response=$call('files',$path($profile),$method,array(),array(),array('HTTP_IF_NONE_MATCH'=>'*','HTTP_RANGE'=>'bytes=0-3'));
    $check($response->getHTTPResponseCode()===403,'native-media-uploaded-avatar-'.strtolower($method).'-no-anonymous-conditional-or-range-bypass');
  }
  // Cached transforms must authorize the original before cache lookup/redirect.
  $derived=PhabricatorFile::newFromFileData($stock->loadFileData(),array('name'=>'preview.png','mime-type'=>'image/png',
    'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  (new PhabricatorTransformedFile())->setOriginalPHID($profile->getPHID())->setTransform('preview')->setTransformedPHID($derived->getPHID())->save();
  $transform_path='/file/xform/preview/'.$profile->getPHID().'/'.$profile->getSecretKey().'/';
  $response=$call('files',$transform_path,'GET',array());
  $check($response->getHTTPResponseCode()===403,'native-media-anonymous-avatar-transform-cache-does-not-leak-redirect');
  $cached=$call('files',$transform_path,'GET',array(),$admin_cookies);
  $check($cached instanceof AphrontRedirectResponse&&strpos($headers($cached,'Cache-Control'),'no-store')!==false,
    'native-media-authorized-native-cached-transform-redirect-remains-protected');
  $derived_response=$call('files',$path($derived),'GET',array());
  $check($derived_response->getHTTPResponseCode()===403,'native-media-direct-avatar-derived-file-is-not-anonymous');
  $derived_response=$call('files',$path($derived),'GET',array(),$admin_cookies);
  $check($derived_response instanceof AphrontFileResponse&&$bytes($derived_response)===$stock->loadFileData(),
    'native-media-derived-file-native-original-capability-allows-author');
  $before=$fixture->countRows('file','file_transformedfile');
  $uncached='/file/xform/workcard/'.$profile->getPHID().'/'.$profile->getSecretKey().'/';
  $response=$call('files',$uncached,'GET',array());
  $check($response->getHTTPResponseCode()===403&&$fixture->countRows('file','file_transformedfile')===$before,
    'native-media-anonymous-transform-generation-refused-before-native-cache-write');
  $old_mimes=PhabricatorEnv::getEnvConfig('files.viewable-mime-types');
  try{
    PhabricatorEnv::overrideConfig('files.viewable-mime-types',$old_mimes+array('text/html'=>'text/html','image/svg+xml'=>'image/svg+xml',
      'application/javascript'=>'application/javascript','application/xml'=>'application/xml','application/pdf'=>'application/pdf'));
    foreach(array('html'=>'text/html','svg'=>'image/svg+xml','js'=>'application/javascript','xml'=>'application/xml','pdf'=>'application/pdf') as $extension=>$mime){
      $active=PhabricatorFile::newFromFileData('<script>parent.postMessage("synthetic-active-content","*")</script>',array(
        'name'=>'active.'.$extension,'authorPHID'=>$actor->getPHID(),'mime-type'=>$mime,'viewPolicy'=>PhabricatorPolicies::POLICY_USER));
      $response=$call('files',$path($active),'GET',array(),$admin_cookies);
      $check($response instanceof AphrontFileResponse&&$response->getMimeType()==='application/octet-stream'&&
        $response->getDownload()!==null&&strpos($headers($response,'Content-Disposition'),'attachment')!==false&&
        strpos($headers($response,'Content-Security-Policy'),'sandbox')!==false&&$headers($response,'X-Content-Type-Options')==='nosniff',
        'native-media-active-'.$extension.'-forced-attachment-octetstream-sandbox-despite-viewable-config');
    }
    // A safe source MIME must not become executable through a server mapping.
    PhabricatorEnv::overrideConfig('files.viewable-mime-types',array('image/png'=>'text/html')+$old_mimes);
    $mapped=$call('files',$path($profile),'GET',array(),$admin_cookies);
    $check($mapped instanceof AphrontFileResponse&&$mapped->getMimeType()==='application/octet-stream'&&
      $mapped->getDownload()!==null&&strpos($headers($mapped,'Content-Disposition'),'attachment')!==false&&
      strpos($headers($mapped,'Content-Security-Policy'),'sandbox')!==false&&$headers($mapped,'X-Content-Type-Options')==='nosniff',
      'native-media-safe-png-mapped-to-active-html-is-still-forced-attachment');
  }finally{PhabricatorEnv::overrideConfig('files.viewable-mime-types',$old_mimes);}
  // Actual native default-favicon producer emits an independent immutable path,
  // not a File capability which may be reused as an ordinary avatar.
  $favicon=$call('files','/favicon.ico','GET',array());
  $favicon_uri=$favicon instanceof AphrontRedirectResponse?$favicon->getURI():null;
  $check(is_string($favicon_uri)&&strpos($favicon_uri,'https://files.fixture.invalid/media/static/')===0,
    'native-media-default-favicon-native-producer-uses-fixed-static-origin-route');
  $static=$call('files',(new PhutilURI($favicon_uri))->getPath(),'GET',array());
  $check($static->getHTTPResponseCode()===200&&$headers($static,'Content-Type')==='image/png'&&substr($bytes($static),0,8)==="\x89PNG\r\n\x1a\n",
    'native-media-login-default-favicon-immutable-source-remains-anonymous');
  require_once __DIR__.'/media-image-cases.php';
  $image_summary=governance_native_media_image_cases($fixture,$check,$state,$call,$admin_cookies,$member_cookies,$path,$bytes);
  return array('real_gorge_bytes_and_native_file_query'=>true,'native_author_and_any_attachment_verified'=>true,
    'all_file_avatar_origins_require_login'=>true,'cached_transform_original_guard_verified'=>true,
    'anonymous_transform_generation_blocked'=>true,'active_formats_forced_attachment'=>true,
    'default_favicon_producer_static_route_verified'=>true,'actual_transform_generation_verified'=>true,'image_tests'=>$image_summary,
    'custom_branding_verified'=>false,'browser_active_content_verified'=>false,'wire_https_verified'=>false);
}

/** Observe real native service events without retaining query/URI/config data. */
function governance_native_media_observe_file_io($fixture,callable $action){
  $profiler=PhutilServiceProfiler::getInstance();$listeners=new ReflectionProperty(PhutilServiceProfiler::class,'listeners');
  $old=$listeners->getValue($profiler);$counts=array('file_metadata_events'=>0,'http_events'=>0,'image_transform_events'=>0,'image_probe_events'=>0,'image_capability_events'=>0,'exec_events'=>0);$database=$fixture->getNamespace().'_file';
  $profiler->addListener(static function($phase,$id,array $data) use(&$counts,$database){
    if($phase!=='begin'){return;}$type=$data['type']??null;
    if($type==='exec'){$counts['exec_events']++;}
    if($type==='http'){
      $counts['http_events']++;$path=parse_url($data['uri']??'',PHP_URL_PATH);
      foreach(array('/api/image/transform'=>'image_transform_events','/api/image/probe'=>'image_probe_events',
        '/api/image/capabilities'=>'image_capability_events') as $expected=>$counter){if($path===$expected){$counts[$counter]++;}}
    }
    if(in_array($type,array('query','multi-query','connect'),true)){
      $is_file=($data['database']??null)===$database||($data['config']['database']??null)===$database;
      foreach($data['queries']??array($data['query']??'') as $query){
        if(is_string($query)&&preg_match('/\bfile_(?:file|attachment|transformedfile|storageblob)\b/i',$query)){$is_file=true;}
      }
      if($is_file){$counts['file_metadata_events']++;}
    }
  });
  try{$result=$action();return array($result,$counts);}finally{$listeners->setValue($profiler,$old);}
}
