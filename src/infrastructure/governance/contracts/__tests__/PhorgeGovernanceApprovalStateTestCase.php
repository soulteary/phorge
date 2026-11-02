<?php

final class PhorgeGovernanceApprovalStateTestCase extends PhutilTestCase {

  private function newData() {
    return array(
      'operation_id' => 'synthetic-operation-1',
      'requester' => 'synthetic-requester',
      'executor' => 'synthetic-executor',
      'target' => 'synthetic-project',
      'operation' => 'policy.replace',
      'target_version' => 'target-v1',
      'parameters' => array('policy' => array('allow' => 'team-1')),
      'read_set' => array('space' => 'v1', 'actor' => 'v1'),
      'issued_at' => 100,
      'expires_at' => 200,
    );
  }

  private function newRequest(array $change = array()) {
    return new PhorgeGovernanceApprovalRequest(
      array_replace($this->newData(), $change));
  }

  private function newContext() {
    return array(
      'approver' => 'synthetic-approver',
      'approver_active' => true,
      'approver_is_system_admin' => true,
      'approver_can_approve' => true,
      'requester_active' => true,
      'requester_can_request' => true,
      'executor_active' => true,
      'executor_native_eligible' => true,
      'coverage_verified' => true,
      'technical_gates_verified' => true,
      'read_set_verified' => true,
      'transaction_adapter_verified' => true,
      'source_status' => 'complete',
    );
  }

  private function newApproved() {
    $state = new PhorgeGovernanceApprovalState($this->newRequest());
    return $state->submit(110)->approve(
      'synthetic-approver', $this->newContext(), 120);
  }

  public function testOrderedTransitionsNeverExecute() {
    $initial = new PhorgeGovernanceApprovalState($this->newRequest());
    $submitted = $initial->submit(110);
    $approved = $submitted->approve(
      'synthetic-approver', $this->newContext(), 120);
    $ready = $approved->evaluateExecution(
      $this->newRequest(), $this->newContext(), 130);
    $this->assertEqual('draft', $initial->getState());
    $this->assertEqual('submitted', $submitted->getState());
    $this->assertEqual('approved', $approved->getState());
    $this->assertEqual('ready-contract-only', $ready->getState());
    $this->assertFalse($ready->canExecute());
  }

  public function testSelfApprovalOrNonAdministratorIsBlocked() {
    foreach (array('synthetic-requester', 'synthetic-executor', '') as $who) {
      $state = new PhorgeGovernanceApprovalState($this->newRequest());
      $result = $state->submit(110)->approve(
        $who, $this->newContext(), 120);
      $this->assertEqual('not_independent', $result->getReason());
    }
    $context = $this->newContext();
    $context['approver_is_system_admin'] = false;
    $state = new PhorgeGovernanceApprovalState($this->newRequest());
    $this->assertEqual('approver_ineligible', $state->submit(110)->approve(
      'synthetic-approver', $context, 120)->getReason());
  }

  public function testOutOfOrderAndLocalReplayAreBlocked() {
    $state = new PhorgeGovernanceApprovalState($this->newRequest());
    $this->assertEqual('blocked', $state->approve(
      'synthetic-approver', $this->newContext(), 120)->getState());
    $this->assertEqual('blocked', $state->evaluateExecution(
      $this->newRequest(), $this->newContext(), 130)->getState());
    $ready = $this->newApproved()->evaluateExecution(
      $this->newRequest(), $this->newContext(), 130);
    $this->assertEqual('invalid_transition', $ready->evaluateExecution(
      $this->newRequest(), $this->newContext(), 131)->getReason());
    // This only rejects an in-memory transition. A future durable ledger is
    // required to prevent reusing a copied approved value across processes.
  }

  public function testReversedTransitionTimesAreBlocked() {
    $state = new PhorgeGovernanceApprovalState($this->newRequest());
    $this->assertEqual('invalid_time', $state->submit(130)->approve(
      'synthetic-approver', $this->newContext(), 120)->getReason());
    $this->assertEqual('invalid_time', $this->newApproved()->evaluateExecution(
      $this->newRequest(), $this->newContext(), 119)->getReason());
  }

  public function testExpiryAndFutureRequestAreBlocked() {
    $state = new PhorgeGovernanceApprovalState($this->newRequest());
    $this->assertEqual('invalid_time', $state->submit(99)->getReason());
    $this->assertEqual('invalid_time', $state->submit(200)->getReason());
    $this->assertEqual('invalid_time', $this->newApproved()->evaluateExecution(
      $this->newRequest(), $this->newContext(), 200)->getReason());
  }

  public function testParameterTargetAndReadSetChangesInvalidateApproval() {
    $changes = array(
      array('parameters' => array('policy' => 'different')),
      array('target' => 'other-target'),
      array('target_version' => 'target-v2'),
      array('read_set' => array('space' => 'v2', 'actor' => 'v1')),
      array('operation_id' => 'other-operation'),
      array('executor' => 'other-executor'),
      array('expires_at' => 300),
    );
    foreach ($changes as $change) {
      $result = $this->newApproved()->evaluateExecution(
        $this->newRequest($change), $this->newContext(), 130);
      $this->assertEqual('stale_binding', $result->getReason());
    }
  }

  public function testCurrentRevocationAndUnknownProofsBlock() {
    $base = $this->newContext();
    foreach (array('requester_active', 'requester_can_request',
      'executor_active', 'executor_native_eligible', 'approver_active',
      'approver_is_system_admin', 'approver_can_approve',
      'coverage_verified', 'technical_gates_verified', 'read_set_verified',
      'transaction_adapter_verified') as $key) {
      $context = $base;
      $context[$key] = false;
      $result = $this->newApproved()->evaluateExecution(
        $this->newRequest(), $context, 130);
      $this->assertEqual('execution_gate', $result->getReason());
    }
    $context = $base;
    $context['source_status'] = 'unknown';
    $this->assertEqual('unknown_sources',
      $this->newApproved()->evaluateExecution(
        $this->newRequest(), $context, 130)->getReason());
    $context = $base;
    $context['approver'] = 'different-approver';
    $this->assertEqual('approver_changed',
      $this->newApproved()->evaluateExecution(
        $this->newRequest(), $context, 130)->getReason());
  }

  public function testMissingOrTruthyProofDoesNotAuthorize() {
    $context = $this->newContext();
    unset($context['coverage_verified']);
    $this->assertEqual('execution_gate',
      $this->newApproved()->evaluateExecution(
        $this->newRequest(), $context, 130)->getReason());
    $context = $this->newContext();
    $context['executor_native_eligible'] = 1;
    $this->assertEqual('execution_gate',
      $this->newApproved()->evaluateExecution(
        $this->newRequest(), $context, 130)->getReason());
  }

  public function testCanonicalBindingPreservesTypesAndListOrder() {
    $a = $this->newRequest(array(
      'parameters' => array('b' => 2, 'a' => 1),
      'read_set' => array('space' => 'v1', 'actor' => 'v1')));
    $b = $this->newRequest(array(
      'parameters' => array('a' => 1, 'b' => 2),
      'read_set' => array('actor' => 'v1', 'space' => 'v1')));
    $this->assertEqual($a->getDigest(), $b->getDigest());
    $a = $this->newRequest(array('parameters' => array(1, 2)));
    $b = $this->newRequest(array('parameters' => array(2, 1)));
    $this->assertFalse($a->getDigest() === $b->getDigest());
    $a = $this->newRequest(array('parameters' => array('n' => 1)));
    $b = $this->newRequest(array('parameters' => array('n' => '1')));
    $this->assertFalse($a->getDigest() === $b->getDigest());
    $b = $this->newRequest(array('parameters' => array('n' => 1.0)));
    $this->assertFalse($a->getDigest() === $b->getDigest());
  }

  public function testRecursiveParametersFailWithBoundedDepth() {
    $params = array();
    $params['self'] = &$params;
    $caught = null;
    try {
      $this->newRequest(array('parameters' => $params));
    } catch (InvalidArgumentException $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof InvalidArgumentException);
  }

  public function testMalformedBindingFailsClosed() {
    foreach (array(
      array('read_set' => array()),
      array('read_set' => array('actor' => null)),
      array('expires_at' => 100),
      array('parameters' => array(new stdClass())),
      array('parameters' => array(INF)),
      array('parameters' => array("invalid-utf8-\xff")),
    ) as $change) {
      $caught = null;
      try {
        $this->newRequest($change);
      } catch (InvalidArgumentException $ex) {
        $caught = $ex;
      }
      $this->assertTrue($caught instanceof InvalidArgumentException);
    }
  }

}
