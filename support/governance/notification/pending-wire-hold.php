<?php
/** Prepare genuine native snapshots and hold only disposable state for parent. */
function governance_private_mail_pending_hold($fixture,callable $check){
  $fixture->assertOwned();$directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');$owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');
  if(!is_string($directory)||is_link($directory)||realpath($directory)!==$directory||
    realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/',basename($directory))||(fileperms($directory)&0777)!==0700||
    !is_string($owner)||!preg_match('/\A[a-f0-9]{64}\z/',$owner)||!is_file($directory.'/owner')||is_link($directory.'/owner')||
    (fileperms($directory.'/owner')&0777)!==0600||file_get_contents($directory.'/owner')!==$owner){throw new RuntimeException('private_mail_parent_scope_refused');}
  require_once __DIR__.'/pending-wire-protocol.php';
  $case=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_PENDING_CASE');if(!in_array($case,array('commit','rollback','recipient-revoked','admin-revoked'),true)){throw new RuntimeException('private_mail_pending_case_refused');}
  $ports=array();foreach(array('HTTP','MAILER')as$name){$value=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$name.'_PORT');
    if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,4}\z/',$value)||(int)$value>65535){throw new RuntimeException('private_mail_parent_port_refused');}
    $ports[$name]=(int)$value;}
  if($ports['HTTP']===$ports['MAILER']){throw new RuntimeException('private_mail_parent_ports_conflict');}
  $tokens=array();foreach(array('MAILER_TOKEN','CALLBACK_TOKEN')as$name){$value=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_'.$name);
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/',$value)){throw new RuntimeException('private_mail_parent_token_refused');}$tokens[$name]=$value;}
  $lease=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_HOLD_SECONDS');$seconds=$lease===false||$lease===''?900:(int)$lease;
  if(($lease!==false&&$lease!==''&&!preg_match('/\A[1-9][0-9]{2,3}\z/',$lease))||$seconds<120||$seconds>1800){throw new RuntimeException('private_mail_parent_lease_refused');}
  $write=static function($name,array$value)use($directory){
    if(!in_array($name,array('router.json','ready.json','control.json'),true)||file_exists($directory.'/'.$name)){throw new RuntimeException('private_mail_parent_file_refused');}
    $temporary=$directory.'/.'.bin2hex(random_bytes(16)).'.tmp';$stream=null;
    try{$stream=fopen($temporary,'x');if($stream===false||!chmod($temporary,0600)){throw new RuntimeException('private_mail_parent_write_refused');}
      $raw=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(fwrite($stream,$raw)!==strlen($raw)){throw new RuntimeException('private_mail_parent_write_refused');}
      fclose($stream);$stream=null;if(!rename($temporary,$directory.'/'.$name)){throw new RuntimeException('private_mail_parent_write_refused');}
    }finally{if(is_resource($stream)){fclose($stream);}if(is_file($temporary)){unlink($temporary);}}
  };
  require_once dirname(__DIR__).'/completion/pipeline-gorge.php';require_once __DIR__.'/owlmail-sink.php';
  $queue=null;$sink=null;$scope=null;$failure=null;$cleanup=array();$cleanup_failed=false;$result=null;$transaction=null;$transaction_owned=false;$pending_state=null;
  try{
    if((new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false){throw new RuntimeException('private_mail_background_delivery_refused');}
    $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);$sink=governance_private_mail_start_sink($fixture,$check);
    $mailer_uri='http://127.0.0.1:'.$ports['MAILER'];$callback_origin='http://127.0.0.1:'.$ports['HTTP'];
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.private-mail.enabled',true)
      ->overrideEnvConfig('governance.organization.enabled',false)->overrideEnvConfig('governance.media-exchange.enabled',false)
      ->overrideEnvConfig('metamta.gorge-delivery-mode','native')->overrideEnvConfig('gorge.taskqueue.owner','gorge')
      ->overrideEnvConfig('gorge.taskqueue.uri',$queue['uri'])->overrideEnvConfig('gorge.taskqueue.token',$queue['token'])
      ->overrideEnvConfig('gorge.service-policies',array('file'=>'required','taskqueue'=>'required','mailer'=>'required'))
      ->overrideEnvConfig('cluster.mailers',array(array('key'=>'isolated-physical','type'=>'gorge','inbound'=>false,
        'options'=>array('uri'=>$mailer_uri,'token'=>$tokens['MAILER_TOKEN']))));
    $check(is_array((new PhabricatorGorgeTaskQueueClient())->getStats()),'private-mail-physical-owned-real-queue-ready-no-workers');
    $users=array();$suffix=substr($fixture->getNamespace(),-10);
    foreach(array('admin','subject')as$role){$user=(new PhabricatorUser())->setUsername('pmpending'.$role.$suffix)
      ->setRealName('Synthetic pending source account')->setIsApproved($role==='subject'&&$case==='admin-revoked'?0:1)
      ->setIsAdmin($role==='admin'?1:0)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();$users[$role]=$user;}
    require_once dirname(__DIR__).'/native-organization/native-cases.php';
    $admin_auth=governance_native_request($users['admin'],new PhabricatorAuthSessionEngine());
    $mail_table=new PhabricatorMetaMTAMail();$before=(int)queryfx_one($mail_table->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$mail_table)['n'];
    $transaction=$users['subject']->establishConnection('w');$transaction->openTransaction();$transaction_owned=true;
    $type=$case==='admin-revoked'?PhabricatorUserApproveTransaction::TRANSACTIONTYPE:PhabricatorUserUsernameTransaction::TRANSACTIONTYPE;
    (new PhabricatorUserTransactionEditor())->setActor($admin_auth['viewer'])->setContentSourceFromRequest($admin_auth['request'])->setCancelURI('/people/')
      ->applyTransactions($users['subject'],array((new PhabricatorUserTransaction())->setTransactionType($type)
        ->setNewValue($case==='admin-revoked'?true:'pmpendingrenamed'.$suffix)));
    $flow=$case==='admin-revoked'?'account.approved.admin':'account.username';$selected=array();
    foreach($mail_table->loadAllWhere('id>%d ORDER BY id',$before)as$item){if($item->getPrivateMailPolicyValue('governance.mail-flow')===$flow){$selected[]=$item;}}
    if(count($selected)!==1||!$transaction->isInsideTransaction()){throw new RuntimeException('private_mail_pending_real_producer_refused');}
    $mail=head($selected);$snapshot=$mail->prepareGorgeDelivery();
    if(($snapshot['authorization']['version']??null)!==1||!isset($snapshot['message'])){throw new RuntimeException('private_mail_pending_snapshot_refused');}
    $check(true,'private-mail-pending-genuine-user-editor-and-snapshot-held-before-physical-people-commit');
    $pending_state=array('owner'=>$owner,'case'=>$case,'state'=>'holding','processed_commands'=>0,'last_action'=>null,'last_request_id'=>null,
      'source_transaction_committed'=>false,'source_transaction_rolled_back'=>false,'source_age_injected'=>false,'recipient_binding_changed'=>false,'admin_binding_changed'=>false);
    governance_pending_wire_write($directory,$owner,'pending-state.json',$pending_state);
    $mailer_config=$fixture->createPrivateMailerPrincipal();
    $check($mailer_config['database']===$fixture->getNamespace().'_metamta','private-mail-physical-scoped-metamta-principal-created');
    $writer=$fixture->getWriterConfig();$config=array('owner'=>$owner,'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
      'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'writer'=>$writer,
      'callback_origin'=>$callback_origin,'service_token'=>$tokens['CALLBACK_TOKEN'],'mailer_uri'=>$mailer_uri,'mailer_token'=>$tokens['MAILER_TOKEN'],
      'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    $write('router.json',$config);
    $write('control.json',array('owner'=>$owner,'pending_case'=>$case,'pending_mail_id'=>(int)$mail->getID()));
    $write('ready.json',array('scope'=>'private-owned-native-pending-physical-fixture','owner'=>$owner,'pending_case'=>$case,
      'pending_snapshot'=>$snapshot,'pending_mail_id'=>(int)$mail->getID(),'pending_flow'=>$flow,'source_transaction_open'=>true,
      'mailer_database'=>$mailer_config,'callback_origin'=>$callback_origin,'smtp_port'=>$sink['smtp_port'],
      'capture_uri'=>$sink['uri'],'capture_authorization'=>$sink['authorization'],'capture_binary_sha256'=>$sink['binary_sha256'],
      'native_permission_model_changed'=>false,'real_delivery_proved_by_preparation'=>false));
    $check(true,'private-mail-physical-private-parent-manifest-ready-no-public-control-route');
    $deadline=microtime(true)+$seconds;$released=false;
    do{$fixture->assertOwned();governance_pending_wire_scope();$command_path=$directory.'/pending-command.json';
      if(file_exists($command_path)){
        $command=governance_pending_wire_read($directory,$owner,'pending-command.json');$keys=array_keys($command);sort($keys);
        if($keys!==array('action','case','expected_state','owner','request_id')||$command['case']!==$case||$command['expected_state']!==$pending_state['state']||
          !is_string($command['request_id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$command['request_id'])||$command['request_id']===$pending_state['last_request_id']||
          !governance_pending_wire_allowed($case,$pending_state['state'],$command['action'])){throw new RuntimeException('private_mail_pending_command_refused');}
        switch($command['action']){
          case 'commit':$transaction->saveTransaction();$transaction_owned=false;$pending_state['state']='committed';$pending_state['source_transaction_committed']=true;break;
          case 'rollback':$transaction->killTransaction();$transaction_owned=false;$pending_state['state']='rolled-back';$pending_state['source_transaction_rolled_back']=true;break;
          case 'aged':queryfx($mail_table->establishConnection('w'),'UPDATE %R SET dateCreated=UNIX_TIMESTAMP()-301 WHERE id=%d',$mail_table,$mail->getID());
            $pending_state['state']='aged';$pending_state['source_age_injected']=true;break;
          case 'revoke':
            $transaction->killTransaction();$transaction_owned=false;$pending_state['source_transaction_rolled_back']=true;
            if($case==='admin-revoked'){$current=(new PhabricatorUser())->load($users['admin']->getID());$current->setIsAdmin(0)->save();$pending_state['admin_binding_changed']=true;}
            else{$email=(new PhabricatorUserEmail())->loadOneWhere('userPHID=%s AND isPrimary=1',$users['subject']->getPHID());$email->setIsPrimary(0)->save();
              (new PhabricatorUserEmail())->setUserPHID($users['subject']->getPHID())->setAddress('replacement'.$suffix.'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
              $pending_state['recipient_binding_changed']=true;}
            $pending_state['state']='revoked';break;
        }
        $pending_state['processed_commands']++;$pending_state['last_action']=$command['action'];$pending_state['last_request_id']=$command['request_id'];
        if(!unlink($command_path)){throw new RuntimeException('private_mail_pending_command_cleanup_failed');}governance_pending_wire_write($directory,$owner,'pending-state.json',$pending_state,true);
      }
      $release=$directory.'/release';
      if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&file_get_contents($release)===$owner){$released=true;break;}
      usleep(100000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('private_mail_parent_lease_expired');}
    $check(true,'private-mail-physical-owner-release-observed');
    $expected=$case==='commit'?'committed':($case==='rollback'?'aged':'revoked');
    if($pending_state['state']!==$expected||$transaction_owned||$transaction->isInsideTransaction()){throw new RuntimeException('private_mail_pending_parent_sequence_incomplete');}
    $result=array('private_preparation_only'=>true,'native_source_snapshots'=>1,'parent_release_observed'=>true,
      'pending_case'=>$case,'pending_final_state'=>$pending_state['state'],'owner_commands_processed'=>$pending_state['processed_commands'],
      'source_transaction_closed'=>true,'source_transaction_committed'=>$pending_state['source_transaction_committed'],
      'source_transaction_rolled_back'=>$pending_state['source_transaction_rolled_back'],'source_age_injected'=>$pending_state['source_age_injected'],
      'recipient_binding_changed'=>$pending_state['recipient_binding_changed'],'admin_binding_changed'=>$pending_state['admin_binding_changed'],
      'smtp_end_to_end_proved_by_this_preparation'=>false,'production_activation'=>false);

  }catch(Throwable$error){$failure=$error;}
  finally{if($transaction_owned){try{$transaction->killTransaction();$transaction_owned=false;}catch(Throwable$error){$cleanup_failed=true;}}unset($scope);foreach(array('queue'=>&$queue,'capture'=>&$sink)as$name=>&$service){if($service!==null){
      try{$cleanup[$name]=governance_completion_stop_gorge($service);if(!$cleanup[$name]['process_stopped']||!$cleanup[$name]['private_storage_removed']){$cleanup_failed=true;}}
      catch(Throwable$error){$cleanup_failed=true;}}}unset($service);}
  if($failure!==null){throw$failure;}if($cleanup_failed){throw new RuntimeException('private_mail_owned_service_cleanup_failed');}
  $result['owned_service_cleanup']=$cleanup;return$result;
}
