<?php

// Exercise the actual integration-case hooks without constructing storage,
// running a test body, or changing the bundled runner's pure-unit default.
require_once dirname(__DIR__).'/contract/bootstrap.php';
$original = (int)ini_get('max_execution_time');
$check = function($condition, $message) {
  if (!$condition) { throw new Exception($message); }
};
try {
  foreach (array(0, 17) as $limit) {
    foreach (array(false, true) as $body_fails) {
      set_time_limit($limit);
      $case = new PhorgeAtomicEnvironmentTestCase();
      $will = new ReflectionMethod($case, 'willRunOneTest');
      $did = new ReflectionMethod($case, 'didRunOneTest');
      $check(!LiskDAO::shouldIsolateAllLiskEffectsToTransactions(),
        'The hook contract requires a pristine transaction-isolation scope.');
      $will->invoke($case, 'testStorageUpgradeCanBeRepeated');
      try {
        $check((int)ini_get('max_execution_time') === 30,
          'The actual integration hook did not apply its bounded CPU budget.');
        if ($body_fails) {
          throw new RuntimeException('Synthetic body failure.');
        }
      } catch (RuntimeException $ex) {
        $check($body_fails, 'An unexpected synthetic body failure occurred.');
      } finally {
        $did->invoke($case, 'testStorageUpgradeCanBeRepeated');
      }
      $check((int)ini_get('max_execution_time') === $limit,
        'The integration hook leaked its CPU budget into the next test.');
      $check(!LiskDAO::shouldIsolateAllLiskEffectsToTransactions(),
        'The integration hook leaked its transaction-isolation scope.');
    }
  }
} finally {
  set_time_limit($original);
}
echo "Atomic integration CPU budget and native restoration passed (4 scenarios).\n";
