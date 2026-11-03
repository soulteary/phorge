<?php
/** Genuine native account/key editors and only their owned publication tasks. */
function governance_private_mail_remaining_producer_cases($fixture, callable $check) {
  $fixture->assertOwned();
  if (!PhorgePrivateMailPolicy::enabled()) { throw new RuntimeException('private_mail_producer_fixture_not_enabled'); }
  $suffix=substr($fixture->getNamespace(),-10);$publisher_count=0;
  $new_user=static function($name,$email=true,$admin=false)use($suffix){
    $user=(new PhabricatorUser())->setUsername('pmremaining'.$name.$suffix)->setRealName('Synthetic remaining producer')
      ->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin($admin?1:0)->save();
    if($email){(new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();}return$user;
  };
  $latest_mail=static function(){
    $table=new PhabricatorMetaMTAMail();return(int)queryfx_one($table->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$table)['n'];
  };
  $mails_since=static function($id,$producer){
    $rows=(new PhabricatorMetaMTAMail())->loadAllWhere('id>%d ORDER BY id',$id);$result=array();
    foreach($rows as$mail){if($mail->getPrivateMailPolicyValue('governance.mail-producer')===$producer){$result[]=$mail;}}
    return$result;
  };
  $publish=static function($object,$actor,$after)use(&$publisher_count,$fixture){
    $table=new PhabricatorWorkerActiveTask();$rows=$table->loadAllWhere('id>%d AND taskClass=%s AND objectPHID=%s ORDER BY id',
      $after,'PhabricatorApplicationTransactionPublishWorker',$object->getPHID());
    if(count($rows)!==1){throw new RuntimeException('private_mail_owned_publisher_cardinality_refused');}$task=head($rows);
    $data_row=(new PhabricatorWorkerTaskData())->load($task->getDataID());$data=$data_row?$data_row->getData():null;
    if(!is_array($data)||($data['objectPHID']??null)!==$object->getPHID()||($data['actorPHID']??null)!==$actor->getPHID()||
      !is_array($data['xactionPHIDs']??null)||!$data['xactionPHIDs']||count($data['xactionPHIDs'])>16){
      throw new RuntimeException('private_mail_owned_publication_identity_refused');}
    $xactions=(new PhabricatorAuthSSHKeyTransactionQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withObjectPHIDs(array($object->getPHID()))->withPHIDs($data['xactionPHIDs'])->execute();
    if(count($xactions)!==count(array_unique($data['xactionPHIDs']))){throw new RuntimeException('private_mail_owned_publication_transactions_refused');}
    (new PhabricatorApplicationTransactionPublishWorker($data))->executeTask();$publisher_count++;
  };
  $apply_key=static function($key,$actor,array $xactions)use($publish){
    $table=new PhabricatorWorkerActiveTask();$before=(int)queryfx_one($table->establishConnection('r'),
      'SELECT COALESCE(MAX(id),0) n FROM %R',$table)['n'];
    $auth=governance_native_request($actor,new PhabricatorAuthSessionEngine());
    (new PhabricatorAuthSSHKeyEditor())->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])
      ->setCancelURI('/auth/sshkey/')->setContinueOnNoEffect(true)->setIsSilent(false)->applyTransactions($key,$xactions);
    $publish($key,$auth['viewer'],$before);
  };
  foreach(array('withmail'=>true,'withoutmail'=>false)as$label=>$deliverable){
    $owner=$new_user($label,$deliverable);
    // A well-formed synthetic public key wire blob; no private key is created,
    // and this tests native storage/mutation, not an SSH handshake.
    $public=pack('N',11).'ssh-ed25519'.pack('N',32).hash('sha256',$fixture->getNamespace().$label,true);
    $raw='ssh-ed25519 '.base64_encode($public).' synthetic-public-only';
    $key=PhabricatorAuthSSHKey::initializeNewSSHKey($owner,$owner);$before_mail=$latest_mail();
    $apply_key($key,$owner,array((new PhabricatorAuthSSHKeyTransaction())->setTransactionType(PhabricatorTransactions::TYPE_CREATE),
      (new PhabricatorAuthSSHKeyTransaction())->setTransactionType(PhabricatorAuthSSHKeyTransaction::TYPE_NAME)->setNewValue('Synthetic SSH key'),
      (new PhabricatorAuthSSHKeyTransaction())->setTransactionType(PhabricatorAuthSSHKeyTransaction::TYPE_KEY)->setNewValue($raw)));
    $created=(new PhabricatorAuthSSHKey())->load($key->getID());$mails=$mails_since($before_mail,'PhabricatorAuthSSHKeyEditor');
    $check($created&&$created->getObjectPHID()===$owner->getPHID()&&(bool)$created->getIsActive(),
      'private-mail-ssh-'.$label.'-real-native-create-and-owned-publish-commit');
    if($deliverable){
      $check(count($mails)===1&&head($mails)->getPrivateMailPolicyValue('governance.mail-flow')==='account.ssh'&&
        PhorgePrivateMailPolicy::authorize(head($mails))&&isset(head($mails)->prepareGorgeDelivery()['authorization']),
        'private-mail-ssh-real-create-notice-current-owner-key-and-address-bound');
    }else{
      $allowed=false;foreach($mails as$mail){$allowed=$allowed||PhorgePrivateMailPolicy::authorize($mail);}
      $check(!$allowed,'private-mail-ssh-no-primary-create-does-not-authorize-undeliverable-notice');
    }
    $before_mail=$latest_mail();
    $apply_key($key,$owner,array((new PhabricatorAuthSSHKeyTransaction())
      ->setTransactionType(PhabricatorAuthSSHKeyTransaction::TYPE_NAME)->setNewValue('Renamed synthetic SSH key')));
    $mails=$mails_since($before_mail,'PhabricatorAuthSSHKeyEditor');
    $check((new PhabricatorAuthSSHKey())->load($key->getID())->getName()==='Renamed synthetic SSH key',
      'private-mail-ssh-'.$label.'-real-native-rename-commits');
    if($deliverable){$check(count($mails)===1&&PhorgePrivateMailPolicy::authorize(head($mails)),
      'private-mail-ssh-real-rename-publisher-owner-notice-bound');}
    $before_mail=$latest_mail();
    $apply_key($key,$owner,array((new PhabricatorAuthSSHKeyTransaction())
      ->setTransactionType(PhabricatorAuthSSHKeyTransaction::TYPE_DEACTIVATE)->setNewValue(true)));
    $mails=$mails_since($before_mail,'PhabricatorAuthSSHKeyEditor');
    $check(!(bool)(new PhabricatorAuthSSHKey())->load($key->getID())->getIsActive(),
      'private-mail-ssh-'.$label.'-real-native-revoke-commits');
    if($deliverable){$check(count($mails)===1&&PhorgePrivateMailPolicy::authorize(head($mails)),
      'private-mail-ssh-revocation-alert-retains-native-inactive-key-history');}
    $refused=false;$auth=governance_native_request($owner,new PhabricatorAuthSessionEngine());
    try{(new PhabricatorAuthSSHKeyEditor())->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])
      ->setCancelURI('/auth/sshkey/')->applyTransactions($key,array((new PhabricatorAuthSSHKeyTransaction())
        ->setTransactionType(PhabricatorAuthSSHKeyTransaction::TYPE_DEACTIVATE)->setNewValue(false)));}
    catch(PhabricatorApplicationTransactionValidationException$error){
      $errors=$error->getErrors();$refused=(bool)$errors;
      foreach($errors as$validation_error){if($validation_error->getType()!==PhabricatorAuthSSHKeyTransaction::TYPE_DEACTIVATE){throw$error;}}
    }
    $check($refused&&!(bool)(new PhabricatorAuthSSHKey())->load($key->getID())->getIsActive(),
      'private-mail-ssh-'.$label.'-native-reactivation-stays-refused');
  }
  $admin=$new_user('admin',true,true);$subject=$new_user('account');$auth=governance_native_request($admin,new PhabricatorAuthSessionEngine());
  $conn=$subject->establishConnection('w');$old_name=$subject->getUsername();$before=$latest_mail();$conn->openTransaction();
  try{
    (new PhabricatorUserTransactionEditor())->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])->setCancelURI('/people/')
      ->applyTransactions($subject,array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserUsernameTransaction::TRANSACTIONTYPE)
        ->setNewValue('pmremainingrolledback'.$suffix)));
    $escaped=$mails_since($before,'PhabricatorPeopleUsernameMailEngine');
    $check(count($escaped)===1,'private-mail-username-real-producer-created-mail-inside-outer-native-transaction');
    $conn->killTransaction();
  }catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
  $expired=head($escaped);queryfx($expired->establishConnection('w'),'UPDATE %R SET dateCreated=UNIX_TIMESTAMP()-301 WHERE id=%d',$expired,$expired->getID());
  $escaped=array((new PhabricatorMetaMTAMail())->load($expired->getID()));
  $check((new PhabricatorUser())->load($subject->getID())->getUsername()===$old_name&&
    !PhorgePrivateMailPolicy::authorize(head($escaped))&&head($escaped)->prepareGorgeDelivery()===array('state'=>'void'),
    'private-mail-username-outer-native-rollback-orphan-notice-cannot-deliver');
  return array('actual_ssh_create_rename_revoke_and_reactivation_checks'=>true,'owned_publication_tasks_executed'=>$publisher_count,
    'actual_native_username_outer_rollback_source_checked'=>true,'ssh_login_or_cryptographic_handshake_proved'=>false,
    'smtp_producer_matrix_complete'=>false,'device_ssh_producer_matrix_complete'=>false,'legalpad_supported_signer_flow'=>false);
}
