<?php

final class PhabricatorMetaMTAWorker
  extends PhabricatorWorker {

  public function getMaximumRetryCount() {
    return 250;
  }

  public function getWaitBeforeRetry(PhabricatorWorkerTask $task) {
    return ($task->getFailureCount() * 15);
  }

  protected function doWork() {
    $id = (int)$this->getTaskData();
    $lock = PhabricatorGlobalLock::newLock('gorge-mail', array('id' => $id));
    $lock->lock(5);
    try {
      $message = $this->loadMessage();
      if ($message->getStatus() != PhabricatorMailOutboundStatus::STATUS_QUEUE) {
        return;
      }
      try {
        $message->sendNow();
      } catch (PhabricatorMetaMTAPermanentFailureException $ex) {
        throw new PhabricatorWorkerPermanentFailureException($ex->getMessage());
      }
    } finally {
      $lock->unlock();
    }
  }


  private function loadMessage() {
    $message_id = $this->getTaskData();
    $message = id(new PhabricatorMetaMTAMail())
      ->load($message_id);

    if (!$message) {
      throw new PhabricatorWorkerPermanentFailureException(
        pht(
          'Unable to load mail message (with ID "%s") while preparing to '.
          'deliver it.',
          $message_id));
    }

    return $message;
  }

  public function renderForDisplay(PhabricatorUser $viewer) {
    return phutil_tag(
      'pre',
      array(),
      PlatformSymbols::getPlatformServerPath().
      ' $ ./bin/mail show-outbound --id '.
      $this->getTaskData());
  }

}
