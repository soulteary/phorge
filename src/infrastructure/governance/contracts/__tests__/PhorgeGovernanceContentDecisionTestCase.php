<?php

final class PhorgeGovernanceContentDecisionTestCase extends PhutilTestCase {

  private function newContext() {
    return array(
      'coverage_verified' => true,
      'technical_gates_verified' => true,
      'risk_verified' => true,
      'account_active' => true,
      'can_view' => true,
      'can_use_space' => true,
      'can_use_application' => true,
      'content_eligible' => true,
      'native_edit_eligible' => true,
      'risk' => 'low',
    );
  }

  public function testContentPositiveIsNotAnExecutionGrant() {
    $result = PhorgeGovernanceContentDecision::evaluate(
      'content', 'task.description', $this->newContext());
    $this->assertEqual('eligible-contract-only', $result['status']);
    $this->assertFalse($result['can_execute']);
  }

  public function testContentCannotManageOrMoveObjects() {
    foreach (array('policy.replace', 'project.members',
      'organization.admin', 'object.space', 'object.relationship') as $op) {
      $result = PhorgeGovernanceContentDecision::evaluate(
        'content', $op, $this->newContext());
      $this->assertEqual('blocked', $result['status']);
    }
  }

  public function testNativeEditIsPreservedForLowRiskOnly() {
    $result = PhorgeGovernanceContentDecision::evaluate(
      'native-edit', 'project.members', $this->newContext());
    $this->assertEqual('native_edit_preserved', $result['reason']);
    $context = $this->newContext();
    $context['risk'] = 'high';
    $result = PhorgeGovernanceContentDecision::evaluate(
      'native-edit', 'project.members', $context);
    $this->assertEqual('approval-required', $result['status']);
    $this->assertFalse($result['can_execute']);
  }

  public function testNewContentDoesNotInheritNativeManagement() {
    $context = $this->newContext();
    $context['native_edit_eligible'] = false;
    $result = PhorgeGovernanceContentDecision::evaluate(
      'content', 'wiki.content', $context);
    $this->assertEqual('eligible-contract-only', $result['status']);
    $result = PhorgeGovernanceContentDecision::evaluate(
      'native-edit', 'policy.replace', $context);
    $this->assertEqual('blocked', $result['status']);
  }

  public function testNativeEditDoesNotGrantOrganizationAppointments() {
    $result = PhorgeGovernanceContentDecision::evaluate(
      'native-edit', 'organization.admin', $this->newContext());
    $this->assertEqual('blocked', $result['status']);
    $this->assertEqual('separate_organization_contract', $result['reason']);
  }

  public function testMissingUnknownAndUnverifiedInputsBlock() {
    $context = $this->newContext();
    foreach (array('coverage_verified', 'technical_gates_verified', 'risk_verified',
      'account_active', 'can_view', 'can_use_space', 'can_use_application')
      as $key) {
      $bad = $context;
      $bad[$key] = false;
      $result = PhorgeGovernanceContentDecision::evaluate(
        'content', 'task.description', $bad);
      $this->assertEqual('blocked', $result['status']);
      $bad[$key] = 1;
      $result = PhorgeGovernanceContentDecision::evaluate(
        'content', 'task.description', $bad);
      $this->assertEqual('blocked', $result['status']);
    }
    foreach (array(null, 'unknown') as $risk) {
      $context['risk'] = $risk;
      $result = PhorgeGovernanceContentDecision::evaluate(
        'native-edit', 'policy.replace', $context);
      $this->assertEqual('blocked', $result['status']);
    }
    $result = PhorgeGovernanceContentDecision::evaluate(
      'content', 'board.unverified-move', $this->newContext());
    $this->assertEqual('unsupported_operation', $result['reason']);
  }

}
