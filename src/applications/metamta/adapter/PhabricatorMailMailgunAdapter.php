<?php

/**
 * Mail adapter that uses Mailgun's web API to deliver email.
 */
final class PhabricatorMailMailgunAdapter
  extends PhabricatorMailAdapter {

  const ADAPTERTYPE = 'mailgun';

  public function getSupportedMessageTypes() {
    return array(
      PhabricatorMailEmailMessage::MESSAGETYPE,
    );
  }

  public function supportsMessageIDHeader() {
    return true;
  }

  protected function validateOptions(array $options) {
    PhutilTypeSpec::checkMap(
      $options,
      array(
        'api-key' => 'string',
        'domain' => 'string',
        'api-hostname' => 'string',
      ));
  }

  public function newDefaultOptions() {
    return array(
      'api-key' => null,
      'domain' => null,
      'api-hostname' => 'api.mailgun.net',
    );
  }

  public function sendMessage(PhabricatorMailExternalMessage $message) {
    throw new Exception(pht(
      'Native email delivery has been retired. Configure an outbound '.
      'cluster.mailers entry of type "gorge" and move provider settings '.
      'to the Gorge mailer. Keep this provider entry inbound-only.'));
  }

}
