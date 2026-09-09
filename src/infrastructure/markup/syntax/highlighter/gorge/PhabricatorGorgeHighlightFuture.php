<?php

final class PhabricatorGorgeHighlightFuture extends FutureProxy {

  private $uri;
  private $scrub;

  public function __construct(Future $proxied, $uri, $scrub = false) {
    parent::__construct($proxied);
    $this->uri = $uri;
    $this->scrub = $scrub;
  }

  protected function didReceiveResult($result) {
    try {
      $data = PhabricatorGorgeRenderClient::parseResponseEnvelope(
        $this->uri,
        $result);
    } catch (Exception $ex) {
      throw $this->newPolicyException($ex);
    }

    $html = null;
    if (is_array($data)) {
      $html = idx($data, 'html');
    }

    if (!is_string($html)) {
      $ex = new Exception(
        pht(
          'The Gorge render service returned a response for "%s" with no '.
          'usable "html" field.',
          $this->uri));
      throw $this->newPolicyException($ex);
    }

    if ($this->scrub && strlen($html)) {
      $html = preg_replace('/^.*\n/', '', $html);
    }

    return phutil_safe_html($html);
  }

  /**
   * Convert a service failure into the signal expected by the selected
   * deployment policy.
   *
   * The generic syntax engine catches PhutilSyntaxHighlighterException and
   * invokes its local highlighter. Only the explicit migration fallback may
   * produce that exception. In required mode the original service exception
   * escapes that catch boundary, so an outage can not become a silent local
   * render.
   */
  private function newPolicyException(Exception $ex) {
    $service = PhabricatorGorgeServiceRegistry::getService('render');
    if (!$service->isFallbackAllowed()) {
      return $ex;
    }

    $service->recordFallback('highlight');
    return new PhutilSyntaxHighlighterException($ex->getMessage());
  }

}
