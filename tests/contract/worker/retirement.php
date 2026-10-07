<?php

$arcanist = getenv('GORGE_TEST_ARCANIST_DIR');
if (!$arcanist) { fwrite(STDERR, "Set GORGE_TEST_ARCANIST_DIR.\n"); exit(1); }
require $arcanist.'/src/init/init-library.php';
phutil_load_library($arcanist.'/src');
$root = dirname(__FILE__).'/../../..';
phutil_load_library($root.'/src');
PhabricatorEnv::initializeScriptEnvironment(true, true);
try {
  $working = ArcanistWorkingCopyIdentity::newFromPath($root);
  $count = 0;
  $skipped = 0;
  foreach (array('PhutilDefaultSyntaxHighlighterEngineTestCase',
    'PhabricatorMailAdapterTestCase', 'PhabricatorMailConfigTestCase',
    'PhabricatorDeploymentConfigBuilderTestCase',
    'PhabricatorFileStorageEngineTestCase', 'PhabricatorGorgeImageTestCase',
    'PhabricatorGorgeServiceRegistryTestCase') as $class) {
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
  echo 'Retirement PHP checks passed: '.$count.' tests; '.$skipped." skipped.\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n");
  exit(1);
}
