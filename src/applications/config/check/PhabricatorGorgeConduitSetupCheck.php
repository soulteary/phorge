<?php

final class PhabricatorGorgeConduitSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    if (PhabricatorGorgeServiceRegistry::getService('conduit')
        ->isDisabled()) {
      return;
    }

    $uri = PhabricatorGorgeConduitClient::getConfiguredURI();

    if ($uri === null) {
      return;
    }

    $this->checkReachable($uri);
  }


  /**
   * Probe the gateway and report it if it does not answer.
   *
   * @param string $uri Base URI of the gateway.
   * @return bool True if the gateway answered.
   */
  private function checkReachable($uri) {
    // The health probe is the one route which is neither authenticated nor
    // wrapped in a response envelope, so a bare 200 is all we look for.
    $health_uri = $uri.'/healthz';

    // A host which does not resolve can take longer than this to fail; see the
    // note in PhabricatorGorgeServiceClient::newRequestFuture() for why that
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
      'The Gorge conduit gateway is configured, but does not respond to a '.
      'health check.');

    $message = pht(
      'This software is configured to reach the Conduit API through the '.
      'Gorge conduit gateway at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Until the gateway responds, any Conduit call routed through it fails. '.
      'Check that the service is running and that %s names a host this '.
      'server can reach.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.conduit.uri'));

    $this->newIssue('gorge.conduit.unreachable')
      ->setName(pht('Gorge Conduit Gateway Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.conduit.uri');

    return false;
  }

}
