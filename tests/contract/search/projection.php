<?php

require_once dirname(__DIR__).'/bootstrap.php';
$root = dirname(__FILE__).'/../../..';
PhabricatorEnv::initializeScriptEnvironment(true, true);
try {
  $working = ArcanistWorkingCopyIdentity::newFromPath($root);
  $count = 0;
  foreach (array('PhabricatorSearchProjectionTestCase') as $class) {
    foreach (id(new $class())->setWorkingCopy($working)->run() as $result) {
      if ($result->getResult() !== ArcanistUnitTestResult::RESULT_PASS) {
        throw new Exception($result->getName().': '.$result->getUserData());
      }
      $count++;
    }
  }
  if ($count !== 6) { throw new Exception('Expected search projection tests did not run.'); }
  echo 'Search projection PHP checks passed: '.$count." tests.\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n");
  exit(1);
}
