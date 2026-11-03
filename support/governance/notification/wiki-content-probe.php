<?php
/** Owned independent PHP probe; private payload is never copied into receipt. */
function governance_wiki_content_probe_factory($fixture,array $queue,&$processes,&$directories,&$created,&$live){
  return static function($task,$object,$phase)use($fixture,$queue,&$processes,&$directories,&$created,&$live){
    $directory=sys_get_temp_dir().'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));$owner=bin2hex(random_bytes(32));
    if(!mkdir($directory,0700)){throw new RuntimeException('private_mail_race_directory_failed');}
    $created++;$stat=lstat($directory);$process=null;$pipes=array();
    try{
      $write=static function($name,$value)use($directory){$path=$directory.'/'.$name;if(file_put_contents($path,$value)===false||!chmod($path,0600)){throw new RuntimeException('private_mail_race_private_write_failed');}};
      $write('owner',$owner);$row=$fixture->getAdmin()->query('SELECT owner_nonce FROM '.$fixture->table('governance','fixture_guard').' WHERE id=1')->fetch_assoc();
      $data=array('callback_origin'=>'http://127.0.0.1:1','file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'),
        'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'fixture_nonce'=>$row['owner_nonce'],'mailer_token'=>bin2hex(random_bytes(32)),
        'mailer_uri'=>'http://127.0.0.1:1','owner'=>$owner,'service_token'=>bin2hex(random_bytes(32)),
        'source_sha256'=>hash_file('sha256',dirname(phutil_get_library_root('phabricator')).'/resources/sql/quickstart.sql'),
        'writer'=>$fixture->getWriterConfig());
      $write('queue.json',json_encode(array('owner'=>$owner,'uri'=>$queue['uri'],'token'=>$queue['token']),JSON_THROW_ON_ERROR));
      $write('router.json',json_encode($data,JSON_THROW_ON_ERROR));$write('probe.json',json_encode(array('owner'=>$owner,'task_id'=>(int)$task->getID(),'object_phid'=>$object->getPHID(),'phase'=>$phase,'lease_owner'=>$task->getLeaseOwner(),'lease_expires'=>(int)$task->getLeaseExpires()),JSON_THROW_ON_ERROR));
      $process=proc_open(array(PHP_BINARY,__DIR__.'/wiki-content-publication-worker.php',$directory),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
      if(!is_resource($process)){throw new RuntimeException('private_mail_race_spawn_failed');}$processes++;$live++;fclose($pipes[0]);unset($pipes[0]);
      foreach($pipes as$pipe){stream_set_blocking($pipe,false);}$out='';$err='';$deadline=microtime(true)+15;$exit=null;
      do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);
        if(strlen($out)>16384||strlen($err)>4096){throw new RuntimeException('private_mail_race_output_budget');}
        if(!$status['running']){$exit=$status['exitcode'];break;}if(microtime(true)>$deadline){throw new RuntimeException('private_mail_race_worker_timeout');}usleep(20000);
      }while(true);
      $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);foreach($pipes as$pipe){fclose($pipe);}$pipes=array();proc_close($process);$process=null;$live--;
      $result=json_decode($out,true,8,JSON_THROW_ON_ERROR);
      if($exit!==0||$err!==''||!is_array($result)||($result['passed']??null)!==true||($result['actual_owned_taskqueue_http_ready']??null)!==true){
        // Only bounded diagnostic metadata reaches the failed fixture receipt.
        // Never retain the child's message, stderr, envelope or credentials.
        $diagnostic=array('exit_code'=>(int)$exit,'stderr_present'=>$err!=='');
        $child=is_array($result)?($result['failure']??null):null;
        if(is_array($child)&&is_string($child['type']??null)&&preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/D',$child['type'])&&
          is_string($child['file']??null)&&preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D',$child['file'])&&
          is_int($child['line']??null)&&$child['line']>0&&$child['line']<100000){
          $diagnostic['error_type']=$child['type'];$diagnostic['source_file']=$child['file'];$diagnostic['source_line']=$child['line'];
        }
        $GLOBALS['governance_private_business_child_failure']=$diagnostic;
        throw new RuntimeException('private_mail_race_worker_failed');}
      return$result;
    }finally{
      if(is_resource($process)){proc_terminate($process);foreach($pipes as$pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($process);$live--;}
      $current=lstat($directory);if(!$current||$current['dev']!==$stat['dev']||$current['ino']!==$stat['ino']||is_link($directory)){throw new RuntimeException('private_mail_race_cleanup_owner_refused');}
      foreach(array('owner','router.json','probe.json','queue.json')as$name){$path=$directory.'/'.$name;if(file_exists($path)){if(is_link($path)||!unlink($path)){throw new RuntimeException('private_mail_race_cleanup_failed');}}}
      if(!rmdir($directory)){throw new RuntimeException('private_mail_race_cleanup_failed');}$directories++;
    }
  };
}
