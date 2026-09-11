<?php

final class PhabricatorGorgeTaskQueueSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    $service = PhabricatorGorgeServiceRegistry::getService('taskqueue');
    if (!$service->isOwnedBy('gorge')) {
      // Phorge ownership used to mean "the native taskmaster pool leases from
      // the SQL queue". PhabricatorTaskmasterDaemon has been retired and phd
      // no longer launches it, so this is now a configuration in which tasks
      // are written and never consumed. Report it instead of leaving the
      // install to discover it as work which silently never happens.
      $this->newIssue('gorge.taskqueue.no-consumer')
        ->setName(pht('Task Queue Has No Consumer'))
        ->setSummary(
          pht(
            'The worker queue is assigned to Phorge, but the native '.
            'taskmaster daemon has been removed.'))
        ->setMessage(
          pht(
            'Queue ownership resolves to %s, either because %s is set to %s '.
            'or because it is %s with no %s configured. Tasks are still '.
            'written to the SQL queue, but %s has been retired and %s no '.
            'longer starts a taskmaster pool at any value of %s, so nothing '.
            'leases them: every background job (mail, search indexing, '.
            'repository work, Herald) stops running.'.
            "\n\n".
            'Configure the Gorge task queue and worker services and assign '.
            'ownership to %s. In the bundled stack that is %s with a '.
            'reachable %s.',
            phutil_tag('tt', array(), 'phorge'),
            phutil_tag('tt', array(), 'gorge.taskqueue.owner'),
            phutil_tag('tt', array(), 'phorge'),
            phutil_tag('tt', array(), 'auto'),
            phutil_tag('tt', array(), 'gorge.taskqueue.uri'),
            phutil_tag('tt', array(), 'PhabricatorTaskmasterDaemon'),
            phutil_tag('tt', array(), 'phd'),
            phutil_tag('tt', array(), 'phd.taskmasters'),
            phutil_tag('tt', array(), 'gorge'),
            phutil_tag('tt', array(), 'GORGE_TASKQUEUE_MODE=enable'),
            phutil_tag('tt', array(), 'GORGE_TASKQUEUE_URI')))
        ->addRelatedPhabricatorConfig('gorge.taskqueue.owner')
        ->addRelatedPhabricatorConfig('gorge.taskqueue.uri');
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
            'Set `%s` to a reachable task queue service. Handing the queue ' .
            'back to `%s` is no longer an option: the native taskmaster ' .
            'daemon has been removed, so that configuration has no consumer ' .
            'at all.',
            'gorge.taskqueue.uri',
            'phorge'))
        ->addRelatedPhabricatorConfig('gorge.taskqueue.owner')
        ->addRelatedPhabricatorConfig('gorge.taskqueue.uri');
      return;
    }

    if (!$this->checkReachable($uri)) {
      return;
    }

    if (!$this->checkReady($uri)) {
      return;
    }

    $this->checkConsumed();
  }


  /**
   * Report a queue which nothing is draining.
   *
   * The two probes above cover the task queue service, which is only half of
   * the pipeline: `gorge-worker` is a separate process which leases tasks from
   * that service and executes them. Phorge has no configured address for it --
   * the worker is a client of the queue, not something this server calls -- so
   * there is nothing to probe here, and a worker which is stopped or
   * crash-looping would otherwise leave both this check and the repository
   * panel reporting success while every background job sat unleased.
   *
   * The backlog is read from the service rather than from this server's worker
   * tables. Those tables hold the queue only when the service runs its MySQL
   * store; under `GORGE_TASKQUEUE_BACKEND=redis` the tasks live in Redis and
   * the SQL tables stay empty, so reading SQL directly would report a clean
   * queue during exactly the outage this check exists to find. The service's
   * own counts are the same either way.
   */
  private function checkConsumed() {
    try {
      $client = new PhabricatorGorgeTaskQueueClient();
      $stats = $client->getStats();
    } catch (Exception $ex) {
      // A service which does not answer has already been reported by the
      // probes above, so do not describe the same outage twice.
      return;
    }

    // "activeCount" is every task in the queue and "leasedCount" is the subset
    // a worker holds a live lease on, so the difference is the work that
    // nothing is touching.
    $active = (int)idx($stats, 'activeCount');
    $leased = (int)idx($stats, 'leasedCount');

    $waiting = $active - $leased;
    if ($waiting < 1) {
      return;
    }

    // A backlog only means something once it stops moving: a burst is normal,
    // and a worker which is keeping up drains it in seconds. Age the report off
    // the oldest task nothing is holding.
    $window = phutil_units('15 minutes in seconds');
    $horizon = PhabricatorTime::getNow() - $window;

    $oldest = $this->getOldestWaitingTask($client, $horizon);
    if ($oldest === null) {
      return;
    }

    $age = PhabricatorTime::getNow() - $oldest;

    $summary = pht(
      'Tasks are queued but no worker is leasing them, so background work is '.
      'not running.');

    $message = pht(
      'The task queue holds %s task(s) which no worker is holding -- either '.
      'never leased, or leased by a worker which stopped and let the lease '.
      'expire -- the oldest queued for %s. The Gorge task queue service is '.
      'reachable, so the tasks are being written correctly; what is missing '.
      'is a consumer draining them.'.
      "\n\n".
      'That consumer is `%s`, a separate process which leases from the task '.
      'queue service and executes the work. This server never calls it, so '.
      'it cannot be probed directly -- check that it is running and look at '.
      'its logs. In the bundled Compose stack it answers a health check on '.
      '%s.'.
      "\n\n".
      'While this persists, every background job -- outbound mail, search '.
      'indexing, repository import, Herald -- is queued and never run.',
      new PhutilNumber($waiting),
      phutil_format_relative_time($age),
      phutil_tag('tt', array(), 'gorge-worker'),
      phutil_tag('tt', array(), ':8170/healthz'));

    $this->newIssue('gorge.taskqueue.not-consumed')
      ->setName(pht('Task Queue Is Not Being Drained'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.taskqueue.uri')
      ->addRelatedPhabricatorConfig('gorge.taskqueue.owner');
  }


  /**
   * Find the oldest queued task which no worker is holding.
   *
   * Samples one page instead of walking the queue. The service lists by
   * priority and then by id, so when nothing is draining the oldest tasks sit
   * at the front of each band and land on the first page; a queue which is
   * moving may hide its oldest task further in, which errs toward reporting
   * nothing rather than toward a false alarm.
   *
   * A task counts as held only while its lease is live. An expired lease is
   * leasable again -- `PhabricatorWorkerLeaseQuery` takes both its unleased
   * and expired phases -- so a worker which died mid-batch leaves tasks that
   * still name it as their owner and still need a consumer.
   *
   * @param PhabricatorGorgeTaskQueueClient $client Configured client.
   * @param int $horizon Epoch before which a waiting task counts as stuck.
   * @return int|null Creation time of the oldest stuck task, if any.
   */
  private function getOldestWaitingTask(
    PhabricatorGorgeTaskQueueClient $client,
    $horizon) {

    try {
      $tasks = $client->getTasks(100);
    } catch (Exception $ex) {
      return null;
    }

    $now = PhabricatorTime::getNow();
    $oldest = null;

    foreach ($tasks as $task) {
      $owner = idx($task, 'leaseOwner');
      $expires = (int)idx($task, 'leaseExpires');
      if (phutil_nonempty_string($owner) && ($expires >= $now)) {
        continue;
      }

      $created = (int)idx($task, 'dateCreated');
      if ($created >= $horizon) {
        continue;
      }

      if (($oldest === null) || ($created < $oldest)) {
        $oldest = $created;
      }
    }

    return $oldest;
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
      'server can reach. There is no longer a way to hand the queue back to '.
      'native taskmasters: that daemon has been retired, so %s is the only '.
      'consumer and bringing it back up is the only repair. Queued tasks '.
      'are leased once it returns.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.taskqueue.owner'),
      phutil_tag('tt', array(), 'gorge'),
      phutil_tag('tt', array(), 'gorge.taskqueue.uri'),
      phutil_tag('tt', array(), 'gorge-worker'));

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
