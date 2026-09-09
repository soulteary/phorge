<?php

/**
 * HTTP client for the Gorge render service.
 *
 * Gorge is a Go service which highlights source code with Chroma and emits
 * HTML using Pygments-compatible CSS class names, so existing stylesheets
 * continue to work without modification.
 *
 * Request building and envelope parsing live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share. This class adds the two highlight routes and reads its endpoint from
 * @{class:PhabricatorEnv}.
 */
final class PhabricatorGorgeRenderClient
  extends PhabricatorGorgeServiceClient {

  const PATH_HIGHLIGHT = '/api/highlight/render';
  const PATH_LANGUAGES = '/api/highlight/languages';

  public function __construct() {
    $service = PhabricatorGorgeServiceRegistry::getService('render');
    $uri = $service->getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge render '.
          'service, but it is not set.',
          'gorge.render.uri'));
    }

    $this->setURI($uri);
    $this->setToken($service->getConfiguredToken());
  }

  protected static function getServiceName() {
    return pht('Gorge render service');
  }

  protected function getDefaultTimeout() {
    // Modest on purpose. A slow name lookup is bounded only by this timeout
    // and can not be made to fail sooner; see the note in
    // @{method:PhabricatorGorgeServiceClient::newRequestFuture}. That bites
    // here more than anywhere else: when "gorge.render.uri" names a host
    // which does not resolve and the resolver does not answer promptly, every
    // render of source code waits out the timeout before falling back to the
    // local highlighter. Keeping this small is the only mitigation on this
    // side.
    return 15;
  }


  /**
   * Read the configured base URI of the service.
   *
   * @return string|null Base URI with any trailing slash removed, or null if
   *   the service is not configured.
   */
  public static function getConfiguredURI() {
    return PhabricatorGorgeServiceRegistry::getService('render')
      ->getConfiguredURI();
  }

  public static function isConfigured() {
    return (self::getConfiguredURI() !== null);
  }

  public function getHighlightURI() {
    return $this->getURI().self::PATH_HIGHLIGHT;
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

    return $this->newJSONRequestFuture($this->getHighlightURI(), $data);
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
    $uri = $this->getURI().self::PATH_LANGUAGES;

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }


  /**
   * Unwrap the "{data, error}" envelope of an API response.
   *
   * This is public for one caller: highlight requests are handed out as
   * unresolved futures, so @{class:PhabricatorGorgeHighlightFuture} unwraps
   * the response itself once the future resolves, with no client in hand.
   *
   * @param string $uri URI which was requested, for diagnostics.
   * @param wild $result Raw result of an @{class@arcanist:HTTPSFuture}.
   * @return wild The "data" section of the envelope.
   */
  public static function parseResponseEnvelope($uri, $result) {
    return parent::parseResponseEnvelope($uri, $result);
  }

}
