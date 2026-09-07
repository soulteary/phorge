<?php

/**
 * HTTP client for the Gorge mailer service.
 *
 * Gorge is a Go service which fronts SMTP, Sendmail, Amazon SES, SendGrid,
 * Mailgun and Postmark with one HTTP API, picking a backend by priority and
 * failing over between them.
 *
 * Request building and envelope parsing live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share. This class adds the two mailer routes, serializes messages onto the
 * wire, and raises "ERR_PERMANENT_FAILURE" as a mail-specific exception.
 *
 * Unlike @{class:PhabricatorGorgeRenderClient}, this client reads nothing
 * from @{class:PhabricatorEnv}: the endpoint and token are injected by
 * @{class:PhabricatorMailGorgeAdapter} from the options of the
 * `cluster.mailers` entry which selected it. That keeps mailer configuration
 * in one place and lets an install point two entries at two services.
 */
final class PhabricatorGorgeMailerClient
  extends PhabricatorGorgeServiceClient {

  const PATH_SEND = '/api/mailer/send';
  const PATH_MAILERS = '/api/mailer/mailers';

  protected static function getServiceName() {
    return pht('Gorge mailer service');
  }

  protected function getDefaultTimeout() {
    // A slow name lookup is bounded only by this timeout and can not be made
    // to fail sooner; see the note in
    // @{method:PhabricatorGorgeServiceClient::newRequestFuture}. It matters
    // less here than it does for a page render, since a stalled send blocks
    // one queue worker, but it is why the timeout is a required part of the
    // mailer options rather than something to leave generous.
    return 30;
  }

  public function setURI($uri) {
    // Name the mailer option rather than a configuration key: this endpoint
    // comes from a `cluster.mailers` entry, so that is where an install has
    // to go to fix it.
    if (!phutil_nonempty_string($uri)) {
      throw new Exception(
        pht(
          'Mailer option "%s" is required to reach the Gorge mailer service, '.
          'but it is empty.',
          'uri'));
    }

    return parent::setURI($uri);
  }

  public function setTimeout($timeout) {
    // The mailer option is declared "optional int", so an entry which spells
    // it out as null -- or as zero -- reaches us intact. Passing that through
    // would leave the request unbounded, and an unbounded send holds a queue
    // worker forever, so keep the default instead.
    if ($timeout === null || (int)$timeout <= 0) {
      return $this;
    }

    return parent::setTimeout((int)$timeout);
  }


  /**
   * Hand a message to the service for delivery.
   *
   * @param PhabricatorMailExternalMessage $message Message to deliver.
   * @param list<string> $mailer_keys Restrict delivery to these backends, by
   *   the keys the service knows them under. Empty means "any backend", which
   *   is what callers normally want: the service already orders its backends
   *   by priority and fails over between them.
   * @return wild The "data" section of the response envelope.
   */
  public function sendMessage(
    PhabricatorMailExternalMessage $message,
    array $mailer_keys = array()) {

    $body = array(
      'message' => $this->serializeMessage($message),
    );

    if ($mailer_keys) {
      $body['mailerKeys'] = array_values($mailer_keys);
    }

    $uri = $this->getURI().self::PATH_SEND;

    $future = $this->newJSONRequestFuture($uri, $body);

    return self::parseResponseEnvelope($uri, $future->resolve());
  }


  /**
   * List the backends the service has configured.
   *
   * This is a one-shot call used by diagnostics.
   *
   * @return wild Backend list reported by the service.
   */
  public function getMailers() {
    $uri = $this->getURI().self::PATH_MAILERS;

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }


  /**
   * Convert a message into the wire form the service expects.
   *
   * Field names are camelCase and are part of a frozen contract with the Go
   * side; renaming one here drops it silently, since unknown keys are ignored
   * when the request is decoded.
   *
   * @param PhabricatorMailExternalMessage $message Message to serialize.
   * @return map<string, wild> Wire representation of the message.
   */
  private function serializeMessage(
    PhabricatorMailExternalMessage $message) {

    $result = array();

    $from = $message->getFromAddress();
    if ($from) {
      $result['from'] = $this->serializeAddress($from);
    }

    $reply_to = $message->getReplyToAddress();
    if ($reply_to) {
      $result['replyTo'] = $this->serializeAddress($reply_to);
    }

    $to_addresses = $message->getToAddresses();
    if ($to_addresses) {
      $to = array();
      foreach ($to_addresses as $address) {
        $to[] = $this->serializeAddress($address);
      }
      $result['to'] = $to;
    }

    $cc_addresses = $message->getCCAddresses();
    if ($cc_addresses) {
      $cc = array();
      foreach ($cc_addresses as $address) {
        $cc[] = $this->serializeAddress($address);
      }
      $result['cc'] = $cc;
    }

    $subject = $message->getSubject();
    if ($subject !== null) {
      $result['subject'] = $subject;
    }

    $text_body = $message->getTextBody();
    if (phutil_nonempty_string($text_body)) {
      $result['textBody'] = $text_body;
    }

    $html_body = $message->getHTMLBody();
    if (phutil_nonempty_string($html_body)) {
      $result['htmlBody'] = $html_body;
    }

    $headers = $message->getHeaders();
    if ($headers) {
      $header_list = array();
      foreach ($headers as $header) {
        $header_list[] = array(
          'name' => $header->getName(),
          'value' => $header->getValue(),
        );
      }
      $result['headers'] = $header_list;
    }

    $attachments = $message->getAttachments();
    if ($attachments) {
      $file_list = array();
      foreach ($attachments as $attachment) {
        // Attachment bodies are arbitrary bytes and the request is JSON, so
        // they are base64 encoded here and decoded by the service before it
        // builds MIME. Encoding on this side rather than leaving it to the
        // backend keeps the wire format the same for all of them.
        $file_list[] = array(
          'filename' => $attachment->getFilename(),
          'mimeType' => $attachment->getMimeType(),
          'data' => base64_encode($attachment->getData()),
        );
      }
      $result['attachments'] = $file_list;
    }

    return $result;
  }

  private function serializeAddress(PhutilEmailAddress $address) {
    return array(
      'name' => (string)$address->getDisplayName(),
      'address' => $address->getAddress(),
    );
  }


  /**
   * Raise "ERR_PERMANENT_FAILURE" as a mail-specific exception.
   *
   * That distinction is the whole point of the code. Anything else -- every
   * other envelope error, and every transport failure, which the shared
   * parser raises as a plain exception -- leaves the message queued for
   * another attempt, which is right for a refused connection or a throttled
   * provider but wrong for a malformed recipient: the service has told us
   * that retrying will fail the same way forever, and this exception is how
   * the mail stack is told to stop and mark the message as failed.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param string $code Error code from the envelope.
   * @param string $message Error message from the envelope.
   * @return Exception Exception to raise.
   */
  protected static function newServiceErrorException($uri, $code, $message) {
    if ($code === 'ERR_PERMANENT_FAILURE') {
      return new PhabricatorMetaMTAPermanentFailureException(
        pht(
          'The Gorge mailer service at "%s" permanently rejected this '.
          'message: %s',
          $uri,
          $message));
    }

    return parent::newServiceErrorException($uri, $code, $message);
  }

}
