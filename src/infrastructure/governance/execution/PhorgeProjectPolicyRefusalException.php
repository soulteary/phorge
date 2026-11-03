<?php

/** An expected business refusal, never an authorization or execution permit. */
final class PhorgeProjectPolicyRefusalException extends RuntimeException {
  private $reason;

  private function __construct($reason, $message) {
    $this->reason = $reason;
    parent::__construct($message);
  }

  public static function newForReason($reason) {
    $messages = array(
      'request-input' => 'project_execution_payload_refused',
      'ticket-input' => 'project_execution_identifier_refused',
      'ticket-unavailable' => 'project_execution_ticket_refused',
      'ticket-expired' => 'project_execution_ticket_expired',
      'ticket-not-ready' => 'project_execution_transition_refused',
      'execution-binding' => 'project_execution_execution_binding_refused',
      'ticket-binding' => 'project_execution_ticket_binding_changed',
      'sources-stale' => 'project_execution_sources_stale',
      'independent-approver' => 'project_execution_independent_admin_required',
    );
    if (!is_string($reason) || !isset($messages[$reason])) {
      throw new InvalidArgumentException('project_policy_refusal_reason_invalid');
    }
    return new self($reason, $messages[$reason]);
  }

  public function getRefusalReason() {
    return $this->reason;
  }
}
