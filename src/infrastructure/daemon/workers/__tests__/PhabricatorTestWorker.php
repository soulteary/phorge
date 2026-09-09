<?php

final class PhabricatorTestWorker extends PhabricatorWorker {

  public function getRequiredLeaseTime() {
    return idx(
      $this->getTaskData(),
      'getRequiredLeaseTime',
      parent::getRequiredLeaseTime());
  }

  public function getMaximumRetryCount() {
    return idx(
      $this->getTaskData(),
      'getMaximumRetryCount',
      parent::getMaximumRetryCount());
  }

  public function getWaitBeforeRetry(PhabricatorWorkerTask $task) {
    return idx(
      $this->getTaskData(),
      'getWaitBeforeRetry',
      parent::getWaitBeforeRetry($task));
  }

  protected function doWork() {
    $data = $this->getTaskData();

    if (idx($data, 'queueFollowup')) {
      $this->queueTask(
        'PhabricatorTestWorker',
        array(
          'isFollowup' => true,
        ));
    }

    $duration = idx($data, 'duration');
    if ($duration) {
      usleep($duration * 1000000);
    }

    switch (idx($data, 'doWork')) {
      case 'fail-temporary':
        throw new Exception(pht('Temporary failure!'));
      case 'fail-permanent':
        throw new PhabricatorWorkerPermanentFailureException(
          pht('Permanent failure!'));
      default:
        return;
    }
  }

}
