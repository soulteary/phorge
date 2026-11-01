<?php

final class PhabricatorWorkerBulkJobCreateWorker
  extends PhabricatorWorkerBulkJobWorker {

  protected function doWork() {
    $lock = $this->acquireJobLock();

    try {
      $job = $this->loadJob();
      $actor = $this->loadActor($job);

      $status = $job->getStatus();
      if ($job->getParameter('nativeQueueBatchVersion') === 1 &&
          in_array($status, array(PhabricatorWorkerBulkJob::STATUS_RUNNING,
            PhabricatorWorkerBulkJob::STATUS_COMPLETE), true)) {
        // A previous execution committed the complete batch but lost its
        // parent queue acknowledgement. Never create or enqueue it again.
        return;
      }
      if ($status !== PhabricatorWorkerBulkJob::STATUS_WAITING) {
        throw new PhabricatorWorkerPermanentFailureException(
          pht('Found unexpected job status ("%s").', $status));
      }

      $job->openTransaction();
      try {
        $tasks = $job->createTasks();
        foreach ($tasks as $task) {
          $task->save();
          $this->queueTask(
            PhabricatorWorkerBulkJobTaskWorker::class,
            array('jobID' => $job->getID(), 'taskID' => $task->getID()),
            array('priority' => PhabricatorWorker::PRIORITY_BULK));
        }
        $this->updateJobStatus($job, PhabricatorWorkerBulkJob::STATUS_RUNNING);
        // These tables share the worker database. Persist items, status,
        // child queue rows and the replay marker as one SQL batch, including
        // when Gorge owns leasing. This is not a service-failure fallback.
        $this->flushTaskQueueNatively();
        $job->setParameter('nativeQueueBatchVersion', 1)->save();
        $job->saveTransaction();
      } catch (Throwable $ex) {
        $job->killTransaction();
        throw $ex;
      }
    } finally {
      $lock->unlock();
    }

    $this->updateJob($job);
  }

}
