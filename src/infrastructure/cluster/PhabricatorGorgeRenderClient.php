<?php

/**
 * HTTP client for the Gorge render service.
 *
 * Gorge is a Go service which highlights source code with Chroma and emits
 * HTML using Pygments-compatible CSS class names, so existing stylesheets
 * continue to work without modification.
 *
 * Requests are authenticated with an "X-Service-Token" header, and every
 * "/api/" route answers with a "{data, error}" envelope in which exactly one
 * of the two keys is populated. The envelope is present on error responses
 * too, so callers should read it before falling back to the HTTP status.
 */
final class PhabricatorGorgeRenderClient extends Phobject {

  const PATH_HIGHLIGHT = '/api/highlight/render';
  const PATH_LANGUAGES = '/api/highlight/languages';

  private $baseURI;
  private $token;
  private $timeout = 15;

  public function __construct() {
    $uri = self::getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge render '.
          'service, but it is not set.',
          'gorge.render.uri'));
    }

    $this->baseURI = $uri;

    $token = PhabricatorEnv::getEnvConfigIfExists('gorge.render.token');
    if (!phutil_nonempty_string($token)) {
      $token = null;
    }

    $this->token = $token;
  }


  /**
   * Read the configured base URI of the service.
   *
   * @return string|null Base URI with any trailing slash removed, or null if
   *   the service is not configured.
   */
  public static function getConfiguredURI() {
    $uri = PhabricatorEnv::getEnvConfigIfExists('gorge.render.uri');

    if (!phutil_nonempty_string($uri)) {
      return null;
    }

    // Trailing slashes matter: the service routes exactly, and a doubled
    // slash produces an "ERR_NOT_FOUND" envelope rather than a highlight.
    return rtrim($uri, '/');
  }

  public static function isConfigured() {
    return (self::getConfiguredURI() !== null);
  }

  public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }

  public function getTimeout() {
    return $this->timeout;
  }

  public function getHighlightURI() {
    return $this->baseURI.self::PATH_HIGHLIGHT;
  }


  /**
   * Build a highlight request without resolving it.
   *
   * Callers get an unresolved future so that several requests may be
   * resolved together with a @{class@arcanist:FutureIterator}. Rendering a
   * changeset issues one request per side of the diff, and resolving them
   * one at a time would double the latency of every diff view.
   *
   * @param string $source Source code to highlight.
   * @param string $language Language name or alias.
   * @return HTTPSFuture Unresolved highlight request.
   */
  public function newHighlightFuture($source, $language) {
    $data = array(
      'source' => $source,
      'language' => $language,
    );

    $future = $this->newRequestFuture($this->getHighlightURI())
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/json');

    $future->setData(phutil_json_encode($data));

    return $future;
  }


  /**
   * List the languages the service can highlight.
   *
   * This is a one-shot call used by diagnostics, so it resolves inline
   * instead of returning a future.
   *
   * @return wild Language list reported by the service.
   */
  public function getLanguages() {
    $uri = $this->baseURI.self::PATH_LANGUAGES;

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }


  /**
   * Unwrap the "{data, error}" envelope of an API response.
   *
   * The service keeps the envelope in the body when it answers 4xx or 5xx,
   * and the "error.code" it carries ("ERR_NOT_FOUND", "ERR_TOO_LARGE", ...)
   * is far more actionable than a bare status code, so read the envelope
   * first and only fall back to the status when it can not be parsed.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param wild $result Raw result of an @{class@arcanist:HTTPSFuture}.
   * @return wild The "data" section of the envelope.
   */
  public static function parseResponseEnvelope($uri, $result) {
    list($status, $body) = $result;

    $status_code = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      $status_code = $status->getStatusCode();
    } else if ($status instanceof Exception) {
      // The request never produced a response: connection refused, DNS
      // failure, timeout, and so on. There is no envelope to read.
      throw new Exception(
        pht(
          'Request to the Gorge render service at "%s" failed: %s',
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

        throw new Exception(
          pht(
            'The Gorge render service returned an error for "%s" [%s]: %s',
            $uri,
            idx($error, 'code', 'UNKNOWN'),
            idx($error, 'message', '')));
      }
    }

    if ($status_code !== null && $status_code != 200) {
      throw new Exception(
        pht(
          'The Gorge render service returned HTTP %d for "%s": %s',
          $status_code,
          $uri,
          $body));
    }

    if ($envelope === null) {
      throw new Exception(
        pht(
          'The Gorge render service returned an invalid JSON response '.
          'for "%s".',
          $uri));
    }

    return idx($envelope, 'data', array());
  }

  private function newRequestFuture($uri) {
    $future = id(new HTTPSFuture($uri))
      ->addHeader('Accept', 'application/json')
      ->setTimeout($this->timeout);

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
    // This bites when "gorge.render.uri" names a host that does not resolve
    // and the resolver does not answer promptly: every render of source code
    // waits out the timeout before falling back to the local highlighter.
    // Keeping the timeout modest is the only mitigation on this side.

    if ($this->token !== null) {
      $future->addHeader('X-Service-Token', $this->token);
    }

    return $future;
  }

}
