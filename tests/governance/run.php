<?php

// Explicit pure native test entry point; no deployment config or SQL loading.
$root = dirname(__DIR__, 2);
$report = array('passed' => false, 'runtime' => PHP_VERSION, 'results' => array());
try {
  foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $config) {
    if (file_exists($root.'/conf/local/'.$config)) {
      throw new RuntimeException('Deployment configuration refused.');
    }
  }
  if (getenv('PHABRICATOR_ENV')) {
    throw new RuntimeException('Deployment environment refused.');
  }
  require_once $root.'/support/runtime/bootstrap-cli.php';
  phutil_load_library($root.'/src/');
  $classes = array(
    'PhorgeGovernanceDirectoryReaderTestCase',
    'PhorgeGovernanceContentDecisionTestCase',
    'PhorgeGovernanceApprovalStateTestCase',
    'PhorgeGovernanceCapabilityMapTestCase',
    'PhorgeGovernanceReadSetTestCase',
    'PhorgeGovernanceNativeObservationTestCase',
    'PhorgeGovernanceNativeSourceReaderTestCase',
    'PhorgeGovernanceNativeRuleHierarchyTestCase',
    'PhorgeGovernanceNativePolicyCompositionTestCase',
    'PhorgeGovernanceNativeReadViewTestCase',
    'PhorgeGovernanceOrganizationStoreTestCase',
    'PhorgeGovernanceOrganizationApprovalTestCase',
    'PhorgeOrganizationRequestTestCase',
    'PhorgeOrganizationMetadataTestCase',
    'PhorgeOrganizationCompoundTestCase',
  'PhorgeOrganizationHistoryTestCase',
  'PhorgeOrganizationCalendarTestCase',
  'PhorgeOrganizationTransferRequestTestCase',
  'PhorgeOrganizationTransferAdapterTestCase',
  'PhorgeOrganizationHandoverAdapterTestCase',
  'PhorgeOrganizationTransferControllerTestCase',
  'PhorgeGovernanceNativeNoncredentialIdentitySourceTestCase',
    'PhorgeGovernanceNativeIdentityTestCase',
    'PhorgeGovernanceNativeApprovalTestCase',
    'PhorgeGovernanceMediaExchangeServiceTestCase',
    'PhorgeGovernanceMediaIssuerTestCase',
    'PhorgeGovernanceMediaTargetTestCase',
    'PhorgeGovernanceMediaHTTPProtocolTestCase',
    'PhorgeGovernanceMediaFileGuardTestCase',
    'PhorgeProjectExecutionTestCase',
    'PhorgeProjectPolicyFailureTestCase',
    'PhorgePrivateMailPolicyTestCase',
    'PhorgeGovernanceNoticeEventTestCase',
    'PhorgeLegalpadVerificationTestCase',
    'PhorgeRecoveryNativeAccountFactsTestCase',
    'PhorgeRecoveryNativeWebFactsTestCase',
    'PhorgeRecoveryStopTestCase',
    'PhorgeRecoveryNativeAPIFactsTestCase',
    'PhorgeRecoveryRestoreRequestTestCase',
    'PhorgeRecoveryGuardTestCase',
    'PhorgeRecoveryPolicySourceTestCase',
    'PhorgeRecoveryProjectSourceFactsTestCase',
    'PhorgeRecoverySpacesSourceFactsTestCase',
    'PhorgeRecoveryTaskOwnerSourceTestCase',
    'PhorgeRecoveryFileSourceFactsTestCase',
    'PhorgeRecoveryApplicationConfigFactsTestCase',
    'PhorgeRecoveryOrganizationProducerTestCase',
    'PhorgeRecoveryMetadataGrantDescriptionTestCase',
    'PhorgeRecoveryOwnedRRScopeTestCase',
  );
  $expected = array();
  $cases = array();
  $working_copy = ArcanistWorkingCopyIdentity::newFromPath($root);
  foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    if (!$reflection->isSubclassOf('PhutilTestCase') ||
        $reflection->isSubclassOf('PhabricatorTestCase') ||
        $reflection->isAbstract()) {
      throw new RuntimeException('Only pure native test classes are permitted.');
    }
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
      if (strncmp($method->getName(), 'test', 4) === 0) {
        $expected[$class.'::'.$method->getName()] = true;
      }
    }
    $cases[] = (new $class())->setWorkingCopy($working_copy);
  }
  foreach ($cases as $case) {
    $case->setEnableCoverage(false);
    $case->willRunTestCases($cases);
  }
  $seen = array();
  $passed = true;
  foreach ($cases as $case) {
    foreach ($case->run() as $result) {
      $id = $result->getNamespace().'::'.$result->getName();
      if (!isset($expected[$id]) || isset($seen[$id])) {
        throw new RuntimeException('Unexpected or duplicate native result.');
      }
      $seen[$id] = true;
      $ok = $result->getResult() === ArcanistUnitTestResult::RESULT_PASS;
      $passed = $passed && $ok;
      $report['results'][] = array(
        'id' => $id,
        'result' => $result->getResult(),
        'detail' => $result->getUserData(),
      );
    }
  }
  foreach ($cases as $case) {
    $case->didRunTestCases($cases);
  }
  $report['passed'] = $passed && count($expected) > 0 &&
    count($seen) === count($expected);
  $report['expected_methods'] = count($expected);
} catch (Throwable $error) {
  $report['error'] = get_class($error).': '.$error->getMessage();
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed'] ? 0 : 1);
