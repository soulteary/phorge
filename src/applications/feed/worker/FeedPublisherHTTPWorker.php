<?php

final class FeedPublisherHTTPWorker extends FeedPushWorker {

  public static function newGorgeTaskData(PhabricatorFeedStory $story, $uri) {
    $data = $story->getStoryData();
    return array(
      'deliveryVersion' => 1,
      'uri' => $uri,
      'body' => http_build_query(array(
        'storyID' => $data->getID(),
        'storyType' => $data->getStoryType(),
        'storyData' => $data->getStoryData(),
        'storyAuthorPHID' => $data->getAuthorPHID(),
        'storyText' => $story->renderText(),
        'epoch' => $data->getEpoch(),
      ), '', '&'),
    );
  }

  // Compatibility converter for persisted key-based tasks. Actual HTTP
  // delivery belongs to Gorge; this worker only materializes old story data.
  protected function doWork() {
    if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      return;
    }
    $data = $this->getTaskData();
    if (idx($data, 'deliveryVersion') === 1) {
      throw new PhabricatorWorkerPermanentFailureException(pht(
        'Versioned Feed delivery must execute in the Gorge native handler.'));
    }
    $uri = idx($data, 'uri');
    if (!in_array($uri, PhabricatorEnv::getEnvConfig('feed.http-hooks'), true)) {
      throw new PhabricatorWorkerPermanentFailureException();
    }
    $this->queueTask(
      'FeedPublisherHTTPWorker',
      self::newGorgeTaskData($this->loadFeedStory(), $uri));
  }

  public function getWaitBeforeRetry(PhabricatorWorkerTask $task) {
    return max($task->getFailureCount(), 1) * 60;
  }

}
