<?php

final class PhorgeGovernanceNativePolicyCompositionTestCase extends PhutilTestCase {

  public function testCompositionCapabilitiesAreExactBoundedSet() {
    foreach (array(array('view'), array('edit'), array('view', 'edit'),
      array('view', 'edit', 'join')) as $valid) {
      $this->assertTrue(
        PhorgeGovernanceNativePolicyCompositionVerifier::validCapabilities($valid));
    }
    foreach (array(array(), array('unknown'), array('view', 'view'),
      array('a' => 'view'), array(true), array(new stdClass()),
      array('view', 'edit', 'join', 'other')) as $invalid) {
      $this->assertFalse(
        PhorgeGovernanceNativePolicyCompositionVerifier::validCapabilities($invalid));
    }
  }

  public function testCompositionUnsupportedInputCannotClaimAtomicPermission() {
    $result = PhorgeGovernanceNativePolicyCompositionVerifier::observe(
      array(), array('unknown'));
    $this->assertEqual('blocked', $result['status']);
    $this->assertEqual('unproved-blocked', $result['source_consistency']);
    $this->assertFalse(isset($result['observations']));
    foreach (array('native_closure_complete', 'risk_complete', 'can_execute',
      'execution_atomicity_proved', 'source_and_native_observation_atomic') as $key) {
      $this->assertFalse($result[$key]);
    }
  }

  public function testCompositionTargetCannotUseArbitraryClassOrPhid() {
    $method = new ReflectionMethod(
      PhorgeGovernanceNativePolicyCompositionVerifier::class, 'target');
    foreach (array(array(), array('object_class' => 'stdClass', 'target' => 'x'),
      array('object_class' => PhabricatorProject::class,
        'target' => 'PHID-TASK-'.str_repeat('a', 20))) as $binding) {
      $caught = false;
      try {
        $method->invoke(null, $binding);
      } catch (InvalidArgumentException $error) {
        $caught = true;
      }
      $this->assertTrue($caught);
    }
  }
  public function testEffectivePolicySampleKeysAndBoundedScalarValues() {
    $keys = PhorgeGovernanceNativePolicyCompositionVerifier::getEffectivePolicyKeys();
    foreach (array('phabricator.application-settings', 'policy.allow-public',
      'policy.locked', 'phabricator.uninstalled-applications') as $key) {
      $this->assertTrue(in_array($key, $keys, true));
    }
    $method = new ReflectionMethod(
      PhorgeGovernanceNativePolicyCompositionVerifier::class, 'copyConfigValue');
    $value = 'no-one';
    $input = array('policy' => &$value);
    $budget = array('nodes' => 0, 'bytes' => 0);
    $args = array($input, 0, &$budget);
    $copy = $method->invokeArgs(null, $args);
    $value = 'users';
    $this->assertEqual('no-one', $copy['policy']);
    foreach (array(new stdClass(), str_repeat('x', 65537), 1.5) as $bad) {
      $caught = false;
      $budget = array('nodes' => 0, 'bytes' => 0);
      $args = array($bad, 0, &$budget);
      try {
        $method->invokeArgs(null, $args);
      } catch (RuntimeException $error) {
        $caught = true;
      }
      $this->assertTrue($caught);
    }
  }

  public function testKnownUnsupportedCaptureRefusesBeforeNativeContext() {
    $binding = array('operation_id' => 'synthetic-operation', 'actor' => 'actor',
      'executor' => 'executor', 'approver' => 'approver', 'target' => 'target',
      'operation' => 'project.description', 'object_class' => 'PhabricatorProject',
      'parameters_digest' => hash('sha256', 'synthetic-parameters'),
      'actor_session' => 'a', 'executor_session' => 'e', 'approver_session' => 'p');
    $snapshot = new PhorgeGovernanceReadSetSnapshot($binding, array());
    $capture = new PhorgeGovernanceNativeSourceCapture($snapshot, array(
      'status' => 'native-observed-blocked', 'native_closure_complete' => false,
      'can_execute' => false, 'execution_atomicity_proved' => false,
      'unknown_reasons' => array('unsupported_native_policy_rule')));
    // A synthetic control-flow fixture, not native SQL evidence. Invalid
    // binding proves that entering observeNative would give a different reason.
    $class = new ReflectionClass(
      PhorgeGovernanceNativePolicyCompositionVerifier::class);
    $observer = $class->newInstanceWithoutConstructor();
    $class->getProperty('binding')->setValue($observer, array());
    $class->getProperty('before')->setValue($observer, array('capture' => $capture));
    $result = $observer->finish();
    $this->assertEqual('unsupported_native_policy_rule', $result['reason']);
    $this->assertFalse(isset($result['observations']));
    $this->assertFalse($result['can_execute']);
  }

}
