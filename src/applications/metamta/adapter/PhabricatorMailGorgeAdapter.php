<?php

/**
 * Mail adapter which delegates delivery to the Gorge mailer service.
 *
 * The service speaks SMTP, Sendmail, Amazon SES, SendGrid, Mailgun and
 * Postmark behind one HTTP API, so this adapter only has to serialize the
 * message and hand it over; choosing a backend and failing over between
 * several of them happens on the far side.
 *
 * Unlike the other Gorge integrations in this fork, this one registers no
 * configuration option of its own: the endpoint and the shared token live in
 * the "options" of the `cluster.mailers` entry which selects this adapter.
 * Mailers are already configured one entry at a time, so a global option
 * would be a second place to look with no way to describe two services, and
 * an entry which names a service it can not reach is a more obvious failure
 * than one which silently inherits an endpoint from elsewhere.
 *
 * A minimal entry looks like this:
 *
 *   {
 *     "key": "gorge-mailer",
 *     "type": "gorge",
 *     "inbound": false,
 *     "media": ["email"],
 *     "options": {
 *       "uri": "http://gorge-mailer:8110"
 *     }
 *   }
 *
 * The service delivers outbound mail only and never receives any, so entries
 * should set "inbound" to false. There is no adapter-side hook for that: the
 * flag defaults to true and can only be turned off in the configuration.
 */
final class PhabricatorMailGorgeAdapter
  extends PhabricatorMailAdapter {

  const ADAPTERTYPE = 'gorge';

  public function getSupportedMessageTypes() {
    return array(
      PhabricatorMailEmailMessage::MESSAGETYPE,
    );
  }


  /**
   * Report whether the service preserves a "Message-ID" we choose.
   *
   * This defaults to false because the answer depends on a backend this
   * adapter can not see. The service builds MIME itself for SMTP, Sendmail
   * and SES, and a "Message-ID" survives that path; SendGrid and Postmark
   * assign their own and discard ours. Claiming support we do not have breaks
   * mail threading in a way which is invisible from here -- clients quietly
   * start a new thread per message -- so the default is the safe answer, and
   * installs which know their backend keeps the header can set the option.
   */
  public function supportsMessageIDHeader() {
    return (bool)$this->getOption('supports-message-id');
  }

  protected function validateOptions(array $options) {
    PhutilTypeSpec::checkMap(
      $options,
      array(
        'uri' => 'string',
        'token' => 'optional string',
        'timeout' => 'optional int',
        'supports-message-id' => 'optional bool',
      ));
  }

  public function newDefaultOptions() {
    return array(
      // No default: "uri" is declared as a required string above, so an entry
      // which omits it is rejected when the configuration is validated rather
      // than when the first mail is sent.
      'uri' => null,
      'token' => null,

      // Bounded well below the queue worker's own patience: this call blocks
      // a worker for its whole duration, and the service does its own bounded
      // retrying inside it.
      'timeout' => 30,

      'supports-message-id' => false,
    );
  }

  public function sendMessage(PhabricatorMailExternalMessage $message) {
    $client = id(new PhabricatorGorgeMailerClient())
      ->setURI($this->getOption('uri'))
      ->setToken($this->getOption('token'))
      ->setTimeout($this->getOption('timeout'));

    $client->sendMessage($message);
  }

}
