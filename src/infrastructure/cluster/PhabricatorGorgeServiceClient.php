<?php

/**
 * Shared HTTP plumbing for the Gorge service clients.
 *
 * Gorge is a set of Go services which each front one piece of infrastructure
 * -- source highlighting, mail delivery, fulltext search, file storage --
 * behind an HTTP API. They are separate binaries reached at separate
 * endpoints, but they all answer the same way: requests are authenticated
 * with an "X-Service-Token" header, and an "/api/" route which succeeds with
 * a value returns a "{data, error}" envelope in which exactly one of the two
 * keys is populated. Failures always answer with that envelope, on every
 * route, so callers should read it before falling back to the HTTP status.
 *
 * The one exception to the envelope is the file storage service, which
 * carries file bytes as an "application/octet-stream" body rather than
 * encoding them into JSON. That is a success-path exception only: those
 * routes still fail with an envelope, which is why
 * @{method:parseBinaryResponse} branches on the status code and hands
 * anything but a 200 to @{method:parseResponseEnvelope}.
 *
 * That common shape is what lives here: building an authenticated request and
 * unwrapping the envelope. Subclasses add the business methods for their own
 * routes and say how the service should be named when something fails.
 */
abstract class PhabricatorGorgeServiceClient extends Phobject {

  private $baseURI;
  private $token;
  private $timeout;


  /**
   * Name of the service as it appears in exception messages, like
   * "Gorge render service".
   *
   * This is static because @{method:parseResponseEnvelope} must work without
   * a client instance: a highlight request is resolved by
   * @{class:PhabricatorGorgeHighlightFuture} long after the client which
   * built it has gone out of scope.
   *
   * @return string Name of the service.
   */
  abstract protected static function getServiceName();


  /**
   * Default request timeout, in seconds.
   *
   * Each service gets its own default because the work behind the request
   * differs by an order of magnitude: highlighting a file is interactive,
   * delivering mail is not.
   *
   * @return int Timeout in seconds.
   */
  abstract protected function getDefaultTimeout();


/* -(  Configuration  )------------------------------------------------------ */


  /**
   * Set the base URI of the service.
   *
   * @param string $uri Base URI, with or without a trailing slash.
   * @return $this
   */
  public function setURI($uri) {
    if (!phutil_nonempty_string($uri)) {
      throw new Exception(
        pht(
          'A base URI is required to reach the %s, but the URI provided is '.
          'empty.',
          static::getServiceName()));
    }

    // Trailing slashes matter: the services route exactly, and a doubled
    // slash produces an "ERR_NOT_FOUND" envelope rather than a result.
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


/* -(  Requests  )----------------------------------------------------------- */


  /**
   * Build an unauthenticated liveness/readiness probe request.
   *
   * Gorge probe routes (`/healthz` and `/readyz`) deliberately sit outside
   * the authenticated API envelope. Setup checks across the individual
   * services all need the same short request budget, so keep that transport
   * policy here instead of open-coding `HTTPSFuture` in every check.
   *
   * @param string $uri Absolute probe URI.
   * @return HTTPSFuture Unresolved request.
   */
  public static function newProbeFuture($uri) {
    if (!phutil_nonempty_string($uri)) {
      throw new Exception(pht('A Gorge probe URI is required.'));
    }

    return id(new HTTPSFuture($uri))
      ->setTimeout(5);
  }


  /**
   * Build an authenticated request against a route of this service.
   *
   * @param string $uri Absolute URI to request.
   * @return HTTPSFuture Unresolved request.
   */
  protected function newRequestFuture($uri) {
    $future = id(new HTTPSFuture($uri))
      ->addHeader('Accept', 'application/json')
      ->setTimeout($this->getTimeout());

    // Known limitation: a slow name lookup is bounded only by the timeout
    // above, and can not be made to fail sooner. setTimeout() maps to
    // CURLOPT_TIMEOUT_MS, a ceiling on the request as a whole, and libcurl's
    // threaded resolver can not interrupt a getaddrinfo() which is already
    // running. Adding CURLOPT_CONNECTTIMEOUT through
    // HTTPSFuture::addCURLOption() does not help: measured against a resolver
    // which drops queries, the request took the same 10 seconds with the
    // option set as without it, and the only difference was that the failure
    // was relabelled from CURLE_COULDNT_RESOLVE_HOST to a generic
    // CURLE_OPERATION_TIMEDOUT, which names the problem less clearly. So it is
    // deliberately not set.
    //
    // This bites when a service is configured with a host that does not
    // resolve and the resolver does not answer promptly: every request waits
    // out the timeout before the caller can fall back. Keeping the timeout
    // modest is the only mitigation on this side.

    if ($this->token !== null) {
      $future->addHeader('X-Service-Token', $this->token);
    }

    return $future;
  }


  /**
   * Build an authenticated POST request carrying a JSON body.
   *
   * @param string $uri Absolute URI to request.
   * @param wild $body Structure to encode as the request body.
   * @return HTTPSFuture Unresolved request.
   */
  protected function newJSONRequestFuture($uri, $body) {
    $future = $this->newRequestFuture($uri)
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/json');

    $future->setData(phutil_json_encode($body));

    return $future;
  }


  /**
   * Build an authenticated POST request carrying a raw body.
   *
   * File bytes are sent as-is instead of being encoded into JSON, which keeps
   * the request the same size as the file and keeps neither side holding a
   * base64 copy of it. Everything the service needs in order to place the
   * bytes travels in the query string of $uri, so this method only has to
   * attach the body.
   *
   * @param string $uri Absolute URI to request.
   * @param string $data Raw bytes to send as the request body.
   * @return HTTPSFuture Unresolved request.
   */
  protected function newBinaryRequestFuture($uri, $data) {
    return $this->newRequestFuture($uri)
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/octet-stream')
      ->setData($data);
  }


  /**
   * Read the body of a response which carries raw bytes on success.
   *
   * Branching on the status code rather than on whether the body is empty is
   * the whole point of this method: an empty 200 is the correct response for
   * reading a zero-byte file, and treating an empty body as a failure would
   * make those files unreadable. A response which is not a 200 still carries
   * an envelope, so it is handed to @{method:parseResponseEnvelope} and
   * reported the same way as every other failure.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param wild $result Raw result of an @{class@arcanist:HTTPSFuture}.
   * @return string Raw response body.
   */
  protected static function parseBinaryResponse($uri, $result) {
    list($status, $body) = $result;

    // Check for an HTTP status before checking for an exception: every
    // response status is an Exception subclass, including the ones which
    // describe a perfectly good 200.
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      if ($status->getStatusCode() == 200) {
        return (string)$body;
      }
    }

    static::parseResponseEnvelope($uri, $result);

    // Not reachable in practice: the envelope parser throws for every status
    // which is not a 200, which is every status which reaches this line. It
    // is here so that a future change to that method can not turn a failed
    // read into a silently empty file.
    throw new Exception(
      pht(
        'The %s returned an unexpected response for "%s".',
        static::getServiceName(),
        $uri));
  }


  /**
   * Unwrap the "{data, error}" envelope of an API response.
   *
   * The services keep the envelope in the body when they answer 4xx or 5xx,
   * and the "error.code" it carries ("ERR_NOT_FOUND", "ERR_TOO_LARGE", ...)
   * is far more actionable than a bare status code, so read the envelope
   * first and only fall back to the status when it can not be parsed.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param wild $result Raw result of an @{class@arcanist:HTTPSFuture}.
   * @return wild The "data" section of the envelope.
   */
  protected static function parseResponseEnvelope($uri, $result) {
    list($status, $body) = $result;

    $status_code = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      $status_code = $status->getStatusCode();
    } else if ($status instanceof Exception) {
      // The request never produced a response: connection refused, DNS
      // failure, timeout, and so on. There is no envelope to read.
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
      // Continue: this is reported below, with the status code if we have
      // one, since the status is the more useful diagnostic in that case.
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


  /**
   * Build the exception raised for an "error" section in the envelope.
   *
   * This is a separate method so that a service which has a code the caller
   * must react to differently can raise a different class for it; see
   * @{method:PhabricatorGorgeMailerClient::newServiceErrorException}.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param string $code Error code from the envelope.
   * @param string $message Error message from the envelope.
   * @return Exception Exception to raise.
   */
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
