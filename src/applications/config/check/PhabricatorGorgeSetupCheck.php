<?php

final class PhabricatorGorgeSetupCheck extends PhabricatorSetupCheck {

  const ENGINE_CLASS = 'PhabricatorGorgeSyntaxHighlighterEngine';

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    if (PhabricatorGorgeServiceRegistry::getService('render')->isDisabled()) {
      return;
    }

    $uri = PhabricatorGorgeRenderClient::getConfiguredURI();
    if ($uri === null) {
      $this->newIssue('gorge.diff.uri-missing')
        ->setName(pht('Gorge Diff Service Has No URI'))
        ->setSummary(
          pht(
            'Gorge is the required diff implementation, but the shared '.
            'render service URI is not configured.'))
        ->setMessage(
          pht(
            'Set %s to the base URI of the bundled `gorge-render` service. '.
            'The native GNU/PHP diff implementations have been retired.',
            phutil_tag('tt', array(), 'gorge.render.uri')))
        ->addRelatedPhabricatorConfig('gorge.render.uri');
      return;
    }

    if (!$this->checkReachable($uri)) {
      return;
    }

    $this->checkHighlighting($uri);
  }

  private function checkReachable($uri) {
    $health_uri = $uri.'/healthz';
    $future = id(new HTTPSFuture($health_uri))->setTimeout(5);

    try {
      $future->resolvex();
      return true;
    } catch (Exception $ex) {
      $error = $ex->getMessage();
    }

    $summary = pht(
      'The Gorge render and diff service is configured, but does not '.
      'respond to a health check.');

    $message = pht(
      'This software requires the Gorge render service at %s for difference '.
      'generation, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Difference generation will fail until the service responds. Check '.
      'that the service is running and that %s names a host this server can '.
      'reach.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.render.uri'));

    $this->newIssue('gorge.unreachable')
      ->setName(pht('Gorge Render Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.render.uri')
      ->addPhabricatorConfig('syntax-highlighter.engine');

    return false;
  }

  /**
   * Diff is always routed to Gorge once the service is configured. The only
   * remaining optional render capability is syntax highlighting.
   */
  private function checkHighlighting($uri) {
    $engine = PhabricatorEnv::getEnvConfig('syntax-highlighter.engine');

    if ($engine === self::ENGINE_CLASS ||
        is_subclass_of($engine, self::ENGINE_CLASS)) {
      return;
    }

    $summary = pht(
      'Gorge already serves required difference generation, but syntax '.
      'highlighting is still using the built-in engine.');

    $message = pht(
      'The Gorge render service at %s is already required for diff '.
      'generation. To route highlighting through the same service, set %s '.
      'to %s and purge the render cache. Highlighted HTML is cached, so '.
      'previously viewed content keeps its old markup until that cache is '.
      'discarded.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), 'syntax-highlighter.engine'),
      phutil_tag('tt', array(), self::ENGINE_CLASS));

    $this->newIssue('gorge.highlight.native')
      ->setName(pht('Gorge Highlighting Not Enabled'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addCommand(
        hsprintf(
          '<samp>%s $</samp><kbd>./bin/config set '.
          'syntax-highlighter.engine %s</kbd>',
          PlatformSymbols::getPlatformServerPath(),
          self::ENGINE_CLASS))
      ->addCommand(
        hsprintf(
          '<samp>%s $</samp><kbd>./bin/cache purge --all</kbd>',
          PlatformSymbols::getPlatformServerPath()))
      ->addRelatedPhabricatorConfig('syntax-highlighter.engine')
      ->addRelatedPhabricatorConfig('gorge.render.uri');
  }

}
