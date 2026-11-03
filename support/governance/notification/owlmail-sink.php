<?php

/** Actual pinned standalone Owlmail, private loopback capture only. */
function governance_private_mail_start_sink($fixture, callable $check) {
  $fixture->assertOwned();
  $binary=getenv('PHORGE_GOVERNANCE_OWLMAIL_BINARY');
  $expected=getenv('PHORGE_GOVERNANCE_OWLMAIL_SHA256');
  if(!is_string($binary)||!is_file($binary)||!is_executable($binary)||
      !is_string($expected)||!preg_match('/\A[a-f0-9]{64}\z/',$expected)||
      !hash_equals($expected,hash_file('sha256',$binary))){
    throw new RuntimeException('private_mail_pinned_capture_binary_required');
  }
  $sockets=array();$ports=array();
  try{
    for($i=0;$i<2;$i++){
      $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
      if(!$socket){throw new RuntimeException('private_mail_capture_port_failed');}
      $sockets[]=$socket;$ports[]=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);
    }
  }finally{foreach($sockets as$socket){fclose($socket);}}
  $private=sys_get_temp_dir().'/governance-service-'.bin2hex(random_bytes(12));
  if(!mkdir($private,0700)){throw new RuntimeException('private_mail_capture_directory_failed');}
  $owned_stat=lstat($private);
  $sink=array('process'=>null,'private'=>$private,'kind'=>'owlmail','binary_sha256'=>$expected);
  try{
  $nonce=bin2hex(random_bytes(24));$password=bin2hex(random_bytes(32));$sink['nonce']=$nonce;
  if(file_put_contents($private.'/owner',$nonce,LOCK_EX)!==strlen($nonce)||
      !chmod($private.'/owner',0600)||!mkdir($private.'/mail',0700)){
    throw new RuntimeException('private_mail_capture_owner_setup_failed');
  }
  $env=array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','OWLMAIL_MODE'=>'standalone',
    'OWLMAIL_SMTP_HOST'=>'127.0.0.1','OWLMAIL_SMTP_PORT'=>(string)$ports[0],
    'OWLMAIL_WEB_HOST'=>'127.0.0.1','OWLMAIL_WEB_PORT'=>(string)$ports[1],
    'OWLMAIL_WEB_USER'=>'isolated-capture','OWLMAIL_WEB_PASSWORD'=>$password,
    'OWLMAIL_MAIL_DIR'=>$private.'/mail','OWLMAIL_AUTO_RELAY'=>'false');
  $process=proc_open(array(realpath($binary)),array(0=>array('file','/dev/null','r'),
    1=>array('file',$private.'/service.log','a'),2=>array('file',$private.'/service.log','a')),
    $pipes,$private,$env);
  $sink=array('process'=>$process,'private'=>$private,'nonce'=>$nonce,'kind'=>'owlmail',
    'binary_sha256'=>$expected,'smtp_port'=>$ports[0],'uri'=>'http://127.0.0.1:'.$ports[1],
    'authorization'=>'Basic '.base64_encode('isolated-capture:'.$password));
    if(!is_resource($process)){throw new RuntimeException('private_mail_capture_process_failed');}
    $deadline=microtime(true)+15;$ready=false;
    do{
      if(!proc_get_status($process)['running']){break;}
      $context=stream_context_create(array('http'=>array('timeout'=>0.5,'ignore_errors'=>true,
        'follow_location'=>0,'max_redirects'=>0,'proxy'=>'','header'=>'Authorization: '.$sink['authorization'])));
      $body=@file_get_contents($sink['uri'].'/api/v1/emails?limit=1',false,$context);
      $headers=http_get_last_response_headers();
      if($body!==false&&isset($headers[0])&&str_contains($headers[0],' 200 ')){
        $result=phutil_json_decode($body);$ready=is_array($result);if($ready){break;}
      }
      usleep(50000);
    }while(microtime(true)<$deadline);
    $check($ready,'private-mail-real-pinned-owlmail-ready-private-loopback');
    return $sink;
  }catch(Throwable$error){
    $now=lstat($private);
    if(!$now||is_link($private)||$now['dev']!==$owned_stat['dev']||$now['ino']!==$owned_stat['ino']){
      throw new RuntimeException('private_mail_capture_cleanup_ownership_refused');
    }
    if(isset($sink['nonce'])&&is_file($private.'/owner')&&!is_link($private.'/owner')&&
        hash_equals($sink['nonce'],file_get_contents($private.'/owner'))){
      governance_completion_stop_gorge($sink);
    }else{
      // Before the marker exists, nothing except that partial marker can have
      // been created. The successful mkdir inode proves this exact ownership.
      $entries=array_values(array_diff(scandir($private),array('.','..')));
      if($entries!==array()&&$entries!==array('owner')){
        throw new RuntimeException('private_mail_capture_partial_cleanup_refused');
      }
      if($entries){if(!is_file($private.'/owner')||is_link($private.'/owner')){
        throw new RuntimeException('private_mail_capture_marker_cleanup_refused');}
        unlink($private.'/owner');}
      rmdir($private);
    }
    throw$error;
  }
}
