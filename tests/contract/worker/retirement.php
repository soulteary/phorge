<?php

require_once dirname(__DIR__).'/bootstrap.php';
$root = dirname(__FILE__).'/../../..';
PhabricatorEnv::initializeScriptEnvironment(true, true);
try {
  $working = ArcanistWorkingCopyIdentity::newFromPath($root);
  $count = 0;
  $skipped = 0;
  foreach (array('PhutilDefaultSyntaxHighlighterEngineTestCase',
    'PhabricatorMailAdapterTestCase', 'PhabricatorMailConfigTestCase',
    'PhabricatorDeploymentConfigBuilderTestCase',
    'PhabricatorFileStorageEngineTestCase', 'PhabricatorGorgeImageTestCase',
    'PhabricatorGorgeServiceRegistryTestCase',
    'PhabricatorConfigRetiredSchemaTestCase') as $class) {
    foreach (id(new $class())->setWorkingCopy($working)->run() as $result) {
      if ($result->getResult() === ArcanistUnitTestResult::RESULT_SKIP) {
        // Optional real service contracts have dedicated acceptance jobs.
        // A skip is counted and printed, never described as a passing test.
        $skipped++;
        echo 'Skipped: '.$result->getName()."\n";
        continue;
      }
      if ($result->getResult() !== ArcanistUnitTestResult::RESULT_PASS) {
        throw new Exception($result->getName().': '.$result->getUserData());
      }
      $count++;
    }
  }
  if ($count < 10) { throw new Exception('Expected retirement test suites did not run.'); }
  foreach (array('console.php', 'notifications.php',
    'notification-status.php') as $contract) {
    list($config_output) = execx('%s %s', PHP_BINARY,
      dirname(__DIR__).'/config/'.$contract);
    echo $config_output;
  }
  echo 'Retirement PHP checks passed: '.$count.' tests; '.$skipped." skipped.\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n");
  exit(1);
}
