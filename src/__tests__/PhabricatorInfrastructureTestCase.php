<?php

final class PhabricatorInfrastructureTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testApplicationsInstalled() {
    $all = PhabricatorApplication::getAllApplications();
    $installed = PhabricatorApplication::getAllInstalledApplications();

    $this->assertEqual(
      count($all),
      count($installed),
      pht('In test cases, all applications should default to installed.'));
  }

  public function testRejectMySQLNonUTF8Queries() {
    $table = new HarbormasterScratchTable();
    $conn_r = $table->establishConnection('w');

    $snowman = "\xE2\x98\x83";
    $invalid = "\xE6\x9D";

    qsprintf($conn_r, 'SELECT %B', $snowman);
    qsprintf($conn_r, 'SELECT %s', $snowman);
    qsprintf($conn_r, 'SELECT %B', $invalid);

    $caught = null;
    try {
      qsprintf($conn_r, 'SELECT %s', $invalid);
    } catch (AphrontCharacterSetQueryException $ex) {
      $caught = $ex;
    }

    $this->assertTrue($caught instanceof AphrontCharacterSetQueryException);
  }

  public function testExecutionTimeLimitRestoredForTestResults() {
    $original_limit = (int)ini_get('max_execution_time');
    $outcomes = array(
      'pass' => ArcanistUnitTestResult::RESULT_PASS,
      'fail' => ArcanistUnitTestResult::RESULT_FAIL,
      'skip' => ArcanistUnitTestResult::RESULT_SKIP,
    );
    try {
      foreach (array(0, 17) as $limit) {
        foreach ($outcomes as $scenario => $outcome) {
          set_time_limit($limit);
          $case = $this->newExecutionTimeLimitTestCase($scenario);
          $results = $case->run();
          $this->assertEqual(
            array($outcome, $outcome),
            mpull($results, 'getResult'));
          $this->assertEqual(array(4, 4), $case->bodyLimits);
          $this->assertEqual(array($limit, $limit), $case->teardownLimits);
          $this->assertExecutionTimeLimitRestored($case, $limit);
        }
      }
    } finally {
      set_time_limit($original_limit);
    }
  }

  public function testExecutionTimeLimitRestoredForTeardownFailure() {
    $original_limit = (int)ini_get('max_execution_time');
    try {
      set_time_limit(17);
      $case = $this->newExecutionTimeLimitTestCase('teardown');
      $results = $case->run();
      $this->assertEqual(
        array(
          ArcanistUnitTestResult::RESULT_PASS,
          ArcanistUnitTestResult::RESULT_FAIL,
          ArcanistUnitTestResult::RESULT_PASS,
          ArcanistUnitTestResult::RESULT_FAIL,
        ),
        mpull($results, 'getResult'));
      $this->assertEqual(array(17, 17), $case->teardownLimits);
      $this->assertExecutionTimeLimitRestored($case, 17);
    } finally {
      set_time_limit($original_limit);
    }
  }

  public function testExecutionTimeLimitRestoredAfterSetupFailure() {
    $original_limit = (int)ini_get('max_execution_time');
    try {
      foreach (array(1, 2) as $failures) {
        set_time_limit(17);
        $case = $this->newExecutionTimeLimitTestCase('pass');
        $case->setupFailures = $failures;
        $results = $case->run();
        $expected = array(
          ArcanistUnitTestResult::RESULT_FAIL,
          $failures === 1
            ? ArcanistUnitTestResult::RESULT_PASS
            : ArcanistUnitTestResult::RESULT_FAIL,
        );
        $this->assertEqual($expected, mpull($results, 'getResult'));
        $this->assertEqual(array(4, 4), $case->setupLimits);
        $this->assertEqual(
          $failures === 1 ? array(4) : array(),
          $case->bodyLimits);
        $this->assertExecutionTimeLimitRestored($case, 17);
      }
    } finally {
      set_time_limit($original_limit);
    }
  }

  public function testExecutionTimeLimitRestoredAfterClassTeardownFailure() {
    $original_limit = (int)ini_get('max_execution_time');
    try {
      set_time_limit(17);
      $case = $this->newExecutionTimeLimitTestCase('class-teardown');
      $case->setupFailures = 2;
      $caught = null;
      try {
        $case->run();
      } catch (RuntimeException $ex) {
        $caught = $ex;
      }
      $this->assertTrue($caught instanceof RuntimeException);
      $this->assertEqual(array(4, 4), $case->setupLimits);
      $this->assertExecutionTimeLimitRestored($case, 17);
    } finally {
      set_time_limit($original_limit);
    }
  }

  private function assertExecutionTimeLimitRestored($case, $limit) {
    $this->assertEqual($limit, (int)ini_get('max_execution_time'));
    $current = new ReflectionProperty(PhabricatorTestCase::class, 'currentTest');
    $this->assertEqual(null, $current->getValue($case));
  }

  private function newExecutionTimeLimitTestCase($scenario) {
    // Exercise the real runner and hooks without creating nested storage or
    // environment fixtures. Anonymous classes do not add library-map symbols.
    $case = new class extends PhabricatorTestCase {
      public $scenario;
      public $setupFailures = 0;
      public $setupLimits = array();
      public $bodyLimits = array();
      public $teardownLimits = array();
      private $failConfiguration = false;

      protected function willRunTests() {
      }

      protected function getPhabricatorTestCaseConfiguration() {
        if ($this->failConfiguration) {
          $this->failConfiguration = false;
          throw new RuntimeException('Synthetic test cleanup failure.');
        }
        return array(self::PHABRICATOR_TESTCONFIG_ISOLATE_LISK => false);
      }

      protected function willRunOneTest($test) {
        parent::willRunOneTest($test);
        $this->setupLimits[] = (int)ini_get('max_execution_time');
        if ($this->setupFailures > 0) {
          --$this->setupFailures;
          throw new RuntimeException('Synthetic test setup failure.');
        }
      }

      protected function didRunOneTest($test) {
        $this->failConfiguration = ($this->scenario === 'teardown');
        try {
          parent::didRunOneTest($test);
        } finally {
          $this->teardownLimits[] = (int)ini_get('max_execution_time');
        }
      }

      protected function didRunTests() {
        $this->failConfiguration = ($this->scenario === 'class-teardown');
        parent::didRunTests();
      }

      public function testFirst() {
        $this->runScenario();
      }

      public function testSecond() {
        $this->runScenario();
      }

      private function runScenario() {
        $this->bodyLimits[] = (int)ini_get('max_execution_time');
        $this->assertEqual(4, (int)ini_get('max_execution_time'));
        if ($this->scenario === 'fail') {
          $this->assertFailure('Synthetic test failure.');
        } else if ($this->scenario === 'skip') {
          $this->assertSkipped('Synthetic skipped test.');
        } else {
          $this->assertTrue(true);
        }
      }
    };
    $case->scenario = $scenario;
    return $case
      ->setWorkingCopy($this->getWorkingCopy())
      ->setEnableCoverage(false);
  }

}
