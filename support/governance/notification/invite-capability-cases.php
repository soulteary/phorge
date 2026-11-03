<?php
/** Native People capability regression; no mocked capability answers. */
function governance_private_mail_invite_capability_settings($policy) {
  return array(id(new PhabricatorPeopleApplication())->getPHID()=>array('policy'=>array(
    PeopleCreateUsersCapability::CAPABILITY=>$policy)));
}
function governance_private_mail_invite_capability_cases($fixture,callable $check) {
  $fixture->assertOwned();$suffix=substr($fixture->getNamespace(),-10);
  $actor=(new PhabricatorUser())->setUsername('pminviter'.$suffix)->setRealName('Synthetic Inviter')
    ->setIsApproved(1)->setIsAdmin(0)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($actor->getPHID())->setAddress($actor->getUsername().'@example.invalid')
    ->setIsPrimary(1)->setIsVerified(1)->save();
  $scope=PhabricatorEnv::beginScopedEnv();
  $produce=function($name)use($actor,$suffix){
    $address=$name.$suffix.'@example.invalid';
    (new PhabricatorAuthInviteWorker(array('address'=>$address,'authorPHID'=>$actor->getPHID(),
      'template'=>'Synthetic invitation {$INVITE_URI}')))->executeTask();
    $invite=(new PhabricatorAuthInvite())->loadOneWhere('emailAddress=%s',$address);
    $mails=(new PhabricatorMetaMTAMail())->loadAllWhere('id>0 ORDER BY id DESC LIMIT 100');
    foreach($mails as$mail){if($mail->getPrivateMailPolicyValue('governance.mail-flow')==='account.invite'&&
      str_contains($mail->getBody(),$invite->getVerificationCode())){return$mail;}}
    throw new RuntimeException('private_mail_invite_fixture_mail_missing');
  };
  try {
    $app=new PhabricatorPeopleApplication();
    $scope->overrideEnvConfig('phabricator.application-settings',governance_private_mail_invite_capability_settings($actor->getPHID()));
    $check(!$actor->getIsAdmin()&&PhabricatorPolicyFilter::hasCapability($actor,$app,PeopleCreateUsersCapability::CAPABILITY),
      'private-mail-invite-native-nonadmin-people-capability-granted');
    $mail=$produce('invitegranted');$snapshot=$mail->prepareGorgeDelivery();
    $check(PhorgePrivateMailPolicy::authorize($mail)&&isset($snapshot['authorization'])&&str_contains($mail->getBody(),'/auth/invite/'),
      'private-mail-invite-native-nonadmin-worker-prepares-genuine-challenge');
    $binding=$mail->getPrivateMailPolicyValue('governance.mail-binding');
    $binding['admin']='PHID-USER-zzzzzzzzzzzzzzzzzzzz';$mismatch=false;
    try{PhorgePrivateMailPolicy::validateFlow($mail,'account.invite',$binding);}
    catch(InvalidArgumentException$error){$mismatch=true;}
    $check($mismatch,'private-mail-invite-legacy-admin-binding-must-equal-author-subject');
    $scope->overrideEnvConfig('phabricator.application-settings',governance_private_mail_invite_capability_settings(PhabricatorPolicies::POLICY_ADMIN));
    $denied=$mail->authorizeGorgeDelivery();
    $check(($denied['changed']??null)===true,
      'private-mail-invite-current-capability-revocation-refuses-callback');
    $scope->overrideEnvConfig('phabricator.application-settings',governance_private_mail_invite_capability_settings($actor->getPHID()));
    $reload=(new PhabricatorMetaMTAMail())->load($mail->getID());$again=$reload->authorizeGorgeDelivery();
    $check(($again['changed']??null)===true&&$reload->getPrivateMailPolicyValue('governance.mail-flow')==='account.invite',
      'private-mail-invite-regrant-cannot-revive-permanently-quarantined-snapshot');
    $scope->overrideEnvConfig('phabricator.application-settings',governance_private_mail_invite_capability_settings(PhabricatorPolicies::POLICY_ADMIN));
    $refused=$produce('invitenocap');
    $check(!PhorgePrivateMailPolicy::authorize($refused),
      'private-mail-invite-nonadmin-without-native-capability-refused');
    $actor->setIsAdmin(1)->save();$adminmail=$produce('inviteadmin');
    $check(PhorgePrivateMailPolicy::authorize($adminmail),
      'private-mail-invite-native-default-admin-still-authorized');
    // Observe the actual native filter: do not invent an OR-admin fallback or
    // assume whether this native runtime automatically bypasses custom policy.
    $scope->overrideEnvConfig('phabricator.application-settings',governance_private_mail_invite_capability_settings(PhabricatorPolicies::POLICY_NOONE));
    $native_admin_capability=PhabricatorPolicyFilter::hasCapability($actor,
      new PhabricatorPeopleApplication(),PeopleCreateUsersCapability::CAPABILITY);
    $custom_admin_mail=$produce('invitecustomadmin');
    $check(PhorgePrivateMailPolicy::authorize($custom_admin_mail)===$native_admin_capability,
      'private-mail-invite-custom-policy-admin-result-matches-real-native-capability');
    $actor->setIsAdmin(0)->save();
    $welcome=(new PhabricatorPeopleWelcomeMailEngine())->setSender($actor)->setRecipient($actor)->sendMail();
    $scope->overrideEnvConfig('phabricator.application-settings',governance_private_mail_invite_capability_settings($actor->getPHID()));
    $check(!PhorgePrivateMailPolicy::authorize($welcome),
      'private-mail-invite-capability-does-not-authorize-admin-only-welcome');
  }finally{unset($scope);}
}
