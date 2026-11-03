<?php

/** Native generation with a parent-owned paired OCI service, never PHP fallback. */
function governance_native_media_image_cases($fixture,callable $check,array $state,callable $call,
  array $admin_cookies,array $member_cookies,callable $path,callable $bytes){
  $fixture->assertOwned();$uri=getenv('PHORGE_GOVERNANCE_GORGE_IMAGE_URI');$token=getenv('PHORGE_GOVERNANCE_GORGE_IMAGE_TOKEN');
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||!is_string($uri)||
    !preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$uri,$match)||(int)$match[1]>65535||
    !is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token)){
    throw new RuntimeException('media_image_fixture_owned_service_required');}
  $old=array();foreach(array('gorge.image.uri'=>$uri,'gorge.image.token'=>$token,'gorge.image.mode'=>'gorge') as $key=>$value){
    $old[$key]=PhabricatorEnv::getEnvConfig($key);PhabricatorEnv::overrideConfig($key,$value);}
  try{
    $fallback_before=PhabricatorGorgeServiceSpec::getFallbackCounts();
    $capabilities=(new PhabricatorGorgeImageClient())->getCapabilities();
    if(!is_array($capabilities)||!is_string($capabilities['backendRevision']??null)||$capabilities['backendRevision']===''){
      throw new RuntimeException('media_image_fixture_backend_revision_missing');}
    $revision=hash('sha256',$capabilities['backendRevision']);
    $data=Filesystem::readFile(dirname(phutil_get_library_root('phabricator')).'/resources/builtin/profile.png');
    $file=PhabricatorFile::newFromFileData($data,array('name'=>'synthetic-image.png','authorPHID'=>$state['admin']['viewer']->getPHID(),
      'viewPolicy'=>PhabricatorPolicies::POLICY_USER,'mime-type'=>'image/png'));
    $transform='/file/xform/workcard/'.$file->getPHID().'/'.$file->getSecretKey().'/';
    list($generated,$generation_io)=governance_native_media_observe_file_io($fixture,
      static fn()=>$call('files',$transform,'GET',array(),$admin_cookies));
    $relation=(new PhabricatorTransformedFile())->loadOneWhere('originalPHID=%s AND transform=%s',$file->getPHID(),'workcard');
    $check($generated instanceof AphrontRedirectResponse&&$relation!==null&&$generation_io['image_transform_events']>0&&$generation_io['exec_events']===0,
      'native-media-authorized-uncached-transform-calls-real-paired-gorge-image-service');
    $result=(new PhabricatorFile())->loadOneWhere('phid=%s',$relation->getTransformedPHID());
    $check($result&&$result->getStorageEngine()==='gorge'&&in_array($result->getImageWidth(),array(526,'526'),true),
      'native-media-real-image-transform-result-is-stored-in-gorge-with-native-relation');
    $response=$call('files',$path($result),'GET',array(),$member_cookies);
    $check($response instanceof AphrontFileResponse&&substr($bytes($response),0,8)==="\x89PNG\r\n\x1a\n",
      'native-media-generated-derived-bytes-use-native-original-visibility');
    list($cached,$cache_io)=governance_native_media_observe_file_io($fixture,
      static fn()=>$call('files',$transform,'GET',array(),$admin_cookies));
    $check($cached instanceof AphrontRedirectResponse&&$cached->getURI()===$generated->getURI()&&$cache_io['image_transform_events']===0,
      'native-media-real-generated-transform-cache-reuse-performs-no-image-transform-http');
    $file->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    foreach(array('cached'=>$transform,'derived'=>$path($result),
      'uncached'=>'/file/xform/pinboard/'.$file->getPHID().'/'.$file->getSecretKey().'/') as $label=>$target){
      list($denied,$denied_io)=governance_native_media_observe_file_io($fixture,
        static fn()=>$call('files',$target,'GET',array(),$member_cookies));
      $check($denied->getHTTPResponseCode()===403&&$denied_io['image_transform_events']===0&&$denied_io['http_events']===0,
        'native-media-source-policy-revoked-'.$label.'-refused-before-image-or-byte-service-call');
    }
    $check(PhabricatorGorgeServiceSpec::getFallbackCounts()===$fallback_before,
      'native-media-real-image-generation-does-not-invoke-native-service-fallback');
    return array('actual_paired_image_generation_verified'=>true,'backend_revision_sha256'=>$revision,
      'real_cached_generation_reuse_verified'=>true,'source_revocation_blocks_cached_derived_and_uncached'=>true,
      'php_transform_fallback_enabled'=>false,'service_lifecycle_owned_by_parent'=>true);
  }finally{foreach($old as $key=>$value){PhabricatorEnv::overrideConfig($key,$value);}}
}
