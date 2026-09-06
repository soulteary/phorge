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
      // Rethrow as a highlighter exception rather than logging and returning
      // unhighlighted source: PhutilSyntaxHighlighterEngine and
      // DifferentialChangesetParser both catch this class, and the latter
      // uses it to tell the viewer that highlighting failed instead of
      // quietly rendering a colorless page.
      throw new PhutilSyntaxHighlighterException($ex->getMessage());
    }

    $html = null;
    if (is_array($data)) {
      $html = idx($data, 'html');
    }

    if (!is_string($html)) {
      throw new PhutilSyntaxHighlighterException(
        pht(
          'The Gorge render service returned a response for "%s" with no '.
          'usable "html" field.',
          $this->uri));
    }

    if ($this->scrub && strlen($html)) {
      $html = preg_replace('/^.*\n/', '', $html);
    }

    return phutil_safe_html($html);
  }

}
