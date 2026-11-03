<?php

/** Current native FileQuery authority, separate from media-cookie identity. */
final class PhorgeGovernanceMediaFileGuard extends Phobject {
  public static function authorize(AphrontRequest $request){
    if(PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')!==true||
      PhabricatorEnv::getEnvConfig('governance.media-exchange.http-enabled')!==true){throw new RuntimeException('media_file_disabled');}
    $origins=PhorgeGovernanceMediaHTTPProtocol::origins();
    $actual=clone $request->getAbsoluteRequestURI();$actual->setPath('/')->removeAllQueryParams()->setFragment(null);
    if(!$request->isHTTPS()||PhorgeGovernanceMediaHTTPProtocol::origin((string)$actual)!==$origins['files']||
      !in_array($_SERVER['REQUEST_METHOD']??'',array('GET','HEAD','POST'),true)){
      throw new RuntimeException('media_file_origin_refused');}
    $instance=PhabricatorEnv::getEnvConfig('cluster.instance')?:null;
    $route_instance=$request->getURIData('instance');
    // Native preg_match optional captures are empty strings, not null. Only
    // the absent representation is normalized; foreign instances stay denied.
    if($route_instance===''){$route_instance=null;}
    if($route_instance!==$instance){throw new RuntimeException('media_file_instance_refused');}
    $phid=$request->getURIData('phid');$key=$request->getURIData('key');
    if(!is_string($phid)||!preg_match('/\APHID-FILE-[a-zA-Z0-9]{20}\z/D',$phid)||!is_string($key)||strlen($key)>64){
      throw new RuntimeException('media_file_unavailable');}
    $secret=PhorgeGovernanceMediaHTTPProtocol::cookie($request,PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE);
    if($secret===null){throw new RuntimeException('media_file_login_required');}
    $identity=(new PhorgeGovernanceMediaExchangeService())->authenticate($secret);
    $viewer=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($identity->getUserPHID()))->executeOne();
    if(!$viewer||$viewer->getIsDisabled()||!$viewer->getIsApproved()){
      throw new RuntimeException('media_file_identity_unavailable');}
    // Preserve native author/ANY visible attachment/original-transform semantics.
    // No omnipotent file is returned as a substitute for a failed native query.
    $visible=(new PhabricatorFileQuery())->setViewer($viewer)->withPHIDs(array($phid))->withIsDeleted(false)->executeOne();
    if(!$visible){throw new RuntimeException('media_file_policy_refused');}
    if(!is_string($visible->getSecretKey())||!hash_equals($visible->getSecretKey(),$key)||$visible->getIsPartial()){
      throw new RuntimeException('media_file_unavailable');}
    return array('file'=>$visible,'viewer'=>$viewer,'trusted_static'=>false);
  }
  public static function safeInlineMime($mime){
    return is_string($mime)&&in_array(strtolower(trim(explode(';',$mime)[0])),array(
      'image/png','image/jpeg','image/gif','image/webp','image/avif','image/bmp',
      'audio/mpeg','audio/ogg','audio/wav','video/mp4','video/webm','video/ogg','text/plain'),true);
  }
  public static function secureResponse(AphrontResponse $response,?PhabricatorFile $file=null){
    $response->setCanCDN(false)->setCacheDurationInSeconds(0)
      ->addHeader('Cache-Control','no-store, private')->addHeader('Pragma','no-cache')
      ->addHeader('X-Content-Type-Options','nosniff')
      ->addHeader('Content-Security-Policy',"sandbox; default-src 'none'; base-uri 'none'; frame-ancestors 'none'")
      ->addHeader('Referrer-Policy','no-referrer');
    if($file!==null&&$response instanceof AphrontFileResponse&&
      (!self::safeInlineMime($file->getMimeType())||!self::safeInlineMime($response->getMimeType()))){
      $response->setMimeType('application/octet-stream')->setDownload(PhabricatorFile::normalizeFileName($file->getName()));
    }
    return $response;
  }
}
