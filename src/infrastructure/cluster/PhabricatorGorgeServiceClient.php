<?php

/**
 * Shared HTTP plumbing for the Gorge service clients.
 */
abstract class PhabricatorGorgeServiceClient extends Phobject {

  private $baseURI;
  private $token;
  private $timeout;

  abstract protected static function getServiceName();
  abstract protected function getDefaultTimeout();

  public function setURI($uri) {
    if (!phutil_nonempty_string($uri)) {
      throw new Exception(
        pht(
          'A base URI is required to reach the %s, but the URI provided is '.
          'empty.',
          static::getServiceName()));
    }

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

  protected function getToken() {
    return $this->token;
  }

  public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }

  public function getTimeout() {
    if ($this->timeout === null) {
      return $this->getDefaultTimeout();
    }

    return $this->timeout;
  }

  /**
   * Probe an unauthenticated Gorge health/readiness endpoint.
   *
   * Setup checks used to duplicate HTTPSFuture construction, timeout handling,
   * status extraction and transport-error conversion. Keep that wire behavior
   * in the same shared HTTP layer as normal Gorge requests, while leaving each
   * setup check responsible for its domain-specific diagnosis and message.
   *
   * @param string $base_uri Service base URI.
   * @param string $path Probe path, for example "/healthz" or "/readyz".
   * @param int $timeout Timeout in seconds.
   * @return map<string, wild> Probe result.
   */
  public static function probeEndpoint($base_uri, $path, $timeout = 5) {
    $uri = rtrim($base_uri, '/').'/'.ltrim($path, '/');
    $future = id(new HTTPSFuture($uri))->setTimeout($timeout);

    list($status, $body) = $future->resolve();

    $status_code = null;
    $error = null;

    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      $status_code = $status->getStatusCode();
    } else if ($status instanceof Exception) {
      $error = $status->getMessage();
    }

    return array(
      'uri' => $uri,
      'ok' => ($status_code === 200),
      'status' => $status_code,
      'body' => (string)$body,
      'error' => $error,
    );
  }

  protected function newRequestFuture($uri) {
    $future = id(new HTTPSFuture($uri))
      ->addHeader('Accept', 'application/json')
      ->setTimeout($this->getTimeout());

    if ($this->token !== null) {
      $future->addHeader('X-Service-Token', $this->token);
    }

    return $future;
  }

  protected function newJSONRequestFuture($uri, $body) {
    $future = $this->newRequestFuture($uri)
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/json');

    $future->setData(phutil_json_encode($body));
    return $future;
  }

  protected function newBinaryRequestFuture($uri, $data) {
    return $this->newRequestFuture($uri)
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/octet-stream')
      ->setData($data);
  }

  protected static function parseBinaryResponse($uri, $result) {
    list($status, $body) = $result;

    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      if ($status->getStatusCode() == 200) {
        return (string)$body;
      }
    }

    static::parseResponseEnvelope($uri, $result);

    throw new Exception(
      pht(
        'The %s returned an unexpected response for "%s".',
        static::getServiceName(),
        $uri));
  }

  protected static function parseResponseEnvelope($uri, $result) {
    list($status, $body) = $result;

    $status_code = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      $status_code = $status->getStatusCode();
    } else if ($status instanceof Exception) {
      throw new Exception(
        pht(
          'Request to the %s at "%s" failed: %s',
          static::getServiceName(),
          $uri,
          $status->getMessage()));
    }

    $envelope = null;
    try {
      $envelope = phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      // Fall through to the HTTP/status diagnostics below.
    }

    if ($envelope !== null) {
      $error = idx($envelope, 'error');
      if ($error) {
        if (!is_array($error)) {
          $error = array('message' => $error);
        }

        throw static::newServiceErrorException(
          $uri,
          idx($error, 'code', 'UNKNOWN'),
          idx($error, 'message', ''));
      }
    }

    if ($status_code !== null && $status_code != 200) {
      throw new Exception(
        pht(
          'The %s returned HTTP %d for "%s": %s',
          static::getServiceName(),
          $status_code,
          $uri,
          $body));
    }

    if ($envelope === null) {
      throw new Exception(
        pht(
          'The %s returned an invalid JSON response for "%s".',
          static::getServiceName(),
          $uri));
    }

    return idx($envelope, 'data', array());
  }

  protected static function newServiceErrorException($uri, $code, $message) {
    return new Exception(
      pht(
        'The %s returned an error for "%s" [%s]: %s',
        static::getServiceName(),
        $uri,
        $code,
        $message));
  }

}
