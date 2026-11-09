<?php
/** Actual native controllers and SQL service; all identities are persisted. */
function media_positive_check($ok,$label) {
  if (!$ok) { throw new RuntimeException('media_positive_assertion'); }
  $GLOBALS['media_positive_checks'][]=$label;
}
function media_positive_session($phid) {
  $engine=new PhabricatorAuthSessionEngine();
  $token=$engine->establishSession(PhabricatorAuthSession::TYPE_WEB,$phid,false);
  $viewer=$engine->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$token);
  media_positive_check($viewer && $viewer->getPHID()===$phid && $viewer->hasSession(),'native-session-issued-and-loaded');
  return array($token,$viewer);
}
function media_positive_route($side,$action,$host) {
  $actions=array('app'=>array('authorize','issue'),'files'=>array('bridge','prepare','redeem','ready'));
  if(!isset($actions[$side])||!in_array($action,$actions[$side],true)){throw new RuntimeException('media_positive_route_profile');}
  $path=$side==='app'?'/auth/media/'.$action.'/':'/media/session/'.$action.'/';
  $request=new AphrontRequest($host,$path);
  $prototype=$side==='app'?new PhabricatorPlatformSite():new PhabricatorResourceSite();
  $site=$prototype->newSiteForRequest($request);
  if($site===null||get_class($site)!==get_class($prototype)){throw new RuntimeException('media_positive_site_refused');}
  $app=$side==='app'?new PhabricatorAuthApplication():new PhabricatorFilesApplication();
  $routes=(new AphrontRoutingMap())->setSite($site)->setApplication($app)
    ->setRoutes($side==='app'?$app->getRoutes():$app->getResourceRoutes());
  $match=$routes->routePath($path);
  $expected=$side==='app'?PhorgeGovernanceMediaAuthorizeController::class:PhorgeGovernanceMediaResourceController::class;
  if($match===null||get_class($match->getController())!==$expected||($match->getURIData()['mediaAction']??null)!==$action){throw new RuntimeException('media_positive_exact_route_required');}
  return array($match,$request);
}
function media_positive_request($side,$action,array $data,$token=null,$viewer=null,$wire='') {
  $host=$side==='app'?'app.example.com':'files.example.com';
  $path=$side==='app'?'/auth/media/'.$action.'/':'/media/session/'.$action.'/';
  $_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='127.0.0.1';
  $_SERVER['HTTP_ORIGIN']='https://'.$host;$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
  $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';$_SERVER['HTTP_COOKIE']=$wire;
  $_COOKIE=$token===null?array():array(PhabricatorCookies::COOKIE_SESSION=>$token);
  list($match,$request)=media_positive_route($side,$action,$host);media_positive_check(true,'actual-native-route');
  $payload=array('payload'=>json_encode($data,JSON_THROW_ON_ERROR));
  if($viewer!==null){$payload[AphrontRequest::getCSRFTokenName()]=$viewer->getCSRFToken();}
  $request=$request->setUser($viewer?:new PhabricatorUser())->setURIMap($match->getURIData())->setRequestData($payload);
  $controller=$match->getController();$response=$controller->setRequest($request)->handleRequest($request);
  return $response;
}
function media_positive_cookie($response,$name) {
  $found=array();foreach($response->getHeaders()as$header){if($header[0]==='Set-Cookie'&&str_starts_with($header[1],$name.'=')){$found[]=$header[1];}}
  media_positive_check(count($found)===1,'actual-response-cookie');
  $pair=explode(';',$found[0],2)[0];$secret=substr($pair,strlen($name)+1);PhorgeGovernanceMediaExchangeService::validateSecret($secret);return $secret;
}
function media_positive_body($response,$code=200) {
  media_positive_check($response->getHTTPResponseCode()===$code,'controller-response-status');
  return json_decode($response->buildResponseString(),true,8,JSON_THROW_ON_ERROR);
}
function media_positive_exchange($token,$viewer,$browser=null) {
  $path='/file/data/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/synthetic.png';$nonce=bin2hex(random_bytes(32));
  $wire=$browser===null?'':PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE.'='.$browser;
  $p=media_positive_request('files','prepare',array('path'=>$path,'client_nonce'=>$nonce),null,null,$wire);
  $prepared=media_positive_body($p);$browser=media_positive_cookie($p,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE);
  $issued=media_positive_body(media_positive_request('app','issue',array('transaction'=>$prepared['transaction'],'client_nonce'=>$nonce,'path'=>$path),$token,$viewer));
  $payload=array('transaction'=>$prepared['transaction'],'code'=>$issued['code'],'client_nonce'=>$nonce,'path'=>$path);
  // Wrong bindings must fail before the valid one-use redemption, not consume it.
  foreach(array('client_nonce','path')as$key){$bad=$payload;$bad[$key]=$key==='path'?str_replace('synthetic.png','other.png',$path):str_repeat('0',64);media_positive_body(media_positive_request('files','redeem',$bad,null,null,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE.'='.$browser),403);}
  media_positive_body(media_positive_request('files','redeem',$payload,null,null,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE.'='.str_repeat('0',64)),403);
  $redeemed=media_positive_request('files','redeem',$payload,null,null,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE.'='.$browser);
  media_positive_check(media_positive_body($redeemed)===array('redeemed'=>true),'actual-redeem');
  $credential=media_positive_cookie($redeemed,PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE);
  media_positive_body(media_positive_request('files','redeem',$payload,null,null,PhorgeGovernanceMediaHTTPProtocol::BROWSER_COOKIE.'='.$browser),403);
  $ready=media_positive_body(media_positive_request('files','ready',array('client_nonce'=>$nonce),null,null,PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE.'='.$credential));
  media_positive_check($ready===array('ready'=>true),'actual-ready');
  $snapshot=(new PhorgeGovernanceMediaExchangeService())->authenticate($credential);
  media_positive_check($snapshot->getUserPHID()===$viewer->getPHID()&&$snapshot->getSessionPHID()===$viewer->getSession()->getPHID(),'actual-native-binding');
  $issuer=PhorgeGovernanceMediaIssuer::newFromConfiguration();$conn=(new PhabricatorUser())->establishConnection('w');
  $row=queryfx_one($conn,'SELECT usedAt,userPHID,sessionPHID FROM governance_mediaexchange WHERE transactionDigest=%s',$issuer->digest('transaction',$prepared['transaction']));
  media_positive_check($row&&$row['usedAt']!==null&&$row['userPHID']===$viewer->getPHID()&&$row['sessionPHID']===$viewer->getSession()->getPHID(),'actual-used-ledger');
  return array($browser,$credential);
}
function media_positive_denied($secret,$label) {
  $denied=false;try{(new PhorgeGovernanceMediaExchangeService())->authenticate($secret);}catch(RuntimeException $e){$denied=true;}
  media_positive_check($denied,$label);
}
function media_positive_cases($fixture) {
  $ids=$fixture->getBaselineIDs();$a=$ids['actor'];$b=$ids['executor'];
  list($ta,$ua)=media_positive_session($a);list($tb,$ub)=media_positive_session($b);
  list($browser,$ca)=media_positive_exchange($ta,$ua);
  list($same,$cb)=media_positive_exchange($tb,$ub,$browser);media_positive_check($same===$browser,'same-browser-account-generation');
  media_positive_denied($ca,'old-account-generation-denied');
  (new PhabricatorAuthSessionEngine())->logoutSession($ub,$ub->getSession());media_positive_denied($cb,'native-logout-invalidates-media');
  list($ta2,$ua2)=media_positive_session($a);list($unused,$disabled)=media_positive_exchange($ta2,$ua2);
  $conn=(new PhabricatorUser())->establishConnection('w');
  queryfx($conn,'UPDATE %R SET isDisabled=1 WHERE phid=%s',new PhabricatorUser(),$a);media_positive_denied($disabled,'current-disabled-account-denied');
  queryfx($conn,'UPDATE %R SET isDisabled=0 WHERE phid=%s',new PhabricatorUser(),$a);
  list($tb2,$ub2)=media_positive_session($b);list($unused,$expired)=media_positive_exchange($tb2,$ub2);
  queryfx($conn,'UPDATE %R SET sessionExpires=UNIX_TIMESTAMP()-1 WHERE phid=%s',new PhabricatorAuthSession(),$ub2->getSession()->getPHID());media_positive_denied($expired,'current-session-expiry-denied');
}
