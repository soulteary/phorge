<?php

/**
 * Publish one real-time notification outside the durable write transaction.
 *
 * Required notification failures retry this task without repeating the
 * business operation which created the feed story. Fallback policy is still
 * applied by @{class:PhabricatorNotificationClient} at the HTTP boundary.
 */
final class PhabricatorNotificationPublishWorker
  extends PhabricatorWorker {

  protected function doWork() {
    $task_data = $this->getTaskData();
    $message = idx($task_data, 'message');

    if (!is_array($message)) {
      throw new PhabricatorWorkerPermanentFailureException(
        pht('Notification publish task has no valid message payload.'));
    }

    PhabricatorNotificationClient::tryToPostMessage($message);
  }

}
