<?php

final class PhabricatorSearchRetryTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testWorkerProtocolRetriesCaptureAndBackendFailures() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'required');
    $env->overrideEnvConfig('gorge.service-policies', array());
    $env->overrideEnvConfig('gorge.conduit.token', 'search-retry-fixture');
    $header = idx($_SERVER, 'HTTP_X_SERVICE_TOKEN');
    $_SERVER['HTTP_X_SERVICE_TOKEN'] = 'search-retry-fixture';
    $provider = new class extends PhabricatorFulltextStorageEngine {
      public $failure;
      public $documents = array();
      public function getHostType() { return new PhabricatorMySQLSearchHost($this); }
      public function getEngineIdentifier() { return 'gorge'; }
      public function reindexAbstractDocument(PhabricatorSearchAbstractDocument $doc) {
        if ($this->failure !== null) { throw $this->failure; }
        $this->documents[$doc->getPHID()] = $doc->getDocumentTitle();
      }
      public function executeSearch(PhabricatorSavedQuery $query) {
        return array_keys($this->documents);
      }
      public function indexExists() { return true; }
      public function getIndexStats() { return array(); }
    };
    $service = new PhabricatorSearchService($provider);
    $service->setConfig(array('type' => 'gorge', 'roles' => array('read' => true, 'write' => true)));
    $cache = PhabricatorCaches::getRequestCache();
    $before = $cache->getKey(PhabricatorSearchService::KEY_REFS);
    try {
      $cache->setKey(PhabricatorSearchService::KEY_REFS, array($service));
      $user = $this->generateNewTestUser();
      foreach (array(new PhabricatorSearchProjectionException('capture failed'),
        new Exception('backend unavailable')) as $failure) {
        $task = ManiphestTask::initializeNewTask($user)
          ->setTitle('Retry fixture '.$this->getNextObjectSeed())->save();
        $params = array('taskID' => 1, 'taskClass' => 'PhabricatorSearchWorker',
          'phase' => 'execute', 'executionVersion' => 1, 'failureCount' => 0,
          'priority' => PhabricatorWorker::PRIORITY_INDEX,
          'leaseOwner' => 'search-retry-fixture', 'leaseExpires' => time() + 3600,
          'data' => phutil_json_encode(array('documentPHID' => $task->getPHID())));
        $provider->failure = $failure;
        $result = id(new ConduitCall('worker.execute', $params))->execute();
        $this->assertEqual('failure', $result['result']);
        $this->assertEqual('transient', $result['failureType']);
        $this->assertTrue($result['retry'] > 0);
        $versions = id(new PhabricatorSearchIndexVersion())->loadAllWhere(
          'objectPHID = %s AND extensionKey = %s', $task->getPHID(), 'fulltext');
        $this->assertEqual(array(), $versions, 'Failure must not advance index version.');
        // Retry the same payload after the backend recovers; no object edit.
        $provider->failure = null;
        $params['failureCount'] = 1;
        $result = id(new ConduitCall('worker.execute', $params))->execute();
        $this->assertEqual('success', $result['result']);
        $this->assertEqual($task->getTitle(), $provider->documents[$task->getPHID()]);
        $versions = id(new PhabricatorSearchIndexVersion())->loadAllWhere(
          'objectPHID = %s AND extensionKey = %s', $task->getPHID(), 'fulltext');
        $this->assertEqual(1, count($versions));
        $this->assertTrue(in_array($task->getPHID(),
          PhabricatorSearchService::executeSearch(new PhabricatorSavedQuery()), true));
      }
    } finally {
      $cache->setKey(PhabricatorSearchService::KEY_REFS, $before);
      if ($header === null) { unset($_SERVER['HTTP_X_SERVICE_TOKEN']); }
      else { $_SERVER['HTTP_X_SERVICE_TOKEN'] = $header; }
    }
  }
}
