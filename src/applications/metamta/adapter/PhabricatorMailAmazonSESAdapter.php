<?php

final class PhabricatorMailAmazonSESAdapter
  extends PhabricatorMailAdapter {

  const ADAPTERTYPE = 'ses';

  public function getSupportedMessageTypes() {
    return array(
      PhabricatorMailEmailMessage::MESSAGETYPE,
    );
  }

  protected function validateOptions(array $options) {
    PhutilTypeSpec::checkMap(
      $options,
      array(
        'access-key' => 'string',
        'secret-key' => 'string',
        'region' => 'string',
        'endpoint' => 'string',
      ));
  }

  public function newDefaultOptions() {
    return array(
      'access-key' => null,
      'secret-key' => null,
      'region' => null,
      'endpoint' => null,
    );
  }

  public function sendMessage(PhabricatorMailExternalMessage $message) {
    throw new Exception(pht(
      'Native email delivery has been retired. Configure an outbound '.
      'cluster.mailers entry of type "gorge" and move provider settings '.
      'to the Gorge mailer. Keep this provider entry inbound-only.'));
  }

}
