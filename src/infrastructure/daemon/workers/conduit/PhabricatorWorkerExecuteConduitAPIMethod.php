<?php

/**
 * Run a single worker task in-process on the Phorge side.
 *
 * This is the PHP half of Gorge's worker delegation. When the queue is fronted
 * by the Gorge task queue service, `gorge-worker` leases the task, does the
 * bookkeeping (lease, retry, archive) against the service, and hands the
 * business logic back to Phorge by calling this method through the Gorge
 * conduit gateway. That lets `gorge-worker` stand in for
 * @{class:PhabricatorTaskmasterDaemon} without re-implementing every
 * @{class:PhabricatorWorker} in Go: the Go side owns the queue, PHP still runs
 * the work.
 *
 * The call is internal, machine-to-machine. This method independently verifies
 * the service token; callers may not bypass authentication by reaching PHP
 * directly. Historically it was assumed to reach this method only
 * through the gateway, which authenticates with its own "X-Service-Token"
 * before forwarding. There is no Phorge user session on the request, so this
 * method does not require Conduit authentication and allows unguarded writes:
 * the worker's `doWork()` is expected to write, and there is no CSRF surface on
 * an internal API call.
 *
 * The method mirrors the classifications
 * @{class:PhabricatorWorkerActiveTask::executeTask} makes, but reports them in
 * the return value instead of driving the queue itself, since the queue is the
 * caller's to drive:
 *
 *   - normal return  => "success",
 *   - @{class:PhabricatorWorkerYieldException}           => "yield",
 *   - @{class:PhabricatorWorkerPermanentFailureException} => "permanent-failure",
 *   - any other exception                                => "failure" (retry).
 */
final class PhabricatorWorkerExecuteConduitAPIMethod
  extends ConduitAPIMethod {

  public function getAPIMethodName() {
    return 'worker.execute';
  }

  public function getMethodDescription() {
    return pht(
      'Run a single worker task in-process. Used by the Gorge worker to '.
      'delegate task execution back to Phorge.');
  }

  public function shouldRequireAuthentication() {
    // The gateway authenticates the caller with "X-Service-Token" and no user
    // session is present; requiring a Conduit session here would reject every
    // legitimate call.
    return false;
  }

  public function shouldAllowUnguardedWrites() {
    // A worker's doWork() typically writes, and this is an internal API call
    // with no CSRF surface, so writes must be allowed without a write guard.
    return true;
  }

  protected function defineParamTypes() {
    return array(
      'taskID'    => 'required int',
      'phase' => 'required string',
      'executionVersion' => 'required int',
      'failureCount' => 'required int',
      'priority' => 'required int',
      'leaseOwner' => 'required string',
      'leaseExpires' => 'required int',
      'taskClass' => 'required string',
      'data'      => 'optional string',
    );
  }

  protected function defineReturnType() {
    return 'map<string, wild>';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR-NO-TASK-CLASS' => pht('A "taskClass" is required.'),
      'ERR-WORKER-AUTH' => pht('A configured worker service token is required.'),
      'ERR-WORKER-CONTEXT' => pht('A valid versioned execution context is required.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $expected = PhabricatorGorgeServiceRegistry::getService('conduit')
      ->getConfiguredToken();
    $presented = AphrontRequest::getHTTPHeader('X-Service-Token');
    if (!phutil_nonempty_string($expected) ||
        !phutil_nonempty_string($presented) ||
        !hash_equals($expected, $presented)) {
      throw new ConduitException('ERR-WORKER-AUTH');
    }
    // Probe with an empty taskClass: older implementations reject it before
    // instantiating a worker, so mixed-version deployments never run work
    // while negotiating the protocol.
    if ($request->getValue('phase') === 'capabilities' &&
        $request->getValue('executionVersion') === 1 &&
        $request->getValue('taskClass') === '') {
      return array('executionVersion' => 1, 'result' => 'capabilities');
    }
    if ($request->getValue('executionVersion') !== 1 ||
        !in_array($request->getValue('phase'), array('prepare', 'execute'), true) ||
        $request->getValue('taskID') <= 0 ||
        $request->getValue('failureCount') < 0 ||
        !phutil_nonempty_string($request->getValue('leaseOwner')) ||
        $request->getValue('leaseExpires') <= time()) {
      throw new ConduitException('ERR-WORKER-CONTEXT');
    }
    $task_class = $request->getValue('taskClass');
    if (!phutil_nonempty_string($task_class)) {
      throw new ConduitException('ERR-NO-TASK-CLASS');
    }

    $task_id = $request->getValue('taskID');

    // "data" arrives as a JSON string (the same encoding the enqueue path
    // uses), so decode it back into the array shape a worker expects. An
    // absent or empty value means "no data", i.e. an empty array.
    $raw_data = $request->getValue('data');
    $data = array();
    if (phutil_nonempty_string($raw_data)) {
      try {
        $data = phutil_json_decode($raw_data);
      } catch (PhutilJSONParserException $ex) {
        return array(
          'executionVersion' => 1,
          'result'        => 'permanent-failure',
          'failureType'   => 'permanent',
          'failureReason' => pht(
            'Task data for "%s" was not valid JSON: %s',
            $task_class,
            $ex->getMessage()),
        );
      }
    }

    // Build an ephemeral active task so the worker can reach its id, class and
    // data through getCurrentWorkerTask(); this is never saved. The Go worker
    // owns the row, so this object exists only to carry context.
    $task = id(new PhabricatorWorkerActiveTask())
      ->makeEphemeral()
      ->setTaskClass($task_class)
      ->setData($data)
      ->setFailureCount($request->getValue('failureCount'))
      ->setPriority($request->getValue('priority'))
      ->setLeaseOwner($request->getValue('leaseOwner'))
      ->setLeaseExpires($request->getValue('leaseExpires'));
    if ($task_id !== null) {
      $task->setID((int)$task_id);
    }

    $worker = null;
    $t_start = microtime(true);
    try {
      $worker = $task->getWorkerInstance();
      $worker->setCurrentWorkerTask($task);
      $maximum = $worker->getMaximumRetryCount();
      if ($maximum !== null && $task->getFailureCount() > $maximum) {
        throw new PhabricatorWorkerPermanentFailureException(
          pht('Task has exceeded its maximum number of failures.'));
      }
      if ($request->getValue('phase') === 'prepare') {
        if ($task_class === 'FeedPublisherHTTPWorker') {
          if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
            return array('executionVersion' => 1, 'result' => 'skipped');
          }
          if (!in_array(idx($data, 'uri'),
              PhabricatorEnv::getEnvConfig('feed.http-hooks'))) {
            throw new PhabricatorWorkerPermanentFailureException(
              pht('Feed hook is no longer configured.'));
          }
        }
        return array(
          'executionVersion' => 1,
          'result' => 'prepared',
          'leaseDuration' => $worker->getRequiredLeaseTime(),
        );
      }
      $worker->executeTask();
    } catch (PhabricatorWorkerYieldException $ex) {
      return array(
        'executionVersion' => 1,
        'result'   => 'yield',
        'duration' => phutil_microseconds_since($t_start),
        'retry'    => (int)$ex->getDuration(),
      );
    } catch (PhabricatorWorkerPermanentFailureException $ex) {
      return array(
        'executionVersion' => 1,
        'result'        => 'permanent-failure',
        'failureType'   => 'permanent',
        'failureReason' => $ex->getMessage(),
        'duration'      => phutil_microseconds_since($t_start),
      );
    } catch (Exception $ex) {
      return array(
        'executionVersion' => 1,
        'result'        => 'failure',
        'failureType'   => 'transient',
        'failureReason' => $ex->getMessage(),
        'duration'      => phutil_microseconds_since($t_start),
        'retry' => $this->getRetryDelay($worker, $task),
      );
    } catch (Throwable $ex) {
      return array(
        'executionVersion' => 1,
        'result'        => 'failure',
        'failureType'   => 'transient',
        'failureReason' => $ex->getMessage(),
        'duration'      => phutil_microseconds_since($t_start),
        'retry' => $this->getRetryDelay($worker, $task),
      );
    }

    return array(
      'executionVersion' => 1,
      'followups' => $worker->exportQueuedTasksForGorge(),
      'result'   => 'success',
      'duration' => phutil_microseconds_since($t_start),
    );
  }

  private function getRetryDelay($worker, PhabricatorWorkerTask $task) {
    $task->setFailureCount($task->getFailureCount() + 1);
    if ($worker) {
      $retry = $worker->getWaitBeforeRetry($task);
      if ($retry !== null) {
        return (int)$retry;
      }
    }
    return PhabricatorWorkerLeaseQuery::getDefaultWaitBeforeRetry();
  }

}
