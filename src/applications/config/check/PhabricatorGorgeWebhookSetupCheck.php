<?php

final class PhabricatorGorgeWebhookSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    $service = PhabricatorGorgeServiceRegistry::getService('webhook');
    if (!$service->isOwnedBy('gorge')) {
      return;
    }

    $uri = PhabricatorGorgeWebhookClient::getConfiguredURI();

    if ($uri === null) {
      $this->newIssue('gorge.webhook.owner-without-endpoint')
        ->setName(pht('Gorge Owns Webhooks Without an Endpoint'))
        ->setSummary(
          pht(
            'Webhook ownership is assigned to Gorge, but its diagnostic ' .
            'endpoint is not configured.'))
        ->setMessage(
          pht(
            'Webhook delivery remains delegated so the PHP worker can not ' .
            'race the Gorge consumer. Set `%s` to the running service, or ' .
            'atomically set `%s` back to `%s`.',
            'gorge.webhook.uri',
            'gorge.webhook.owner',
            'phorge'))
        ->addRelatedPhabricatorConfig('gorge.webhook.owner')
        ->addRelatedPhabricatorConfig('gorge.webhook.uri');
      return;
    }

    // Silent mode is checked before the probes rather than after them, which
    // is the opposite of how the other Gorge checks are ordered. There, the
    // "configured but not in use" case is the last stage of an unfinished
    // setup and the service is doing real work in the meantime. Here it is a
    // branch: while silent mode is on, delivery has not moved to the service
    // at all, so whether the service answers does not matter and reporting it
    // as unreachable would point at the wrong thing.
    if ($this->checkSilent($uri)) {
      return;
    }

    if (!$this->checkReachable($uri)) {
      return;
    }

    $this->checkReady($uri);
  }


  /**
   * Report a service which is configured but held back by silent mode.
   *
   * Delegating delivery requires both this option and silent mode being off,
   * because the service can not see silent mode: it is configuration of this
   * server, and all the service reads from the queue is the per-request flag
   * the editor recorded, which describes one transaction rather than the
   * install. An install which handed delivery over while silent would have
   * its webhooks delivered anyway.
   *
   * So silent installs keep delivering through the daemon, which fails every
   * request with an "In Silent Mode" error instead of sending it, exactly as
   * it did before the service existed. That is the correct behaviour, but it
   * means the service sits idle with a configured address, which is worth
   * saying out loud on this page.
   *
   * @param string $uri Base URI of the service.
   * @return bool True if silent mode is holding delivery back.
   */
  private function checkSilent($uri) {
    if (!PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      return false;
    }

    $summary = pht(
      'The Gorge webhook service is configured, but silent mode is enabled, '.
      'so webhook delivery has not moved to it.');

    $message = pht(
      'This software is configured to deliver Herald webhooks with the Gorge '.
      'webhook service at %s, but %s is enabled, so delivery is still '.
      'handled here and every webhook request is failed with an %s error '.
      'rather than sent.'.
      "\n\n".
      'This is deliberate. Silent mode is configuration of this server and '.
      'the service does not read it, so handing delivery over while silent '.
      'would send the webhooks silent mode exists to suppress. Delivery '.
      'moves to the service on its own once silent mode is turned off; no '.
      'other change is needed.'.
      "\n\n".
      'Until then the service has nothing to do, and its own health is not '.
      'checked on this page.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), 'phabricator.silent'),
      phutil_tag('tt', array(), 'In Silent Mode'));

    $this->newIssue('gorge.webhook.silent')
      ->setName(pht('Gorge Webhook Service Not In Use'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('phabricator.silent')
      ->addRelatedPhabricatorConfig('gorge.webhook.uri');

    return true;
  }


  /**
   * Probe the service and report it if it does not answer.
   *
   * This is the check which matters most of the three, because delivery has
   * already moved by the time it runs and there is nothing else which would
   * report the service being down. Requests keep being written to the queue
   * and keep sitting in "queued" status, which the web interface renders as
   * an ordinary "Queued" icon, so from the Herald side a service which is not
   * running looks the same as one which is a moment behind.
   *
   * @param string $uri Base URI of the service.
   * @return bool True if the service answered.
   */
  private function checkReachable($uri) {
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
      'The Gorge webhook service is configured, but does not respond to a '.
      'health check.');

    $message = pht(
      'This software is configured to deliver Herald webhooks with the Gorge '.
      'webhook service at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Setting %s stops this server from delivering webhooks itself, so '.
      'while the service is down no webhook is delivered at all. Requests '.
      'are still recorded and simply stay in %s status, which the Herald '.
      'interface shows as an ordinary queued request, so nothing else will '.
      'report this. They are delivered once the service comes back, unless '.
      'the garbage collector reaches them first.'.
      "\n\n".
      'Check that the service is running and that %s names a host this '.
      'server can reach. To hand delivery back to the daemon in the '.
      'meantime, clear %s.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.webhook.uri'),
      phutil_tag('tt', array(), 'queued'),
      phutil_tag('tt', array(), 'gorge.webhook.uri'),
      phutil_tag('tt', array(), 'gorge.webhook.uri'));

    $this->newIssue('gorge.webhook.unreachable')
      ->setName(pht('Gorge Webhook Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.webhook.uri');

    return false;
  }


  /**
   * Report a service which is running but can not reach the queue.
   *
   * The service reads and writes one table in this server's database, and it
   * is configured with its own copy of the connection parameters. Getting
   * those wrong produces a process which starts, answers its health check and
   * reports healthy to the orchestration while delivering nothing, which is
   * indistinguishable from a working install on the Herald side. Readiness is
   * the one signal which separates them.
   *
   * @param string $uri Base URI of the service.
   * @return bool True if the service reported itself ready.
   */
  private function checkReady($uri) {
    $ready_uri = $uri.'/readyz';

    $future = id(new HTTPSFuture($ready_uri))
      ->setTimeout(5);

    list($status, $body) = $future->resolve();

    $reason = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      if ($status->getStatusCode() == 200) {
        return true;
      }

      // The service answers 503 with {"status": "unavailable", "reason": ...},
      // and the reason names the misconfiguration, so surface it rather than
      // the status code.
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
      'This server reaches the Gorge webhook service, but the service '.
      'reports that it is not ready to deliver webhooks.');

    $message = pht(
      'This server can reach the Gorge webhook service at %s, but %s reports '.
      'that it is not ready:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Readiness here means the service can reach the database which holds '.
      'the webhook queue. It is configured with its own connection settings '.
      '(%s, %s, %s, %s and %s), and %s must name the same prefix as %s does '.
      'on this side, because the queue is the %s table in the %s database.'.
      "\n\n".
      'While the service is in this state, webhook requests accumulate in %s '.
      'status and nothing is delivered, exactly as if the service were down.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $ready_uri),
      phutil_tag('pre', array(), $reason),
      phutil_tag('tt', array(), 'GORGE_WEBHOOK_MYSQL_HOST'),
      phutil_tag('tt', array(), 'GORGE_WEBHOOK_MYSQL_PORT'),
      phutil_tag('tt', array(), 'GORGE_WEBHOOK_MYSQL_USER'),
      phutil_tag('tt', array(), 'GORGE_WEBHOOK_MYSQL_PASS'),
      phutil_tag('tt', array(), 'GORGE_WEBHOOK_NAMESPACE'),
      phutil_tag('tt', array(), 'GORGE_WEBHOOK_NAMESPACE'),
      phutil_tag('tt', array(), 'storage.default-namespace'),
      phutil_tag('tt', array(), 'herald_webhookrequest'),
      phutil_tag('tt', array(), '{namespace}_herald'),
      phutil_tag('tt', array(), 'queued'));

    $this->newIssue('gorge.webhook.notready')
      ->setName(pht('Gorge Webhook Service Not Ready'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.webhook.uri')
      ->addRelatedPhabricatorConfig('storage.default-namespace');

    return false;
  }

}
