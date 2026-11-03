<?php

final class PhabricatorUserApproveTransaction
  extends PhabricatorUserTransactionType {

  const TRANSACTIONTYPE = 'user.approve';

  public function generateOldValue($object) {
    return (bool)$object->getIsApproved();
  }

  public function generateNewValue($object, $value) {
    return (bool)$value;
  }

  public function applyInternalEffects($object, $value) {
    $object->setIsApproved((int)$value);
  }

  public function applyExternalEffects($object, $value) {
    $user = $object;

    $actor = $this->getActor();
    $title = pht(
      '%s Account "%s" Approved',
      PlatformSymbols::getPlatformServerName(),
      $user->getUsername());

    $body = sprintf(
      "%s\n\n  %s\n\n",
      pht(
        'Your %s account (%s) has been approved by %s. You can '.
        'login here:',
        PlatformSymbols::getPlatformServerName(),
        $user->getUsername(),
        $actor->getUsername()),
      PhabricatorEnv::getProductionURI('/'));

    if(PhorgePrivateMailPolicy::enabled()){
      $owner_email=$user->loadPrimaryEmail();
      if($owner_email){
      $binding=PhorgePrivateMailPolicy::userEventBinding($user,$user,
        $this->getTransactionPHIDForMailSource(),self::TRANSACTIONTYPE,$owner_email);
      (new PhabricatorMetaMTAMail())->setPrivateMailProducer(__CLASS__)->setPrivateMailFlow('account.approved.owner',$binding)
        ->addTos(array($user->getPHID()))->setForceDelivery(true)
        ->setSubject($value?pht('Account Approved'):pht('Account Approval Withdrawn'))
        ->setBody(($value?pht('Your account has been approved. Sign in to continue.'):
          pht('Your account approval has been withdrawn. Contact an administrator if you need access.'))."\n\n".PhabricatorEnv::getProductionURI('/'))
        ->saveAndSend();
      }
      $actor_email=$actor->loadPrimaryEmail();
      if($actor->getPHID()!==$user->getPHID()&&$actor_email){
        $binding=PhorgePrivateMailPolicy::userEventBinding($user,$actor,
          $this->getTransactionPHIDForMailSource(),self::TRANSACTIONTYPE,$actor_email,$actor->getPHID());
        (new PhabricatorMetaMTAMail())->setPrivateMailProducer(__CLASS__)->setPrivateMailFlow('account.approved.admin',$binding)
          ->addTos(array($actor->getPHID()))->setForceDelivery(true)->setSubject($title)->setBody($body)->saveAndSend();
      }
      return;
    }

    $mail = id(new PhabricatorMetaMTAMail())
      ->setPrivateMailProducer(__CLASS__)
      ->addTos(array($user->getPHID()))
      ->addCCs(array($actor->getPHID()))
      ->setSubject(
        pht(
          '[%s] %s',
          PlatformSymbols::getPlatformServerName(),
          $title))
      ->setForceDelivery(true)
      ->setBody($body)
      ->saveAndSend();
  }

  public function getTitle() {
    $new = $this->getNewValue();
    if ($new) {
      return pht(
        '%s approved this user.',
        $this->renderAuthor());
    } else {
      return pht(
        '%s rejected this user.',
        $this->renderAuthor());
    }
  }

  public function shouldHideForFeed() {
    return true;
  }

  public function validateTransactions($object, array $xactions) {
    $actor = $this->getActor();
    $errors = array();

    foreach ($xactions as $xaction) {
      $is_approved = (bool)$object->getIsApproved();

      if ((bool)$xaction->getNewValue() === $is_approved) {
        continue;
      }

      $is_admin = $actor->getIsAdmin();
      $is_omnipotent = $actor->isOmnipotent();

      if (!$is_admin && !$is_omnipotent) {
        $errors[] = $this->newInvalidError(
          pht('You must be an administrator to approve users.'));
      }
    }

    return $errors;
  }

  public function getRequiredCapabilities(
    $object,
    PhabricatorApplicationTransaction $xaction) {

    // Unlike normal user edits, approvals require admin permissions, which
    // is enforced by validateTransactions().

    return null;
  }
}
