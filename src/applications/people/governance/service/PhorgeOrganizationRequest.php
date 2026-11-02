<?php

/** Detached bounded input. This value contract establishes no actor authority. */
final class PhorgeOrganizationRequest extends Phobject {

  public static function normalize(array $input) {
    $keys = array_keys($input); sort($keys);
    if ($keys !== array('mutations', 'operation_id') ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['operation_id'])) {
      throw new InvalidArgumentException('organization_request_refused');
    }
    // Reuse the versioned shape validator; this sentinel is never an identity
    // source, query parameter, audit actor or executable request field.
    $value = PhorgeGovernanceOrganizationRequest::normalize(array(
      'actor' => 'PHID-USER-00000000000000000000',
      'operation_id' => $input['operation_id'],
      'mutations' => $input['mutations'],
    ));
    return array('operation_id' => $value['operation_id'],
      'mutations' => $value['mutations']);
  }

  public static function unit(array $input) {
    $keys = array_keys($input); sort($keys);
    if ($keys !== array('kind', 'name', 'operation_id', 'parent_id', 'unit_id') ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['operation_id']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['unit_id'], 64) ||
        !in_array($input['kind'], array('team', 'department'), true) ||
        !is_string($input['name']) || trim($input['name']) === '' ||
        strlen($input['name']) > 255 || !phutil_is_utf8($input['name']) ||
        ($input['parent_id'] !== null &&
          !PhorgeGovernanceOrganizationRequest::identifier($input['parent_id'], 64)) ||
        $input['parent_id'] === $input['unit_id']) {
      throw new InvalidArgumentException('organization_unit_refused');
    }
    $copy = array(); foreach ($keys as $key) { $copy[$key] = $input[$key]; }
    return $copy;
  }

  public static function primaryDepartment(array $input) {
    $keys = array_keys($input); sort($keys);
    if ($keys !== array('expected_version', 'operation_id', 'unit_id', 'user_phid') ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['operation_id']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['unit_id'], 64) ||
        !PhorgeGovernanceOrganizationRequest::userID($input['user_phid']) ||
        !is_int($input['expected_version']) || $input['expected_version'] < 0 ||
        $input['expected_version'] >= PHP_INT_MAX) {
      throw new InvalidArgumentException('organization_primary_department_refused');
    }
    $copy = array(); foreach ($keys as $key) { $copy[$key] = $input[$key]; }
    return $copy;
  }


  public static function updateUnit(array $input) {
    $keys = array_keys($input); sort($keys);
    if ($keys !== array('expected_version', 'name', 'operation_id', 'parent_id', 'state', 'unit_id') ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['operation_id']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['unit_id'], 64) ||
        !is_int($input['expected_version']) || $input['expected_version'] < 1 ||
        $input['expected_version'] >= PHP_INT_MAX ||
        !in_array($input['state'], array('active', 'archived'), true) ||
        !is_string($input['name']) || trim($input['name']) === '' ||
        strlen($input['name']) > 255 || !phutil_is_utf8($input['name']) ||
        ($input['parent_id'] !== null &&
          !PhorgeGovernanceOrganizationRequest::identifier($input['parent_id'], 64)) ||
        $input['parent_id'] === $input['unit_id']) {
      throw new InvalidArgumentException('organization_unit_update_refused');
    }
    $copy = array(); foreach ($keys as $key) { $copy[$key] = $input[$key]; }
    return $copy;
  }


  public static function approval(array $input) {
    $keys = array_keys($input); sort($keys);
    if ($keys !== array('action', 'approval_id', 'operation_id', 'target_user', 'team',
        'ttl_seconds', 'valid_from', 'valid_until') ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['approval_id']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['operation_id']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($input['team'], 64) ||
        !PhorgeGovernanceOrganizationRequest::userID($input['target_user']) ||
        !in_array($input['action'], array('appointment.grant', 'appointment.revoke'), true) ||
        !is_int($input['ttl_seconds']) || $input['ttl_seconds'] < 1 ||
        $input['ttl_seconds'] > 86400) {
      throw new InvalidArgumentException('organization_approval_request_refused');
    }
    if ($input['action'] === 'appointment.grant') {
      if (!is_int($input['valid_from']) || $input['valid_from'] < 0 ||
          ($input['valid_until'] !== null && (!is_int($input['valid_until']) ||
            $input['valid_until'] <= $input['valid_from']))) {
        throw new InvalidArgumentException('organization_period_refused');
      }
    } else if ($input['valid_from'] !== null || $input['valid_until'] !== null) {
      throw new InvalidArgumentException('organization_period_refused');
    }
    $copy = array(); foreach ($keys as $key) { $copy[$key] = $input[$key]; }
    return $copy;
  }
}
