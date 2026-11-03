<?php
/** Real security producers; internal outputs contain synthetic secrets, never public receipt data. */
function governance_private_mail_security_wire_cases($fixture, callable $check) {
  $fixture->assertOwned();
  if (!PhorgePrivateMailPolicy::enabled()) { throw new RuntimeException('private_mail_security_wire_not_enabled'); }
  $suffix=substr($fixture->getNamespace(),-10);$snapshots=array();$targets=array();$number=0;
  $user=function($role,$admin=false,$approved=true)use($suffix,&$number){
    $number++;$u=(new PhabricatorUser())->setUsername('pmsw'.$role.$number.$suffix)
      ->setRealName('Synthetic security wire account')->setIsApproved($approved?1:0)
      ->setIsAdmin($admin?1:0)->setIsEmailVerified(1)->save();
    $e=(new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();return array($u,$e);
  };
  $produced=function($producer,$flow,callable $send,$recipient=null){
    $model=new PhabricatorMetaMTAMail();$conn=$model->establishConnection('r');
    $row=queryfx_one($conn,'SELECT COALESCE(MAX(id),0) n FROM %R',$model);$send();
    $mails=(new PhabricatorMetaMTAMail())->loadAllWhere('id>%d ORDER BY id',(int)$row['n']);$found=array();
    foreach($mails as$mail){if($mail->getPrivateMailPolicyValue('governance.mail-producer')===$producer&&
      $mail->getPrivateMailPolicyValue('governance.mail-flow')===$flow&&
      ($recipient===null||$mail->getToPHIDs()===array($recipient))){$found[]=$mail;}}
    if(count($found)!==1){throw new RuntimeException('private_mail_security_wire_producer_cardinality_refused');}
    return head($found);
  };
  $capture=function($case,$mail,array $target)use(&$snapshots,&$targets){
    if(isset($snapshots[$case])||!PhorgePrivateMailPolicy::authorize($mail)){
      throw new RuntimeException('private_mail_security_wire_current_source_refused');}
    $snapshot=$mail->prepareGorgeDelivery();
    if(($snapshot['authorization']['version']??null)!==1||!isset($snapshot['message'])){
      throw new RuntimeException('private_mail_security_wire_snapshot_refused');}
    $snapshots[$case]=$snapshot;$targets[$case]=$target+array('mail_id'=>$mail->getID());
  };
  foreach(array('login-allow','login-disable','login-secret-rotate','login-primary-switch')as$case){
    list($owner,$email)=$user('login');
    $mail=(new PhabricatorPeopleEmailLoginMailEngine())->setSender(new PhabricatorUser())->setRecipient($owner)->sendMail();
    $capture($case,$mail,array('kind'=>'account.login','user_phid'=>$owner->getPHID(),'email_phid'=>$email->getPHID()));
  }
  foreach(array('welcome-allow','welcome-demote')as$case){
    list($owner,$email)=$user('welcome');list($admin)=$user('weladmin',true);
    $mail=(new PhabricatorPeopleWelcomeMailEngine())->setSender($admin)->setRecipient($owner)->sendMail();
    $capture($case,$mail,array('kind'=>'account.welcome','user_phid'=>$owner->getPHID(),'admin_phid'=>$admin->getPHID()));
  }
  foreach(array('verify-allow','verify-code-rotate','verify-completed')as$case){
    list($owner,$email)=$user('verify');$email->setIsVerified(0)->save();
    $mail=$produced('PhabricatorUserEmail','account.email.verify',function()use($owner,$email){$email->sendVerificationEmail($owner);});
    $capture($case,$mail,array('kind'=>'account.email.verify','user_phid'=>$owner->getPHID(),'email_phid'=>$email->getPHID()));
  }
  foreach(array('queue-allow','queue-demote')as$case){
    list($pending)=$user('pending',false,false);list($admin)=$user('queueadmin',true);
    $method=new ReflectionMethod(PhabricatorAuthRegisterController::class,'sendWaitingForApprovalEmail');
    $mail=$produced('PhabricatorAuthRegisterController','account.approval.queue',function()use($method,$pending){
      $method->invoke(new PhabricatorAuthRegisterController(),$pending);
    },$admin->getPHID());
    $capture($case,$mail,array('kind'=>'account.approval.queue','user_phid'=>$pending->getPHID(),'admin_phid'=>$admin->getPHID()));
  }
  foreach(array('invite-allow','invite-code-rotate','invite-demote')as$case){
    list($admin)=$user('invadmin',true);$address='pmswinvite'.$number.$suffix.'@example.invalid';
    $mail=$produced('PhabricatorAuthInviteWorker','account.invite',function()use($admin,$address){
      (new PhabricatorAuthInviteWorker(array('address'=>$address,'authorPHID'=>$admin->getPHID(),
        'template'=>'Synthetic invitation {$INVITE_URI}')))->executeTask();
    });
    $invite=(new PhabricatorAuthInvite())->loadOneWhere('emailAddress=%s',$address);
    if(!$invite){throw new RuntimeException('private_mail_security_wire_invite_missing');}
    $capture($case,$mail,array('kind'=>'account.invite','admin_phid'=>$admin->getPHID(),'invite_phid'=>$invite->getPHID()));
  }
  foreach(array('old-primary-allow','new-primary-allow')as$case){
    list($owner,$old)=$user('primary');$old->setIsPrimary(0)->save();
    $new=(new PhabricatorUserEmail())->setUserPHID($owner->getPHID())->setAddress('pmswnew'.$number.$suffix.'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $flow=$case==='old-primary-allow'?'account.email.old-primary':'account.email.new-primary';
    $mail=$produced('PhabricatorUserEmail',$flow,function()use($case,$owner,$old,$new){
      if($case==='old-primary-allow'){$old->sendOldPrimaryEmail($owner,$new);}else{$new->sendNewPrimaryEmail($owner);}
    });
    $capture($case,$mail,array('kind'=>$flow,'user_phid'=>$owner->getPHID()));
  }
  $check(count($snapshots)===16,'private-mail-security-wire-sixteen-genuine-producer-snapshots-prepared-no-smtp-proof');
  $large_snapshots=array();
  foreach(array('large-body-allow','large-attachment-allow','large-body-revoked','large-attachment-revoked')as$case){
    list($owner,$email)=$user('large');
    $mail=(new PhabricatorPeopleEmailLoginMailEngine())->setSender(new PhabricatorUser())->setRecipient($owner)->sendMail();
    if(str_contains($case,'attachment')){$mail->setAttachments(array(new PhabricatorMailAttachment(
      str_repeat('A',50*1024),'synthetic-large.txt','text/plain')));}
    else{$mail->setBody($mail->getBody().str_repeat('Synthetic security body. ',4096));}
    $mail->save();$snapshot=$mail->prepareGorgeDelivery();
    if(!PhorgePrivateMailPolicy::authorize($mail)||!isset($snapshot['authorization'])||
      strlen(phutil_json_encode($snapshot['message']))<=65536){
      throw new RuntimeException('private_mail_large_security_snapshot_refused');}
    $large_snapshots[$case]=$snapshot;$targets[$case]=array('kind'=>'account.login',
      'user_phid'=>$owner->getPHID(),'email_phid'=>$email->getPHID(),'mail_id'=>$mail->getID());
  }
  $check(count($large_snapshots)===4,'private-mail-large-security-wire-four-distinct-native-login-sources-no-smtp-proof');
  return array('snapshots'=>$snapshots,'targets'=>$targets,'large_snapshots'=>$large_snapshots,'public_summary'=>array(
    'actual_producer_snapshots'=>16,'synthetic_accounts_only'=>true,'class_name_authorization_mock_used'=>false,
    'registration_method_seam_not_full_http'=>true,'primary_alert_methods_not_full_change_http'=>true,
    'smtp_security_matrix_complete'=>false,'all_security_flows_complete'=>false));
}
