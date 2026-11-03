<?php
/** Real loopback Conduit HTTP requests. Only ephemeral fixture credentials. */
function governance_native_measurement_http_request($port,$form=null){
  $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,2);
  if(!$socket){throw new RuntimeException('measurement_http_transport_unknown');}
  try{
    stream_set_timeout($socket,5);$body=$form===null?'':http_build_query($form,'','&',PHP_QUERY_RFC3986);
    $request=($form===null?'GET /measurement-ready':'POST /api/user.whoami')." HTTP/1.0\r\nHost: 127.0.0.1:".$port.
      "\r\nConnection: close\r\n".($form===null?'':"Content-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n")."\r\n".$body;
    $written=0;while($written<strlen($request)){$n=fwrite($socket,substr($request,$written));
      if(!$n){throw new RuntimeException('measurement_http_transport_unknown');}$written+=$n;}
    $raw='';while(!feof($socket)){$part=fread($socket,4096);if($part===false){throw new RuntimeException('measurement_http_transport_unknown');}
      $raw.=$part;if(strlen($raw)>32768||stream_get_meta_data($socket)['timed_out']){
        throw new RuntimeException('measurement_http_response_unknown');}}
    if(!preg_match('/\AHTTP\/1\.[01] ([0-9]{3})[^\r\n]*\r\n/',$raw,$match)||!str_contains($raw,"\r\n\r\n")){
      throw new RuntimeException('measurement_http_response_unknown');}
    list($headers,$response)=explode("\r\n\r\n",$raw,2);
    if(stripos($headers,"\r\nTransfer-Encoding:")!==false||stripos($headers,"\r\nLocation:")!==false){
      throw new RuntimeException('measurement_http_response_unknown');}
    try{$value=json_decode($response,true,16,JSON_THROW_ON_ERROR);}catch(Throwable$error){$value=null;}
    // Raw native errors may contain the credential. Never return or log them.
    return array('status'=>(int)$match[1],'value'=>$value);
  }finally{fclose($socket);}
}
function governance_native_revocation_http_cases($fixture,callable $check,array $state){
  $fixture->assertOwned();$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)||PHP_INT_SIZE!==8){throw new RuntimeException('measurement_http_source_required');}
  $boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
  if(!preg_match('/\A[a-f0-9-]{36}\z/',$boot)){throw new RuntimeException('measurement_linux_clock_required');}
  $boot_hash=hash('sha256',$boot);$router=__DIR__.'/revocation-http-router.php';$router_hash=hash_file('sha256',$router);
  $user=(new PhabricatorUser())->setUsername('n12http'.substr($fixture->getNamespace(),-12))
    ->setRealName('Synthetic HTTP Measurement')->setIsApproved(1)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
    ->setIsPrimary(1)->setIsVerified(1)->save();
  $token=PhabricatorConduitToken::initializeNewToken($user->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
  $id=(int)$token->getID();$owner=hash('sha256',$user->getPHID());$conn=$token->establishConnection('w');$owned=false;
  $started=0;$stopped=0;$removed=0;$control_seen=false;
  $source=static function()use($conn,$id){$row=queryfx_one($conn,'SELECT id,objectPHID,tokenType,token,expires FROM %R WHERE id=%d',new PhabricatorConduitToken(),$id);
    return array($row,hash('sha256',phutil_json_encode(array('id'=>$id,'row'=>$row))));};
  $probe=static function($parse_control=false)use($fixture,$token,$id,$owner,$router,$router_hash,$revision,$boot_hash,$source,&$started,&$stopped,&$removed,&$control_seen){
    $begin=hrtime(true);$reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$reservation){throw new RuntimeException('measurement_http_port_failed');}
    $address=stream_socket_get_name($reservation,false);$port=(int)substr(strrchr($address,':'),1);fclose($reservation);
    $directory=sys_get_temp_dir().'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));
    if(!mkdir($directory,0700)){throw new RuntimeException('measurement_http_directory_failed');}$stat=lstat($directory);$process=null;$nonce=bin2hex(random_bytes(32));
    try{
      $config=array('owner'=>$nonce,'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
        'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'writer'=>$fixture->getWriterConfig(),
        'callback_origin'=>'http://127.0.0.1:'.$port,'service_token'=>bin2hex(random_bytes(32)),
        'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'),
        'mailer_uri'=>'http://127.0.0.1:1','mailer_token'=>bin2hex(random_bytes(32)));
      foreach(array('owner'=>$nonce,'router.json'=>json_encode($config,JSON_THROW_ON_ERROR),'stdout.log'=>'','stderr.log'=>'')as$name=>$data){
        $file=$directory.'/'.$name;if(file_put_contents($file,$data)===false||!chmod($file,0600)){throw new RuntimeException('measurement_http_private_write_failed');}}
      $process=proc_open(array(PHP_BINARY,'-S','127.0.0.1:'.$port,$router),
        array(0=>array('file','/dev/null','r'),1=>array('file',$directory.'/stdout.log','a'),2=>array('file',$directory.'/stderr.log','a')),$pipes,null,
        array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1',
          'PHORGE_GOVERNANCE_MEASUREMENT_HTTP_DIR'=>$directory,'PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION'=>$revision));
      if(!is_resource($process)){throw new RuntimeException('measurement_http_spawn_failed');}$started++;
      $ready=null;$deadline=microtime(true)+8;
      do{if(!proc_get_status($process)['running']){throw new RuntimeException('measurement_http_server_failed');}
        try{$ready=governance_native_measurement_http_request($port);}catch(RuntimeException$error){$ready=null;}
        if(($ready['status']??null)===200&&($ready['value']['ready']??null)===true){break;}usleep(20000);
      }while(microtime(true)<$deadline);
      if(($ready['value']['boot_id_sha256']??null)!==$boot_hash||($ready['value']['router_sha256']??null)!==$router_hash||
        ($ready['value']['source_revision']??null)!==$revision){throw new RuntimeException('measurement_http_server_binding_refused');}
      list($row,$binding)=$source();
      $response=governance_native_measurement_http_request($port,array('params'=>json_encode(array('__conduit__'=>array('token'=>$token->getToken()))),'output'=>'json'));
      $value=$response['value'];$outcome='unknown';$code='unknown';
      if($response['status']===200&&is_array($value)&&array_key_exists('error_code',$value)&&array_key_exists('result',$value)){
        if($value['error_code']===null&&$row&&is_array($value['result'])&&hash('sha256',$value['result']['phid']??'')===$owner){$outcome='allowed';$code='native-success';}
        else if($row===null&&$value['error_code']==='ERR-INVALID-AUTH'&&$value['result']===null){$outcome='denied';$code='ERR-INVALID-AUTH';}}
      if($parse_control){$bad=governance_native_measurement_http_request($port,array('params'=>'{','output'=>'json'));
        $control_seen=$bad['status']===200&&($bad['value']['error_code']??null)==='ERR-CONDUIT-CALL';
        if(!$control_seen){throw new RuntimeException('measurement_http_unknown_control_failed');}}
      return array('start_ns'=>$begin,'outcome'=>$outcome,'code'=>$code,'source_binding'=>$binding);
    }finally{
      if(is_resource($process)){
        if(proc_get_status($process)['running']){proc_terminate($process);$deadline=microtime(true)+2;
          while(proc_get_status($process)['running']&&microtime(true)<$deadline){usleep(20000);}
          if(proc_get_status($process)['running']){proc_terminate($process,9);}}
        proc_close($process);$stopped++;
      }
      $now=lstat($directory);if(!$now||is_link($directory)||$now['dev']!==$stat['dev']||$now['ino']!==$stat['ino']||
        !hash_equals($nonce,file_get_contents($directory.'/owner'))){throw new RuntimeException('measurement_http_cleanup_owner_refused');}
      foreach(array('owner','router.json','stdout.log','stderr.log')as$name){$file=$directory.'/'.$name;
        if(is_link($file)||!unlink($file)){throw new RuntimeException('measurement_http_cleanup_failed');}}
      if(!rmdir($directory)){throw new RuntimeException('measurement_http_cleanup_failed');}$removed++;
    }
  };
  try{
    $before=$probe(true);$before['finish_ns']=hrtime(true);
    $check($before['outcome']==='allowed'&&$before['code']==='native-success','n12-conduit-http-independent-standard-token-pretransition-allow');
    $check($control_seen,'n12-conduit-http-native-parse-error-is-unknown-not-auth-denial');
    if($conn->isInsideTransaction()||(new PhabricatorUser())->establishConnection('w')->isInsideTransaction()){
      throw new RuntimeException('measurement_http_outer_transaction_refused');}
    $begin=hrtime(true);$conn->openTransaction();$owned=true;$token->delete();$affected=$conn->getAffectedRows();
    if($affected!==1){throw new RuntimeException('measurement_http_single_delete_required');}$conn->saveTransaction();$owned=false;$ack=hrtime(true);
    list($absent,$absent_binding)=$source();
    $check($absent===null&&!$conn->isInsideTransaction(),'n12-conduit-http-owned-token-delete-commit-and-absence-proof');
    $after=$probe();$after['finish_ns']=hrtime(true);$repeat=$probe();$repeat['finish_ns']=hrtime(true);
    $check($after['outcome']==='denied'&&$repeat['outcome']==='denied'&&$after['source_binding']===$absent_binding&&$repeat['source_binding']===$absent_binding,
      'n12-conduit-http-fresh-postcommit-native-auth-denial');
    $check($before['finish_ns']<=$begin&&$ack<=$after['start_ns']&&$after['finish_ns']<=$repeat['start_ns'],
      'n12-conduit-http-serial-parent-clock-same-boot-source-binding');
    $check($started===3&&$stopped===3&&$removed===3,'n12-conduit-http-three-owned-servers-reaped-and-private-directories-removed');
    $transition=hash('sha256',phutil_json_encode(array(bin2hex(random_bytes(32)),$owner,$id,$before['source_binding'],$absent_binding)));
    $attempts=array();foreach(array($before,$after,$repeat)as$i=>$sample){$attempts[]=array('id'=>$i+1,'transition_id'=>$transition,
      'boot_id_sha256'=>$boot_hash,'start_ns'=>$sample['start_ns'],'finish_ns'=>$sample['finish_ns'],'outcome'=>$sample['outcome']);}
    return array('profile'=>'native-conduit-http-token-revocation-only','HTTP_proved'=>true,'gateway_proved'=>false,'long_connection_proved'=>false,
      'SLA_proved'=>false,'convergence_proved'=>false,'actual_Conduit_HTTP_requests'=>4,'actual_independent_HTTP_processes'=>3,
      'native_parse_error_control_observed'=>true,'token_rows_deleted'=>1,'native_transaction_record_available'=>false,
      'server_cleanup'=>array('started'=>$started,'stopped'=>$stopped,'directories_removed'=>$removed,'remaining'=>0,'live'=>0),
      'router_sha256'=>$router_hash,'before_source_binding_sha256'=>$before['source_binding'],'after_source_binding_sha256'=>$absent_binding,
      'event'=>array('schema'=>3,'source_revision'=>$revision,'producer_sha256'=>hash_file('sha256',__FILE__),'family'=>'api-token',
        'clock'=>array('kind'=>'monotonic_ns','boot_id_sha256'=>$boot_hash),'transition'=>array('id'=>$transition,'action'=>'revoke',
          'operation_binding_sha256'=>$transition,'boundary'=>'native-token-dao-delete-http-new-requests','operation_start_ns'=>$begin,'commit_confirmed_return_ns'=>$ack),
        'attempts'=>$attempts));
  }finally{
    if($owned){$conn->killTransaction();}$left=(new PhabricatorConduitToken())->load($id);
    if($left){if($left->getObjectPHID()!==$user->getPHID()||!hash_equals($left->getToken(),$token->getToken())){throw new RuntimeException('measurement_http_token_cleanup_refused');}$left->delete();}
  }
}
