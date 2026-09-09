<?php

/**
 * HTTP client for the Gorge file storage service.
 *
 * Gorge is a Go service which fronts MySQL blob storage, local disk and
 * S3-compatible object stores with one HTTP API, picking a backend by
 * priority and moving down the list when a write fails.
 *
 * Request building and envelope parsing live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share. This class adds the file routes and reads its endpoint from
 * @{class:PhabricatorEnv}, the same way
 * @{class:PhabricatorGorgeRenderClient} does.
 *
 * The bytes of a file travel as an "application/octet-stream" body in both
 * directions rather than encoded into JSON, so this is the one client which
 * uses @{method:PhabricatorGorgeServiceClient::newBinaryRequestFuture} and
 * @{method:PhabricatorGorgeServiceClient::parseBinaryResponse}. Everything
 * which is not file data -- the handle a write produced, the result of a
 * delete, the engine list -- is still a "{data, error}" envelope.
 *
 * Everything the service needs in order to place or find the bytes travels in
 * the query string, including the handle. That is not for brevity: a
 * local-disk handle looks like "ab/cd/{28 hex digits}", and a handle with
 * slashes in it can not be a path segment without the service having to guess
 * where the route ends and the handle begins.
 */
final class PhabricatorGorgeFileStorageClient
  extends PhabricatorGorgeServiceClient {

  const PATH_BLOB = '/api/file/blob';
  const PATH_ENGINES = '/api/file/engines';

  public function __construct() {
    $service = PhabricatorGorgeServiceRegistry::getService('file');
    $uri = $service->getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge file '.
          'storage service, but it is not set.',
          'gorge.file.uri'));
    }

    $this->setURI($uri);
    $this->setToken($service->getConfiguredToken());
  }

  protected static function getServiceName() {
    return pht('Gorge file storage service');
  }

  protected function getDefaultTimeout() {
    // More generous than the render client's 15 seconds and matched to the
    // mailer's 30: a request here carries up to 8MB of file data (see
    // @{class:PhabricatorGorgeFileStorageEngine} for why it is never more
    // than that) and the backend behind the service may be S3 in another
    // region. It is still bounded, because an unbounded write would hold a
    // web request or a queue worker open indefinitely.
    return 30;
  }


  /**
   * Read the configured base URI of the service.
   *
   * @return string|null Base URI with any trailing slash removed, or null if
   *   the service is not configured.
   */
  public static function getConfiguredURI() {
    return PhabricatorGorgeServiceRegistry::getService('file')
      ->getConfiguredURI();
  }

  public static function isConfigured() {
    return (self::getConfiguredURI() !== null);
  }


/* -(  File Data  )---------------------------------------------------------- */


  /**
   * Hand file data to the service and learn where it put it.
   *
   * @param string $data Raw bytes to store.
   * @param map<string, string> $options Optional "engine", "name" and
   *   "mimeType" hints. Leaving "engine" out is the normal case: the service
   *   picks a backend by priority and falls through to the next one if the
   *   write fails, which is the same thing Phorge does with its own engines.
   * @return wild The "data" section of the envelope, with "handle", "engine"
   *   and "size" keys.
   */
  public function writeFile($data, array $options = array()) {
    $params = array();
    foreach (array('engine', 'name', 'mimeType') as $key) {
      $value = idx($options, $key);
      if (phutil_nonempty_string($value)) {
        $params[$key] = $value;
      }
    }

    $uri = $this->newBlobURI($params);

    $future = $this->newBinaryRequestFuture($uri, $data);

    // A write sends bytes but is answered with an envelope: the caller needs
    // the handle and the name of the engine which accepted it, neither of
    // which it can predict.
    return self::parseResponseEnvelope($uri, $future->resolve());
  }


  /**
   * Read file data back out of the service.
   *
   * @param string $engine Identifier of the backend which holds the file.
   * @param string $handle Handle that backend returned when it stored it.
   * @return string Raw bytes. Empty for a zero-byte file, which is a real
   *   thing Phorge stores and not an error.
   */
  public function readFile($engine, $handle) {
    $uri = $this->newBlobURI(
      array(
        'engine' => $engine,
        'handle' => $handle,
      ));

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseBinaryResponse($uri, $result);
  }


  /**
   * Discard file data.
   *
   * @param string $engine Identifier of the backend which holds the file.
   * @param string $handle Handle that backend returned when it stored it.
   * @return wild The "data" section of the envelope.
   */
  public function deleteFile($engine, $handle) {
    $uri = $this->newBlobURI(
      array(
        'engine' => $engine,
        'handle' => $handle,
      ));

    $result = $this->newRequestFuture($uri)
      ->setMethod('DELETE')
      ->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }


  /**
   * List the backends the service has configured.
   *
   * This is a one-shot call used by diagnostics, so it resolves inline
   * instead of returning a future.
   *
   * @return wild Backend list reported by the service. Each entry has
   *   "identifier", "priority", "canWrite" and "sizeLimit" keys.
   */
  public function getEngines() {
    $uri = $this->getURI().self::PATH_ENGINES;

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * Build the URI of the blob route with a query string.
   *
   * @param map<string, string> $params Query parameters to set.
   * @return string Absolute URI.
   */
  private function newBlobURI(array $params) {
    return (string)id(new PhutilURI($this->getURI().self::PATH_BLOB))
      ->setQueryParams($params);
  }

}
