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
 * The call is internal, machine-to-machine, and reaches this method only
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
 *
 * It also has to flush the worker's follow-up queue. A worker stages child
 * work with @{method:PhabricatorWorker::queueTask}, which keeps it in memory
 * until something persists it, and the only other thing which does is
 * @{class:PhabricatorWorkerActiveTask::executeTask} -- the native path this
 * method exists to replace.
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
      'taskID'    => 'optional int',
      'taskClass' => 'required string',
      'data'      => 'optional string',
      'priority'  => 'optional int',
    );
  }

  protected function defineReturnType() {
    return 'map<string, wild>';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR-NO-TASK-CLASS' => pht('A "taskClass" is required.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
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
      ->setData($data);
    if ($task_id !== null) {
      $task->setID((int)$task_id);
    }

    $t_start = microtime(true);
    $worker = null;
    try {
      $worker = $task->getWorkerInstance();
      $worker->setCurrentWorkerTask($task);
      $worker->executeTask();
    } catch (PhabricatorWorkerYieldException $ex) {
      return array(
        'result'   => 'yield',
        'duration' => phutil_microseconds_since($t_start),
        'retry'    => (int)$ex->getDuration(),
      );
    } catch (PhabricatorWorkerPermanentFailureException $ex) {
      return array(
        'result'        => 'permanent-failure',
        'failureType'   => 'permanent',
        'failureReason' => $ex->getMessage(),
        'duration'      => phutil_microseconds_since($t_start),
      );
    } catch (Exception $ex) {
      return array(
        'result'        => 'failure',
        'failureType'   => 'transient',
        'failureReason' => $ex->getMessage(),
        'duration'      => phutil_microseconds_since($t_start),
      );
    } catch (Throwable $ex) {
      return array(
        'result'        => 'failure',
        'failureType'   => 'transient',
        'failureReason' => $ex->getMessage(),
        'duration'      => phutil_microseconds_since($t_start),
      );
    }

    // Persist whatever the worker staged with queueTask(). Dropping these is
    // silent and permanent: FeedPublisherWorker stages the actual publishing
    // this way, and PhabricatorRepositoryCommitParserWorker stages the next
    // import stage, so the parent would report success while the work that
    // matters never happened.
    //
    // The native finalizer commits the children and the parent's archive in
    // one transaction. That is not available here: this task has no SQL row to
    // enlist -- the service owns the row -- and the service completes the
    // parent only after this method returns. So children are enqueued first
    // and the parent is reported afterwards. If completion is then lost and
    // the task is retried, the children are queued again, which is the
    // at-least-once contract every retried worker already lives under, and a
    // far better failure than losing them.
    if ($worker->hasQueuedTasks()) {
      $defaults = array();

      // The service does not send a priority today, in which case children
      // are scheduled at the default. Honour it when it starts to.
      $priority = $request->getValue('priority');
      if ($priority !== null) {
        $defaults['priority'] = (int)$priority;
      }

      try {
        $worker->flushTaskQueue($defaults);
      } catch (Exception $ex) {
        return $this->newFollowupFailure($task_class, $ex, $t_start);
      } catch (Throwable $ex) {
        return $this->newFollowupFailure($task_class, $ex, $t_start);
      }
    }

    return array(
      'result'   => 'success',
      'duration' => phutil_microseconds_since($t_start),
    );
  }

  /**
   * Report follow-up scheduling which failed after the work itself succeeded.
   *
   * Reported as transient so the task is retried: re-running the worker
   * re-stages the same children, which may duplicate any that were already
   * persisted, but leaves nothing unscheduled. Returning success instead would
   * hide the loss completely.
   *
   * @param string $task_class Task class which ran.
   * @param Throwable $ex Exception the flush raised.
   * @param float $t_start Execution start, from microtime(true).
   * @return array<string, wild> Conduit result structure.
   */
  private function newFollowupFailure($task_class, $ex, $t_start) {
    return array(
      'result'        => 'failure',
      'failureType'   => 'transient',
      'failureReason' => pht(
        'Task "%s" ran successfully, but scheduling the follow-up tasks it '.
        'queued failed: %s',
        $task_class,
        $ex->getMessage()),
      'duration'      => phutil_microseconds_since($t_start),
    );
  }

}
