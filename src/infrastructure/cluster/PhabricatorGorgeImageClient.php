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

  public function meme($data, $above, $below, $preserve_animation, array $expected = array()) {
    if (!$expected) { $expected = $this->getCapabilities(); }
    $uri = (string)id(new PhutilURI($this->getURI().'/api/image/meme'))
      ->setQueryParams(array('revision' => 'meme-v1',
        'animation' => $preserve_animation ? 'legacy-preserve' : 'legacy-static'));
    $result = $this->newBinaryRequestFuture($uri, $data)
      ->addHeader('X-Gorge-Meme-Above', base64_encode((string)$above))
      ->addHeader('X-Gorge-Meme-Below', base64_encode((string)$below))
      ->resolve();
    $source = @getimagesizefromstring($data);
    if (!$source) { throw new Exception(pht('Invalid Meme source.')); }
    $output = self::validateRecipeResponse($uri, $result,
      'meme-v1', $source[0], $source[1], idx($source, 'mime'));
    $headers = array();
    foreach (idx($result, 2, array()) as $header) { $headers[strtolower($header[0])] = $header[1]; }
    if (idx($headers, 'x-gorge-font-revision') !== idx(idx($expected, 'meme', array()), 'fontRevision') ||
        idx($headers, 'x-gorge-backend-revision') !== idx($expected, 'backendRevision')) {
      throw new PhabricatorGorgeImageTransientException(pht('Meme backend or font changed during generation.'));
    }
    return $output;
  }

  public function compose(array $request) {
    $request['revision'] = 'compose-v1';
    $uri = $this->getURI().'/api/image/compose';
    $result = $this->newJSONRequestFuture($uri, $request)->resolve();
    switch ($request['recipe']) {
      case 'avatar': $width = $height = 400; break;
      case 'icon': $width = $height = 200; break;
      case 'favicon': $width = $request['width']; $height = $request['height']; break;
      default: throw new Exception(pht('Unknown composition recipe.'));
    }
    return self::validateRecipeResponse($uri, $result,
      'compose-v1', $width, $height, 'image/png');
  }

  public static function composeWithRollout(array $request, $legacy) {
    $mode = PhabricatorEnv::getEnvConfig('gorge.image.builtin-mode');
    if ($mode === 'legacy') { return $legacy(); }
    if ($mode === 'gorge') { return id(new self())->compose($request); }
    if ($mode !== 'shadow') { throw new Exception(pht('Invalid builtin image rollout mode.')); }
    $output = $legacy();
    try {
      $shadow = id(new self())->compose($request);
      if (@getimagesizefromstring($output) !== @getimagesizefromstring($shadow)) {
        phlog(pht('Gorge composition shadow metadata differs (%s).', $request['recipe']));
      }
    } catch (Throwable $ex) {
      phlog(pht('Gorge composition shadow failed (%s).', $request['recipe']));
    }
    return $output;
  }

  private static function validateRecipeResponse($uri, array $result,
    $revision, $width, $height, $mime) {
    $output = self::parseBinaryResponse($uri, $result);
    $info = @getimagesizefromstring($output);
    $headers = array();
    foreach (idx($result, 2, array()) as $header) {
      $key = strtolower($header[0]);
      if (isset($headers[$key]) && $headers[$key] !== $header[1]) {
        throw new PhabricatorGorgeImageTransientException(pht('Conflicting recipe headers.'));
      }
      $headers[$key] = $header[1];
    }
    if (!$info || $info[0] !== (int)$width || $info[1] !== (int)$height ||
        idx($info, 'mime') !== $mime || strlen($output) > 16 * 1024 * 1024 ||
        idx($headers, 'x-gorge-recipe-revision') !== $revision ||
        !strlen((string)idx($headers, 'x-gorge-backend-revision')) ||
        idx($headers, 'x-gorge-image-width') !== (string)$width ||
        idx($headers, 'x-gorge-image-height') !== (string)$height ||
        idx($headers, 'content-length') !== (string)strlen($output) ||
        strtolower((string)idx($headers, 'content-type')) !== $mime ||
        idx($headers, 'etag') !== '"'.hash('sha256', $output).'"') {
      throw new PhabricatorGorgeImageTransientException(pht('Invalid Gorge recipe output.'));
    }
    return $output;
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
