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
    $service = PhabricatorGorgeServiceRegistry::getService('file');
    return !$service->isDisabled() &&
      (self::getConfiguredURI() !== null);
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
   * Fetch public HTTP(S) bytes through Gorge. This does not create a blob or
   * a file object: the caller applies metadata and existing storage formats.
   */
  public function getDownloadCapabilities() {
    $uri = $this->getURI().'/api/file/fetch/meta';
    $caps = self::parseResponseEnvelope(
      $uri, $this->newRequestFuture($uri)->resolve());
    if (!is_array($caps)) {
      throw new Exception(pht('Invalid Gorge download capabilities.'));
    }
    return $caps;
  }

  public static function hasDownloadCapabilities(array $caps) {
    return idx($caps, 'protocolVersion') === 1 &&
      is_int(idx($caps, 'maxBytes')) &&
      idx($caps, 'maxBytes', 0) >= 16 * 1024 * 1024 &&
      idx($caps, 'publicOnly') === true &&
      idx($caps, 'headerTokenOnly') === true &&
      idx($caps, 'pinnedDNS') === true;
  }

  public function downloadFile($remote_uri) {
    if (!self::isConfigured() || !$this->getToken()) {
      throw new Exception(
        pht('Remote download requires an enabled Gorge file service and token.'));
    }
    $uri = $this->getURI().'/api/file/fetch';
    $future = $this->newJSONRequestFuture(
      $uri,
      array(
        'uri' => $remote_uri,
        'denyCIDRs' => PhabricatorEnv::getEnvConfig(
          'security.outbound-blacklist'),
      ));
    return self::parseBinaryResponse($uri, $future->resolve());
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


  public function getLifecycleCapabilities() {
    $uri = $this->getURI().'/api/file/lifecycle/meta';
    return self::parseResponseEnvelope($uri, $this->newRequestFuture($uri)->resolve());
  }

  public function getUploadCapabilities() {
    $uri = $this->getURI().'/api/file/uploads/meta';
    $caps = self::parseResponseEnvelope(
      $uri, $this->newRequestFuture($uri)->resolve());
    if (!is_array($caps)) {
      throw new Exception(pht('Invalid Gorge upload capabilities.'));
    }
    return $caps;
  }

  public static function hasUploadCapabilities(array $caps) {
    return idx($caps, 'protocolVersion') === 1 && idx($caps, 'enabled') === true &&
      idx($caps, 'integrityVersion') === 1 &&
      idx($caps, 'chunkSize') === 4 * 1024 * 1024 &&
      is_int(idx($caps, 'maxSize')) && $caps['maxSize'] > 0 &&
      $caps['maxSize'] <= 64 * 1024 * 1024 * 1024 &&
      idx($caps, 'storageFormat') === 'raw' &&
      idx($caps, 'durability') === 'posix-volume';
  }

  public function createUpload($id, $size) {
    $this->newUploadURI($id);
    $uri = $this->getURI().'/api/file/uploads/session';
    $upload = self::validateUploadResponse($id, self::parseResponseEnvelope($uri,
      $this->newJSONRequestFuture($uri, array('id' => $id, 'size' => $size))->resolve()));
    if ($upload['size'] !== $size) {
      throw new Exception(pht('Gorge upload size does not match allocation.'));
    }
    return $upload;
  }

  private function newUploadURI($id, $suffix = '') {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
      throw new Exception(pht('Invalid Gorge upload handle.'));
    }
    return $this->getURI().'/api/file/uploads/'.$id.$suffix;
  }

  public function getUpload($id) {
    $uri = $this->newUploadURI($id);
    return self::validateUploadResponse($id,
      self::parseResponseEnvelope($uri, $this->newRequestFuture($uri)->resolve()));
  }

  public function getUploadForResume($id) {
    $uri = $this->newUploadURI($id);
    $result = $this->newRequestFuture($uri)->resolve();
    $envelope = json_decode($result[1], true);
    // Only a confirmed tombstone/expiry is terminal. A missing volume, 404 or
    // network failure must not cause the PHP file to be destroyed.
    if ($result[0] instanceof HTTPFutureHTTPResponseStatus &&
        $result[0]->getStatusCode() === 410 && is_array($envelope) &&
        is_array(idx($envelope, 'error')) &&
        idx($envelope['error'], 'code') === 'ERR_UPLOAD_EXPIRED') {
      return null;
    }
    return self::validateUploadResponse($id,
      self::parseResponseEnvelope($uri, $result));
  }

  public function uploadChunk($id, $start, $data) {
    $uri = (string)id(new PhutilURI($this->newUploadURI($id, '/chunk')))
      ->setQueryParam('start', $start);
    return self::validateUploadResponse($id, self::parseResponseEnvelope($uri,
      $this->newBinaryRequestFuture($uri, $data)->setMethod('PUT')->resolve()));
  }

  public function completeUpload($id) {
    $uri = $this->newUploadURI($id, '/complete');
    $upload = self::validateUploadResponse($id, self::parseResponseEnvelope($uri,
      $this->newJSONRequestFuture($uri, array())->resolve()));
    if ($upload['state'] !== 'complete') {
      throw new Exception(pht('Gorge upload completion was not confirmed.'));
    }
    return $upload;
  }

  /** Reject malformed manifests before they can complete a PHP file record. */
  public static function validateUploadResponse($id, $upload) {
    $chunk_size = 4 * 1024 * 1024;
    if (!is_array($upload) || idx($upload, 'id') !== $id ||
        !is_int(idx($upload, 'size')) || $upload['size'] <= 0 ||
        $upload['size'] > 64 * 1024 * 1024 * 1024 ||
        !in_array(idx($upload, 'state'), array('uploading', 'complete'), true) ||
        !is_array(idx($upload, 'chunks')) ||
        count($upload['chunks']) !== (int)ceil($upload['size'] / $chunk_size)) {
      throw new Exception(pht('Invalid Gorge upload manifest.'));
    }
    $start = 0;
    foreach ($upload['chunks'] as $chunk) {
      $end = min($start + $chunk_size, $upload['size']);
      if (!is_array($chunk) || idx($chunk, 'byteStart') !== $start ||
          idx($chunk, 'byteEnd') !== $end || !is_bool(idx($chunk, 'complete')) ||
          ($upload['state'] === 'complete' && !$chunk['complete']) ||
          ($chunk['complete'] && (!is_string(idx($chunk, 'sha256')) ||
            !preg_match('/^[a-f0-9]{64}$/D', $chunk['sha256'])))) {
        throw new Exception(pht('Invalid Gorge upload chunk manifest.'));
      }
      $start = $end;
    }
    if ($upload['state'] === 'complete' &&
        (!is_string(idx($upload, 'sha256')) ||
          !preg_match('/^[a-f0-9]{64}$/D', $upload['sha256']))) {
      throw new Exception(pht('Invalid Gorge completed upload digest.'));
    }
    return $upload;
  }

  public function cancelUpload($id) {
    $uri = $this->newUploadURI($id);
    return self::parseResponseEnvelope($uri,
      $this->newRequestFuture($uri)->setMethod('DELETE')->resolve());
  }

  public function verifyUpload($id) {
    $uri = $this->newUploadURI($id, '/verify');
    $upload = self::validateUploadResponse($id, self::parseResponseEnvelope($uri,
      $this->newJSONRequestFuture($uri, array())->resolve()));
    if ($upload['state'] !== 'complete') {
      throw new Exception(pht('Gorge upload is not complete.'));
    }
    return $upload;
  }

  public function readUploadRange($id, $start, $end) {
    $uri = (string)id(new PhutilURI($this->newUploadURI($id, '/data')))
      ->setQueryParams(array('start' => $start, 'end' => $end));
    $data = self::parseBinaryResponse($uri, $this->newRequestFuture($uri)->resolve());
    if (strlen($data) !== $end - $start) {
      throw new Exception(pht('Gorge upload range was truncated.'));
    }
    return $data;
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
