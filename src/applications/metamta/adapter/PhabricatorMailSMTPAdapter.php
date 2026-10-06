<?php

final class PhabricatorMailSMTPAdapter
  extends PhabricatorMailAdapter {

  const ADAPTERTYPE = 'smtp';

  public function getSupportedMessageTypes() {
    return array(
      PhabricatorMailEmailMessage::MESSAGETYPE,
    );
  }

  public function supportsMessageIDHeader() {
    return $this->guessIfHostSupportsMessageID(
      $this->getOption('message-id'),
      $this->getOption('host'));
  }

  protected function validateOptions(array $options) {
    PhutilTypeSpec::checkMap(
      $options,
      array(
        'host' => 'string|null',
        'port' => 'int',
        'user' => 'string|null',
        'password' => 'string|null',
        'protocol' => 'string|null',
        'message-id' => 'bool|null',
      ));
  }

  public function newDefaultOptions() {
    return array(
      'host' => null,
      'port' => 25,
      'user' => null,
      'password' => null,
      'protocol' => null,
      'message-id' => null,
    );
  }

  public function sendMessage(PhabricatorMailExternalMessage $message) {
    throw new Exception(pht(
      'Native email delivery has been retired. Configure an outbound '.
      'cluster.mailers entry of type "gorge" and move provider settings '.
      'to the Gorge mailer. Keep this provider entry inbound-only.'));
  }

}
