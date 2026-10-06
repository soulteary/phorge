<?php

/** Binary image computation; Phorge retains file metadata and permissions. */
final class PhabricatorGorgeImageClient
  extends PhabricatorGorgeServiceClient {

  const REVISION = 'phorge-v1';
  const PATH_TRANSFORM = '/api/image/transform';
  const PATH_PROBE = '/api/image/probe';
  const PATH_CAPABILITIES = '/api/image/capabilities';

  public function __construct() {
    $service = PhabricatorGorgeServiceRegistry::getService('image');
    if ($service->isDisabled() || !$service->getConfiguredURI() ||
        !$service->getConfiguredToken()) {
      throw new PhabricatorGorgeImageTransientException(
        pht('Gorge image URI and token are required, and service must be enabled.'));
    }
    $this->setURI($service->getConfiguredURI());
    $this->setToken($service->getConfiguredToken());
  }

  protected static function getServiceName() {
    return pht('Gorge image service');
  }

  protected function getDefaultTimeout() {
    return 15;
  }

  public function getCapabilities() {
    $uri = $this->getURI().self::PATH_CAPABILITIES;
    return static::parseResponseEnvelope(
      $uri, $this->newRequestFuture($uri)->resolve());
  }

  public function probe($data) {
    if (strlen($data) > 16 * 1024 * 1024) {
      throw new Exception(pht('Image exceeds the 16 MiB transform limit.'));
    }
    $uri = $this->getURI().self::PATH_PROBE;
    $info = static::parseResponseEnvelope(
      $uri, $this->newBinaryRequestFuture($uri, $data)->resolve());
    $source = @getimagesizefromstring($data);
    if (!$source || !is_array($info) ||
        idx($info, 'width') !== $source[0] ||
        idx($info, 'height') !== $source[1] ||
        idx($info, 'mimeType') !== idx($source, 'mime') ||
        !is_int(idx($info, 'frames')) ||
        $info['frames'] < 1 || $info['frames'] > 100) {
      throw new PhabricatorGorgeImageTransientException(
        pht('Gorge image returned incompatible image metadata.'));
    }
    return $info;
  }

  public function transform($data, $recipe, $preserve_animation) {
    if (strlen($data) > 16 * 1024 * 1024) {
      throw new Exception(pht('Image exceeds the 16 MiB transform limit.'));
    }
    $uri = id(new PhutilURI($this->getURI().self::PATH_TRANSFORM))
      ->setQueryParams(array(
        'recipe' => $recipe,
        'revision' => self::REVISION,
        'animation' => $preserve_animation
          ? 'legacy-preserve'
          : 'legacy-static',
      ));
    $uri = (string)$uri;
    try {
      $result = $this->newBinaryRequestFuture($uri, $data)->resolve();
    } catch (Exception $ex) {
      throw new PhabricatorGorgeImageTransientException($ex->getMessage());
    }
    list($status, $body) = $result;
    if (!($status instanceof HTTPFutureHTTPResponseStatus)) {
      throw new PhabricatorGorgeImageTransientException(
        pht('Gorge image transport failed.'));
    }
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      $code = $status->getStatusCode();
      if ($code !== 200 && !in_array($code, array(413, 415, 422), true)) {
        throw new PhabricatorGorgeImageTransientException(
          pht('Gorge image service is temporarily unavailable (%s).', $code));
      }
    }
    $output = static::parseBinaryResponse($uri, $result);
    return self::validateTransformResponse($output, $data, $recipe, idx($result, 2, array()));
  }

  /** Validate the actual binary and negotiated contract before storing a file. */
  public static function validateTransformResponse($output, $source, $recipe, array $headers) {
    $map = array();
    foreach ($headers as $header) {
      $key = strtolower($header[0]);
      if (isset($map[$key]) && $map[$key] !== $header[1]) {
        throw new PhabricatorGorgeImageTransientException(pht('Conflicting image response headers.'));
      }
      $map[$key] = $header[1];
    }
    $original = @getimagesizefromstring($source);
    $info = @getimagesizefromstring($output);
    $dimensions = null;
    if ($original) {
      $file = id(new PhabricatorFile())
        ->setMimeType(idx($original, 'mime'))
        ->setMetadata(array(
        PhabricatorFile::METADATA_IMAGE_WIDTH => $original[0],
        PhabricatorFile::METADATA_IMAGE_HEIGHT => $original[1]));
      foreach (id(new PhabricatorFileThumbnailTransform())->generateTransforms() as $transform) {
        if ($transform->getTransformKey() === $recipe) {
          $dimensions = $transform->getTransformedDimensions($file);
          break;
        }
      }
    }
    if (!$info || !$dimensions || strlen($output) > 16 * 1024 * 1024 ||
        $info[0] !== (int)$dimensions[0] || $info[1] !== (int)$dimensions[1] ||
        idx($info, 'mime') !== idx($original, 'mime') ||
        idx($map, 'x-gorge-recipe-revision') !== self::REVISION ||
        !strlen((string)idx($map, 'x-gorge-backend-revision')) ||
        idx($map, 'x-gorge-image-width') !== (string)$info[0] ||
        idx($map, 'x-gorge-image-height') !== (string)$info[1] ||
        idx($map, 'etag') !== '"'.hash('sha256', $output).'"' ||
        idx($map, 'content-length') !== (string)strlen($output) ||
        strtolower((string)idx($map, 'content-type')) !== idx($info, 'mime')) {
      throw new PhabricatorGorgeImageTransientException(
        pht('Gorge image returned an incompatible or invalid result.'));
    }
    return $output;
  }
}
