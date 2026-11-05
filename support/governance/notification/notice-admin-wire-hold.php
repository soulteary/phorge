<?php
/** Real producer only. The parent must run relay/daemon/SMTP, not this process. */
function governance_notice_admin_wire_hold($fixture,callable $check){
  global $control;
  $fixture->assertOwned();$directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');$owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');
  if(!is_string($directory)||is_link($directory)||realpath($directory)!==$directory||
    realpath(dirname($directory))!==realpath(sys_get_temp_dir())||!preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/D',basename($directory))||
    (fileperms($directory)&0777)!==0700||!is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/D',$owner)||
    !is_file($directory.'/owner')||is_link($directory.'/owner')||(fileperms($directory.'/owner')&0777)!==0600||
    !hash_equals($owner,file_get_contents($directory.'/owner'))){throw new RuntimeException('notice_wire_parent_refused');}
  require_once __DIR__.'/notice-wire-config.php';
  $case=governance_notice_wire_case(getenv('PHORGE_GOVERNANCE_NOTICE_WIRE_CASE'));
  if(!in_array($case,array('allow','admin-revoked'),true)){throw new RuntimeException('notice_admin_wire_case_refused');}
  if(getenv('PHORGE_GOVERNANCE_OWLMAIL_RELEASE')!=='v0.14.0'){throw new RuntimeException('notice_wire_stable_capture_required');}
  $ports=array();foreach(array('HTTP','MAILER')as$key){$v=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$key.'_PORT');
    if(!is_string($v)||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$v)||(int)$v>65535){throw new RuntimeException('notice_wire_port_refused');}$ports[$key]=(int)$v;}
  if($ports['HTTP']===$ports['MAILER']){throw new RuntimeException('notice_wire_ports_conflict');}
  $tokens=array();foreach(array('MAILER_TOKEN','CALLBACK_TOKEN')as$key){$v=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$key);
    if(!is_string($v)||!preg_match('/\A[a-f0-9]{64}\z/D',$v)){throw new RuntimeException('notice_wire_token_refused');}$tokens[$key]=$v;}
  $lease=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS');
  if(!is_string($lease)||!preg_match('/\A[1-9][0-9]{2,3}\z/D',$lease)||(int)$lease<120||(int)$lease>1800){throw new RuntimeException('notice_wire_lease_refused');}
  $write=static function($name,array$value)use($directory){
    if(!in_array($name,array('router.json','notice.json','notice-control.json','ready.json'),true)||file_exists($directory.'/'.$name)){
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
      ->setIsAdmin(0)->setIsApproved(1)->setIsEmailVerified(1)->save();
    $email=(new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $user_writer=$user->establishConnection('w');
    $auth=governance_native_request($user,new PhabricatorAuthSessionEngine());
    $operator=(new PhabricatorUser())->loadOneWhere('phid=%s',$fixture->getBaselineIDs()['approver']);
    if(!$operator||!$operator->getIsAdmin()||$operator->loadPrimaryEmail()){throw new RuntimeException('notice_wire_native_operator_refused');}
    $operator_auth=governance_native_request($operator,new PhabricatorAuthSessionEngine());
    // The actual native producer is a real authenticated administrator grant.
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$operator_auth['token'];
    $grant=static function()use($user,$operator_auth){
      $target=(new PhabricatorUser())->load($user->getID());
      (new PhabricatorUserTransactionEditor())->setActor($operator_auth['viewer'])
        ->setContentSourceFromRequest($operator_auth['request'])->setCancelURI('/people/')->setContinueOnNoEffect(true)
        ->applyTransactions($target,array((new PhabricatorUserTransaction())
          ->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(true)));
    };
    $grant();
    $meta=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
    $xaction=queryfx_one($user_writer,'SELECT id,phid,objectPHID,authorPHID,transactionType,oldValue,newValue,dateCreated '.
      'FROM %R WHERE objectPHID=%s AND transactionType=%s ORDER BY id DESC LIMIT 1',new PhabricatorUserTransaction(),
      $user->getPHID(),PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE);
    if(!$xaction||$xaction['authorPHID']!==$operator->getPHID()){throw new RuntimeException('notice_admin_wire_actual_native_source_missing');}
    $value=PhorgeGovernanceNoticeEventValue::fromAudit(PhorgeGovernanceNoticeSource::nativeAdminGrantAudit($xaction));
    $event=queryfx_one($meta,'SELECT eventID,state FROM governance_notice_event WHERE eventID=%s',$value['event_id']);
    if(!$event||$event['state']!=='pending'){throw new RuntimeException('notice_admin_wire_pending_event_missing');}
    $relay=queryfx_one($meta,'SELECT eventID,deliveredEpoch FROM metamta_gorgeoutbox WHERE eventID=%s','governance.notice/'.$event['eventID'].'/1');
    $primary=queryfx_one($user_writer,'SELECT CONNECTION_ID() thread,@@server_uuid uuid');
    $control->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $observer=$control->query('SELECT CONNECTION_ID() thread,@@server_uuid uuid,@@autocommit ac')->fetch_assoc();
    if((string)$observer['thread']===(string)$primary['thread']||$observer['uuid']!==$primary['uuid']||
      !in_array($observer['ac'],array(1,'1'),true)){throw new RuntimeException('notice_admin_wire_commit_observer_refused');}
    $observe=$control->prepare('SELECT u.isAdmin,x.phid,e.eventID FROM '.$fixture->table('user','user').' u JOIN '.
      $fixture->table('user','user_transaction').' x ON x.objectPHID=u.phid JOIN '.$fixture->table('metamta','governance_notice_event').
      ' e ON e.sourceID=x.id AND e.sourceKind=? WHERE u.id=? AND x.phid=? AND e.eventID=?');
    $kind='native-admin-grant';$id=$user->getID();$phid=$xaction['phid'];$event_id=$event['eventID'];$observe->bind_param('siss',$kind,$id,$phid,$event_id);
    try{$observe->execute();$committed=$observe->get_result()->fetch_all(MYSQLI_ASSOC);}finally{$observe->close();}
    $independent=count($committed)===1&&in_array($committed[0]['isAdmin'],array(1,'1'),true)&&$committed[0]['phid']===$phid&&$committed[0]['eventID']===$event_id;
    $check($independent&&!$user_writer->isInsideTransaction()&&$relay!==null&&$relay['deliveredEpoch']===null&&
      (int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_delivery')['n']===0,
      'notice-admin-wire-real-native-USER-grant-and-notice-commit-before-any-daemon-or-SMTP');
    $native_count=(int)queryfx_one($user_writer,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorUserTransaction(),$user->getPHID())['n'];
    $grant();
    $check((int)queryfx_one($meta,'SELECT COUNT(*) n FROM governance_notice_event')['n']===1&&
      (int)queryfx_one($user_writer,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s',new PhabricatorUserTransaction(),$user->getPHID())['n']===$native_count,
      'notice-admin-wire-real-native-noeffect-keeps-one-USER-source-and-event');
    $destinations=queryfx_all($user_writer,'SELECT u.phid FROM %R u JOIN %R e ON e.userPHID=u.phid '.
      'WHERE u.isAdmin=1 AND u.isDisabled=0 AND u.isApproved=1 AND u.isSystemAgent=0 AND u.isMailingList=0 '.
      'AND e.isPrimary=1 AND e.isVerified=1 ORDER BY u.phid LIMIT 129',new PhabricatorUser(),new PhabricatorUserEmail());
    if(count($destinations)!==1||$destinations[0]['phid']!==$user->getPHID()){
      throw new RuntimeException('notice_wire_single_native_destination_refused');}
    $config=array('owner'=>$owner,'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
      'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'writer'=>$fixture->getWriterConfig(),
      'callback_origin'=>$origin,'service_token'=>$tokens['CALLBACK_TOKEN'],'mailer_uri'=>$mailer,'mailer_token'=>$tokens['MAILER_TOKEN'],
      'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    $write('router.json',$config);
    $write('notice.json',array('owner'=>$owner,'case'=>$case,'queue_uri'=>$queue['uri'],'queue_token'=>$queue['token'],'stable_capture_version'=>'v0.14.0'));
    $write('notice-control.json',array('owner'=>$owner,'actor_phid'=>$user->getPHID(),'email_phid'=>$email->getPHID(),
      'operator_phid'=>$operator->getPHID(),'operator_session'=>$operator_auth['token'],'event_id'=>$event['eventID'],
      'native_transaction_phid'=>$xaction['phid'],'source_kind'=>'native-admin-grant'));
    $write('ready.json',array('scope'=>'private-owned-native-admin-notice-pipeline','owner'=>$owner,'case'=>$case,
      'event_id'=>$event['eventID'],'relay_event_id'=>$relay['eventID'],'callback_origin'=>$origin,
      'queue'=>array('uri'=>$queue['uri'],'token'=>$queue['token']),
      'mailer_database'=>$fixture->createPrivateMailerPrincipal(),'relay_database'=>$fixture->createPrivateNoticeRelayPrincipal(),
      'smtp_port'=>$sink['smtp_port'],'capture_uri'=>$sink['uri'],'capture_authorization'=>$sink['authorization'],
      'capture_binary_sha256'=>$sink['binary_sha256'],'capture_version_required'=>'v0.14.0',
      'expected_destination'=>$email->getAddress(),'native_source_only'=>true,'native_source_kind'=>'native-admin-grant','real_delivery_proved_by_preparation'=>false));
    $check(true,'notice-wire-owned-parent-manifest-ready-before-any-daemon-or-mail-prepare');
    $deadline=microtime(true)+(int)$lease;$released=false;
    do{$fixture->assertOwned();$release=$directory.'/release';
      if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&hash_equals($owner,file_get_contents($release))){$released=true;break;}
      usleep(200000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('notice_wire_parent_lease_expired');}
    $check(true,'notice-wire-parent-release-observed');
    $result=array('actual_native_admin_grant_source'=>true,'independent_committed_observer'=>true,'committed_notice_events'=>1,'source_only_preparation'=>true,'automatic_relay_proved'=>false,
      'Go_daemon_proved'=>false,'SMTP_proved'=>false,'all_governance_producers_complete'=>false,'parent_release_observed'=>true);
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
