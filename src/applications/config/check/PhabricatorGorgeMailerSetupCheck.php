<?php

final class PhabricatorGorgeMailerSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    if (PhabricatorGorgeServiceRegistry::getService('mailer')
        ->isDisabled()) {
      return;
    }

    foreach ($this->getGorgeMailerURIs() as $mailer_key => $uri) {
      // Liveness and readiness are two separate failures with two separate
      // fixes, and a service which does not answer at all can not report
      // whether it is ready, so only ever raise one of them per mailer.
      if (!$this->checkReachable($mailer_key, $uri)) {
        continue;
      }

      $this->checkReady($mailer_key, $uri);
    }
  }


  /**
   * Find the endpoint of every mailer which routes through the service.
   *
   * There is no global option to read: this integration is configured one
   * `cluster.mailers` entry at a time, and an install may point two entries at
   * two services, so each is checked separately.
   *
   * The configuration is read defensively rather than through the adapter,
   * because a malformed entry must not turn the config page -- the page which
   * would report that the entry is malformed -- into a stack trace.
   *
   * @return map<string, string> Mailer key to base URI, trailing slash
   *   removed.
   */
  private function getGorgeMailerURIs() {
    $mailers = PhabricatorEnv::getEnvConfig('cluster.mailers');
    if (!is_array($mailers)) {
      return array();
    }

    $type = PhabricatorMailGorgeAdapter::ADAPTERTYPE;

    $results = array();
    foreach ($mailers as $index => $spec) {
      if (!is_array($spec)) {
        continue;
      }

      if (idx($spec, 'type') !== $type) {
        continue;
      }

      $options = idx($spec, 'options');
      if (!is_array($options)) {
        continue;
      }

      $uri = idx($options, 'uri');
      if (!phutil_nonempty_string($uri)) {
        continue;
      }

      $key = idx($spec, 'key');
      if (!phutil_nonempty_string($key)) {
        $key = (string)$index;
      }

      $results[$key] = rtrim($uri, '/');
    }

    return $results;
  }


  /**
   * Probe the service and report it if it does not answer.
   *
   * @param string $mailer_key Key of the mailer being checked.
   * @param string $uri Base URI of the service.
   * @return bool True if the service answered.
   */
  private function checkReachable($mailer_key, $uri) {
    // The probe routes are the ones which are neither authenticated nor
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
      'Mailer "%s" sends mail through the Gorge mailer service, but the '.
      'service does not respond to a health check.',
      $mailer_key);

    $message = pht(
      'The mailer %s is configured to deliver mail through the Gorge mailer '.
      'service at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Until the service responds, every message routed to this mailer fails '.
      'and is retried by the queue. Check that the service is running and '.
      'that the %s option of this mailer names a host this server can reach.',
      phutil_tag('tt', array(), $mailer_key),
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'uri'));

    $this->newIssue('gorge.mailer.unreachable.'.$mailer_key)
      ->setName(pht('Gorge Mailer Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addPhabricatorConfig('cluster.mailers');

    return false;
  }


  /**
   * Report a service which is running but has no delivery backend configured.
   *
   * This is the state which is easiest to reach and hardest to diagnose: the
   * container starts, its health check passes, Compose reports it healthy, and
   * every message still fails, because the service was never given an SMTP
   * host or provider credentials. Readiness is the one signal which separates
   * that from a working install, so it gets its own issue rather than being
   * folded into the reachability check above.
   *
   * @param string $mailer_key Key of the mailer being checked.
   * @param string $uri Base URI of the service.
   * @return void
   */
  private function checkReady($mailer_key, $uri) {
    $ready_uri = $uri.'/readyz';

    $future = id(new HTTPSFuture($ready_uri))
      ->setTimeout(5);

    list($status, $body) = $future->resolve();

    $reason = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      if ($status->getStatusCode() == 200) {
        return;
      }

      // The service answers 503 with {"status": "unavailable", "reason": ...},
      // and the reason names the misconfiguration, so surface it rather than
      // the status code. Older builds may not have the route at all, in which
      // case the body is an "ERR_NOT_FOUND" envelope and is still worth
      // showing.
      try {
        $response = phutil_json_decode($body);
        $reason = idx($response, 'reason');
      } catch (PhutilJSONParserException $ex) {
        // Continue: fall back to the raw body below.
      }

      if (!phutil_nonempty_string($reason)) {
        $reason = (string)$body;
      }
    } else if ($status instanceof Exception) {
      // Reaching here means /healthz answered a moment ago but /readyz did
      // not, so the service is going down, or is slow enough that the probe
      // timed out. Either way it can not be assumed ready.
      $reason = $status->getMessage();
    }

    if (!phutil_nonempty_string($reason)) {
      $reason = pht('(The service did not say why.)');
    }

    $summary = pht(
      'Mailer "%s" reaches the Gorge mailer service, but the service reports '.
      'that it is not ready to deliver mail.',
      $mailer_key);

    $message = pht(
      'This server can reach the Gorge mailer service at %s, but %s reports '.
      'that it is not ready:'.
      "\n\n".
      '%s'.
      "\n\n".
      'The service is ready once at least one delivery backend is configured '.
      'on its own side, through its environment (%s and the %s or %s '.
      'variables) or its configuration file. Until then it accepts requests '.
      'and fails every one of them, so mail routed to the mailer %s stays in '.
      'the queue.'.
      "\n\n".
      'Note that the container health check probes %s rather than %s, so a '.
      'service in this state still reports as healthy to the orchestration.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $ready_uri),
      phutil_tag('pre', array(), $reason),
      phutil_tag('tt', array(), 'MAILER_TYPE'),
      phutil_tag('tt', array(), 'SMTP_*'),
      phutil_tag('tt', array(), 'MAILER_*'),
      phutil_tag('tt', array(), $mailer_key),
      phutil_tag('tt', array(), '/healthz'),
      phutil_tag('tt', array(), '/readyz'));

    $this->newIssue('gorge.mailer.notready.'.$mailer_key)
      ->setName(pht('Gorge Mailer Service Not Ready'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addPhabricatorConfig('cluster.mailers');
  }

}
