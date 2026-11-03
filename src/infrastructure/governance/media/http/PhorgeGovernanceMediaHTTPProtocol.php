<?php

/** Fixed transport rules. No file authorization and no reusable URL credential. */
final class PhorgeGovernanceMediaHTTPProtocol extends Phobject {
  const BROWSER_COOKIE='__Host-phorge-media-browser';
  const CREDENTIAL_COOKIE='__Host-phorge-media';

  /** Before native session/ephemeral-cookie consumers; FILE origin only. */
  public static function restrictFileOriginCredentials(AphrontRequest $request){
    if(PhabricatorEnv::getEnvConfig('governance.media-exchange.http-enabled')!==true){return;}
    $origins=self::origins();
    $host=(new PhutilURI('https://'.$request->getHost()))->getDomain();
    $file_host=(new PhutilURI($origins['files']))->getDomain();
    // Strip native credentials on every port/scheme spelling of the FILE
    // hostname. Authentication still requires the exact configured HTTPS
    // origin later. Do not turn an APP HTTP request into a pre-redirect error.
    if(strtolower(rtrim($host,'.'))!==$file_host){return;}
    $_SERVER['HTTP_COOKIE']=self::fileCookieHeader($_SERVER['HTTP_COOKIE']??'');
    // Native SessionEngine reads PHP cookie globals; media auth reads raw wire.
    $_COOKIE=array();
    foreach(array('HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','PHP_AUTH_USER','PHP_AUTH_PW','PHP_AUTH_DIGEST')as$key){unset($_SERVER[$key]);}
  }
  public static function fileCookieHeader($raw){
    if(!is_string($raw)||strlen($raw)>8192){return '';}
    $kept=array();foreach(explode(';',$raw)as$part){
      $part=trim($part);$name=explode('=',$part,2)[0];
      // Keep raw values/duplicates so the final strict parser rejects ambiguity.
      if(in_array($name,array(self::BROWSER_COOKIE,self::CREDENTIAL_COOKIE),true)){$kept[]=$part;}
    }
    return implode('; ',$kept);
  }
  public static function pageBootstrapConfig(AphrontRequest $request){
    if(PhabricatorEnv::getEnvConfig('governance.media-exchange.http-enabled')!==true ||
      PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')!==true){return null;}
    try{
      $origins=self::assertRequest($request,'app',false);
      PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      return array('files'=>$origins['files'],'app'=>$origins['app'],
        'max_path_length'=>PhorgeGovernanceMediaTarget::MAX_RETURN_PATH_BYTES,
        'csrf_name'=>AphrontRequest::getCSRFTokenName(),'csrf'=>$request->getViewer()->getCSRFToken(),
        'failure'=>pht('Private media access is unavailable. Sign in again, then reload this page.'));
    }catch(Throwable$error){return null;}
  }
  public static function origins(){
    $app=self::origin(PhabricatorEnv::getEnvConfig('phabricator.base-uri'));
    $files=self::origin(PhabricatorEnv::getEnvConfig('security.alternate-file-domain'));
    self::assertSeparateOrigins($app,$files);
    return array('app'=>$app,'files'=>$files);
  }
  public static function assertSeparateOrigins($app,$files){
    $app=self::origin($app);$files=self::origin($files);
    if($app===$files||(new PhutilURI($app))->getDomain()===(new PhutilURI($files))->getDomain()){
      throw new RuntimeException('media_http_separate_origin_required');}
  }
  public static function origin($value){
    if(!is_string($value)||!preg_match('@\Ahttps://[a-z0-9.-]+(?::[1-9][0-9]{0,4})?/?\z@D',$value)){
      throw new RuntimeException('media_http_https_origin_required');}
    $uri=new PhutilURI($value);$port=(int)$uri->getPortWithProtocolDefault();
    $domain=$uri->getDomain();
    if($port<1||$port>65535||strlen($domain)>253||
      !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D',$domain)){
      throw new RuntimeException('media_http_https_origin_required');}
    return 'https://'.$uri->getDomain().($port===443?'':':'.$port);
  }
  public static function assertRequest(AphrontRequest $request,$side,$post){
    if(PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')!==true||
      PhabricatorEnv::getEnvConfig('governance.media-exchange.http-enabled')!==true){throw new RuntimeException('media_http_disabled');}
    $origins=self::origins();
    if(!isset($origins[$side])||!$request->isHTTPS()){throw new RuntimeException('media_http_transport_refused');}
    $actual=clone $request->getAbsoluteRequestURI();$actual->setPath('/')->removeAllQueryParams()->setFragment(null);
    if(self::origin((string)$actual)!==$origins[$side]){throw new RuntimeException('media_http_host_refused');}
    if($post){
      if(!$request->isHTTPPost()||$request->getHTTPHeader('Origin')!==$origins[$side]){throw new RuntimeException('media_http_origin_refused');}
      $fetch=$request->getHTTPHeader('Sec-Fetch-Site');
      if($fetch!==null&&$fetch!=='same-origin'){throw new RuntimeException('media_http_fetch_site_refused');}
      $type=$request->getHTTPHeader('Content-Type','');
      if(!preg_match('@\Aapplication/x-www-form-urlencoded(?:;\s*charset=UTF-8)?\z@iD',$type)){throw new RuntimeException('media_http_content_type_refused');}
    }elseif($request->isHTTPPost()||($_SERVER['REQUEST_METHOD']??'')!=='GET'){
      throw new RuntimeException('media_http_method_refused');
    }
    return $origins;
  }
  public static function payload(AphrontRequest $request,array $keys){
    return self::parsePayload($request->getStr('payload',''),$keys);
  }
  public static function parsePayload($raw,array $keys){
    if(!is_string($raw)||strlen($raw)>8192||!preg_match('/\A\s*\{/u',$raw,$start)){
      throw new RuntimeException('media_http_payload_refused');}
    $offset=strlen($start[0]);$data=array();
    // The transport is a bounded flat string map. Parse each JSON string token
    // so duplicate/escaped-equivalent keys cannot disappear in json_decode().
    $token= <<<'REGEX'
"(?:[^"\\\x00-\x1f]|\\(?:["\\\/bfnrt]|u[0-9a-fA-F]{4}))*"
REGEX;
    while(true){
      if(preg_match('/\G\s*\}\s*\z/u',$raw,$end,0,$offset)){break;}
      if(!preg_match('/\G\s*('.$token.')\s*:\s*('.$token.')\s*([,}])/u',$raw,$match,0,$offset)){
        throw new RuntimeException('media_http_payload_refused');}
      $key=json_decode($match[1],true,2,JSON_THROW_ON_ERROR);$value=json_decode($match[2],true,2,JSON_THROW_ON_ERROR);
      if(array_key_exists($key,$data)){throw new RuntimeException('media_http_payload_refused');}
      $data[$key]=$value;$offset+=strlen($match[0]);
      if($match[3]==='}'){
        if(trim(substr($raw,$offset))!==''){throw new RuntimeException('media_http_payload_refused');}break;
      }
      if(preg_match('/\G\s*\}/u',$raw,$end,0,$offset)){throw new RuntimeException('media_http_payload_refused');}
    }
    $actual=array_keys($data);sort($actual);sort($keys);
    if($actual!==$keys){throw new RuntimeException('media_http_payload_refused');}
    return $data;
  }
  public static function cookie(AphrontRequest $request,$name){
    if(!in_array($name,array(self::BROWSER_COOKIE,self::CREDENTIAL_COOKIE),true)){throw new LogicException('media_http_cookie_refused');}
    // Read the exact wire name/value. PHP normalizes cookie names and decoded
    // globals must never substitute for a missing or different __Host- cookie.
    $wire=$request->getHTTPHeader('Cookie','');
    if(!is_string($wire)||strlen($wire)>16384){throw new RuntimeException('media_http_cookie_refused');}
    $found=null;$matches=0;
    foreach(explode(';',$wire) as $entry){
      $parts=explode('=',$entry,2);$raw_name=trim($parts[0]);
      // Quoted/encoded/normalized aliases are not this credential's wire name.
      if($raw_name!==$name){continue;}
      $matches++;
      if($matches>1||count($parts)!==2){throw new RuntimeException('media_http_cookie_refused');}
      try{$found=PhorgeGovernanceMediaExchangeService::validateSecret($parts[1]);}
      catch(InvalidArgumentException $error){throw new RuntimeException('media_http_cookie_refused');}
    }
    return $found;
  }

  public static function setCookie(PhorgeGovernanceMediaHTTPResponse $response,$name,$value,$expires){
    if(!in_array($name,array(self::BROWSER_COOKIE,self::CREDENTIAL_COOKIE),true)){throw new LogicException('media_http_cookie_refused');}
    PhorgeGovernanceMediaExchangeService::validateSecret($value);
    $age=max(0,min(86400,$expires-time()));
    return $response->withHeader('Set-Cookie',$name.'='.$value.'; Path=/; Max-Age='.$age.'; Secure; HttpOnly; SameSite=Strict');
  }
  public static function json(array $data){
    return (new PhorgeGovernanceMediaHTTPResponse())->withHeader('Content-Type','application/json; charset=UTF-8')
      ->withHeader('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'; sandbox")
      ->setBody(json_encode($data,JSON_THROW_ON_ERROR));
  }
  public static function deny(){return self::json(array('error'=>'media_request_unavailable'))->setHTTPResponseCode(403);}
  public static function scriptJSON(array $data){return json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);}
}
