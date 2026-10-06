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

  protected function doWork() {
    if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      // Don't invoke hooks in silent mode.
      return;
    }

    $snapshot = $this->getTaskData();
    if (idx($snapshot, 'deliveryVersion') === 1) {
      $uri = idx($snapshot, 'uri');
      if (!in_array($uri, PhabricatorEnv::getEnvConfig('feed.http-hooks'))) {
        throw new PhabricatorWorkerPermanentFailureException();
      }
      id(new HTTPSFuture($uri, idx($snapshot, 'body')))
        ->setMethod('POST')->setTimeout(30)->resolvex();
      return;
    }
    $story = $this->loadFeedStory();
    $data = $story->getStoryData();

    $uri = idx($this->getTaskData(), 'uri');
    $valid_uris = PhabricatorEnv::getEnvConfig('feed.http-hooks');
    if (!in_array($uri, $valid_uris)) {
      throw new PhabricatorWorkerPermanentFailureException();
    }

    $post_data = array(
      'storyID'         => $data->getID(),
      'storyType'       => $data->getStoryType(),
      'storyData'       => $data->getStoryData(),
      'storyAuthorPHID' => $data->getAuthorPHID(),
      'storyText'       => $story->renderText(),
      'epoch'           => $data->getEpoch(),
    );

    // NOTE: We're explicitly using "http_build_query()" here because the
    // "storyData" parameter may be a nested object with arbitrary nested
    // sub-objects.
    $post_data = http_build_query($post_data, '', '&');

    id(new HTTPSFuture($uri, $post_data))
      ->setMethod('POST')
      ->setTimeout(30)
      ->resolvex();
  }

  public function getWaitBeforeRetry(PhabricatorWorkerTask $task) {
    return max($task->getFailureCount(), 1) * 60;
  }

}
