<?php

/** Optional one-invocation HTTP fixture; source credentials never enter stdout. */
function governance_directory_product_browser($fixture,$service,$sessions,$admin,$users,callable $check) {
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
    $fresh=(new PhabricatorUser())->setUsername('dpbrowser'.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Browser Member')->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($fresh->getPHID())->setAddress($fresh->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $manager=governance_native_request($users['manager'],$sessions);
    $client=array('schema'=>'directory-browser-private-v1','origin'=>$origin,'transport'=>$transport,
      'admin_session'=>$admin['token'],'manager_session'=>$manager['token'],
      'fresh_username'=>$fresh->getUsername(),'fresh_phid'=>$fresh->getPHID(),
      'subject_username'=>$users['subject']->getUsername(),'owner'=>$owner,'directory'=>$directory);
    $write('client.json',json_encode($client,JSON_THROW_ON_ERROR));
    $env=array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1',
      'PHORGE_DIRECTORY_HTTP_FIXTURE'=>$directory);
    // Child environments are deliberately closed. Forward only the qualified
    // candidate profile so its HTTP interpreter is checked under the same gate.
    if(PHP_VERSION==='8.6.0'&&getenv('PHORGE_PHP_COMPATIBILITY_PROFILE')==='php86-isolated-v1'){
      phorge_assert_supported_php_version(PHP_VERSION);
      $env['PHORGE_PHP_COMPATIBILITY_PROFILE']='php86-isolated-v1';
    }
    $process=proc_open(array(PHP_BINARY,'-S','0.0.0.0:33388',__DIR__.'/directory-product-http-router.php'),
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
