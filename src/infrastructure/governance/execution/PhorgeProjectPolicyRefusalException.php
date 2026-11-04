<?php

/** An expected business refusal, never an authorization or execution permit. */
final class PhorgeProjectPolicyRefusalException extends RuntimeException {
  private $reason;

  // Native library overrides must preserve RuntimeException's public visibility.
  // Construction still accepts only a fixed business reason, never free text.
  public function __construct($reason) {
    $messages = array(
      'proposal-input' => 'project_execution_proposal_refused',
      'proposal-no-change' => 'project_execution_no_change',
      'additional-effects' => 'project_execution_expanded_closure_unsupported',
      'request-input' => 'project_execution_payload_refused',
      'ticket-input' => 'project_execution_identifier_refused',
      'native-edit' => 'project_execution_native_edit_required',
      'native-view' => 'project_execution_native_view_required',
      'current-approver' => 'project_execution_approver_admin_required',
      'target-unavailable' => 'project_execution_target_missing',
      'ticket-unavailable' => 'project_execution_ticket_refused',
      'ticket-expired' => 'project_execution_ticket_expired',
      'ticket-not-ready' => 'project_execution_transition_refused',
      'execution-binding' => 'project_execution_execution_binding_refused',
      'ticket-binding' => 'project_execution_ticket_binding_changed',
      'sources-stale' => 'project_execution_sources_stale',
      'independent-approver' => 'project_execution_independent_admin_required',
    );
    if (func_num_args() !== 1 || !is_string($reason) || !isset($messages[$reason])) {
      throw new InvalidArgumentException('project_policy_refusal_reason_invalid');
    }
    $this->reason = $reason;
    parent::__construct($messages[$reason]);
  }

  public static function newForReason($reason) {
    return new self($reason);
  }

  public function getRefusalReason() {
    return $this->reason;
  }
}
