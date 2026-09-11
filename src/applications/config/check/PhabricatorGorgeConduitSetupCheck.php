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
