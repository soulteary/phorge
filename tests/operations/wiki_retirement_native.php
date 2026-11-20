<?php
// Run only in an isolated, network-disabled supported PHP environment.
$root = dirname(__DIR__, 2);
require $root.'/scripts/init/lib.php';
init_phabricator_script(array('config.optional' => true, 'no-extensions' => true));
$classes = array(
  'PhorgeGovernanceCapabilityMapTestCase',
  'PhorgeGovernanceReadSetTestCase',
  'PhorgeGovernanceContentDecisionTestCase',
  'PhorgeGovernanceNativeObservationTestCase',
  'PhabricatorConfigRetiredSchemaTestCase',
);
$failed = 0;
$count = 0;
foreach ($classes as $class) {
  $test = new $class();
  $test->setWorkingCopy(ArcanistWorkingCopyIdentity::newDummyWorkingCopy());
  foreach ($test->run() as $result) {
    $count++;
    if ($result->getResult() !== 'pass') {
      $failed++;
      echo $class.' '.$result->getName().' '.$result->getResult().' '.$result->getUserData()."\n";
    }
  }
}
$checks = array();
$checks['wiki_classes_absent'] = !class_exists('PhrictionDocument') &&
  !class_exists('PhabricatorPhrictionApplication');
$applications = PhabricatorApplication::getAllApplications();
$classes = array_map('get_class', $applications);
$checks['core_applications_present'] =
  in_array('PhabricatorManiphestApplication', $classes, true) &&
  in_array('PhabricatorProjectApplication', $classes, true);
$checks['wiki_application_absent'] =
  !in_array('PhabricatorPhrictionApplication', $classes, true);
$methods = ConduitAPIMethod::loadAllConduitMethods();
$checks['task_api_present'] = isset($methods['maniphest.search']);
$checks['wiki_api_absent'] = true;
foreach ($methods as $name => $method) {
  if (str_starts_with($name, 'phriction.')) {
    $checks['wiki_api_absent'] = false;
  }
}
foreach ($checks as $name => $passed) {
  if (!$passed) {
    $failed++;
    echo 'Failed runtime check: '.$name."\n";
  }
}
echo json_encode(array('tests' => $count, 'failed' => $failed,
  'runtime_checks' => $checks))."\n";
exit($failed ? 1 : 0);
