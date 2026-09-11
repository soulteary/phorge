<?php

final class PhabricatorGorgeSetupCheck extends PhabricatorSetupCheck {

  const ENGINE_CLASS = 'PhabricatorGorgeSyntaxHighlighterEngine';

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    if (PhabricatorGorgeServiceRegistry::getService('render')
        ->isDisabled()) {
      return;
    }

    $uri = PhabricatorGorgeRenderClient::getConfiguredURI();

    if ($uri === null) {
      if (PhabricatorEnv::getEnvConfig('gorge.diff.enabled')) {
        $this->newIssue('gorge.diff.uri-missing')
          ->setName(pht('Gorge Diff Service Has No URI'))
          ->setSummary(
            pht(
              'Gorge diff computation is enabled, but the shared render '.
              'service URI is not configured.'))
          ->setMessage(
            pht(
              'Set %s to the base URI of the `gorge-render` service, or '.
              'disable %s to use the local difference engines.',
              phutil_tag('tt', array(), 'gorge.render.uri'),
              phutil_tag('tt', array(), 'gorge.diff.enabled')))
          ->addRelatedPhabricatorConfig('gorge.render.uri')
          ->addRelatedPhabricatorConfig('gorge.diff.enabled');
      }
      return;
    }

    if (!$this->checkReachable($uri)) {
      return;
    }

    $this->checkEnabled($uri);
  }

  private function checkReachable($uri) {
    $probe = PhabricatorGorgeServiceClient::probeEndpoint($uri, '/healthz');
    if ($probe['ok']) {
      return true;
    }

    $error = $probe['error'];
    if (!phutil_nonempty_string($error)) {
      $status = idx($probe, 'status');
      if ($status !== null) {
        $error = pht('HTTP %d: %s', $status, idx($probe, 'body', ''));
      } else {
        $error = pht('(The service did not say why.)');
      }
    }

    $health_uri = $probe['uri'];
    $summary = pht(
      'The Gorge render and diff service is configured, but does not '.
      'respond to a health check.');

    $message = pht(
      'This software is configured to use the Gorge render service at %s, '.
      'but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Until the service responds, source highlighting and difference '.
      'generation fail according to the configured Gorge service policy. '.
      'Check that the service is running and that %s names a host this '.
      'server can reach.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.render.uri'));

    $this->newIssue('gorge.unreachable')
      ->setName(pht('Gorge Render Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.render.uri')
      ->addRelatedPhabricatorConfig('gorge.diff.enabled')
      ->addPhabricatorConfig('syntax-highlighter.engine');

    return false;
  }

  private function checkEnabled($uri) {
    $engine = PhabricatorEnv::getEnvConfig('syntax-highlighter.engine');
    $diff_enabled = PhabricatorEnv::getEnvConfig('gorge.diff.enabled');

    if ($engine === self::ENGINE_CLASS ||
        is_subclass_of($engine, self::ENGINE_CLASS)) {
      return;
    }

    if ($diff_enabled) {
      return;
    }

    $summary = pht(
      'The Gorge render service is deployed and healthy, but neither syntax '.
      'highlighting nor difference generation is routed to it.');

    $message = pht(
      'This server can reach the Gorge render service at %s, but %s is set '.
      'to %s and %s is disabled, so the service is never called. Setting %s '.
      'makes the service available; it does not route either capability to '.
      'it.'.
      "\n\n".
      'To use the service for highlighting, switch the engine and then '.
      'discard the render results which are already cached. Purging is not '.
      'optional because highlighted HTML, not source, is cached.'.
      "\n\n".
      'Alternatively, enable %s to route unified and prose difference '.
      'generation to the same service. That switch does not require a cache '.
      'purge.'.
      "\n\n".
      'To roll back, set %s to %s and purge the cache again.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), 'syntax-highlighter.engine'),
      phutil_tag('tt', array(), $engine),
      phutil_tag('tt', array(), 'gorge.diff.enabled'),
      phutil_tag('tt', array(), 'gorge.render.uri'),
      phutil_tag('tt', array(), 'gorge.diff.enabled'),
      phutil_tag('tt', array(), 'syntax-highlighter.engine'),
      phutil_tag('tt', array(), 'PhutilDefaultSyntaxHighlighterEngine'));

    $this->newIssue('gorge.disabled')
      ->setName(pht('Gorge Render Service Not In Use'))
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
      ->addCommand(
        hsprintf(
          '<samp>%s $</samp><kbd>./bin/config set '.
          'gorge.diff.enabled true</kbd>',
          PlatformSymbols::getPlatformServerPath()))
      ->addRelatedPhabricatorConfig('syntax-highlighter.engine')
      ->addRelatedPhabricatorConfig('gorge.diff.enabled')
      ->addRelatedPhabricatorConfig('gorge.render.uri');
  }

}
