<?php

abstract class PhabricatorPeopleMailEngine
  extends Phobject {

  private $sender;
  private $recipient;
  private $recipientAddress;
  private $activityLog;

  final public function setSender(PhabricatorUser $sender) {
    $this->sender = $sender;
    return $this;
  }

  final public function getSender() {
    if (!$this->sender) {
      throw new PhutilInvalidStateException('setSender');
    }
    return $this->sender;
  }

  final public function setRecipient(PhabricatorUser $recipient) {
    $this->recipient = $recipient;
    return $this;
  }

  /**
   * @return PhabricatorUser
   */
  final public function getRecipient() {
    if (!$this->recipient) {
      throw new PhutilInvalidStateException('setRecipient');
    }
    return $this->recipient;
  }

  final public function setRecipientAddress(PhutilEmailAddress $address) {
    $this->recipientAddress = $address;
    return $this;
  }

  final public function getRecipientAddress() {
    if (!$this->recipientAddress) {
      throw new PhutilInvalidStateException('recipientAddress');
    }
    return $this->recipientAddress;
  }

  final public function hasRecipientAddress() {
    return ($this->recipientAddress !== null);
  }

  final public function setActivityLog(PhabricatorUserLog $activity_log) {
    $this->activityLog = $activity_log;
    return $this;
  }

  final public function getActivityLog() {
    return $this->activityLog;
  }

  final public function canSendMail() {
    try {
      $this->validateMail();
      return true;
    } catch (PhabricatorPeopleMailEngineException $ex) {
      return false;
    }
  }

  final public function sendMail() {
    $this->validateMail();
    // Rename is a committed account mutation with an optional notification.
    // An undeliverable account must not roll back the underlying rename.
    if(PhorgePrivateMailPolicy::enabled()&&get_class($this)==='PhabricatorPeopleUsernameMailEngine'&&
      !$this->hasRecipientAddress()&&!$this->getRecipient()->loadPrimaryEmail()){return null;}
    $mail = $this->newMail();

    if ($this->hasRecipientAddress()) {
      $recipient_address = $this->getRecipientAddress();
      $mail->addRawTos(array($recipient_address->getAddress()));
    } else {
      $recipient = $this->getRecipient();
      $mail->addTos(array($recipient->getPHID()));
    }

    if(PhorgePrivateMailPolicy::enabled()){
      $flows=array('PhabricatorPeopleEmailLoginMailEngine'=>'account.login',
        'PhabricatorPeopleWelcomeMailEngine'=>'account.welcome','PhabricatorPeopleUsernameMailEngine'=>'account.username');
      $flow=idx($flows,get_class($this));
      if($flow!==null){$recipient=$this->getRecipient();$email=null;
        if($this->hasRecipientAddress()){$email=(new PhabricatorUserEmail())->loadOneWhere('userPHID=%s AND address=%s',
          $recipient->getPHID(),$this->getRecipientAddress()->getAddress());
          if(!$email){throw new RuntimeException('private_mail_native_destination_required');}}
        $admin=$flow==='account.welcome'?$this->getSender()->getPHID():null;
        $binding=$flow==='account.username'?PhorgePrivateMailPolicy::userEventBinding($recipient,$recipient,
          $this->getPrivateMailSourceTransactionPHID(),PhabricatorUserUsernameTransaction::TRANSACTIONTYPE,$email):
          PhorgePrivateMailPolicy::userBinding($recipient,$recipient,$email,$admin);
        $mail->setPrivateMailFlow($flow,$binding);
      }
    }

    $activity_log = $this->getActivityLog();
    if ($activity_log) {
      $activity_log->save();

      $body = array();
      $body[] = rtrim($mail->getBody(), "\n");
      $body[] = pht('Activity Log ID: #%d', $activity_log->getID());
      $body = implode("\n\n", $body)."\n";

      $mail->setBody($body);
    }

    $mail
      ->setForceDelivery(true)
      ->save();

    return $mail;
  }

  abstract public function validateMail();
  abstract protected function newMail();

  final protected function throwValidationException($title, $body) {
    throw new PhabricatorPeopleMailEngineException($title, $body);
  }

  final protected function newRemarkupText($text) {
    $recipient = $this->getRecipient();

    $engine = PhabricatorMarkupEngine::newMarkupEngine(array())
      ->setConfig('viewer', $recipient)
      ->setConfig('uri.base', PhabricatorEnv::getProductionURI('/'))
      ->setMode(PhutilRemarkupEngine::MODE_TEXT);

    $rendered_text = $engine->markupText($text);
    $rendered_text = rtrim($rendered_text, "\n");

    return $rendered_text;
  }

}
