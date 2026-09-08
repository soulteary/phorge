<?php

final class PhabricatorWorkerActiveTask extends PhabricatorWorkerTask {

  protected $failureTime;

  private $serverTime;
  private $localTime;

  protected function getConfiguration() {
    $parent = parent::getConfiguration();

    $config = array(
      self::CONFIG_IDS => self::IDS_COUNTER,
      self::CONFIG_KEY_SCHEMA => array(
        'taskClass' => array(
          'columns' => array('taskClass'),
        ),
        'leaseExpires' => array(
          'columns' => array('leaseExpires'),
        ),
        'key_failuretime' => array(
          'columns' => array('failureTime'),
        ),
        'key_owner' => array(
          'columns' => array('leaseOwner', 'priority', 'id'),
        ),
      ) + $parent[self::CONFIG_KEY_SCHEMA],
    );

    return $config + $parent;
  }

  public function setServerTime($server_time) {
    $this->serverTime = $server_time;
    $this->localTime = time();
    return $this;
  }

  public function setLeaseDuration($lease_duration) {
    $this->checkLease();
    $server_lease_expires = $this->serverTime + $lease_duration;
    $this->setLeaseExpires($server_lease_expires);

    // NOTE: This is primarily to allow unit tests to set negative lease
    // durations so they don't have to wait around for leases to expire. We
    // check that the lease is valid above.
    return $this->forceSaveWithoutLease();
  }

  public function save() {
    $this->checkLease();
    return $this->forceSaveWithoutLease();
  }

  public function forceSaveWithoutLease() {
    $is_new = !$this->getID();
    if ($is_new) {
      $this->failureCount = 0;
    }

    if ($is_new) {
      $data = new PhabricatorWorkerTaskData();
      $data->setData($this->getData());
      $data->save();

      $this->setDataID($data->getID());
    }

    return parent::save();
  }

  protected function checkLease() {
    $owner = $this->leaseOwner;

    if (!$owner) {
      return;
    }

    if ($owner == PhabricatorWorker::YIELD_OWNER) {
      return;
    }

    $current_server_time = $this->serverTime + (time() - $this->localTime);
    if ($current_server_time >= $this->leaseExpires) {
      throw new Exception(
        pht(
          'Trying to update Task %d (%s) after lease expiration!',
          $this->getID(),
          $this->getTaskClass()));
    }
  }

  public function delete() {
    throw new Exception(
      pht(
        'Active tasks can not be deleted directly. '.
        'Use %s to move tasks to the archive.',
        'archiveTask()'));
  }

  public function archiveTask($result, $duration) {
    if ($this->getID() === null) {
      throw new Exception(
        pht("Attempting to archive a task which hasn't been saved!"));
    }

    $this->checkLease();

    $archive = id(new PhabricatorWorkerArchiveTask())
      ->setID($this->getID())
      ->setTaskClass($this->getTaskClass())
      ->setLeaseOwner($this->getLeaseOwner())
      ->setLeaseExpires($this->getLeaseExpires())
      ->setFailureCount($this->getFailureCount())
      ->setDataID($this->getDataID())
      ->setPriority($this->getPriority())
      ->setObjectPHID($this->getObjectPHID())
      ->setContainerPHID($this->getContainerPHID())
      ->setResult($result)
      ->setDuration($duration)
      ->setDateCreated($this->getDateCreated())
      ->setArchivedEpoch(PhabricatorTime::getNow());

    // NOTE: This deletes the active task (this object)!
    $archive->save();

    return $archive;
  }

  public function executeTask() {
    // We do this outside of the try .. catch because we don't have permission
    // to release the lease otherwise.
    $this->checkLease();

    $did_succeed = false;
    $worker = null;
    $caught = null;
    try {
      $worker = $this->getWorkerInstance();
      $worker->setCurrentWorkerTask($this);

      $maximum_failures = $worker->getMaximumRetryCount();
      if ($maximum_failures !== null) {
        if ($this->getFailureCount() > $maximum_failures) {
          throw new PhabricatorWorkerPermanentFailureException(
            pht(
              'Task %d has exceeded the maximum number of failures (%d).',
              $this->getID(),
              $maximum_failures));
        }
      }

      $lease = $worker->getRequiredLeaseTime();
      if ($lease !== null) {
        $this->setLeaseDuration($lease);
      }

      $t_start = microtime(true);
        $worker->executeTask();
      $duration = phutil_microseconds_since($t_start);

      // When the Gorge task queue service owns the queue, tell it the task
      // completed and let it archive the row; otherwise archive it here with
      // SQL. notifyGoComplete() returns false when the service is not
      // configured (or the call fails), which keeps the native path as a
      // fallback.
      $go_ok = $this->notifyGoComplete($duration);
      if (!$go_ok) {
        $result = $this->archiveTask(
          PhabricatorWorkerArchiveTask::RESULT_SUCCESS,
          $duration);
      } else {
        $result = id(new PhabricatorWorkerArchiveTask())
          ->makeEphemeral()
          ->setID($this->getID())
          ->setTaskClass($this->getTaskClass())
          ->setResult(PhabricatorWorkerArchiveTask::RESULT_SUCCESS)
          ->setDuration($duration);
      }
      $did_succeed = true;
    } catch (PhabricatorWorkerPermanentFailureException $ex) {
      $go_ok = $this->notifyGoFail(true);
      if (!$go_ok) {
        $result = $this->archiveTask(
          PhabricatorWorkerArchiveTask::RESULT_FAILURE,
          0);
      } else {
        $result = id(new PhabricatorWorkerArchiveTask())
          ->makeEphemeral()
          ->setID($this->getID())
          ->setTaskClass($this->getTaskClass())
          ->setResult(PhabricatorWorkerArchiveTask::RESULT_FAILURE)
          ->setDuration(0);
      }
      $result->setExecutionException($ex);
    } catch (PhabricatorWorkerYieldException $ex) {
      $this->setExecutionException($ex);

      $retry = $ex->getDuration();
      $retry = max($retry, 5);

      // Yield through the service when it owns the queue; otherwise release
      // the lease locally by marking the task with the yield owner.
      $go_ok = $this->notifyGoYield($retry);
      if (!$go_ok) {
        $this->setLeaseOwner(PhabricatorWorker::YIELD_OWNER);

        // NOTE: As a side effect, this saves the object.
        $this->setLeaseDuration($retry);
      }

      $result = $this;
    } catch (Exception $ex) {
      $caught = $ex;
    } catch (Throwable $ex) {
      $caught = $ex;
    }

    if ($caught) {
      $this->setExecutionException($ex);
      $this->setFailureCount($this->getFailureCount() + 1);
      $this->setFailureTime(time());

      $retry = null;
      if ($worker) {
        $retry = $worker->getWaitBeforeRetry($this);
      }

      $retry = coalesce(
        $retry,
        PhabricatorWorkerLeaseQuery::getDefaultWaitBeforeRetry());

      // Report the transient failure to the service when it owns the queue;
      // otherwise extend the lease locally so the task is retried later.
      $go_ok = $this->notifyGoFail(false, $retry);
      if (!$go_ok) {
        // NOTE: As a side effect, this saves the object.
        $this->setLeaseDuration($retry);
      }

      $result = $this;
    }

    // NOTE: If this throws, we don't want it to cause the task to fail again,
    // so execute it out here and just let the exception escape.
    if ($did_succeed) {
      // Default the new task priority to our own priority.
      $defaults = array(
        'priority' => (int)$this->getPriority(),
      );
      $worker->flushTaskQueue($defaults);
    }

    return $result;
  }


  /**
   * Report task completion to the Gorge task queue service.
   *
   * Guarded by @{method:PhabricatorGorgeTaskQueueClient::isConfigured}: when
   * the service does not own the queue this returns false immediately and the
   * caller archives the task with SQL. A failed call is also treated as "not
   * handled" so the native path can still complete the task rather than
   * leaving it leased.
   *
   * @param int $duration Execution duration in microseconds.
   * @return bool True if the service handled the completion.
   */
  private function notifyGoComplete($duration) {
    if (!PhabricatorGorgeTaskQueueClient::isConfigured()) {
      return false;
    }

    try {
      $client = new PhabricatorGorgeTaskQueueClient();
      $client->complete($this->getID(), (int)$duration);
      return true;
    } catch (Exception $ex) {
      phlog($ex);
      return false;
    }
  }

  /**
   * Report task failure to the Gorge task queue service.
   *
   * @param bool $permanent True for a permanent failure, false to retry.
   * @param int|null $retry_wait Seconds before retry, or null for the service
   *   default.
   * @return bool True if the service handled the failure.
   */
  private function notifyGoFail($permanent, $retry_wait = null) {
    if (!PhabricatorGorgeTaskQueueClient::isConfigured()) {
      return false;
    }

    try {
      $client = new PhabricatorGorgeTaskQueueClient();
      $client->fail($this->getID(), $permanent, $retry_wait);
      return true;
    } catch (Exception $ex) {
      phlog($ex);
      return false;
    }
  }

  /**
   * Report a task yield to the Gorge task queue service.
   *
   * @param int $duration Seconds to yield for.
   * @return bool True if the service handled the yield.
   */
  private function notifyGoYield($duration) {
    if (!PhabricatorGorgeTaskQueueClient::isConfigured()) {
      return false;
    }

    try {
      $client = new PhabricatorGorgeTaskQueueClient();
      $client->yield($this->getID(), $duration);
      return true;
    } catch (Exception $ex) {
      phlog($ex);
      return false;
    }
  }

}
