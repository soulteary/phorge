<?php

/** Bounded value contract. It grants no native or organizational permission. */
final class PhorgeGovernanceOrganizationRequest extends Phobject {

  public static function identifier($value, $maximum = 96) {
    return is_string($value) && strlen($value) <= $maximum &&
      preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/', $value);
  }

  public static function userID($value) {
    return is_string($value) &&
      preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/', $value);
  }

  public static function normalize(array $request) {
    $keys = array_keys($request); sort($keys);
    if ($keys !== array('actor', 'mutations', 'operation_id') ||
        !self::identifier($request['operation_id']) ||
        !self::userID($request['actor']) ||
        !is_array($request['mutations']) || !array_is_list($request['mutations']) ||
        !$request['mutations'] || count($request['mutations']) > 32) {
      throw new InvalidArgumentException('organization_request_refused');
    }
    $copy = array('operation_id' => $request['operation_id'],
      'actor' => $request['actor'], 'mutations' => array());
    $seen = array();
    foreach ($request['mutations'] as $mutation) {
      if (!is_array($mutation)) {
        throw new InvalidArgumentException('organization_mutation_refused');
      }
      $keys = array_keys($mutation); sort($keys);
      if ($keys !== array('action', 'approval_id', 'expected_appointment_version',
        'expected_member_version', 'expected_team_version', 'target_user', 'team',
        'valid_from', 'valid_until') ||
          !in_array($mutation['action'], array('member.add', 'member.update',
            'member.end', 'member.suspend', 'appointment.grant',
            'appointment.revoke'), true) ||
          !self::identifier($mutation['team'], 64) ||
          !self::userID($mutation['target_user'])) {
        throw new InvalidArgumentException('organization_mutation_refused');
      }
      foreach (array('expected_team_version', 'expected_member_version',
        'expected_appointment_version') as $key) {
        if (!is_int($mutation[$key]) || $mutation[$key] < 0 ||
            $mutation[$key] >= PHP_INT_MAX) {
          throw new InvalidArgumentException('organization_version_refused');
        }
      }
      if ($mutation['expected_team_version'] < 1) {
        throw new InvalidArgumentException('organization_version_refused');
      }
      $timed = in_array($mutation['action'],
        array('member.add', 'member.update', 'appointment.grant'), true);
      if ($timed) {
        if (!is_int($mutation['valid_from']) || $mutation['valid_from'] < 0 ||
            ($mutation['valid_until'] !== null &&
              (!is_int($mutation['valid_until']) ||
                $mutation['valid_until'] <= $mutation['valid_from']))) {
          throw new InvalidArgumentException('organization_period_refused');
        }
      } else if ($mutation['valid_from'] !== null || $mutation['valid_until'] !== null) {
        throw new InvalidArgumentException('organization_period_refused');
      }
      $sensitive = strncmp($mutation['action'], 'appointment.', 12) === 0;
      if (($sensitive && !self::identifier($mutation['approval_id'])) ||
          (!$sensitive && $mutation['approval_id'] !== null)) {
        throw new InvalidArgumentException('organization_approval_refused');
      }
      $target = $mutation['team'].'/'.$mutation['target_user'];
      if (isset($seen[$target])) {
        throw new InvalidArgumentException('organization_ambiguous_batch_refused');
      }
      $seen[$target] = true;
      $fresh = array();
      foreach ($keys as $key) { $fresh[$key] = $mutation[$key]; }
      $copy['mutations'][] = $fresh;
    }
    return $copy;
  }
}
