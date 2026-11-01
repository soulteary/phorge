<?php

final class PhabricatorWorkerBulkJobTaskWorker
  extends PhabricatorWorkerBulkJobWorker {

  protected function doWork() {
    $lock = $this->acquireTaskLock();

    try {
      $task = $this->loadTask();
      $status = $task->getStatus();
      $job = $this->loadJob();
      switch ($task->getStatus()) {
        case PhabricatorWorkerBulkTask::STATUS_WAITING:
          // This is what we expect.
          break;
        case PhabricatorWorkerBulkTask::STATUS_RUNNING:
          if ($job->getParameter('nativeQueueBatchVersion') !== 1) {
            throw new PhabricatorWorkerPermanentFailureException(
              pht('Unversioned interrupted bulk item requires manual reconciliation.'));
          }
          // Holding this lock through business execution distinguishes a live
          // worker from an interrupted one. Its cross-database business result
          // is indeterminate: fail closed instead of replaying mutations.
          $task->setStatus(PhabricatorWorkerBulkTask::STATUS_FAIL)->save();
          $this->updateJob($job);
          throw new PhabricatorWorkerPermanentFailureException(
            pht('Interrupted bulk item has an indeterminate business result. '.
              'Review the object before submitting another edit.'));
        default:
          throw new PhabricatorWorkerPermanentFailureException(
            pht(
              'Found unexpected task status ("%s").',
              $status));
      }

      $task
        ->setStatus(PhabricatorWorkerBulkTask::STATUS_RUNNING)
        ->save();

      $actor = $this->loadActor($job);

      try {
        $job->runTask($actor, $task);
        $status = PhabricatorWorkerBulkTask::STATUS_DONE;
      } catch (Exception $ex) {
        phlog($ex);
        $status = PhabricatorWorkerBulkTask::STATUS_FAIL;
      }

      $task
        ->setStatus($status)
        ->save();

      $this->updateJob($job);
    } finally {
      $lock->unlock();
    }
  }

}
