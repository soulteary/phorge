<?php

/** Explicit default-favicon producer route; never resolves user File PHIDs. */
final class PhorgeGovernanceMediaStaticController extends PhabricatorController {
  public function shouldRequireLogin(){return false;}
  public function shouldAllowPartialSessions(){return true;}
  public static function sources(){
    $root=dirname(phutil_get_library_root('phabricator'));
    $manifest=json_decode(Filesystem::readFile($root.'/resources/governance/media-static-builtins.json'),true,8,JSON_THROW_ON_ERROR);
    if(($manifest['version']??null)!==1||count($manifest['files']??array())!==4){throw new RuntimeException('media_static_manifest_refused');}
    $result=array();foreach($manifest['files'] as $entry){
      if(!preg_match('@\Afavicon/default-(?:76x76|120x120|128x128|152x152)\.png\z@D',$entry['name']??'')){
        throw new RuntimeException('media_static_manifest_refused');}
      $path=$root.'/resources/builtin/'.$entry['name'];$data=Filesystem::readFile($path);
      if(is_link($path)||!is_string($entry['sha256']??null)||!hash_equals($entry['sha256'],hash('sha256',$data))||
        strlen($data)!==($entry['bytes']??null)||substr($data,0,8)!=="\x89PNG\r\n\x1a\n"){
        throw new RuntimeException('media_static_manifest_refused');}
      $result[$entry['sha256']]=$entry+array('data'=>$data);
    }
    if(count($result)!==4){throw new RuntimeException('media_static_manifest_refused');}return $result;
  }
  public static function defaultFaviconURI($width,$height){
    if(!is_int($width)||!is_int($height)||$width<1||$height<1||$width>1024||$height>1024){throw new RuntimeException('media_static_dimensions_refused');}
    $sources=self::sources();uasort($sources,static function($a,$b){return $a['width']<=>$b['width'];});$selected=end($sources);
    foreach($sources as $entry){if($entry['width']>=max($width,$height)){$selected=$entry;break;}}
    $origin=PhorgeGovernanceMediaHTTPProtocol::origins()['files'];
    return $origin.'/media/static/'.$selected['sha256'].'.png';
  }
  public function handleRequest(AphrontRequest $request){
    try{
      PhorgeGovernanceMediaHTTPProtocol::assertRequest($request,'files',false);
      $digest=$request->getURIData('resourceDigest');$sources=self::sources();
      if(!is_string($digest)||!isset($sources[$digest])){throw new RuntimeException('media_static_source_refused');}
      return (new PhorgeGovernanceMediaHTTPResponse())->withHeader('Content-Type','image/png')
        ->withHeader('Content-Security-Policy',"sandbox; default-src 'none'; frame-ancestors 'none'")
        ->setBody($sources[$digest]['data']);
    }catch(Throwable $error){return PhorgeGovernanceMediaHTTPProtocol::deny();}
  }
}
