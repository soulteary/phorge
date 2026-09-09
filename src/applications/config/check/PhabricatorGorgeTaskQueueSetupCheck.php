<?php

final class PhabricatorGorgeTaskQueueSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    $service = PhabricatorGorgeServiceRegistry::getService('taskqueue');
    if (!$service->isOwnedBy('gorge')) {
      return;
    }

    $uri = PhabricatorGorgeTaskQueueClient::getConfiguredURI();

    if ($uri === null) {
      $this->newIssue('gorge.taskqueue.owner-without-endpoint')
        ->setName(pht('Gorge Owns Queue Without an Endpoint'))
        ->setSummary(
          pht(
            'Queue ownership is assigned to Gorge, but no task queue ' .
            'endpoint is configured.'))
        ->setMessage(
          pht(
            'Set `%s` to a reachable task queue service, or atomically set ' .
            '`%s` back to `%s`. Phorge deliberately does not fall back to ' .
            'SQL while Gorge owns the queue, because that would reactivate ' .
            'a second consumer.',
            'gorge.taskqueue.uri',
            'gorge.taskqueue.owner',
            'phorge'))
        ->addRelatedPhabricatorConfig('gorge.taskqueue.owner')
        ->addRelatedPhabricatorConfig('gorge.taskqueue.uri');
      return;
    }

    if (!$this->checkReachable($uri)) {
      return;
    }

    $this->checkReady($uri);
  }


  /**
   * Probe the service and report it if it does not answer.
   *
   * Once the service is configured, the daemon routes every queue operation
   * -- enqueue, lease, complete, fail -- through it. A service which does not
   * answer therefore stalls the queue entirely, so this is the check which
   * matters most of the two.
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
      'The Gorge task queue service is configured, but does not respond to a '.
      'health check.');

    $message = pht(
      'This software is configured to run the worker task queue through the '.
      'Gorge task queue service at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Assigning %s to %s routes queue operations (enqueue, lease, '.
      'complete, fail) '.
      'through the service instead of the local SQL queue, so while the '.
      'service is down the daemons can not lease or complete work and the '.
      'queue stalls.'.
      "\n\n".
      'Check that the service is running and that %s names a host this '.
      'server can reach. To hand the queue back in the default deployment, '.
      'stop the %s consumers, set %s to %s, rerun %s without starting its '.
      'dependencies, and restart Phorge the same way. This regenerates '.
      'one deployment configuration which assigns %s to %s, clears %s and '.
      'removes its %s override together, allowing the previous or default '.
      'native taskmaster pool to resume. Do not restart those consumers '.
      'while native taskmasters are active. If another deployment system owns '.
      'these settings, make the equivalent owner, endpoint and taskmaster '.
      'changes atomically in its configuration source.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.taskqueue.owner'),
      phutil_tag('tt', array(), 'gorge'),
      phutil_tag('tt', array(), 'gorge.taskqueue.uri'),
      phutil_tag('tt', array(), 'gorge-worker'),
      phutil_tag('tt', array(), 'GORGE_TASKQUEUE_MODE'),
      phutil_tag('tt', array(), 'disable'),
      phutil_tag('tt', array(), 'phorge-migrate'),
      phutil_tag('tt', array(), 'gorge.taskqueue.owner'),
      phutil_tag('tt', array(), 'phorge'),
      phutil_tag('tt', array(), 'gorge.taskqueue.uri'),
      phutil_tag('tt', array(), 'phd.taskmasters'));

    $this->newIssue('gorge.taskqueue.unreachable')
      ->setName(pht('Gorge Task Queue Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.taskqueue.owner')
      ->addRelatedPhabricatorConfig('phd.taskmasters')
      ->addRelatedPhabricatorConfig('gorge.taskqueue.uri');

    return false;
  }


  /**
   * Report a service which is running but can not reach the queue database.
   *
   * The service reads and writes the worker tables in this server's database,
   * and it is configured with its own copy of the connection parameters.
   * Getting those wrong produces a process which starts, answers its health
   * check and reports healthy to the orchestration while being unable to
   * touch the queue. Readiness is the one signal which separates that from a
   * working install.
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
      'This server reaches the Gorge task queue service, but the service '.
      'reports that it is not ready to run the queue.');

    $message = pht(
      'This server can reach the Gorge task queue service at %s, but %s '.
      'reports that it is not ready:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Readiness here means the service can reach the database which holds '.
      'the worker queue. It is configured with its own connection settings '.
      '(%s, %s, %s, %s and %s), and %s must name the same prefix as %s does '.
      'on this side, because the queue lives in the %s database.'.
      "\n\n".
      'While the service is in this state, queue operations fail and the '.
      'daemons can not make progress, exactly as if the service were down.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $ready_uri),
      phutil_tag('pre', array(), $reason),
      phutil_tag('tt', array(), 'GORGE_TASKQUEUE_MYSQL_HOST'),
      phutil_tag('tt', array(), 'GORGE_TASKQUEUE_MYSQL_PORT'),
      phutil_tag('tt', array(), 'GORGE_TASKQUEUE_MYSQL_USER'),
      phutil_tag('tt', array(), 'GORGE_TASKQUEUE_MYSQL_PASS'),
      phutil_tag('tt', array(), 'GORGE_TASKQUEUE_NAMESPACE'),
      phutil_tag('tt', array(), 'GORGE_TASKQUEUE_NAMESPACE'),
      phutil_tag('tt', array(), 'storage.default-namespace'),
      phutil_tag('tt', array(), '{namespace}_worker'));

    $this->newIssue('gorge.taskqueue.notready')
      ->setName(pht('Gorge Task Queue Service Not Ready'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.taskqueue.uri')
      ->addRelatedPhabricatorConfig('storage.default-namespace');

    return false;
  }

}
