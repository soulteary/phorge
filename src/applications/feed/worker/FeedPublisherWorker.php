<?php

final class FeedPublisherWorker extends FeedPushWorker {

  public static function newGorgeEvents($chrono_key, array $task_data, $independent) {
    $events = array();
    if ($independent && isset($task_data['notification'])) {
      $events[] = PhabricatorNotificationPublishWorker::newGorgeEvent(
        $chrono_key, $task_data['notification']);
      unset($task_data['notification']);
      $task_data['notificationDeliveryVersion'] = 1;
    }
    $events[] = array(
      'eventID' => 'feed.publish/'.$chrono_key,
      'task' => array(
        'taskClass' => self::class,
        'data' => phutil_json_encode($task_data),
      ),
    );
    return $events;
  }

  protected function doWork() {
    $story = $this->loadFeedStory();
    $task_data = $this->getTaskData();

    // Publish the real-time event in its own followup task. In required mode,
    // an unavailable notification service then retries only that delivery;
    // it can not replay the already-committed feed story, references, or
    // notification rows. Followups are committed atomically when this worker
    // completes, so a failure below does not leave a partial task batch.
    $notification = idx($task_data, 'notification');
    if (idx($task_data, 'notificationDeliveryVersion') !== 1 &&
        is_array($notification)) {
      $this->queueTask(
        'PhabricatorNotificationPublishWorker',
        array(
          'message' => $notification,
        ));
    }

    $uris = PhabricatorEnv::getEnvConfig('feed.http-hooks');
    if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      $uris = array();
    }

    if ($uris) {
      foreach ($uris as $uri) {
        $this->queueTask(
          'FeedPublisherHTTPWorker',
          FeedPublisherHTTPWorker::newGorgeTaskData($story, $uri));
      }
    }

    $argv = array(
      array(),
    );

    // Find and schedule all the enabled Doorkeeper publishers.
    // TODO: Use PhutilClassMapQuery?
    $doorkeeper_workers = id(new PhutilSymbolLoader())
      ->setAncestorClass(DoorkeeperFeedWorker::class)
      ->loadObjects($argv);
    foreach ($doorkeeper_workers as $worker) {
      if (!$worker->isEnabled()) {
        continue;
      }
      $this->queueTask(
        get_class($worker),
        array(
          'key' => $story->getChronologicalKey(),
        ));
    }
  }


}
