<?php
/** Real producer only. The parent must run relay/daemon/SMTP, not this process. */
function governance_notice_wire_hold($fixture,callable $check,?callable $prepare_browser=null){
  $fixture->assertOwned();$directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');$owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');
  if(!is_string($directory)||is_link($directory)||realpath($directory)!==$directory||
    realpath(dirname($directory))!==realpath(sys_get_temp_dir())||!preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))||
    (fileperms($directory)&0777)!==0700||!is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)||
    !is_file($directory.'/owner')||is_link($directory.'/owner')||(fileperms($directory.'/owner')&0777)!==0600||
    !hash_equals($owner,file_get_contents($directory.'/owner'))){throw new RuntimeException('notice_wire_parent_refused');}
  require_once __DIR__.'/notice-wire-config.php';
  $case=governance_notice_wire_case(getenv('PHORGE_GOVERNANCE_NOTICE_WIRE_CASE'));
  if(getenv('PHORGE_GOVERNANCE_OWLMAIL_RELEASE')!=='v0.14.0'){throw new RuntimeException('notice_wire_stable_capture_required');}
  $ports=array();foreach(array('HTTP','MAILER')as$key){$v=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$key.'_PORT');
    if(!is_string($v)||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$v)||(int)$v>65535){throw new RuntimeException('notice_wire_port_refused');}$ports[$key]=(int)$v;}
  if($ports['HTTP']===$ports['MAILER']){throw new RuntimeException('notice_wire_ports_conflict');}
  $tokens=array();foreach(array('MAILER_TOKEN','CALLBACK_TOKEN')as$key){$v=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$key);
    if(!is_string($v)||!preg_match('/\A[a-f0-9]{64}\z/D',$v)){throw new RuntimeException('notice_wire_token_refused');}$tokens[$key]=$v;}
  $lease=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS');
  if(!is_string($lease)||!preg_match('/\A[1-9][0-9]{2,3}\z/D',$lease)||(int)$lease<120||(int)$lease>1800){throw new RuntimeException('notice_wire_lease_refused');}
  $write=static function($name,array$value)use($directory){
    if(!in_array($name,array('router.json','notice.json','notice-control.json','ready.json','history-browser.json','history-queue-quiesced.json'),true)||file_exists($directory.'/'.$name)){
      throw new RuntimeException('notice_wire_manifest_exists');}
    $path=$directory.'/.notice-write-'.bin2hex(random_bytes(12));$stream=fopen($path,'x');
    if(!$stream){throw new RuntimeException('notice_wire_manifest_refused');}
    try{if(!chmod($path,0600)){throw new RuntimeException('notice_wire_manifest_refused');}
      $raw=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
      if(fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)){throw new RuntimeException('notice_wire_manifest_refused');}
      fclose($stream);$stream=null;if(!rename($path,$directory.'/'.$name)){throw new RuntimeException('notice_wire_manifest_refused');}
    }finally{if(is_resource($stream)){fclose($stream);}if(is_file($path)){unlink($path);}}
  };
  require_once dirname(__DIR__).'/completion/pipeline-gorge.php';require_once __DIR__.'/owlmail-sink.php';
  require_once dirname(__DIR__).'/native-organization/native-cases.php';
  $queue=null;$sink=null;$scope=null;$cleanup=array();$failure=null;$result=null;$cleanup_failed=false;
  try{
    if((new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){throw new RuntimeException('notice_wire_inprocess_workers_refused');}
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);$sink=governance_private_mail_start_sink($fixture,$check);
    $origin='http://127.0.0.1:'.$ports['HTTP'];$mailer='http://127.0.0.1:'.$ports['MAILER'];
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled',true)
      ->overrideEnvConfig('governance.organization.enabled',true)->overrideEnvConfig('governance.account-recovery.enabled',false)
      ->overrideEnvConfig('governance.recovery-stop-ledger.enabled',false)->overrideEnvConfig('governance.private-mail.enabled',true)
      ->overrideEnvConfig('metamta.gorge-delivery-mode','native')->overrideEnvConfig('gorge.taskqueue.owner','gorge')
      ->overrideEnvConfig('gorge.taskqueue.uri',$queue['uri'])->overrideEnvConfig('gorge.taskqueue.token',$queue['token'])
      ->overrideEnvConfig('gorge.conduit.uri',$origin)->overrideEnvConfig('gorge.conduit.token',$tokens['CALLBACK_TOKEN'])
      ->overrideEnvConfig('gorge.service-policies',array('file'=>'required','taskqueue'=>'required','mailer'=>'required','conduit'=>'required'))
      ->overrideEnvConfig('cluster.mailers',array(array('key'=>'isolated-notice','type'=>'gorge','inbound'=>false,
        'options'=>array('uri'=>$mailer,'token'=>$tokens['MAILER_TOKEN']))));
    $user=(new PhabricatorUser())->setUsername('gnwire'.substr($fixture->getNamespace(),-12))->setRealName('Synthetic notice recipient')
      ->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
    $email=(new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $browser=$prepare_browser===null?null:$prepare_browser($fixture,$user,$check);
    $user_writer=$user->establishConnection('w');
    $destinations=queryfx_all($user_writer,'SELECT u.phid FROM %R u JOIN %R e ON e.userPHID=u.phid '.
      'WHERE u.isAdmin=1 AND u.isDisabled=0 AND u.isApproved=1 AND u.isSystemAgent=0 AND u.isMailingList=0 '.
      'AND e.isPrimary=1 AND e.isVerified=1 ORDER BY u.phid LIMIT 129',new PhabricatorUser(),new PhabricatorUserEmail());
    if(count($destinations)!==1||$destinations[0]['phid']!==$user->getPHID()){
      throw new RuntimeException('notice_wire_single_native_destination_refused');}
    $auth=governance_native_request($user,new PhabricatorAuthSessionEngine());
    $operator=(new PhabricatorUser())->loadOneWhere('phid=%s',$fixture->getBaselineIDs()['approver']);
    if(!$operator||!$operator->getIsAdmin()||$operator->loadPrimaryEmail()){throw new RuntimeException('notice_wire_native_operator_refused');}
    $operator_auth=governance_native_request($operator,new PhabricatorAuthSessionEngine());
    $input=array('operation_id'=>'notice-wire-unit','unit_id'=>'notice-wire-unit','kind'=>'team',
      'name'=>'SYNTHETIC_PRIVATE_NOTICE_UNIT','parent_id'=>null);
    // Restore the exact creator cookie: another real session was loaded above.
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$auth['token'];
    $service=PhorgeOrganizationService::newForActor($auth['context']);
    if($case==='source-rollback'){
      $trigger='`'.$fixture->getNamespace().'_user`.`notice_wire_owned_fault`';$installed=false;
      try{
        governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('user','organization_operation').
          " FOR EACH ROW BEGIN IF NEW.operationID='notice-wire-unit' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-notice-wire-source-fault'; END IF; END");$installed=true;
        $fault=false;
        try{$service->createUnit($input);}catch(AphrontQueryException$error){
          if($error->getCode()!==1644||!str_contains($error->getMessage(),'owned-notice-wire-source-fault')){throw$error;}$fault=true;}
        $meta_before=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
        $counts=array();
        foreach(array('organization_unit','organization_audit','organization_operation')as$table){
          $column=$table==='organization_unit'?'unitID':'operationID';
          $counts[]=(int)queryfx_one($user_writer,'SELECT COUNT(*) n FROM %T WHERE %T=%s',$table,$column,'notice-wire-unit')['n'];}
        foreach(array('governance_notice_event','governance_notice_delivery','metamta_gorgeoutbox')as$table){
          $counts[]=(int)queryfx_one($meta_before,'SELECT COUNT(*) n FROM %T',$table)['n'];}
        $check($fault&&$counts===array(0,0,0,0,0,0)&&!$user_writer->isInsideTransaction(),
          'notice-wire-real-post-outbox-source-rollback-keeps-no-event-relay-or-mail-before-any-daemon');
      }finally{if($installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}}
    }
    $receipt=$service->createUnit($input);
    $meta=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
    $event=queryfx_one($meta,'SELECT eventID,state FROM governance_notice_event WHERE operationDigest=%s',hash('sha256',$input['operation_id']));
    if(!$event||$event['state']!=='pending'||$receipt['replayed']!==false){throw new RuntimeException('notice_wire_native_event_missing');}
    $relay=queryfx_one($meta,'SELECT eventID,deliveredEpoch FROM metamta_gorgeoutbox WHERE eventID=%s','governance.notice/'.$event['eventID'].'/1');
    $check($relay!==null&&$relay['deliveredEpoch']===null&&(int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_delivery')['n']===0,
      'notice-wire-real-source-commit-has-only-unrelayed-event-no-native-handler-or-SMTP');
    // Same native operation replay must not append another receipt or relay.
    $again=$service->createUnit($input);
    $check($again['replayed']===true&&(int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_event')['n']===1,
      'notice-wire-source-operation-replay-keeps-single-committed-event');
    $config=array('owner'=>$owner,'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
      'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'writer'=>$fixture->getWriterConfig(),
      'callback_origin'=>$origin,'service_token'=>$tokens['CALLBACK_TOKEN'],'mailer_uri'=>$mailer,'mailer_token'=>$tokens['MAILER_TOKEN'],
      'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    $write('router.json',$config);
    $write('notice.json',array('owner'=>$owner,'case'=>$case,'queue_uri'=>$queue['uri'],'queue_token'=>$queue['token'],'stable_capture_version'=>'v0.14.0'));
    $write('notice-control.json',array('owner'=>$owner,'actor_phid'=>$user->getPHID(),'email_phid'=>$email->getPHID(),
      'operator_phid'=>$operator->getPHID(),'operator_session'=>$operator_auth['token'],'event_id'=>$event['eventID'],
      'operation_id'=>$input['operation_id'],'unit_id'=>$input['unit_id']));
    if($browser!==null){
      // Only this new native-browser profile waits for independent all-writer SQL0 before release.
      $GLOBALS['governance_workbench_http_cleanup_permitted']=false;LiskDAO::closeAllConnections();
      $closed=$fixture->closeOwnedWriterConnectionForHTTP();
      $check($closed['owned_fixture_writer_connection_closed']===true&&$closed['exact_original_thread_absent']===true,
        'notice-history-independent-fixture-writer-closed-before-browser-ready');
      $browser['fixture_writer_close_proof']=$closed;
      $write('history-browser.json',$browser+array('owner'=>$owner,'event_id'=>$event['eventID'],
        'history_path'=>'/people/organization/history/','origin'=>$origin));
    }
    $write('ready.json',array('scope'=>'private-owned-governance-notice-pipeline','owner'=>$owner,'case'=>$case,
      'event_id'=>$event['eventID'],'relay_event_id'=>$relay['eventID'],'callback_origin'=>$origin,
      'queue'=>array('uri'=>$queue['uri'],'token'=>$queue['token']),
      'mailer_database'=>$fixture->createPrivateMailerPrincipal(),'relay_database'=>$fixture->createPrivateNoticeRelayPrincipal(),
      'smtp_port'=>$sink['smtp_port'],'capture_uri'=>$sink['uri'],'capture_authorization'=>$sink['authorization'],
      'capture_binary_sha256'=>$sink['binary_sha256'],'capture_version_required'=>'v0.14.0',
      'expected_destination'=>$email->getAddress(),'native_source_only'=>true,'real_delivery_proved_by_preparation'=>false));
    $check(true,'notice-wire-owned-parent-manifest-ready-before-any-daemon-or-mail-prepare');
    $deadline=microtime(true)+(int)$lease;$released=false;$history_queue_quiesced=false;
    do{$fixture->assertOwned();
      if($browser!==null&&!$history_queue_quiesced&&(file_exists($directory.'/history-queue-quiesce')||is_link($directory.'/history-queue-quiesce'))){
        governance_notice_history_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$write);$history_queue_quiesced=true;
      }
      $release=$directory.'/release';
      if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&hash_equals($owner,file_get_contents($release))){
        if($browser!==null){
          if($history_queue_quiesced!==true){throw new RuntimeException('notice_history_queue_not_quiesced');}
          $writer=$fixture->getWriterConfig();$query=$GLOBALS['control']->prepare('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?');
          $name=$writer['user'];$query->bind_param('s',$name);try{$query->execute();$remaining=$query->get_result()->fetch_assoc();}finally{$query->close();}
          if(!is_array($remaining)||!in_array($remaining['n'],array(0,'0'),true)){throw new RuntimeException('notice_history_writer_SQL_not_zero');}
          $GLOBALS['governance_workbench_http_cleanup_permitted']=true;
        }
        $released=true;break;
      }
      usleep(200000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('notice_wire_parent_lease_expired');}
    $check(true,'notice-wire-parent-release-observed');
    $result=array('committed_notice_events'=>1,'source_only_preparation'=>true,'automatic_relay_proved'=>false,
      'Go_daemon_proved'=>false,'SMTP_proved'=>false,'all_governance_producers_complete'=>false,'parent_release_observed'=>true);
    if($browser!==null){$result['queue_quiesced_before_parent_release']=$history_queue_quiesced;}
  }catch(Throwable$error){$failure=$error;}
  finally{
    unset($scope);
    foreach(array('queue'=>&$queue,'capture'=>&$sink)as$name=>&$service){if($service!==null){
      try{$cleanup[$name]=governance_completion_stop_gorge($service);
        if(!$cleanup[$name]['process_stopped']||!$cleanup[$name]['private_storage_removed']){$cleanup_failed=true;}}
      catch(Throwable$error){$cleanup_failed=true;}}}unset($service);
  }
  if($failure!==null){throw$failure;}if($cleanup_failed){throw new RuntimeException('notice_wire_owned_cleanup_failed');}
  $result['owned_service_cleanup']=$cleanup;return$result;
}
