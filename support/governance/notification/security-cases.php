<?php
/** Actual producers and current native sources; no SMTP or delivery daemon. */
function governance_private_mail_security_cases($fixture,callable $check){
  $fixture->assertOwned();
  if(!PhorgePrivateMailPolicy::enabled()){throw new RuntimeException('private_mail_security_fixture_not_enabled');}
  $suffix=substr($fixture->getNamespace(),-10);$users=array();$emails=array();
  foreach(array('owner','admin','pending')as$role){
    $user=(new PhabricatorUser())->setUsername('pms'.$role.$suffix)->setRealName('Synthetic security account')
      ->setIsApproved($role==='pending'?0:1)->setIsAdmin($role==='admin'?1:0)->setIsEmailVerified(1)->save();
    $email=(new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();$users[$role]=$user;$emails[$role]=$email;
  }
  $one_mail=static function($producer,callable $send){
    $model=new PhabricatorMetaMTAMail();$conn=$model->establishConnection('r');
    $before=queryfx_one($conn,'SELECT COALESCE(MAX(id),0) n FROM %R',$model);$send();
    $rows=(new PhabricatorMetaMTAMail())->loadAllWhere('id>%d ORDER BY id ASC',(int)$before['n']);
    $matches=array();foreach($rows as$mail){if($mail->getPrivateMailPolicyValue('governance.mail-producer')===$producer){$matches[]=$mail;}}
    return$matches;
  };
  $mail=(new PhabricatorPeopleEmailLoginMailEngine())->setSender(new PhabricatorUser())
    ->setRecipient($users['owner'])->sendMail();
  $profile_scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('cluster.databases',array(array('host'=>'unreachable.invalid')));
  try{$check(!PhorgePrivateMailPolicy::authorize($mail),
    'private-mail-configured-cluster-routing-refused-before-native-authorization');}finally{unset($profile_scope);}
  $readonly_property=new ReflectionProperty(PhabricatorEnv::class,'readOnly');
  $reason_property=new ReflectionProperty(PhabricatorEnv::class,'readOnlyReason');
  $old_readonly=$readonly_property->getValue();$old_reason=$reason_property->getValue();
  PhabricatorEnv::setReadOnly(true,PhabricatorEnv::READONLY_UNREACHABLE);
  try{$check(!PhorgePrivateMailPolicy::authorize($mail),
    'private-mail-native-readonly-profile-refused-without-replica-fallback');}
  finally{$readonly_property->setValue(null,$old_readonly);$reason_property->setValue(null,$old_reason);}
  $snapshot=$mail->prepareGorgeDelivery();
  $check($mail->getPrivateMailPolicyValue('governance.mail-flow')==='account.login'&&
    isset($snapshot['authorization'])&&str_contains($mail->getBody(),'/login/'),
    'private-mail-real-login-producer-retains-native-one-time-challenge');
  $emails['owner']->setIsPrimary(0)->save();
  $alternate=(new PhabricatorUserEmail())->setUserPHID($users['owner']->getPHID())
    ->setAddress('pmsalternate'.$suffix.'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
  $check(!PhorgePrivateMailPolicy::authorize($mail)&&
    (new PhabricatorUserEmail())->load($emails['owner']->getID())->getAddress()===$emails['owner']->getAddress(),
    'private-mail-native-primary-switch-retaining-old-address-refuses-bound-login-delivery');
  $alternate->setIsPrimary(0)->save();$emails['owner']->setIsPrimary(1)->save();
  $users['owner']->setAccountSecret(Filesystem::readRandomCharacters(64))->save();
  $check(!PhorgePrivateMailPolicy::authorize($mail),
    'private-mail-native-account-secret-rotation-rejects-prepared-login-challenge');
  $welcome=(new PhabricatorPeopleWelcomeMailEngine())->setSender($users['admin'])->setRecipient($users['owner'])->sendMail();
  $check(PhorgePrivateMailPolicy::authorize($welcome)&&str_contains($welcome->getBody(),'/login/'),
    'private-mail-real-welcome-producer-retains-native-challenge-and-admin-binding');
  $users['admin']->setIsAdmin(0)->save();
  $check(!PhorgePrivateMailPolicy::authorize($welcome),
    'private-mail-welcome-current-sender-administrator-required-after-prepare');
  $users['admin']->setIsAdmin(1)->save();
  $emails['owner']->setIsVerified(0)->save();
  $matches=$one_mail('PhabricatorUserEmail',static function()use($emails,$users){$emails['owner']->sendVerificationEmail($users['owner']);});
  $check(count($matches)===1,'private-mail-real-email-verification-producer-single-native-message');$verify=head($matches);
  $check(PhorgePrivateMailPolicy::authorize($verify)&&str_contains($verify->getBody(),$emails['owner']->getVerificationURI()),
    'private-mail-email-verification-unverified-native-address-and-real-code-preserved');
  $emails['owner']->setVerificationCode(bin2hex(random_bytes(12)))->save();
  $check(!PhorgePrivateMailPolicy::authorize($verify),
    'private-mail-email-verification-rotated-native-code-rejects-old-message');
  $method=new ReflectionMethod(PhabricatorAuthRegisterController::class,'sendWaitingForApprovalEmail');
  $queue=$one_mail('PhabricatorAuthRegisterController',static function()use($method,$users){$method->invoke(new PhabricatorAuthRegisterController(),$users['pending']);});
  $own=array();foreach($queue as$item){if($item->getToPHIDs()===array($users['admin']->getPHID())){$own[]=$item;}}
  $check(count($own)===1,'private-mail-real-registration-admin-notice-producer-splits-native-recipients');$notice=head($own);
  $notice_snapshot=$notice->prepareGorgeDelivery();$raw=phutil_json_encode($notice_snapshot);
  $check(PhorgePrivateMailPolicy::authorize($notice)&&!str_contains($raw,$users['pending']->getUsername())&&
    !str_contains($raw,'Awaiting Approval')&&$notice->getHTMLBody()===null&&!$notice->getAttachments(),
    'private-mail-admin-queue-generic-body-subject-no-pending-account-disclosure');
  $users['admin']->setIsAdmin(0)->save();
  $check(!PhorgePrivateMailPolicy::authorize($notice)&&$notice->prepareGorgeDelivery()===array('state'=>'void'),
    'private-mail-admin-notice-current-demotion-blocks-existing-immutable-delivery');
  $users['admin']->setIsAdmin(1)->save();
  $admin_auth=governance_native_request($users['admin'],new PhabricatorAuthSessionEngine());
  $check($admin_auth['viewer']->hasSession(),'private-mail-security-user-edits-use-real-native-session-and-request');
  $renames=$one_mail('PhabricatorPeopleUsernameMailEngine',static function()use($users,$suffix,$admin_auth){
    $target=(new PhabricatorUser())->load($users['owner']->getID());
    (new PhabricatorUserTransactionEditor())->setActor($admin_auth['viewer'])
      ->setContentSourceFromRequest($admin_auth['request'])->setCancelURI('/people/')
      ->applyTransactions($target,array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserUsernameTransaction::TRANSACTIONTYPE)->setNewValue('pmsrenamed'.$suffix)));
  });
  $check(count($renames)===1&&PhorgePrivateMailPolicy::authorize(head($renames)),
    'private-mail-real-native-username-transaction-binds-committed-user-event');
  $approvals=$one_mail('PhabricatorUserApproveTransaction',static function()use($users,$admin_auth){
    $target=(new PhabricatorUser())->load($users['pending']->getID());
    (new PhabricatorUserTransactionEditor())->setActor($admin_auth['viewer'])
      ->setContentSourceFromRequest($admin_auth['request'])->setCancelURI('/people/')
      ->applyTransactions($target,array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserApproveTransaction::TRANSACTIONTYPE)->setNewValue(true)));
  });
  $owners=array();$copies=array();foreach($approvals as$item){
    if($item->getPrivateMailPolicyValue('governance.mail-flow')==='account.approved.owner'){$owners[]=$item;}
    if($item->getPrivateMailPolicyValue('governance.mail-flow')==='account.approved.admin'){$copies[]=$item;}
  }
  $check(count($owners)===1&&count($copies)===1&&PhorgePrivateMailPolicy::authorize(head($owners))&&
    PhorgePrivateMailPolicy::authorize(head($copies)),
    'private-mail-real-native-approval-transaction-owner-admin-separated-and-event-bound');
  $users['admin']->setIsAdmin(0)->save();
  $check(!PhorgePrivateMailPolicy::authorize(head($copies))&&PhorgePrivateMailPolicy::authorize(head($owners)),
    'private-mail-approval-demoted-admin-copy-refused-owner-security-notice-preserved');
  $users['admin']->setIsAdmin(1)->save();
  $invites=$one_mail('PhabricatorAuthInviteWorker',static function()use($users,$suffix){
    (new PhabricatorAuthInviteWorker(array('address'=>'pmsinvite'.$suffix.'@example.invalid',
      'authorPHID'=>$users['admin']->getPHID(),'template'=>'Synthetic invitation {$INVITE_URI}')))->executeTask();
  });
  $check(count($invites)===1&&PhorgePrivateMailPolicy::authorize(head($invites))&&str_contains(head($invites)->getBody(),'/auth/invite/'),
    'private-mail-real-invite-worker-native-code-and-current-author-bound');
  $invite=(new PhabricatorAuthInvite())->loadOneWhere('emailAddress=%s','pmsinvite'.$suffix.'@example.invalid');
  $invite->regenerateVerificationCode();$invite->save();
  $check(!PhorgePrivateMailPolicy::authorize(head($invites)),
    'private-mail-native-invite-regeneration-rejects-prior-challenge');
  $undeliverable=(new PhabricatorUser())->setUsername('pmsnoemail'.$suffix)->setRealName('Synthetic no-email account')
    ->setIsApproved(0)->save();
  $no_email_renames=$one_mail('PhabricatorPeopleUsernameMailEngine',static function()use($undeliverable,$admin_auth,$suffix){
    (new PhabricatorUserTransactionEditor())->setActor($admin_auth['viewer'])
      ->setContentSourceFromRequest($admin_auth['request'])->setCancelURI('/people/')
      ->applyTransactions($undeliverable,array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserUsernameTransaction::TRANSACTIONTYPE)->setNewValue('pmsnoemailrenamed'.$suffix)));
  });
  $check(!$no_email_renames&&(new PhabricatorUser())->load($undeliverable->getID())->getUsername()==='pmsnoemailrenamed'.$suffix,
    'private-mail-no-primary-email-native-rename-commits-without-owner-notice');
  foreach(array(true,false)as$approved){
    $messages=$one_mail('PhabricatorUserApproveTransaction',static function()use($undeliverable,$admin_auth,$approved){
      (new PhabricatorUserTransactionEditor())->setActor($admin_auth['viewer'])
        ->setContentSourceFromRequest($admin_auth['request'])->setCancelURI('/people/')
        ->applyTransactions($undeliverable,array((new PhabricatorUserTransaction())
          ->setTransactionType(PhabricatorUserApproveTransaction::TRANSACTIONTYPE)->setNewValue($approved)));
    });
    $owner_count=0;$admin_count=0;foreach($messages as$item){
      if($item->getPrivateMailPolicyValue('governance.mail-flow')==='account.approved.owner'){$owner_count++;}
      if($item->getPrivateMailPolicyValue('governance.mail-flow')==='account.approved.admin'){$admin_count++;}
    }
    $stored=(new PhabricatorUser())->load($undeliverable->getID());
    $xaction=new PhabricatorUserTransaction();$conn=$xaction->establishConnection('r');
    $event=queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s AND transactionType=%s',$xaction,
      $undeliverable->getPHID(),PhabricatorUserApproveTransaction::TRANSACTIONTYPE);
    $check((bool)$stored->getIsApproved()===$approved&&$owner_count===0&&$admin_count===1&&(int)$event['n']>=($approved?1:2),
      $approved?'private-mail-no-primary-email-native-approval-commits-without-owner-notice':
        'private-mail-no-primary-email-native-withdrawal-commits-without-owner-notice');
  }
  $legacy=(new PhabricatorMetaMTAMail())->setPrivateMailProducer('PhabricatorPeopleEmailLoginMailEngine')
    ->addTos(array($users['owner']->getPHID()))->setSubject('Synthetic unbound challenge')->setBody('Synthetic old challenge')->save();
  $check($legacy->prepareGorgeDelivery()===array('state'=>'void'),
    'private-mail-security-class-name-alone-cannot-authorize-legacy-challenge');
  return array('actual_login_welcome_email_verification_and_registration_producers'=>true,
    'actual_username_approval_transactions_and_invite_worker'=>true,
    'registration_private_method_seam_not_full_registration_http'=>true,
    'current_admin_and_challenge_sources_checked'=>true,'smtp_security_matrix_complete'=>false,
    'all_security_producer_runtime_complete'=>false,'legalpad_signature_delivery_supported'=>false);
}
