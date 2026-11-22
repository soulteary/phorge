<?php

/** Optional one-invocation HTTP fixture; source credentials never enter stdout. */
function governance_project_handoff_browser($fixture,$sessions,$admin,$users,$projects,callable $check) {
  $origin=getenv('PHORGE_DIRECTORY_BROWSER_ORIGIN');
  if(!is_string($origin)||!preg_match('@\Ahttp://directory\.localhost:[1-9][0-9]{0,4}\z@D',$origin)){
    throw new RuntimeException('directory_browser_origin_required');}
  $directory=sys_get_temp_dir().'/directory-http-'.bin2hex(random_bytes(12));
  if(!mkdir($directory,0700)){throw new RuntimeException('directory_browser_directory');}
  $owner=bin2hex(random_bytes(32));$transport=bin2hex(random_bytes(32));$process=null;
  $nonce=$fixture->getAdmin()->query('SELECT owner_nonce FROM '.$fixture->table('governance','fixture_guard').' WHERE id=1')->fetch_assoc()['owner_nonce'];
  $file=array('owner'=>$owner,'transport'=>$transport,'fixture_nonce'=>$nonce,
    'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),
    'writer'=>$fixture->getWriterConfig(),'origin'=>$origin,
    'gorge_file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'gorge_file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
  $write=static function($name,$data)use($directory){$fd=fopen($directory.'/'.$name,'x');if(!$fd){throw new RuntimeException('directory_browser_exclusive_write');}
    try{chmod($directory.'/'.$name,0600);if(fwrite($fd,$data)!==strlen($data)){throw new RuntimeException('directory_browser_write');}fflush($fd);}finally{fclose($fd);}};
  $write('owner',$owner);$write('config.json',json_encode($file,JSON_THROW_ON_ERROR));
  try{
    $ordinary=governance_native_request($users['ordinary'],$sessions);
    $api='api-'.bin2hex(random_bytes(14));
    (new PhabricatorConduitToken())->setObjectPHID($users['admin']->getPHID())->setTokenType('api')->setToken($api)->setTokenName('Synthetic browser handoff')->save();
    $client=array('schema'=>'project-handoff-browser-private-v1','origin'=>$origin,'transport'=>$transport,
      'admin_session'=>$admin['token'],'ordinary_session'=>$ordinary['token'],'admin_api_token'=>$api,
      'recipient_username'=>$users['recipient']->getUsername(),'recipient_phid'=>$users['recipient']->getPHID(),
      'ordinary_phid'=>$users['ordinary']->getPHID(),'web_project_id'=>$projects['web']->getID(),
      'web_project_phid'=>$projects['web']->getPHID(),'api_project_id'=>$projects['api']->getID(),
      'api_project_phid'=>$projects['api']->getPHID(),'owner'=>$owner,'directory'=>$directory);
    $write('client.json',json_encode($client,JSON_THROW_ON_ERROR));
    $env=array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1',
      'PHORGE_DIRECTORY_HTTP_FIXTURE'=>$directory);
    $process=proc_open(array(PHP_BINARY,'-S','0.0.0.0:33388',__DIR__.'/project-handoff-http-router.php'),
      array(0=>array('file','/dev/null','r'),1=>array('file',$directory.'/server.log','a'),2=>array('file',$directory.'/server.log','a')),$pipes,null,$env);
    if(!is_resource($process)){throw new RuntimeException('directory_browser_start');}
    file_put_contents('/tmp/directory-browser-location',$directory,LOCK_EX);chmod('/tmp/directory-browser-location',0600);
    $deadline=microtime(true)+240;$released=false;
    while(microtime(true)<$deadline){
      if(!proc_get_status($process)['running']){throw new RuntimeException('directory_browser_server_exited');}
      if(is_file($directory.'/finished')){
        if(!hash_equals($owner,file_get_contents($directory.'/finished'))){throw new RuntimeException('directory_browser_release_owner');}
        $released=true;break;
      }
      usleep(100000);
    }
    $check($released,'directory-browser-owned-client-finished');
    foreach($projects as$project){$row=queryfx_one($project->establishConnection('w'),
      'SELECT viewPolicy,editPolicy FROM %R WHERE phid=%s',$project,$project->getPHID());
      $check($row['viewPolicy']===$users['recipient']->getPHID()&&$row['editPolicy']===$users['recipient']->getPHID(),
        'handoff-browser-'.$project->getID().'-real-final-policy-pair');}
  }finally{
    if(is_resource($process)){proc_terminate($process);$end=microtime(true)+3;
      while(proc_get_status($process)['running']&&microtime(true)<$end){usleep(20000);}
      if(proc_get_status($process)['running']){proc_terminate($process,9);}proc_close($process);}
    if(is_file($directory.'/server.log')){$log=file_get_contents($directory.'/server.log');$GLOBALS['directory_browser_server_metadata']=array('bytes'=>strlen($log),'accepted'=>substr_count($log,'Accepted'),'closed'=>substr_count($log,'Closing'));}
    if(is_file($directory.'/failures.jsonl')){$GLOBALS['directory_browser_failure_metadata']=array_map(static fn($row)=>json_decode($row,true),file($directory.'/failures.jsonl',FILE_IGNORE_NEW_LINES));}
    if(!is_file($directory.'/owner')||!hash_equals($owner,file_get_contents($directory.'/owner'))){throw new RuntimeException('directory_browser_cleanup_owner');}
    foreach(new DirectoryIterator($directory)as$entry){if(!$entry->isDot()){
      if(!$entry->isFile()||$entry->isLink()){throw new RuntimeException('directory_browser_cleanup_shape');}unlink($entry->getPathname());}}
    rmdir($directory);unlink('/tmp/directory-browser-location');
  }
}
