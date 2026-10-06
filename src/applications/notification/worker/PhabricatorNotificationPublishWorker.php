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

  public static function newGorgeEvent($chrono_key, array $message) {
    $instance = PhabricatorEnv::getEnvConfig('cluster.instance');
    if (!phutil_nonempty_string($instance)) {
      $instance = 'default';
    }
    $event_id = 'notification.publish/'.$chrono_key;
    return array(
      'eventID' => $event_id,
      'task' => array(
        'taskClass' => self::class,
        'data' => phutil_json_encode(array(
          'deliveryVersion' => 1,
          'eventID' => $event_id,
          'instance' => $instance,
          'message' => $message,
        )),
      ),
    );
  }

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
