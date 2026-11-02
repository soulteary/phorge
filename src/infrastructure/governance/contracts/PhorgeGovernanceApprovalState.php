<?php

/**
 * Pure N07-A state machine. Boolean context is a proposed trusted-adapter
 * contract, not evidence of locking or authentication. No durable consumption,
 * replay ledger, target execution, or cross-database atomicity is implemented.
 */
final class PhorgeGovernanceApprovalState extends Phobject {

  private $request;
  private $state = 'draft';
  private $reason;
  private $approver;
  private $lastTransitionAt;

  public function __construct(PhorgeGovernanceApprovalRequest $request) {
    $this->request = $request;
  }

  public function getState() {
    return $this->state;
  }

  public function getReason() {
    return $this->reason;
  }

  public function canExecute() {
    return false;
  }

  public function submit($now) {
    if ($this->state !== 'draft') {
      return $this->transition('blocked', 'invalid_transition');
    }
    if (!$this->request->isValidAt($now) ||
        ($this->lastTransitionAt !== null && $now < $this->lastTransitionAt)) {
      return $this->transition('blocked', 'invalid_time');
    }
    return $this->transition('submitted', null, $now);
  }

  public function approve($approver, array $context, $now) {
    if ($this->state !== 'submitted') {
      return $this->transition('blocked', 'invalid_transition');
    }
    if (!$this->request->isValidAt($now) ||
        ($this->lastTransitionAt !== null && $now < $this->lastTransitionAt)) {
      return $this->transition('blocked', 'invalid_time');
    }
    if (!is_string($approver) || $approver === '' ||
        $approver === $this->request->getRequester() ||
        $approver === $this->request->getExecutor()) {
      return $this->transition('blocked', 'not_independent');
    }
    if (!self::hasProofs($context, array(
      'approver_active', 'approver_is_system_admin', 'approver_can_approve'))) {
      return $this->transition('blocked', 'approver_ineligible');
    }
    $next = $this->transition('approved', null, $now);
    $next->approver = $approver;
    return $next;
  }

  public function evaluateExecution(
    PhorgeGovernanceApprovalRequest $current,
    array $context,
    $now) {

    if ($this->state !== 'approved') {
      return $this->transition('blocked', 'invalid_transition');
    }
    if (!$this->request->isValidAt($now) ||
        ($this->lastTransitionAt !== null && $now < $this->lastTransitionAt)) {
      return $this->transition('blocked', 'invalid_time');
    }
    if (!hash_equals($this->request->getDigest(), $current->getDigest())) {
      return $this->transition('blocked', 'stale_binding');
    }
    if (!isset($context['approver']) ||
        $context['approver'] !== $this->approver) {
      return $this->transition('blocked', 'approver_changed');
    }
    if (!isset($context['source_status']) ||
        $context['source_status'] !== 'complete') {
      return $this->transition('blocked', 'unknown_sources');
    }
    if (!self::hasProofs($context, array(
      'requester_active', 'requester_can_request',
      'executor_active', 'executor_native_eligible',
      'approver_active', 'approver_is_system_admin', 'approver_can_approve',
      'coverage_verified', 'technical_gates_verified',
      'read_set_verified', 'transaction_adapter_verified'))) {
      return $this->transition('blocked', 'execution_gate');
    }
    return $this->transition('ready-contract-only', null, $now);
  }

  private static function hasProofs(array $context, array $keys) {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $context) || $context[$key] !== true) {
        return false;
      }
    }
    return true;
  }

  private function transition($state, $reason, $now = null) {
    $next = clone $this;
    $next->state = $state;
    $next->reason = $reason;
    if ($now !== null) {
      $next->lastTransitionAt = $now;
    }
    return $next;
  }

}
