<?php

final class PhabricatorMailPostmarkAdapter
  extends PhabricatorMailAdapter {

  const ADAPTERTYPE = 'postmark';

  public function getSupportedMessageTypes() {
    return array(
      PhabricatorMailEmailMessage::MESSAGETYPE,
    );
  }

  protected function validateOptions(array $options) {
    PhutilTypeSpec::checkMap(
      $options,
      array(
        'access-token' => 'string',
        'inbound-addresses' => 'list<string>',
      ));

    // Make sure this is properly formatted.
    PhutilCIDRList::newList($options['inbound-addresses']);
  }

  public function newDefaultOptions() {
    return array(
      'access-token' => null,
      'inbound-addresses' => array(
        // Via Postmark support circa February 2018, see:
        //
        // https://postmarkapp.com/support/article/800-ips-for-firewalls
        //
        // "Configuring Outbound Email" should be updated if this changes.
        //
        // These addresses were last updated in December 2021.
        '50.31.156.6/32',
        '50.31.156.77/32',
        '18.217.206.57/32',
        '3.134.147.250/32',
      ),
    );
  }

  public function sendMessage(PhabricatorMailExternalMessage $message) {
    throw new Exception(pht(
      'Native email delivery has been retired. Configure an outbound '.
      'cluster.mailers entry of type "gorge" and move provider settings '.
      'to the Gorge mailer. Keep this provider entry inbound-only.'));
  }

}
