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
  foreach (array('PhutilDefaultSyntaxHighlighterEngineTestCase',
    'PhabricatorMailAdapterTestCase', 'PhabricatorMailConfigTestCase',
    'PhabricatorDeploymentConfigBuilderTestCase',
    'PhabricatorFileStorageEngineTestCase', 'PhabricatorGorgeImageTestCase',
    'PhabricatorGorgeServiceRegistryTestCase') as $class) {
    foreach (id(new $class())->setWorkingCopy($working)->run() as $result) {
      if ($result->getResult() !== ArcanistUnitTestResult::RESULT_PASS) {
        throw new Exception($result->getName().': '.$result->getUserData());
      }
      $count++;
    }
  }
  if ($count < 10) { throw new Exception('Expected retirement test suites did not run.'); }
  echo 'Retirement PHP checks passed: '.$count." tests.\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n");
  exit(1);
}
