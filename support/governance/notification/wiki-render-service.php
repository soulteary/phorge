<?php
/** Owned paired55 computation only; no native prose fallback or public route. */
function governance_wiki_render_start($fixture) {
  if(!is_object($fixture)||get_class($fixture)!==PhorgeGovernanceCompletionFixture::class){throw new RuntimeException('wiki_render_fixture_required');}
  $fixture->assertOwned();$binary=getenv('PHORGE_GOVERNANCE_GORGE_RENDER_BINARY');
  $digest=getenv('PHORGE_GOVERNANCE_GORGE_RENDER_SHA256');
  if($digest!=='d05250617dfa12f0b7cfc9108423b55a91ce9dd56224c96335f847deb40c22d2'||
    !is_string($binary)||!is_file($binary)||!is_executable($binary)||!hash_equals($digest,hash_file('sha256',$binary))){
    throw new RuntimeException('wiki_render_paired55_binary_required');}
  $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
  if(!$socket){throw new RuntimeException('wiki_render_loopback_port_failed');}
  $address=stream_socket_get_name($socket,false);fclose($socket);
  $owner=bin2hex(random_bytes(32));$token=bin2hex(random_bytes(32));
  $directory=sys_get_temp_dir().'/phorge-wiki-render-'.bin2hex(random_bytes(12));
  if(!mkdir($directory,0700)){throw new RuntimeException('wiki_render_directory_failed');}
  $identity=lstat($directory);$process=null;
  $service=array('private'=>$directory,'identity'=>$identity,'owner'=>$owner,'process'=>null,'marker_ready'=>false,
    'uri'=>'http://'.$address,'token'=>$token,'binary_sha256'=>$digest);
  try{
    if(file_put_contents($directory.'/owner',$owner)!==64||!chmod($directory.'/owner',0600)){
      throw new RuntimeException('wiki_render_owner_write_failed');}
    $service['marker_ready']=true;
    $env=array('PATH'=>'/usr/bin:/bin','GORGE_LISTEN_ADDR'=>$address,'GORGE_SERVICE_TOKEN'=>$service['token'],
      'GORGE_RENDER_ENABLE_DIFF'=>'true','GORGE_RENDER_MAX_BYTES'=>'1048576','GORGE_RENDER_TIMEOUT_SEC'=>'15');
    $process=proc_open(array(realpath($binary)),array(0=>array('file','/dev/null','r'),
      1=>array('file',$directory.'/service.log','a'),2=>array('file',$directory.'/service.log','a')),$pipes,null,$env);
    $service['process']=$process;
    if(!is_resource($process)){throw new RuntimeException('wiki_render_process_failed');}
    if(!chmod($directory.'/service.log',0600)){throw new RuntimeException('wiki_render_log_mode_failed');}
    $deadline=microtime(true)+15;$ready=false;
    do{
      if(!proc_get_status($process)['running']){break;}
      $context=stream_context_create(array('http'=>array('timeout'=>0.5,'ignore_errors'=>true,'proxy'=>'')));
      $body=@file_get_contents($service['uri'].'/readyz',false,$context);$headers=http_get_last_response_headers();
      if($body!==false&&isset($headers[0])&&str_contains($headers[0],' 200 ')){$ready=true;break;}
      usleep(50000);
    }while(microtime(true)<$deadline);
    if(!$ready){throw new RuntimeException('wiki_render_not_ready');}
    return$service;
  }catch(Throwable$error){governance_wiki_render_stop($service);throw$error;}
}
function governance_wiki_render_stop(array &$service){
  $process=$service['process'];
  if(is_resource($process)){
    if(proc_get_status($process)['running']){proc_terminate($process);}
    $deadline=microtime(true)+3;
    while(proc_get_status($process)['running']&&microtime(true)<$deadline){usleep(20000);}
    if(proc_get_status($process)['running']){proc_terminate($process,9);}
    proc_close($process);
  }
  $directory=$service['private'];$identity=lstat($directory);
  if(!$identity||is_link($directory)||$identity['dev']!==$service['identity']['dev']||$identity['ino']!==$service['identity']['ino']||
    ($identity['mode']&0777)!==0700){throw new RuntimeException('wiki_render_cleanup_owner_refused');}
  $marker=$directory.'/owner';
  if($service['marker_ready']&&!is_file($marker)){throw new RuntimeException('wiki_render_cleanup_owner_refused');}
  if(file_exists($marker)&&(!is_file($marker)||is_link($marker)||($service['marker_ready']?file_get_contents($marker)!==$service['owner']:
    !str_starts_with($service['owner'],file_get_contents($marker))))){
    throw new RuntimeException('wiki_render_cleanup_owner_refused');}
  foreach(new DirectoryIterator($directory)as$file){if($file->isDot()){continue;}
    if(!in_array($file->getFilename(),array('owner','service.log'),true)||!$file->isFile()||$file->isLink()){
      throw new RuntimeException('wiki_render_cleanup_unknown_file');}}
  foreach(array('service.log','owner')as$name){$path=$directory.'/'.$name;if(file_exists($path)&&!unlink($path)){
    throw new RuntimeException('wiki_render_cleanup_failed');}}
  if(!rmdir($directory)){throw new RuntimeException('wiki_render_cleanup_failed');}
  $receipt=array('binary_sha256'=>$service['binary_sha256'],'process_stopped'=>true,'private_storage_removed'=>true);
  $service=array();return$receipt;
}
function governance_wiki_render_scope($directory,$owner){
  $path=$directory.'/render.json';
  if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>1024){throw new RuntimeException('wiki_render_child_config_refused');}
  $data=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if(!is_array($data)||array_keys($data)!==array('owner','uri','token')||$data['owner']!==$owner||
    !is_string($data['uri'])||!preg_match('/\Ahttp:\/\/127\.0\.0\.1:([1-9][0-9]{0,4})\z/D',$data['uri'],$match)||(int)$match[1]>65535||
    !is_string($data['token'])||!preg_match('/\A[a-f0-9]{64}\z/D',$data['token'])){throw new RuntimeException('wiki_render_child_config_refused');}
  $policies=PhabricatorEnv::getEnvConfig('gorge.service-policies');$policies['render']='required';
  return PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('gorge.render.uri',$data['uri'])
    ->overrideEnvConfig('gorge.render.token',$data['token'])->overrideEnvConfig('gorge.service-policies',$policies);
}
