<?php
/** Prepare genuine native snapshots and hold only disposable state for parent. */
function governance_private_mail_wire_hold($fixture,callable $check){
  $fixture->assertOwned();$directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');$owner=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_OWNER');
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
  $write=static function($name,array$value)use($directory){
    if(!in_array($name,array('router.json','ready.json','control.json'),true)||file_exists($directory.'/'.$name)){throw new RuntimeException('private_mail_parent_file_refused');}
    $temporary=$directory.'/.'.bin2hex(random_bytes(16)).'.tmp';$stream=null;
    try{$stream=fopen($temporary,'x');if($stream===false||!chmod($temporary,0600)){throw new RuntimeException('private_mail_parent_write_refused');}
      $raw=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(fwrite($stream,$raw)!==strlen($raw)){throw new RuntimeException('private_mail_parent_write_refused');}
      fclose($stream);$stream=null;if(!rename($temporary,$directory.'/'.$name)){throw new RuntimeException('private_mail_parent_write_refused');}
    }finally{if(is_resource($stream)){fclose($stream);}if(is_file($temporary)){unlink($temporary);}}
  };
  require_once dirname(__DIR__).'/completion/pipeline-gorge.php';require_once __DIR__.'/owlmail-sink.php';
  $queue=null;$sink=null;$scope=null;$failure=null;$cleanup=array();$cleanup_failed=false;$result=null;
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
    $users=array();foreach(array('author','recipient')as$role){$user=(new PhabricatorUser())
      ->setUsername('pmwire'.$role.substr($fixture->getNamespace(),-10))->setRealName('Synthetic Private Mail '.$role)
      ->setIsApproved(1)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      $users[$role]=$user;}
    $snapshots=array();$targets=array();
    foreach(array('allow','before-revoke','between-attempts','unavailable','mutated','unknown-outcome')as$name){
      $file=PhabricatorFile::newFromFileData('SYNTHETIC_PRIVATE_FILE_BYTES_'.$name,array('name'=>'SYNTHETIC_PRIVATE_FILE_NAME_'.$name,
        'authorPHID'=>$users['author']->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_USER));
      $mail=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('PhabricatorFileEditor')->setRelatedPHID($file->getPHID())
        ->addTos(array($users['recipient']->getPHID()))->setFrom($users['author']->getPHID())
        ->setSubject('SYNTHETIC_PRIVATE_TITLE_'.$name)->setBody('SYNTHETIC_PRIVATE_COMMENT_'.$name)
        ->setHTMLBody('<img src="SYNTHETIC_PRIVATE_INLINE_LINK">')
        ->setAttachments(array(new PhabricatorMailAttachment('SYNTHETIC_PRIVATE_ATTACHMENT_BYTES','private.txt','text/plain')))->save();
      $snapshot=$mail->prepareGorgeDelivery();
      if(!isset($snapshot['authorization']['version'])||$snapshot['authorization']['version']!==1||str_contains(phutil_json_encode($snapshot),'SYNTHETIC_PRIVATE')){
        throw new RuntimeException('private_mail_physical_native_snapshot_refused');}
      $snapshots[$name]=$snapshot;$targets[$name]=array('mail_id'=>$mail->getID(),'file_phid'=>$file->getPHID());
    }
    $check(count($snapshots)===6,'private-mail-physical-real-native-source-snapshots-created-without-private-content');
    require_once __DIR__.'/security-wire-cases.php';
    $security=governance_private_mail_security_wire_cases($fixture,$check);
    $mailer_config=$fixture->createPrivateMailerPrincipal();
    $check($mailer_config['database']===$fixture->getNamespace().'_metamta','private-mail-physical-scoped-metamta-principal-created');
    $writer=$fixture->getWriterConfig();$config=array('owner'=>$owner,'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
      'source_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'writer'=>$writer,
      'callback_origin'=>$callback_origin,'service_token'=>$tokens['CALLBACK_TOKEN'],'mailer_uri'=>$mailer_uri,'mailer_token'=>$tokens['MAILER_TOKEN'],
      'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'));
    $write('router.json',$config);$write('control.json',array('owner'=>$owner,'targets'=>$targets,'recipient_phid'=>$users['recipient']->getPHID(),'security_targets'=>$security['targets']));
    $write('ready.json',array('scope'=>'private-owned-native-mail-physical-fixture','owner'=>$owner,'snapshots'=>$snapshots,
      'invite_capability_snapshots'=>$security['invite_snapshots'],'large_security_snapshots'=>$security['large_snapshots'],'security_snapshots'=>$security['snapshots'],'security_scope'=>$security['public_summary'],
      'mailer_database'=>$mailer_config,'callback_origin'=>$callback_origin,'smtp_port'=>$sink['smtp_port'],
      'capture_uri'=>$sink['uri'],'capture_authorization'=>$sink['authorization'],'capture_binary_sha256'=>$sink['binary_sha256'],
      'native_permission_model_changed'=>false,'real_delivery_proved_by_preparation'=>false));
    $check(true,'private-mail-physical-private-parent-manifest-ready-no-public-control-route');
    $deadline=microtime(true)+$seconds;$released=false;
    do{$fixture->assertOwned();$release=$directory.'/release';
      if(is_file($release)&&!is_link($release)&&(fileperms($release)&0777)===0600&&filesize($release)===64&&file_get_contents($release)===$owner){$released=true;break;}
      usleep(200000);
    }while(microtime(true)<$deadline);
    if(!$released){throw new RuntimeException('private_mail_parent_lease_expired');}
    $check(true,'private-mail-physical-owner-release-observed');
    $result=array('private_preparation_only'=>true,'native_source_snapshots'=>6,'parent_release_observed'=>true,
      'smtp_end_to_end_proved_by_this_preparation'=>false,'production_activation'=>false,'security_preparation'=>$security['public_summary']);
  }catch(Throwable$error){$failure=$error;}
  finally{unset($scope);foreach(array('queue'=>&$queue,'capture'=>&$sink)as$name=>&$service){if($service!==null){
      try{$cleanup[$name]=governance_completion_stop_gorge($service);if(!$cleanup[$name]['process_stopped']||!$cleanup[$name]['private_storage_removed']){$cleanup_failed=true;}}
      catch(Throwable$error){$cleanup_failed=true;}}}unset($service);}
  if($failure!==null){throw$failure;}if($cleanup_failed){throw new RuntimeException('private_mail_owned_service_cleanup_failed');}
  $result['owned_service_cleanup']=$cleanup;return$result;
}
