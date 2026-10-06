<?php

final class PhabricatorMailSendmailAdapter
  extends PhabricatorMailAdapter {

  const ADAPTERTYPE = 'sendmail';

  public function getSupportedMessageTypes() {
    return array(
      PhabricatorMailEmailMessage::MESSAGETYPE,
    );
  }

  public function supportsMessageIDHeader() {
    return $this->guessIfHostSupportsMessageID(
      $this->getOption('message-id'),
      null);
  }

  protected function validateOptions(array $options) {
    PhutilTypeSpec::checkMap(
      $options,
      array(
        'message-id' => 'bool|null',
      ));
  }

  public function newDefaultOptions() {
    return array(
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
