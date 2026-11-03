<?php
/** Owned native source/query/snapshot checks. No delivery worker or SMTP send. */
function governance_private_mail_native_cases($fixture,callable $check){
  $fixture->assertOwned();
  require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
  if(PhorgePrivateMailPolicy::enabled()){throw new RuntimeException('private_mail_fixture_default_not_off');}
  $check(true,'private-mail-real-config-default-off');
  $queue=governance_completion_start_gorge($fixture,'taskqueue',$check);$scope=null;$cleanup=null;
  try{
    $scope=PhabricatorEnv::beginScopedEnv()
      ->overrideEnvConfig('governance.private-mail.enabled',true)
      ->overrideEnvConfig('metamta.gorge-delivery-mode','native')
      ->overrideEnvConfig('gorge.taskqueue.owner','gorge')->overrideEnvConfig('gorge.taskqueue.uri',$queue['uri'])
      ->overrideEnvConfig('gorge.taskqueue.token',$queue['token'])
      ->overrideEnvConfig('gorge.service-policies',array('file'=>'required','taskqueue'=>'required','mailer'=>'required'))
      ->overrideEnvConfig('cluster.mailers',array(array('key'=>'isolated-serialization','type'=>'gorge','inbound'=>false,
        'options'=>array('uri'=>$queue['uri'],'token'=>$queue['token']))));
    $check(is_array((new PhabricatorGorgeTaskQueueClient())->getStats()),'private-mail-real-owned-queue-ready-no-workers');
    $users=array();foreach(array('author','recipient') as $role){
      $user=(new PhabricatorUser())->setUsername('pm'.$role.substr($fixture->getNamespace(),-12))
        ->setRealName('Synthetic private mail actor')->setIsApproved(1)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
        ->setIsPrimary(1)->setIsVerified(1)->save();$users[$role]=$user;
    }
    $file=PhabricatorFile::newFromFileData('SYNTHETIC_PRIVATE_BYTES',array('name'=>'SYNTHETIC_PRIVATE_NAME.txt',
      'authorPHID'=>$users['author']->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_USER));
    $new_mail=function($producer)use($file,$users){return (new PhabricatorMetaMTAMail())
      ->setPrivateMailProducer($producer)->setRelatedPHID($file->getPHID())
      ->addTos(array($users['recipient']->getPHID()))->setFrom($users['author']->getPHID())
      ->setSubject('SYNTHETIC_PRIVATE_TITLE')->setSubjectPrefix('SYNTHETIC_PRIVATE_TEAM')
      ->setBody('SYNTHETIC_PRIVATE_COMMENT')->setHTMLBody('<img src="SYNTHETIC_PRIVATE_LINK">')
      ->setAttachments(array(new PhabricatorMailAttachment('SYNTHETIC_PRIVATE_ATTACHMENT','private.txt','text/plain')));};
    $mail=$new_mail('PhabricatorFileEditor')->save();
    $reloaded=(new PhabricatorMetaMTAMail())->load($mail->getID());
    $check($reloaded->getBody()===$mail->getBody()&&!str_contains($reloaded->getBody(),'SYNTHETIC_PRIVATE')&&
      $reloaded->getHTMLBody()===null&&!$reloaded->getAttachments(),
      'private-mail-real-persisted-business-envelope-no-private-bytes');
    $snapshot=$reloaded->prepareGorgeDelivery();$raw=phutil_json_encode($snapshot);
    $check(isset($snapshot['authorization']['version'])&&$snapshot['authorization']['version']===1&&
      !str_contains($raw,'SYNTHETIC_PRIVATE')&&!isset($snapshot['message']['htmlBody'])&&!isset($snapshot['message']['attachments']),
      'private-mail-immutable-gorge-snapshot-generic-subject-body-headers');
    $allowed=$reloaded->authorizeGorgePhysicalDelivery($snapshot['deliveryID'],$snapshot['authorization']['messageHash'],
      phutil_json_encode($snapshot['message']));
    $check($allowed===array('allowed'=>true),'private-mail-physical-attempt-binds-actual-current-recipient-object-and-message');
    $changed=$snapshot['message'];$changed['subject']='SYNTHETIC_PRIVATE_INJECTED';
    $check($reloaded->authorizeGorgePhysicalDelivery($snapshot['deliveryID'],$snapshot['authorization']['messageHash'],
      phutil_json_encode($changed))===array('allowed'=>false),'private-mail-mutated-physical-payload-refused');
    $address_mail=$new_mail('PhabricatorFileEditor')->save();$address_snapshot=$address_mail->prepareGorgeDelivery();
    $primary=(new PhabricatorUserEmail())->loadOneWhere('userPHID = %s AND isPrimary = 1',$users['recipient']->getPHID());
    $original_address=$primary->getAddress();
    $primary->setAddress('alternate'.$users['recipient']->getUsername().'@example.invalid')->save();
    $check($address_mail->authorizeGorgePhysicalDelivery($address_snapshot['deliveryID'],
      $address_snapshot['authorization']['messageHash'],phutil_json_encode($address_snapshot['message']))===array('allowed'=>false),
      'private-mail-primary-email-policy-change-denied-at-callback-without-prepare');
    $primary->setAddress($original_address)->save();
    $address_mail=(new PhabricatorMetaMTAMail())->load($address_mail->getID());
    $check($address_mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===true&&
      $address_mail->getParameters()['gorge.delivery-snapshot']===$address_snapshot&&
      $address_mail->authorizeGorgePhysicalDelivery($address_snapshot['deliveryID'],
        $address_snapshot['authorization']['messageHash'],phutil_json_encode($address_snapshot['message']))===array('allowed'=>false),
      'private-mail-primary-email-aba-cannot-revive-quarantined-callback-delivery');
    $unprepared=$new_mail('PhabricatorFileEditor')->save();
    $check($unprepared->authorizeGorgeDelivery()===array('changed'=>true)&&
      (new PhabricatorMetaMTAMail())->load($unprepared->getID())
        ->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===false,
      'private-mail-unprepared-authorization-probe-does-not-quarantine');
    $physical=$new_mail('PhabricatorFileEditor')->save();$ps=$physical->prepareGorgeDelivery();
    $direct=$new_mail('PhabricatorFileEditor')->save();$ds=$direct->prepareGorgeDelivery();
    $file->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    foreach(array(array($ps['deliveryID'].'/bad',$ps['authorization']['messageHash'],phutil_json_encode($ps['message'])),
      array($ps['deliveryID'],str_repeat('0',64),phutil_json_encode($ps['message'])),
      array($ps['deliveryID'],$ps['authorization']['messageHash'],'{broken'),
      array($ps['deliveryID'],$ps['authorization']['messageHash'],phutil_json_encode($changed)))as$args){
      $refused=call_user_func_array(array($physical,'authorizeGorgePhysicalDelivery'),$args);
      if($refused!==array('allowed'=>false)){throw new RuntimeException('private_mail_malformed_attempt_allowed');}
    }
    $check((new PhabricatorMetaMTAMail())->load($physical->getID())
      ->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===false,
      'private-mail-malformed-attempt-payload-cannot-poison-current-mail');
    $check($physical->authorizeGorgePhysicalDelivery($ps['deliveryID'],$ps['authorization']['messageHash'],
      phutil_json_encode($ps['message']))===array('allowed'=>false)&&
      $direct->authorizeGorgeDelivery()===array('changed'=>true),
      'private-mail-callback-and-direct-permanent-denial-quarantine-without-preparation');
    $physical=(new PhabricatorMetaMTAMail())->load($physical->getID());
    $direct=(new PhabricatorMetaMTAMail())->load($direct->getID());
    $check($physical->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===true&&
      $direct->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===true&&
      $physical->getParameters()['gorge.delivery-snapshot']===$ps&&
      $direct->getParameters()['gorge.delivery-snapshot']===$ds,
      'private-mail-attempt-quarantine-persists-reload-with-immutable-snapshots');
    $file->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
    $check($physical->authorizeGorgePhysicalDelivery($ps['deliveryID'],$ps['authorization']['messageHash'],
      phutil_json_encode($ps['message']))===array('allowed'=>false)&&
      $direct->authorizeGorgeDelivery()===array('changed'=>true),
      'private-mail-callback-only-regrant-cannot-revive-denied-delivery');
    $file->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $check($reloaded->authorizeGorgeDelivery()===array('changed'=>true)&&
      $reloaded->authorizeGorgePhysicalDelivery($snapshot['deliveryID'],$snapshot['authorization']['messageHash'],
        phutil_json_encode($snapshot['message']))===array('allowed'=>false),
      'private-mail-current-native-file-query-revocation-stops-existing-snapshot');
    $check($reloaded->prepareGorgeDelivery()===array('state'=>'void')&&
      ($reloaded->getParameters()['gorge.delivery-snapshot']??null)===$snapshot,
      'private-mail-cached-snapshot-quarantined-without-immutable-rewrite');
    $file->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
    $check($reloaded->authorizeGorgeDelivery()===array('changed'=>true),
      'private-mail-regrant-cannot-resurrect-quarantined-immutable-delivery');
    $legacy=$new_mail('PhabricatorFileEditor')->save();
    $unsafe=array('deliveryID'=>'mail/'.$legacy->getPHID().'/1','message'=>array('subject'=>'SYNTHETIC_PRIVATE_LEGACY'));
    $parameters=$legacy->getParameters();$parameters['gorge.delivery-snapshot']=$unsafe;
    $legacy->setParameters($parameters)->save();
    $check($legacy->prepareGorgeDelivery()===array('state'=>'void')&&
      ($legacy->getParameters()['gorge.delivery-snapshot']??null)===$unsafe&&$legacy->authorizeGorgeDelivery()===array('changed'=>true),
      'private-mail-unsafe-legacy-snapshot-never-upgraded-or-resent');
    $unknown=$new_mail('UnreviewedProducer')->save();
    $check($unknown->prepareGorgeDelivery()===array('state'=>'void'),'private-mail-unreviewed-producer-fails-closed');
    $space=PhabricatorSpacesNamespace::initializeNewNamespace($users['author'])
      ->setNamespaceName('Synthetic Private Mail Space')->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
    $task=ManiphestTask::initializeNewTask($users['author'])->setTitle('SYNTHETIC_PRIVATE_SPACE_TASK')
      ->setOwnerPHID(null)->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setSpacePHID($space->getPHID())->save();
    $space_mail=$new_mail('ManiphestTransactionEditor')->setRelatedPHID($task->getPHID())->save();
    $outer_cache=PhabricatorCaches::getRequestCache();
    $check(PhorgePrivateMailPolicy::authorize($space_mail)&&PhabricatorCaches::getRequestCache()===$outer_cache,
      'private-mail-fresh-native-task-space-query-restores-request-cache');
    $space->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $check(!PhorgePrivateMailPolicy::authorize($space_mail)&&PhabricatorCaches::getRequestCache()===$outer_cache,
      'private-mail-space-revocation-cannot-reuse-stale-native-request-cache');
    $users['recipient']->setIsDisabled(1)->save();
    $check(!PhorgePrivateMailPolicy::authorize($mail),'private-mail-current-disabled-recipient-cannot-use-business-mail');
    require_once __DIR__.'/security-cases.php';
    $security=governance_private_mail_security_cases($fixture,$check);
    require_once __DIR__.'/invite-capability-cases.php';
    governance_private_mail_invite_capability_cases($fixture,$check);
    require_once __DIR__.'/remaining-producer-cases.php';
    $remaining=governance_private_mail_remaining_producer_cases($fixture,$check);
    require_once __DIR__.'/commit-race-cases.php';
    $race=governance_private_mail_commit_race_cases($fixture,$check);
    require_once __DIR__.'/legalpad-cases.php';
    $legalpad=governance_private_mail_legalpad_cases($fixture,$check);
    require_once __DIR__.'/business-publication-cases.php';
    $business=governance_private_mail_business_publication_cases($fixture,$check);
    return array('business_publication_tests'=>$business,'legalpad_tests'=>$legalpad,'commit_race_tests'=>$race,'remaining_producer_tests'=>$remaining,'security_flow_tests'=>$security,'actual_native_source_and_object_query'=>true,'actual_native_queue'=>true,
      'real_business_serialization_and_legacy_quarantine'=>true,'smtp_or_delivery_workers_executed'=>false,
      'security_challenge_runtime_matrix_complete'=>false,'all_producers_runtime_complete'=>false,
      'physical_callback_runtime_complete'=>false);
  }finally{
    unset($scope);$cleanup=governance_completion_stop_gorge($queue);
    $check($cleanup['process_stopped']&&$cleanup['private_storage_removed'],'private-mail-owned-queue-cleanup');
  }
}
