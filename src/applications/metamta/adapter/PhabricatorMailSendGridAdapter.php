<?php

/**
 * Mail adapter that uses SendGrid's web API to deliver email.
 */
final class PhabricatorMailSendGridAdapter
  extends PhabricatorMailAdapter {

  const ADAPTERTYPE = 'sendgrid';

  public function getSupportedMessageTypes() {
    return array(
      PhabricatorMailEmailMessage::MESSAGETYPE,
    );
  }

  protected function validateOptions(array $options) {
    PhutilTypeSpec::checkMap(
      $options,
      array(
        'api-key' => 'string',
      ));
  }

  public function newDefaultOptions() {
    return array(
      'api-key' => null,
    );
  }

  public function sendMessage(PhabricatorMailExternalMessage $message) {
    throw new Exception(pht(
      'Native email delivery has been retired. Configure an outbound '.
      'cluster.mailers entry of type "gorge" and move provider settings '.
      'to the Gorge mailer. Keep this provider entry inbound-only.'));
  }

}
