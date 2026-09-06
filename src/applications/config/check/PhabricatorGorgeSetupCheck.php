<?php

final class PhabricatorGorgeSetupCheck extends PhabricatorSetupCheck {

  const ENGINE_CLASS = 'PhabricatorGorgeSyntaxHighlighterEngine';

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    $uri = PhabricatorGorgeRenderClient::getConfiguredURI();

    if ($uri === null) {
      return;
    }

    // Deploying the service and routing highlighting to it are two separate
    // steps, and each has its own issue below. Only ever raise one of them:
    // they are the two halves of a single unfinished setup, in the order the
    // documentation performs them, so reporting both at once would put two
    // banners on the config page for one problem. An unreachable service is
    // also the more serious of the two, since it is a fault rather than an
    // unfinished step.
    if (!$this->checkReachable($uri)) {
      return;
    }

    $this->checkEnabled($uri);
  }


  /**
   * Probe the service and report it if it does not answer.
   *
   * @param string $uri Base URI of the service.
   * @return bool True if the service answered.
   */
  private function checkReachable($uri) {
    // The health probe is the one route which is neither authenticated nor
    // wrapped in a response envelope, so a bare 200 is all we look for.
    $health_uri = $uri.'/healthz';

    // A host which does not resolve can take longer than this to fail; see the
    // note in PhabricatorGorgeRenderClient::newRequestFuture() for why that
    // can not be bounded any tighter here.
    $future = id(new HTTPSFuture($health_uri))
      ->setTimeout(5);

    try {
      $future->resolvex();
      return true;
    } catch (Exception $ex) {
      $error = $ex->getMessage();
    }

    $summary = pht(
      'The Gorge render service is configured, but does not respond to a '.
      'health check.');

    $message = pht(
      'This software is configured to highlight source code with the Gorge '.
      'render service at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Until the service responds, source code will render without syntax '.
      'highlighting. Check that the service is running and that %s names a '.
      'host this server can reach.',
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
   * Report a service which is deployed and healthy but not actually in use.
   *
   * Setting `gorge.render.uri` does not enable the service by itself, and the
   * orchestration sets that option while leaving the engine switch to the
   * operator. Without this check that leaves a silent window: the service is
   * running and nothing is wrong with it, but no code is highlighted by it
   * and no setup issue says why.
   *
   * @param string $uri Base URI of the service.
   * @return void
   */
  private function checkEnabled($uri) {
    $engine = PhabricatorEnv::getEnvConfig('syntax-highlighter.engine');

    // Accept a subclass too: the option takes any PhutilSyntaxHighlighterEngine
    // and an install which extends ours is still using the service.
    if ($engine === self::ENGINE_CLASS ||
        is_subclass_of($engine, self::ENGINE_CLASS)) {
      return;
    }

    $summary = pht(
      'The Gorge render service is deployed and healthy, but syntax '.
      'highlighting still runs through a different engine, so nothing ever '.
      'calls it.');

    $message = pht(
      'This server can reach the Gorge render service at %s, but %s is set '.
      'to %s, so source code is still highlighted by that engine and the '.
      'service is never called. Setting %s makes the service available; it '.
      'does not route highlighting to it.'.
      "\n\n".
      'To start using it, switch the engine and then discard the render '.
      'results which are already cached. Purging is not an optional tidying '.
      'step: what gets cached is the highlighted HTML rather than the source, '.
      'so Paste bodies and Differential changesets you have already viewed '.
      'keep their old markup until the cache is dropped, which reads as the '.
      'switch having had no effect.'.
      "\n\n".
      'To roll back, set %s to %s and purge the cache again.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), 'syntax-highlighter.engine'),
      phutil_tag('tt', array(), $engine),
      phutil_tag('tt', array(), 'gorge.render.uri'),
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
      ->addRelatedPhabricatorConfig('syntax-highlighter.engine')
      ->addRelatedPhabricatorConfig('gorge.render.uri');
  }

}
