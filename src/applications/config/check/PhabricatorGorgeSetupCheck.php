<?php

final class PhabricatorGorgeSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    // A disabled render service is not a healthy state any more: both
    // difference engines throw whenever Gorge diff is unavailable, and there
    // is no native implementation left to take over. Report it instead of
    // returning early, or the deployment only fails at the first raw or
    // prose diff.
    if (PhabricatorGorgeServiceRegistry::getService('render')->isDisabled()) {
      $this->newIssue('gorge.diff.policy-off')
        ->setName(pht('Required Difference Generation Is Disabled'))
        ->setSummary(
          pht(
            'The Gorge service policy disables the render service, but Gorge '.
            'is the only difference implementation in this build.'))
        ->setMessage(
          pht(
            'The failure policy for the Gorge "render" service resolves to '.
            '%s, either from %s or from a per-service entry in %s. The '.
            'native GNU and PHP difference engines have been removed, so '.
            'every raw and prose difference request now fails while this '.
            'policy is in effect. Set the policy to %s (or %s) and make the '.
            'render service reachable.',
            phutil_tag('tt', array(), PhabricatorGorgeServiceSpec::POLICY_OFF),
            phutil_tag('tt', array(), 'gorge.service-policy'),
            phutil_tag('tt', array(), 'gorge.service-policies'),
            phutil_tag(
              'tt',
              array(),
              PhabricatorGorgeServiceSpec::POLICY_REQUIRED),
            phutil_tag(
              'tt',
              array(),
              PhabricatorGorgeServiceSpec::POLICY_FALLBACK)))
        ->addRelatedPhabricatorConfig('gorge.service-policy')
        ->addRelatedPhabricatorConfig('gorge.service-policies')
        ->addRelatedPhabricatorConfig('gorge.render.uri');
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

    $this->checkDiffRoutes($uri);
  }

  /**
   * Prove the endpoint actually serves diffs, not just that it answers.
   *
   * `/healthz` says the process is up, which was enough while diff routing was
   * optional. It is not enough now: an older render image, or one started with
   * its diff routes disabled, passes the health check and then fails every raw
   * and prose request with a route error. Pinning GORGE_RENDER_ENABLE_DIFF in
   * the bundled Compose file does not cover a source or custom deployment
   * pointed at an endpoint this install does not start.
   *
   * Both routes are probed. They are separate handlers, so a partially
   * deployed or mismatched service can serve one and not the other, and
   * PhutilProseDifferenceEngine::getDiff() reaches the prose route
   * unconditionally -- probing only the raw route would leave every prose
   * difference failing under a clean setup report.
   *
   * The probes go through the real client, so they exercise the route, the
   * token and the response envelope together.
   */
  private function checkDiffRoutes($uri) {
    $client = new PhabricatorGorgeDiffClient();

    $probes = array(
      PhabricatorGorgeDiffClient::PATH_GENERATE => array($client, 'probeRaw'),
      PhabricatorGorgeDiffClient::PATH_PROSE => array($client, 'probeProse'),
    );

    $failed_path = null;
    $error = null;
    foreach ($probes as $path => $probe) {
      try {
        call_user_func($probe);
      } catch (Exception $ex) {
        $failed_path = $path;
        $error = $ex->getMessage();
        break;
      }
    }

    if ($failed_path === null) {
      return;
    }

    $summary = pht(
      'The Gorge render service answers its health check, but does not serve '.
      'the diff routes this software requires.');

    $message = pht(
      'The service at %s responded to a health check, but a probe of %s did '.
      'not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Difference generation is served entirely by this endpoint -- the '.
      'native GNU and PHP implementations have been removed -- so every raw '.
      'and prose difference fails while these routes are missing.'.
      "\n\n".
      'Check that the service is recent enough to serve %s and %s, and that '.
      'it was started with its diff routes enabled (%s in the bundled '.
      'Compose stack, which pins it on).',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $failed_path),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), PhabricatorGorgeDiffClient::PATH_GENERATE),
      phutil_tag('tt', array(), PhabricatorGorgeDiffClient::PATH_PROSE),
      phutil_tag('tt', array(), 'GORGE_RENDER_ENABLE_DIFF'));

    $this->newIssue('gorge.diff.routes-missing')
      ->setName(pht('Gorge Diff Routes Unavailable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.render.uri')
      ->addRelatedPhabricatorConfig('gorge.render.token');
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

}
