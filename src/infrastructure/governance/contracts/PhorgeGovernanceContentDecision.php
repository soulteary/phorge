<?php

/**
 * Unregistered, side-effect-free N05-A contract. This does not authorize an
 * actual Phorge transaction or replace any native capability check.
 */
final class PhorgeGovernanceContentDecision extends Phobject {

  public static function evaluate($path, $operation, array $context) {
    $content = array(
      'project.description' => true,
      'task.title' => true,
      'task.description' => true,
      'wiki.title' => true,
      'wiki.content' => true,
    );
    $sensitive = array(
      'policy.replace' => true,
      'project.members' => true,
      'organization.admin' => true,
      'object.space' => true,
      'object.relationship' => true,
    );

    if (!is_string($operation) ||
        (!isset($content[$operation]) && !isset($sensitive[$operation]))) {
      return self::result('blocked', 'unsupported_operation');
    }
    if ($path !== 'content' && $path !== 'native-edit') {
      return self::result('blocked', 'unsupported_path');
    }
    foreach (array('coverage_verified', 'technical_gates_verified', 'risk_verified',
      'account_active', 'can_view', 'can_use_space', 'can_use_application')
      as $key) {
      if (!array_key_exists($key, $context) || $context[$key] !== true) {
        return self::result('blocked', $key);
      }
    }
    $risk = isset($context['risk']) ? $context['risk'] : null;
    if ($risk !== 'low' && $risk !== 'high') {
      return self::result('blocked', 'unknown_risk');
    }
    // New organization appointments are never a legacy native-edit effect.
    if ($operation === 'organization.admin') {
      return self::result('blocked', 'separate_organization_contract');
    }
    if ($path === 'content') {
      if (!isset($content[$operation]) || $risk !== 'low') {
        return self::result('blocked', 'content_cannot_manage');
      }
      if (!isset($context['content_eligible']) ||
          $context['content_eligible'] !== true) {
        return self::result('blocked', 'content_ineligible');
      }
      return self::result('eligible-contract-only', 'content');
    }
    if (!isset($context['native_edit_eligible']) ||
        $context['native_edit_eligible'] !== true) {
      return self::result('blocked', 'native_ineligible');
    }
    if ($risk === 'high') {
      return self::result('approval-required', 'high_risk_native_edit');
    }
    return self::result('eligible-contract-only', 'native_edit_preserved');
  }

  private static function result($status, $reason) {
    return array(
      'status' => $status,
      'reason' => $reason,
      'can_execute' => false,
    );
  }

}
