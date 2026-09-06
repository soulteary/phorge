<?php

/**
 * HTTP client for the Gorge mailer service.
 *
 * Gorge is a Go service which fronts SMTP, Sendmail, Amazon SES, SendGrid,
 * Mailgun and Postmark with one HTTP API, picking a backend by priority and
 * failing over between them.
 *
 * Requests are authenticated with an "X-Service-Token" header, and every
 * "/api/" route answers with a "{data, error}" envelope in which exactly one
 * of the two keys is populated. The envelope is present on error responses
 * too, so callers should read it before falling back to the HTTP status.
 *
 * Unlike @{class:PhabricatorGorgeRenderClient}, this client reads nothing
 * from @{class:PhabricatorEnv}: the endpoint and token are injected by
 * @{class:PhabricatorMailGorgeAdapter} from the options of the
 * `cluster.mailers` entry which selected it. That keeps mailer configuration
 * in one place and lets an install point two entries at two services.
 */
final class PhabricatorGorgeMailerClient extends Phobject {

  const PATH_SEND = '/api/mailer/send';
  const PATH_MAILERS = '/api/mailer/mailers';

  private $baseURI;
  private $token;
  private $timeout = 30;

  public function setURI($uri) {
    if (!phutil_nonempty_string($uri)) {
      throw new Exception(
        pht(
          'Mailer option "%s" is required to reach the Gorge mailer service, '.
          'but it is empty.',
          'uri'));
    }

    // Trailing slashes matter: the service routes exactly, and a doubled
    // slash produces an "ERR_NOT_FOUND" envelope rather than a delivery.
    $this->baseURI = rtrim($uri, '/');

    return $this;
  }

  public function getURI() {
    return $this->baseURI;
  }

  public function setToken($token) {
    if (!phutil_nonempty_string($token)) {
      $token = null;
    }

    $this->token = $token;

    return $this;
  }

  public function setTimeout($timeout) {
    // The mailer option is declared "optional int", so an entry which spells
    // it out as null -- or as zero -- reaches us intact. Passing that through
    // would leave the request unbounded, and an unbounded send holds a queue
    // worker forever, so keep the default instead.
    if ($timeout === null || (int)$timeout <= 0) {
      return $this;
    }

    $this->timeout = (int)$timeout;

    return $this;
  }

  public function getTimeout() {
    return $this->timeout;
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

    $uri = $this->baseURI.self::PATH_SEND;

    $future = $this->newRequestFuture($uri)
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/json');

    $future->setData(phutil_json_encode($body));

    return $this->parseResponseEnvelope($uri, $future->resolve());
  }


  /**
   * List the backends the service has configured.
   *
   * This is a one-shot call used by diagnostics.
   *
   * @return wild Backend list reported by the service.
   */
  public function getMailers() {
    $uri = $this->baseURI.self::PATH_MAILERS;

    $result = $this->newRequestFuture($uri)->resolve();

    return $this->parseResponseEnvelope($uri, $result);
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
   * Unwrap the "{data, error}" envelope of an API response.
   *
   * This mirrors @{method:PhabricatorGorgeRenderClient::parseResponseEnvelope}
   * -- read the envelope first and only fall back to the status code when it
   * can not be parsed, because "error.code" says far more than the status --
   * with one addition: "ERR_PERMANENT_FAILURE" is raised as a
   * @{class:PhabricatorMetaMTAPermanentFailureException} rather than a plain
   * exception.
   *
   * That distinction is the whole point of the code. Anything else leaves the
   * message queued for another attempt, which is right for a refused
   * connection or a throttled provider but wrong for a malformed recipient:
   * the service has told us that retrying will fail the same way forever, and
   * this exception is how the mail stack is told to stop and mark the message
   * as failed.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param wild $result Raw result of an @{class@arcanist:HTTPSFuture}.
   * @return wild The "data" section of the envelope.
   */
  private function parseResponseEnvelope($uri, $result) {
    list($status, $body) = $result;

    $status_code = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      $status_code = $status->getStatusCode();
    } else if ($status instanceof Exception) {
      // The request never produced a response: connection refused, DNS
      // failure, timeout, and so on. There is no envelope to read, and none
      // of these are permanent -- the mail should be tried again later.
      throw new Exception(
        pht(
          'Request to the Gorge mailer service at "%s" failed: %s',
          $uri,
          $status->getMessage()));
    }

    $envelope = null;
    try {
      $envelope = phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      // Continue: this is reported below, with the status code if we have
      // one, since the status is the more useful diagnostic in that case.
    }

    if ($envelope !== null) {
      $error = idx($envelope, 'error');
      if ($error) {
        if (!is_array($error)) {
          $error = array('message' => $error);
        }

        $error_code = idx($error, 'code', 'UNKNOWN');
        $error_message = idx($error, 'message', '');

        if ($error_code === 'ERR_PERMANENT_FAILURE') {
          throw new PhabricatorMetaMTAPermanentFailureException(
            pht(
              'The Gorge mailer service at "%s" permanently rejected this '.
              'message: %s',
              $uri,
              $error_message));
        }

        throw new Exception(
          pht(
            'The Gorge mailer service returned an error for "%s" [%s]: %s',
            $uri,
            $error_code,
            $error_message));
      }
    }

    if ($status_code !== null && $status_code != 200) {
      throw new Exception(
        pht(
          'The Gorge mailer service returned HTTP %d for "%s": %s',
          $status_code,
          $uri,
          $body));
    }

    if ($envelope === null) {
      throw new Exception(
        pht(
          'The Gorge mailer service returned an invalid JSON response '.
          'for "%s".',
          $uri));
    }

    return idx($envelope, 'data', array());
  }

  private function newRequestFuture($uri) {
    $future = id(new HTTPSFuture($uri))
      ->addHeader('Accept', 'application/json')
      ->setTimeout($this->timeout);

    // See the note in PhabricatorGorgeRenderClient::newRequestFuture(): a slow
    // name lookup is bounded only by this timeout and can not be made to fail
    // sooner. It matters less here, since a stalled send blocks one queue
    // worker rather than a page render, but it is why the timeout is a
    // required part of the mailer options rather than something to leave
    // generous.

    if ($this->token !== null) {
      $future->addHeader('X-Service-Token', $this->token);
    }

    return $future;
  }

}
