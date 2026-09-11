<?php

/**
 * HTTP client for the diff domain served by the Gorge render service.
 *
 * Render and diff share one process and one endpoint. The bundled deployment
 * now treats Gorge as the only production diff implementation, so routing is
 * selected by the render service itself. The historical `gorge.diff.enabled`
 * migration switch is retired: it is hidden and locked in Config and is not
 * read anywhere.
 */
final class PhabricatorGorgeDiffClient
  extends PhabricatorGorgeServiceClient {

  const PATH_GENERATE = '/api/diff/generate';
  const PATH_PROSE = '/api/diff/prose';

  public function __construct() {
    $uri = PhabricatorGorgeRenderClient::getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge diff '.
          'service, but it is not set.',
          'gorge.render.uri'));
    }

    $this->setURI($uri);
    $this->setToken(PhabricatorEnv::getEnvConfigIfExists('gorge.render.token'));
  }

  protected static function getServiceName() {
    return pht('Gorge diff service');
  }

  protected function getDefaultTimeout() {
    return 15;
  }

  /**
   * Diff is available whenever the render service is selected by policy and
   * has an endpoint. There is no native implementation to switch to anymore.
   */
  public static function isEnabled() {
    $service = PhabricatorGorgeServiceRegistry::getService('render');
    if ($service->isDisabled()) {
      return false;
    }

    return PhabricatorGorgeRenderClient::isConfigured();
  }

  public function generateDiff(
    $old,
    $new,
    $old_name,
    $new_name,
    $normalize) {

    $uri = $this->getURI().self::PATH_GENERATE;

    $request = array(
      'old' => $old,
      'new' => $new,
      'normalize' => (bool)$normalize,
    );

    if (phutil_nonempty_string($old_name)) {
      $request['oldName'] = $old_name;
    }

    if (phutil_nonempty_string($new_name)) {
      $request['newName'] = $new_name;
    }

    $result = $this->newJSONRequestFuture($uri, $request)->resolve();
    $data = self::parseResponseEnvelope($uri, $result);

    $diff = idx($data, 'diff');
    if (!is_string($diff)) {
      throw new Exception(
        pht(
          'The Gorge diff service returned a response for "%s" with no '.
          'valid "diff" string.',
          $uri));
    }

    return $diff;
  }

  public function generateProseDiff($old, $new) {
    $uri = $this->getURI().self::PATH_PROSE;

    $request = array(
      'old' => $old,
      'new' => $new,
    );

    $result = $this->newJSONRequestFuture($uri, $request)->resolve();
    $data = self::parseResponseEnvelope($uri, $result);

    return self::newProseDiffFromData($data, $old, $new);
  }

  public static function newProseDiffFromData(array $data, $old, $new) {
    $parts = idx($data, 'parts');
    if (!is_array($parts)) {
      throw new Exception(
        pht('The Gorge diff service returned no valid prose diff parts.'));
    }

    $diff = new PhutilProseDiff();
    $reconstructed_old = '';
    $reconstructed_new = '';

    foreach ($parts as $part) {
      if (!is_array($part)) {
        throw new Exception(
          pht('The Gorge diff service returned an invalid prose diff part.'));
      }

      $type = idx($part, 'type');
      $text = idx($part, 'text');

      if (!in_array($type, array('=', '-', '+'), true) ||
          !is_string($text)) {
        throw new Exception(
          pht('The Gorge diff service returned an invalid prose diff part.'));
      }

      if ($type !== '+') {
        $reconstructed_old .= $text;
      }
      if ($type !== '-') {
        $reconstructed_new .= $text;
      }

      $diff->addPart($type, $text);
    }

    if ($reconstructed_old !== $old || $reconstructed_new !== $new) {
      throw new Exception(
        pht(
          'The Gorge diff service returned prose diff parts which do not '.
          'reconstruct the original inputs.'));
    }

    return $diff;
  }

}
