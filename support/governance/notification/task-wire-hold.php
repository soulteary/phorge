<?php
/** Enqueue a genuine native Task; only actual Gorge consumers may publish/send. */
function governance_task_wire_hold($fixture,callable $check,$browser_mode=false){
  if(!is_bool($browser_mode)){throw new RuntimeException('task_browser_mode_refused');}
  $fixture->assertOwned();PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  require_once __DIR__.'/task-wire-producer.php';require_once __DIR__.'/task-wire-protocol.php';$directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');$owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');
  if(!is_string($directory)||is_link($directory)||realpath($directory)!==$directory||
    realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/',basename($directory))||(fileperms($directory)&0777)!==0700||
    !is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/',$owner)||!is_file($directory.'/owner')||is_link($directory.'/owner')||
    (fileperms($directory.'/owner')&0777)!==0600||file_get_contents($directory.'/owner')!==$owner){throw new RuntimeException('private_mail_parent_scope_refused');}
  $ports=array();foreach(array('HTTP','MAILER')as$name){$value=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$name.'_PORT');
    if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,4}\z/',$value)||(int)$value>65535){throw new RuntimeException('private_mail_parent_port_refused');}
    $ports[$name]=(int)$value;}
  if($ports['HTTP']===$ports['MAILER']){throw new RuntimeException('private_mail_parent_ports_conflict');}
  $tokens=array();foreach(array('MAILER_TOKEN','CALLBACK_TOKEN')as$name){$value=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$name);
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/',$value)){throw new RuntimeException('private_mail_parent_token_refused');}$tokens[$name]=$value;}
  $lease=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS');$seconds=$lease===false||$lease===''?900:(int)$lease;
  if(($lease!==false&&$lease!==''&&!preg_match('/\A[1-9][0-9]{2,3}\z/',$lease))||$seconds<120||$seconds>1800){throw new RuntimeException('private_mail_parent_lease_refused');}
  governance_task_wire_parent($directory,$owner);
  require_once dirname(__DIR__).'/completion/pipeline-gorge.php';require_once __DIR__.'/owlmail-sink.php';
  $queue=null;$sink=null;$scope=null;$failure=null;$cleanup=array();$cleanup_failed=false;$result=null;
  try{
    if((new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){throw new RuntimeException('private_mail_background_delivery_refused');}
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);$sink=governance_private_mail_start_sink($fixture,$check);
    $mailer_uri='http://127.0.0.1:'.$ports['MAILER'];$callback_origin='http://127.0.0.1:'.$ports['HTTP'];
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.private-mail.enabled',true)
      ->overrideEnvConfig('governance.organization.enabled',false)->overrideEnvConfig('metamta.one-mail-per-recipient',true)->overrideEnvConfig('metamta.default-address','void@example.invalid')
      ->overrideEnvConfig('phabricator.base-uri',$callback_origin.'/')->overrideEnvConfig('phabricator.production-uri',$callback_origin.'/')->overrideEnvConfig('governance.media-exchange.enabled',false)
      ->overrideEnvConfig('metamta.gorge-delivery-mode','native')->overrideEnvConfig('gorge.taskqueue.owner','gorge')
      ->overrideEnvConfig('gorge.taskqueue.uri',$queue['uri'])->overrideEnvConfig('gorge.taskqueue.token',$queue['token'])
      ->overrideEnvConfig('gorge.service-policies',array('file'=>'required','taskqueue'=>'required','mailer'=>'required'))
      ->overrideEnvConfig('cluster.mailers',array(array('key'=>'isolated-physical','type'=>'gorge','inbound'=>false,
        'options'=>array('uri'=>$mailer_uri,'token'=>$tokens['MAILER_TOKEN']))));
    $check(is_array((new PhabricatorGorgeTaskQueueClient())->getStats()),'private-mail-physical-owned-real-queue-ready-no-workers');
    $users=array();foreach(array('author','recipient')as$role){$user=(new PhabricatorUser())
      ->setUsername('pmwire'.$role.substr($fixture->getNamespace(),-10))->setRealName('Synthetic Private Mail '.$role)
      ->setIsApproved(1)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      $users[$role]=$user;}
    // This synthetic author must not add a second, native self-action copy.
    $self_key=PhabricatorEmailSelfActionsSetting::SETTINGKEY;
    $preferences=PhabricatorUserPreferences::loadUserPreferences($users['author']);
    (new PhabricatorUserPreferencesEditor())->setActor($users['author'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)
      ->applyTransactions($preferences,array($preferences->newTransaction(
        $self_key,PhabricatorEmailSelfActionsSetting::VALUE_NO_SELF)));
    $persisted=(new PhabricatorUserPreferences())->loadOneWhere(
      'userPHID=%s',$users['author']->getPHID());
    if(!$persisted||$persisted->getPreference($self_key)!==PhabricatorEmailSelfActionsSetting::VALUE_NO_SELF){
      throw new RuntimeException('task_wire_synthetic_author_no_self_mail_not_persisted');}
    $enqueued=governance_task_wire_enqueue($fixture,$check,$queue,$sink,$users['author'],$users['recipient']);
    $mailer_config=$fixture->createPrivateMailerPrincipal();
    $relay_config=$fixture->createPrivateNoticeRelayPrincipal();
    $writer=$fixture->getWriterConfig();$config=array('owner'=>$owner,'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
      'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'writer'=>$writer,
      'callback_origin'=>$callback_origin,'service_token'=>$tokens['CALLBACK_TOKEN'],'mailer_uri'=>$mailer_uri,'mailer_token'=>$tokens['MAILER_TOKEN'],
      'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    governance_task_wire_publish($directory,$owner,'router.json',$config);
    governance_task_wire_publish($directory,$owner,'queue.json',array('owner'=>$owner,'uri'=>$queue['uri'],'token'=>$queue['token']));
    governance_task_wire_publish($directory,$owner,'ready.json',array('scope'=>'owned-native-queued-task-mail','owner'=>$owner,
      'publish_task_id'=>$enqueued['worker_id'],'task_phid'=>$enqueued['task_phid'],'task_path'=>$enqueued['task']->getURI(),
      'void_address'=>(new PhabricatorMailEmailEngine())->newVoidEmailAddress()->getAddress(),'recipient_address'=>$users['recipient']->loadPrimaryEmailAddress(),'recipient_phid'=>$users['recipient']->getPHID(),
      'mailer_database'=>$mailer_config,'relay_database'=>$relay_config,'queue_uri'=>$queue['uri'],'queue_token'=>$queue['token'],
      'callback_origin'=>$callback_origin,'smtp_port'=>$sink['smtp_port'],'capture_uri'=>$sink['uri'],
      'capture_authorization'=>$sink['authorization'],'capture_binary_sha256'=>$sink['binary_sha256'],
      'manual_native_publication'=>false,'SMTP_proved'=>false));
    $sequence=1;$revoked=false;$browser_prepared=false;$browser_queue_quiesced=false;$browser_writer_closed=false;
    $deadline=microtime(true)+$seconds;$released=false;
    do{$fixture->assertOwned();
      if($sequence<=64){$action=governance_task_wire_command($directory,$owner,$sequence,$browser_mode);
        if($action!==null){
          if($action==='browser-prepare'){
            if(!$browser_mode||$browser_prepared){throw new RuntimeException('task_browser_prepare_replay');}
            require_once __DIR__.'/task-wire-browser.php';
            $browser=governance_task_browser_prepare($fixture,$enqueued,$users['recipient'],$check,$directory,$owner,$callback_origin);
            governance_task_wire_publish($directory,$owner,'task-browser-native.json',$browser);$browser_prepared=true;$browser_writer_closed=true;
            governance_task_wire_publish($directory,$owner,'task-observation-'.$sequence.'.json',array('owner'=>$owner,'sequence'=>$sequence,
              'publish_task_id'=>$enqueued['worker_id'],'manual_native_publication'=>false,'browser_prepared'=>true));$sequence++;
          }else{
          if($browser_prepared){throw new RuntimeException('task_browser_native_control_after_seal_refused');}
          if($action==='revoke'){if($revoked){throw new RuntimeException('task_wire_revoke_replay');}
            governance_task_wire_revoke($fixture,$enqueued,$users['author'],$users['recipient']);$revoked=true;}
          $bindings=governance_task_wire_collect($fixture,$enqueued,$users['recipient']);$rows=array();
          foreach($bindings as$binding){$mail=(new PhabricatorMetaMTAMail())->load($binding['mail_id']);
            $snapshot=(new ReflectionMethod(PhabricatorMetaMTAMail::class,'getParam'))->invoke($mail,'gorge.delivery-snapshot');
            $outbox=(new PhabricatorMetaMTAGorgeOutbox())->loadOneWhere('eventID=%s',$binding['relay_event_id']);
            if(!$outbox){throw new RuntimeException('task_wire_native_outbox_missing');}
            $lineage=governance_task_wire_worker_evidence($binding,$outbox,$snapshot);
            $rows[]=array('worker_lineage'=>$lineage,'binding'=>$binding,'snapshot'=>$snapshot,'mail_status'=>$mail->getStatus(),
              'outbox_queue_task_id'=>$outbox->getQueueTaskID(),'outbox_delivered_epoch'=>$outbox->getDeliveredEpoch());}
          $archive=(new PhabricatorWorkerArchiveTask())->load($enqueued['worker_id']);
          governance_task_wire_publish($directory,$owner,'task-observation-'.$sequence.'.json',array(
            'owner'=>$owner,'sequence'=>$sequence,'revoked'=>$revoked,'mails'=>$rows,
            'history'=>governance_task_wire_history($fixture,$enqueued,$users['recipient']),
            'publish_task_id'=>$enqueued['worker_id'],'publish_archive_result'=>$archive?(int)$archive->getResult():null,
            'manual_native_publication'=>false));$sequence++;
          }
        }}
      if($browser_mode&&!$browser_queue_quiesced&&(file_exists($directory.'/history-queue-quiesce')||is_link($directory.'/history-queue-quiesce'))){
        if(!$browser_writer_closed){
          require_once __DIR__.'/task-wire-browser.php';governance_task_browser_no_transactions();LiskDAO::closeAllConnections();
          $closed=$fixture->closeOwnedWriterConnectionForHTTP();
          if(($closed['owned_fixture_writer_connection_closed']??null)!==true||($closed['exact_original_thread_absent']??null)!==true){throw new RuntimeException('task_browser_abort_writer_unknown');}
          $browser_writer_closed=true;
        }
        require_once __DIR__.'/notice-history-browser.php';
        $write=static function($name,array$data)use($directory,$owner){governance_task_wire_publish($directory,$owner,$name,$data);};
        governance_notice_history_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$write);$browser_queue_quiesced=true;
      }
      $release=$directory.'/release';
      if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&file_get_contents($release)===$owner){
        if($browser_mode){
          if(!$browser_queue_quiesced){throw new RuntimeException('task_browser_queue_not_quiesced');}
          $writer=$fixture->getWriterConfig();$query=$GLOBALS['control']->prepare('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?');
          $name=$writer['user'];$query->bind_param('s',$name);try{$query->execute();$remaining=$query->get_result()->fetch_assoc();}finally{$query->close();}
          if(!is_array($remaining)||!in_array($remaining['n'],array(0,'0'),true)){throw new RuntimeException('task_browser_writer_SQL_not_zero');}
          $GLOBALS['governance_workbench_http_cleanup_permitted']=true;
        }
        $released=true;break;
      }
      usleep(200000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('private_mail_parent_lease_expired');}
    $check(true,'private-mail-physical-owner-release-observed');
    $result=array('queued_native_task_fixture'=>true,'manual_native_publication'=>false,
      'SMTP_proved_by_fixture'=>false,'parent_release_observed'=>true,'production_activation'=>false);
  }catch(Throwable$error){$failure=$error;}
  finally{unset($scope);foreach(array('queue'=>&$queue,'capture'=>&$sink)as$name=>&$service){if($service!==null){
      try{$cleanup[$name]=governance_completion_stop_gorge($service);if(!$cleanup[$name]['process_stopped']||!$cleanup[$name]['private_storage_removed']){$cleanup_failed=true;}}
      catch(Throwable$error){$cleanup_failed=true;}}}unset($service);}
  if($failure!==null){throw$failure;}if($cleanup_failed){throw new RuntimeException('private_mail_owned_service_cleanup_failed');}
  $result['owned_service_cleanup']=$cleanup;return$result;
}
