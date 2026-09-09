<?php

/**
 * HTTP client for the Gorge conduit gateway.
 *
 * Gorge is a Go service which, for this domain, fronts Phorge's own Conduit
 * API as a reverse proxy: it authenticates the caller with an
 * "X-Service-Token" header, applies IP rate limiting, and forwards
 * "ANY /api/{method}" to the upstream Phorge "/api/*". It exists to collect
 * authentication, rate limiting and auditing behind a single gateway so that
 * the other Gorge services (and any Go worker) can reach Conduit through one
 * door instead of each holding a direct line to Phorge.
 *
 * Request building and the token header live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share. This class adds the single forwarding route and reads its endpoint
 * from @{class:PhabricatorEnv}, the same way
 * @{class:PhabricatorGorgeRenderClient} does.
 *
 * The one place it parts ways with its siblings is the response shape. The
 * other services answer with the Gorge "{data, error}" envelope, but this
 * one is a pass-through: on success the gateway relays the upstream Conduit
 * body verbatim, which is Conduit's own "{result, error_code, error_info}"
 * envelope. So @{method:callMethod} reads that envelope rather than handing
 * the response to @{method:PhabricatorGorgeServiceClient::parseResponseEnvelope},
 * which would look for keys that are not there.
 */
final class PhabricatorGorgeConduitClient
  extends PhabricatorGorgeServiceClient {

  public function __construct() {
    $uri = self::getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge conduit '.
          'gateway, but it is not set.',
          'gorge.conduit.uri'));
    }

    $this->setURI($uri);
    $this->setToken(
      PhabricatorEnv::getEnvConfigIfExists('gorge.conduit.token'));
  }

  protected static function getServiceName() {
    return pht('Gorge conduit gateway');
  }

  protected function getDefaultTimeout() {
    // Matched to the gateway's own proxy timeout default (30s): a call here
    // is a full round trip through the gateway to upstream Conduit and back,
    // and some Conduit methods do real work. It is still bounded, because an
    // unbounded call would hold a web request or a queue worker open
    // indefinitely.
    return 30;
  }


  /**
   * Read the configured base URI of the service.
   *
   * @return string|null Base URI with any trailing slash removed, or null if
   *   the service is not configured.
   */
  public static function getConfiguredURI() {
    $uri = PhabricatorEnv::getEnvConfigIfExists('gorge.conduit.uri');

    if (!phutil_nonempty_string($uri)) {
      return null;
    }

    // Trailing slashes matter: the gateway routes exactly, and a doubled
    // slash produces a route mismatch rather than a forwarded call.
    return rtrim($uri, '/');
  }

  public static function isConfigured() {
    return (self::getConfiguredURI() !== null);
  }


  /**
   * Build the URI of a Conduit method behind the gateway.
   *
   * @param string $method Conduit method name, like "conduit.ping".
   * @return string Absolute URI.
   */
  public function getMethodURI($method) {
    return $this->getURI().'/api/'.$method;
  }


  /**
   * Call a Conduit method through the gateway.
   *
   * The gateway forwards the call to upstream Conduit and relays the reply
   * verbatim, so the response is Conduit's own "{result, error_code,
   * error_info}" envelope, not the Gorge "{data, error}" one. A non-null
   * "error_code" is raised as an exception; otherwise the "result" section is
   * returned.
   *
   * @param string $method Conduit method name, like "conduit.ping".
   * @param map<string, wild> $params Parameters for the method.
   * @return wild The "result" section of the Conduit envelope.
   */
  public function callMethod($method, array $params = array()) {
    if (!phutil_nonempty_string($method)) {
      throw new Exception(
        pht('A Conduit method name is required to call the %s.',
          self::getServiceName()));
    }

    $uri = $this->getMethodURI($method);

    $result = $this->newJSONRequestFuture($uri, $params)->resolve();

    return self::parseConduitResponse($uri, $result);
  }


  /**
   * Unwrap the "{result, error_code, error_info}" envelope of a Conduit reply.
   *
   * This is the Conduit protocol's own envelope, relayed verbatim by the
   * gateway, so it does not go through
   * @{method:PhabricatorGorgeServiceClient::parseResponseEnvelope}: that
   * method reads the Gorge "{data, error}" shape, which is not what a
   * pass-through Conduit reply carries. Auth and rate-limit failures produced
   * by the gateway itself use the same envelope, so they are reported the
   * same way.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param wild $result Raw result of an @{class@arcanist:HTTPSFuture}.
   * @return wild The "result" section of the envelope.
   */
  private static function parseConduitResponse($uri, $result) {
    list($status, $body) = $result;

    $status_code = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      // HTTP response statuses are Exception subclasses too, including a
      // successful 200. Inspect them before the generic exception branch so
      // valid Conduit responses are not mistaken for transport failures.
      $status_code = $status->getStatusCode();
    } else if ($status instanceof Exception) {
      // The request never produced a response: connection refused, DNS
      // failure, timeout, and so on. There is no envelope to read.
      throw new Exception(
        pht(
          'Request to the %s at "%s" failed: %s',
          self::getServiceName(),
          $uri,
          $status->getMessage()));
    }

    $envelope = null;
    try {
      $envelope = phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      // Continue: reported below, with the status code if we have one.
    }

    if ($envelope !== null) {
      $error_code = idx($envelope, 'error_code');
      if (phutil_nonempty_string($error_code)) {
        throw self::newServiceErrorException(
          $uri,
          $error_code,
          (string)idx($envelope, 'error_info', ''));
      }

      if ($status_code !== null && $status_code != 200) {
        throw new Exception(
          pht(
            'The %s returned HTTP %d for "%s": %s',
            self::getServiceName(),
            $status_code,
            $uri,
            $body));
      }

      return idx($envelope, 'result');
    }

    if ($status_code !== null && $status_code != 200) {
      throw new Exception(
        pht(
          'The %s returned HTTP %d for "%s": %s',
          self::getServiceName(),
          $status_code,
          $uri,
          $body));
    }

    throw new Exception(
      pht(
        'The %s returned an invalid JSON response for "%s".',
        self::getServiceName(),
        $uri));
  }

}
