<?php

/** Native site maps/controllers and actual SQL, not a TLS/browser receipt. */
function governance_native_media_http_cases($fixture, callable $check, array $state) {
  $fixture->assertOwned();$saved_server=$_SERVER;$saved_cookies=$_COOKIE;$saved_post=$_POST;$saved_locale=PhabricatorEnv::getLocaleCode();
  $request_cache_property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$saved_request_cache=$request_cache_property->getValue();
  $settings=array('governance.media-exchange.http-enabled'=>true,'phabricator.base-uri'=>'https://app.fixture.invalid/',
    'security.alternate-file-domain'=>'https://files.fixture.invalid/');$old=array();
  foreach($settings as $key=>$value){$old[$key]=PhabricatorEnv::getEnvConfig($key);PhabricatorEnv::overrideConfig($key,$value);}
  $path='/file/download/key/PHID-FILE-ssssssssssssssssssss/'.rawurlencode('部门报告.xlsx');$nonce=bin2hex(random_bytes(32));
  $call=static function($side,$action,$method,array $data,array $cookies=array(),array $overrides=array(),$probe_guard=false)
    use($fixture,$state,$check){
    $_SERVER['HTTPS']='on';$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['REQUEST_METHOD']=$method;$_SERVER['QUERY_STRING']='';
    $_SERVER['HTTP_ORIGIN']='https://'.$side.'.fixture.invalid';$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
    $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded;charset=UTF-8';unset($_SERVER['HTTP_CONTENT_TYPE']);
    foreach(array('HTTP_RANGE','HTTP_IF_NONE_MATCH','HTTP_IF_MODIFIED_SINCE','HTTP_X_PHABRICATOR_CSRF') as $header){unset($_SERVER[$header]);}
    $_SERVER['HTTP_COOKIE']=implode('; ',array_map(static fn($name,$value)=>$name.'='.$value,array_keys($cookies),array_values($cookies)));
    foreach($overrides as $key=>$value){if($value===null){unset($_SERVER[$key]);}else{$_SERVER[$key]=$value;}}
    $_COOKIE=$cookies;
    $uri=substr($action,0,1)==='/'?$action:($side==='app'?'/auth/media/'.$action.'/':'/media/session/'.$action.'/');
    (new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,null);
    // Use native request construction and the real controller bootstrap. In
    // particular, anonymous CSRF uses SessionEngine's anonymous token plus
    // PhabricatorController::willBeginExecution(), not a fabricated session.
    $_POST=$method==='POST'?$data:array();
    $_SERVER['QUERY_STRING']=$method==='GET'?http_build_query($data):'';
    $configuration=(new AphrontApplicationConfiguration())->setHost($side.'.fixture.invalid')->setPath($uri);
    $request=$configuration->buildRequest();
    $site=$side==='app'?(new PhabricatorPlatformSite())->newSiteForRequest($request):(new PhabricatorResourceSite())->newSiteForRequest($request);
    if(!$site){throw new RuntimeException('media_http_fixture_site_not_matched');}
    $matched=null;foreach($site->getRoutingMaps() as $map){$matched=$map->routePath($uri);if($matched){break;}}
    if(!$matched){throw new RuntimeException('media_http_fixture_route_not_matched');}
    $request->setURIMap($matched->getURIData());$controller=$matched->getController()->setRequest($request);
    return governance_native_with_request_write_guard($request,static function() use($controller,$request,$probe_guard,$check){
      $response=$controller->willBeginExecution();
      if($response===null){$response=$controller->handleRequest($request);}
      if($probe_guard){$refused=false;try{queryfx((new PhabricatorUser())->establishConnection('w'),
        'UPDATE governance_mediabrowser SET generation=generation WHERE 1=0');}
        catch(AphrontMalformedRequestException $error){$refused=true;}
        $check($refused,'native-media-http-public-post-restores-native-csrf-write-guard');}
      return $response;
    });
  };
  $decode=static function($response){return json_decode((string)$response->buildResponseString(),true,8,JSON_THROW_ON_ERROR);};
  $cookie=static function($response,$name){$found=array();foreach($response->getHeaders() as $h){
    if($h[0]==='Set-Cookie'&&strpos($h[1],$name.'=')===0){$found[]=$h[1];}}
    if(count($found)!==1||strpos($found[0],'; Path=/')===false||strpos($found[0],'; Secure')===false||
      strpos($found[0],'; HttpOnly')===false||strpos($found[0],'; SameSite=Strict')===false||stripos($found[0],'Domain=')!==false){
      throw new RuntimeException('media_http_fixture_cookie_attributes_refused');}
    return substr(explode(';',$found[0],2)[0],strlen($name)+1);
  };
  try{
    $before_invalid_site=governance_native_media_fingerprint($fixture);
    foreach(array('https://files.other.invalid/','https://other.github.io/') as $invalid_origin){
      PhabricatorEnv::overrideConfig('security.alternate-file-domain',$invalid_origin);
      $refused=false;
      try{$call('files','prepare','POST',array('payload'=>json_encode(array('path'=>$path,'client_nonce'=>$nonce))));}
      catch(RuntimeException $error){$refused=$error->getMessage()==='media_http_same_site_required';}
      $check($refused&&governance_native_media_fingerprint($fixture)===$before_invalid_site,
        'native-media-http-cross-site-configuration-refused-before-state-'.hash('sha256',$invalid_origin));
    }
    PhabricatorEnv::overrideConfig('security.alternate-file-domain',$settings['security.alternate-file-domain']);
    $long_name=str_repeat('测',250).'.xlsx';
    $long_file=PhabricatorFile::newFromFileData('synthetic-unicode-uri',array(
      'name'=>$long_name,'authorPHID'=>$state['admin']['viewer']->getPHID(),
      'viewPolicy'=>PhabricatorPolicies::POLICY_USER));
    $stored=(new PhabricatorFile())->load($long_file->getID());
    $path=(new PhutilURI($stored->getDownloadURI()))->getPath();
    $check($stored->getName()===$long_name&&strlen($path)>2048&&
      PhorgeGovernanceMediaTarget::fromPath($path)->getReturnPath()===$path,
      'native-media-http-native-stored-long-unicode-file-uri-remains-a-canonical-target');
    $authorize=$call('app','authorize','GET',array('path'=>$path),array(PhabricatorCookies::COOKIE_SESSION=>$state['admin']['token']));
    $check($authorize->getHTTPResponseCode()===200&&strpos((string)$authorize->buildResponseString(),'media-bridge')!==false,
      'native-media-http-real-platform-route-authenticated-authorize-page');
    $bridge=$call('files','bridge','GET',array());$headers=$bridge->getHeaders();$csp='';
    foreach($headers as $h){if($h[0]==='Content-Security-Policy'){$csp=$h[1];}}
    $check($bridge->getHTTPResponseCode()===200&&strpos($csp,"frame-ancestors https://app.fixture.invalid")!==false&&
      strpos($csp,"connect-src 'self'")!==false&&strpos($csp,"default-src 'none'")!==false,
      'native-media-http-real-resource-route-pinned-frame-csp');
    $payload=array('payload'=>json_encode(array('path'=>$path,'client_nonce'=>$nonce)));
    $before=governance_native_media_fingerprint($fixture);
    foreach(array('foreign'=>'https://foreign.fixture.invalid','null'=>'null','missing'=>null) as $name=>$origin){
      $response=$call('files','prepare','POST',$payload,array(),array('HTTP_ORIGIN'=>$origin));
      $check($response->getHTTPResponseCode()===403,'native-media-http-'.$name.'-origin-preparation-refused');
    }
    $check(governance_native_media_fingerprint($fixture)===$before,'native-media-http-origin-rejection-before-ledger-write');
    $prepared_response=$call('files','prepare','POST',$payload,array(),array(),true);$prepared=$decode($prepared_response);
    $browser=$cookie($prepared_response,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE);
    $check($prepared_response->getHTTPResponseCode()===200&&isset($prepared['transaction'])&&!isset($prepared['browser_secret']),
      'native-media-http-prepare-secret-only-in-host-secure-httponly-cookie');
    $issue_payload=array('payload'=>json_encode(array('transaction'=>$prepared['transaction'],'client_nonce'=>$nonce,'path'=>$path)));
    $app_cookies=array(PhabricatorCookies::COOKIE_SESSION=>$state['admin']['token']);
    $csrf_bad=$call('app','issue','POST',$issue_payload,$app_cookies);
    $check($csrf_bad->getHTTPResponseCode()===403,'native-media-http-app-issue-requires-real-native-csrf');
    $issue_payload[AphrontRequest::getCSRFTokenName()]=$state['admin']['viewer']->getCSRFToken();
    $issued_response=$call('app','issue','POST',$issue_payload,$app_cookies);$issued=$decode($issued_response);
    $check($issued_response->getHTTPResponseCode()===200&&isset($issued['code']),
      'native-media-http-real-session-csrf-issue-code-in-post-response-only');
    $redeem_payload=array('payload'=>json_encode(array('transaction'=>$prepared['transaction'],'code'=>$issued['code'],
      'client_nonce'=>$nonce,'path'=>$path)));$browser_cookies=array(PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE=>$browser);
    $no_browser=$call('files','redeem','POST',$redeem_payload);
    $check($no_browser->getHTTPResponseCode()===403,'native-media-http-redeem-requires-file-origin-browser-cookie');
    $redeemed_response=$call('files','redeem','POST',$redeem_payload,$browser_cookies);
    $credential=$cookie($redeemed_response,PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE);
    $check($redeemed_response->getHTTPResponseCode()===200&&$decode($redeemed_response)===array('redeemed'=>true),
      'native-media-http-redeem-credential-only-in-host-secure-httponly-cookie');
    $replay=$call('files','redeem','POST',$redeem_payload,$browser_cookies);
    $check($replay->getHTTPResponseCode()===403,'native-media-http-once-code-post-replay-refused');
    $ready=$call('files','ready','POST',array('payload'=>json_encode(array('client_nonce'=>$nonce))),
      array(PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE=>$credential));
    $check($ready->getHTTPResponseCode()===200&&$decode($ready)===array('ready'=>true),
      'native-media-http-ready-verifies-current-native-bound-cookie-without-identity-leak');
    $ready_payload=array('payload'=>json_encode(array('client_nonce'=>$nonce)));
    $credential_name=PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE;
    foreach(array('missing'=>'','encoded'=>rawurlencode($credential_name).'='.$credential,
      'quoted'=>'"'.$credential_name.'"='.$credential,'normalized'=>'..Host-phorge-media='.$credential) as $label=>$wire){
      // rawurlencode leaves underscores/hyphens intact; force a real encoded alias.
      if($label==='encoded'){$wire='%5F%5FHost-phorge-media='.$credential;}
      $response=$call('files','ready','POST',$ready_payload,array($credential_name=>$credential),array('HTTP_COOKIE'=>$wire));
      $check($response->getHTTPResponseCode()===403,'native-media-http-'.$label.'-wire-cookie-never-uses-php-global-alias');
    }
    $response=$call('files','ready','POST',$ready_payload,array($credential_name=>$credential),
      array('HTTP_COOKIE'=>$credential_name.'='.$credential.'; '.$credential_name.'='.str_repeat('0',64)));
    $check($response->getHTTPResponseCode()===403,'native-media-http-duplicate-conflicting-wire-cookie-refused');
    $response=$call('files','ready','POST',$ready_payload,array($credential_name=>str_repeat('0',64)),
      array('HTTP_COOKIE'=>$credential_name.'='.$credential));
    $check($response->getHTTPResponseCode()===200&&$decode($response)===array('ready'=>true),
      'native-media-http-valid-wire-cookie-does-not-trust-conflicting-php-global');
    $response=$call('files','ready','POST',$ready_payload,array($credential_name=>$credential),
      array('HTTP_COOKIE'=>$credential_name.'='.str_repeat('0',64)));
    $check($response->getHTTPResponseCode()===403,'native-media-http-invalid-wire-cookie-not-replaced-by-valid-php-global');
    $response=$call('files','ready','POST',$ready_payload,array(
      $credential_name=>$credential,PhabricatorCookies::COOKIE_SESSION=>$state['admin']['token']),
      array('HTTP_AUTHORIZATION'=>'Bearer synthetic-forbidden-native-credential'));
    $check($response->getHTTPResponseCode()===200 && $_COOKIE===array() &&
      !isset($_SERVER['HTTP_AUTHORIZATION']) &&
      $_SERVER['HTTP_COOKIE']===$credential_name.'='.$credential,
      'native-media-resource-bootstrap-strips-native-app-cookie-and-authorization-before-session-engine');
    $response=$call('app','authorize','GET',array('path'=>$path),
      array(PhabricatorCookies::COOKIE_SESSION=>$state['admin']['token']));
    $check($response->getHTTPResponseCode()===200 &&
      ($_COOKIE[PhabricatorCookies::COOKIE_SESSION]??null)===$state['admin']['token'],
      'native-media-file-cookie-firewall-does-not-strip-application-session');
    $_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTPS']='on';
    $bootstrap_request=(new AphrontRequest('app.fixture.invalid','/T1'))->setUser($state['admin']['viewer'])->setRequestData(array());
    $bootstrap=PhorgeGovernanceMediaHTTPProtocol::pageBootstrapConfig($bootstrap_request);
    $check(is_array($bootstrap) && $bootstrap['files']==='https://files.fixture.invalid' &&
      $bootstrap['csrf']===$state['admin']['viewer']->getCSRFToken(),
      'native-media-page-bootstrap-config-requires-real-native-cookie-session-and-app-origin');
    $_COOKIE=array();
    $check(PhorgeGovernanceMediaHTTPProtocol::pageBootstrapConfig($bootstrap_request)===null,
      'native-media-page-bootstrap-viewer-object-alone-cannot-export-csrf');
    $_SERVER['REQUEST_METHOD']='GET';$file_request=(new AphrontRequest('files.fixture.invalid',$path))->setUser(new PhabricatorUser());
    $check((new PhabricatorFileDataController())->handleRequest($file_request)->getHTTPResponseCode()===403,
      'native-media-http-identity-cookie-does-not-bypass-file-object-guard');
    require_once __DIR__.'/media-file-cases.php';
    $file_summary=governance_native_media_file_cases($fixture,$check,$state,$call,$cookie,$credential);
    $check(governance_native_route_guard_depth()===0,'native-media-http-controller-scopes-leave-no-unguarded-depth');
    return array('native_site_and_controller_exchange_verified'=>true,'host_only_cookie_attributes_verified'=>true,
      'real_origin_csrf_and_once_code_verified'=>true,'wire_https_verified'=>false,'browser_verified'=>false,
      'file_guard_tests'=>$file_summary,'file_guard_complete'=>false,'production_enabled'=>false);
  }finally{foreach($old as $key=>$value){PhabricatorEnv::overrideConfig($key,$value);}$_SERVER=$saved_server;$_COOKIE=$saved_cookies;$_POST=$saved_post;PhabricatorEnv::setLocaleCode($saved_locale);$request_cache_property->setValue(null,$saved_request_cache);}
}
