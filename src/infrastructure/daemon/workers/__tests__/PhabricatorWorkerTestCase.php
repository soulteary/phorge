<?php

final class PhabricatorWorkerTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  protected function willRunOneTest($test) {
    parent::willRunOneTest($test);

    // Before we run these test cases, clear the queue. After D20412, we may
    // have queued tasks from migrations.
    $task_table = new PhabricatorWorkerActiveTask();
    $conn = $task_table->establishConnection('w');

    queryfx(
      $conn,
      'TRUNCATE %R',
      $task_table);
  }

  public function testLeaseTask() {
    $task = $this->scheduleTask();
    $this->expectNextLease($task, pht('Leasing should work.'));
  }

  public function testTaskQueuePreflightFailureCanFallback() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'fallback');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');
    $env->overrideEnvConfig('gorge.taskqueue.uri', null);

    PhabricatorGorgeServiceSpec::resetFallbackCounts();

    $task = $this->scheduleTask();
    $this->assertTrue((bool)$task->getID());
    $stored_task = id(new PhabricatorWorkerActiveTask())
      ->load($task->getID());
    $this->assertTrue($stored_task instanceof PhabricatorWorkerActiveTask);
    $this->assertEqual(
      array('taskqueue.enqueue' => 1),
      PhabricatorGorgeServiceSpec::getFallbackCounts());

    PhabricatorGorgeServiceSpec::resetFallbackCounts();
    unset($env);
  }

  public function testTaskQueueLeasePreflightFailureCanFallback() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'fallback');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    $env->overrideEnvConfig('gorge.taskqueue.uri', null);

    $task = $this->scheduleTask();

    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');
    PhabricatorGorgeServiceSpec::resetFallbackCounts();

    $leased = id(new PhabricatorWorkerLeaseQuery())
      ->setLimit(1)
      ->execute();

    $this->assertEqual(1, count($leased));
    $this->assertEqual($task->getID(), head($leased)->getID());
    $this->assertEqual(
      array('taskqueue.lease' => 1),
      PhabricatorGorgeServiceSpec::getFallbackCounts());

    PhabricatorGorgeServiceSpec::resetFallbackCounts();
    unset($env);
  }

  public function testCompletionFailureDoesNotFailSuccessfulWork() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'required');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    $env->overrideEnvConfig(
      'gorge.taskqueue.uri',
      'http://127.0.0.1:1');

    $task = $this->scheduleTask();
    $task = $this->expectNextLease($task);

    // Route only the completion report through the unreachable service. The
    // worker itself has already been leased successfully from native storage.
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');

    $caught = null;
    try {
      $task->executeTask();
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue($caught instanceof Exception);
    $stored_task = id(new PhabricatorWorkerActiveTask())
      ->load($task->getID());
    $this->assertEqual(0, $stored_task->getFailureCount());
    $this->assertEqual(
      PhabricatorWorkerActiveTask::COMPLETION_PENDING_OWNER,
      $stored_task->getLeaseOwner());
    $this->assertTrue($stored_task->getLeaseExpires() > time());

    $followups = id(new PhabricatorWorkerActiveTask())
      ->loadAllWhere('id != %d', $task->getID());
    $this->assertEqual(0, count($followups));

    unset($env);
  }

  public function testGorgeFollowupsUseAtomicNativeFinalization() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'required');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    $env->overrideEnvConfig(
      'gorge.taskqueue.uri',
      'http://127.0.0.1:1');

    $task = $this->scheduleTask(
      array(
        'queueFollowup' => true,
      ));
    $task = $this->expectNextLease($task);
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');

    // The unreachable required service proves this path does not complete
    // the parent before persisting its followup through a second HTTP call.
    $result = $task->executeTask();
    $this->assertTrue($result->isArchived());

    $stored_parent = id(new PhabricatorWorkerActiveTask())
      ->load($task->getID());
    $this->assertEqual(null, $stored_parent);

    $followups = id(new PhabricatorWorkerActiveTask())
      ->loadAllWhere('id != %d', $task->getID());
    $this->assertEqual(1, count($followups));
    $this->assertTrue((bool)head($followups)->getDataID());

    unset($env);
  }

  public function testCompletionFallbackPersistsFollowupNatively() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'fallback');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    $env->overrideEnvConfig(
      'gorge.taskqueue.uri',
      'http://127.0.0.1:1');

    $task = $this->scheduleTask(
      array(
        'queueFollowup' => true,
      ));
    $task = $this->expectNextLease($task);
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');

    $result = $task->executeTask();
    $this->assertTrue($result->isArchived());

    $followups = id(new PhabricatorWorkerActiveTask())
      ->loadAllWhere('id != %d', $task->getID());
    $this->assertEqual(1, count($followups));

    unset($env);
  }

  public function testDelegatedExecutionPersistsFollowups() {
    // When Gorge owns the queue, gorge-worker leases the task and hands the
    // business logic back through "worker.execute". That path does not go
    // through PhabricatorWorkerActiveTask::executeTask(), which is the only
    // other thing that flushes the worker's followup queue, so the children a
    // worker stages with queueTask() have to be persisted by the Conduit
    // method itself or they are lost silently.
    $request = new ConduitAPIRequest(
      array(
        'taskClass' => 'PhabricatorTestWorker',
        'data' => phutil_json_encode(
          array(
            'queueFollowup' => true,
            'followupCount' => 2,
          )),
      ),
      $strictly_typed = false);
    $request->setUser(PhabricatorUser::getOmnipotentUser());

    $result = id(new PhabricatorWorkerExecuteConduitAPIMethod())
      ->executeMethod($request);

    $this->assertEqual('success', idx($result, 'result'));

    $followups = id(new PhabricatorWorkerActiveTask())->loadAll();
    $this->assertEqual(2, count($followups));
  }

  public function testDelegatedExecutionReportsFollowupFailure() {
    // A followup which cannot be scheduled must not be reported as success:
    // the work ran, but the children it staged did not, and only a retry can
    // recover them.
    $request = new ConduitAPIRequest(
      array(
        'taskClass' => 'PhabricatorTestWorker',
        'data' => phutil_json_encode(
          array(
            'queueFollowup' => true,
            'invalidFollowup' => true,
            'followupCount' => 2,
          )),
      ),
      $strictly_typed = false);
    $request->setUser(PhabricatorUser::getOmnipotentUser());

    $result = id(new PhabricatorWorkerExecuteConduitAPIMethod())
      ->executeMethod($request);

    $this->assertEqual('failure', idx($result, 'result'));
    $this->assertEqual('transient', idx($result, 'failureType'));
  }

  public function testFailedFollowupPersistenceDoesNotParkParent() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'required');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    $env->overrideEnvConfig(
      'gorge.taskqueue.uri',
      'http://127.0.0.1:1');

    $task = $this->scheduleTask(
      array(
        'queueFollowup' => true,
        'invalidFollowup' => true,
        'followupCount' => 2,
      ));
    $task = $this->expectNextLease($task);
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');

    $caught = null;
    try {
      $task->executeTask();
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue($caught instanceof Exception);
    $stored_task = id(new PhabricatorWorkerActiveTask())
      ->load($task->getID());
    $this->assertTrue(
      $stored_task->getLeaseOwner() !==
        PhabricatorWorkerActiveTask::COMPLETION_PENDING_OWNER);
    $followups = id(new PhabricatorWorkerActiveTask())
      ->loadAllWhere('id != %d', $task->getID());
    $this->assertEqual(0, count($followups));

    unset($env);
  }

  public function testPermanentFailureReportFailureParksTask() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'required');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    $env->overrideEnvConfig(
      'gorge.taskqueue.uri',
      'http://127.0.0.1:1');

    $task = $this->scheduleTask(
      array(
        'doWork' => 'fail-permanent',
      ));
    $task = $this->expectNextLease($task);
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');

    $caught = null;
    try {
      $task->executeTask();
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue($caught instanceof Exception);
    $stored_task = id(new PhabricatorWorkerActiveTask())
      ->load($task->getID());
    $this->assertEqual(
      PhabricatorWorkerActiveTask::FAILURE_PENDING_OWNER,
      $stored_task->getLeaseOwner());
    $this->assertTrue($stored_task->getLeaseExpires() > time());

    unset($env);
  }

  public function testMultipleLease() {
    $task = $this->scheduleTask();

    $this->expectNextLease($task);
    $this->expectNextLease(
      null,
      pht('We should not be able to lease a task multiple times.'));
  }

  public function testOldestFirst() {
    $task1 = $this->scheduleTask();
    $task2 = $this->scheduleTask();

    $this->expectNextLease(
      $task1,
      pht('Older tasks should lease first, all else being equal.'));
    $this->expectNextLease($task2);
  }

  public function testNewBeforeLeased() {
    $task1 = $this->scheduleTask();
    $task2 = $this->scheduleTask();

    $task1->setLeaseOwner('test');
    $task1->setLeaseExpires(time() - 100000);
    $task1->forceSaveWithoutLease();

    $this->expectNextLease(
      $task2,
      pht(
        'Tasks not previously leased should lease before previously '.
        'leased tasks.'));
    $this->expectNextLease($task1);
  }

  public function testExecuteTask() {
    $task = $this->scheduleAndExecuteTask();

    $this->assertEqual(true, $task->isArchived());
    $this->assertEqual(
      PhabricatorWorkerArchiveTask::RESULT_SUCCESS,
      $task->getResult());
  }

  public function testPermanentTaskFailure() {
    $task = $this->scheduleAndExecuteTask(
      array(
        'doWork' => 'fail-permanent',
      ));

    $this->assertEqual(true, $task->isArchived());
    $this->assertEqual(
      PhabricatorWorkerArchiveTask::RESULT_FAILURE,
      $task->getResult());
  }

  public function testTemporaryTaskFailure() {
    $task = $this->scheduleAndExecuteTask(
      array(
        'doWork' => 'fail-temporary',
      ));

    $this->assertFalse($task->isArchived());
    $this->assertTrue($task->getExecutionException() instanceof Exception);
  }

  public function testTooManyTaskFailures() {
    // Expect temporary failures, then a permanent failure.
    $task = $this->scheduleAndExecuteTask(
      array(
        'doWork'                => 'fail-temporary',
        'getMaximumRetryCount'  => 3,
        'getWaitBeforeRetry'    => -60,
      ));

    // Temporary...
    $this->assertFalse($task->isArchived());
    $this->assertTrue($task->getExecutionException() instanceof Exception);
    $this->assertEqual(1, $task->getFailureCount());

    // Temporary...
    $task = $this->expectNextLease($task);
    $task = $task->executeTask();
    $this->assertFalse($task->isArchived());
    $this->assertTrue($task->getExecutionException() instanceof Exception);
    $this->assertEqual(2, $task->getFailureCount());

    // Temporary...
    $task = $this->expectNextLease($task);
    $task = $task->executeTask();
    $this->assertFalse($task->isArchived());
    $this->assertTrue($task->getExecutionException() instanceof Exception);
    $this->assertEqual(3, $task->getFailureCount());

    // Temporary...
    $task = $this->expectNextLease($task);
    $task = $task->executeTask();
    $this->assertFalse($task->isArchived());
    $this->assertTrue($task->getExecutionException() instanceof Exception);
    $this->assertEqual(4, $task->getFailureCount());

    // Permanent.
    $task = $this->expectNextLease($task);
    $task = $task->executeTask();
    $this->assertTrue($task->isArchived());
    $this->assertEqual(
      PhabricatorWorkerArchiveTask::RESULT_FAILURE,
      $task->getResult());
  }

  public function testWaitBeforeRetry() {
    $task = $this->scheduleTask(
      array(
        'doWork'                => 'fail-temporary',
        'getWaitBeforeRetry'    => 1000000,
      ));

    $this->expectNextLease($task)->executeTask();
    $this->expectNextLease(null);
  }

  public function testRequiredLeaseTime() {
    $task = $this->scheduleAndExecuteTask(
      array(
        'getRequiredLeaseTime'     => 1000000,
      ));

    $this->assertTrue(($task->getLeaseExpires() - time()) > 1000);
  }

  public function testLeasedIsOldestFirst() {
    $task1 = $this->scheduleTask();
    $task2 = $this->scheduleTask();

    $task1->setLeaseOwner('test');
    $task1->setLeaseExpires(time() - 100000);
    $task1->forceSaveWithoutLease();

    $task2->setLeaseOwner('test');
    $task2->setLeaseExpires(time() - 200000);
    $task2->forceSaveWithoutLease();

    $this->expectNextLease(
      $task2,
      pht(
        'Tasks which expired earlier should lease first, '.
        'all else being equal.'));
    $this->expectNextLease($task1);
  }

  public function testLeasedIsLowestPriority() {
    $task1 = $this->scheduleTask(array(), 2);
    $task2 = $this->scheduleTask(array(), 2);
    $task3 = $this->scheduleTask(array(), 1);

    $this->expectNextLease(
      $task3,
      pht('Tasks with a lower priority should be scheduled first.'));
    $this->expectNextLease(
      $task1,
      pht('Tasks with the same priority should be FIFO.'));
    $this->expectNextLease($task2);
  }

  private function expectNextLease($task, $message = null) {
    $leased = id(new PhabricatorWorkerLeaseQuery())
      ->setLimit(1)
      ->execute();

    if ($task === null) {
      $this->assertEqual(0, count($leased), $message);
      return null;
    } else {
      $this->assertEqual(1, count($leased), $message);
      $this->assertEqual(
        (int)head($leased)->getID(),
        (int)$task->getID(),
        $message);
      return head($leased);
    }
  }

  private function scheduleAndExecuteTask(
    array $data = array(),
    $priority = null) {

    $task = $this->scheduleTask($data, $priority);
    $task = $this->expectNextLease($task);
    $task = $task->executeTask();
    return $task;
  }

  private function scheduleTask(array $data = array(), $priority = null) {
    return PhabricatorWorker::scheduleTask(
      'PhabricatorTestWorker',
      $data,
      array('priority' => $priority));
  }

}
