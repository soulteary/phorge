<?php

/**
 * Fixed, conservative N07-B fixture requirements. No caller complete flag or
 * caller-reducible requirements are accepted. This never authorizes execution.
 */
final class PhorgeGovernanceReadSetValidator extends Phobject {

  public static function getRequiredSourceKinds($operation, $class) {
    if (!is_string($class) || !is_string($operation)) {
      return null;
    }
    $specific = array(
      'project.description' => 'PhabricatorProject',
      'task.title' => 'ManiphestTask',
      'task.description' => 'ManiphestTask',
      'wiki.title' => 'PhrictionDocument',
      'wiki.content' => 'PhrictionDocument',
      'project.members' => 'PhabricatorProject',
    );
    $generic = array('policy.replace', 'object.space', 'object.relationship');
    if (!in_array($class, array(
      'PhabricatorProject', 'ManiphestTask', 'PhrictionDocument'), true) ||
        ((!isset($specific[$operation]) || $specific[$operation] !== $class) &&
          !in_array($operation, $generic, true))) {
      return null;
    }
    $kinds = array(
      'user.account', 'auth.session', 'app.capability', 'space.policy',
      'policy.named', 'org.membership', 'org.appointment',
      'project.member', 'project.parent',
    );
    $extra = array(
      'PhabricatorProject' => array('project.policy'),
      'ManiphestTask' => array('task.policy', 'task.owner'),
      'PhrictionDocument' => array('wiki.policy', 'wiki.parent'),
    );
    return array_merge($kinds, $extra[$class]);
  }

  public static function getRequiredSources(array $binding) {
    $snapshot = new PhorgeGovernanceReadSetSnapshot($binding, array());
    $binding = $snapshot->getBinding();
    $kinds = self::getRequiredSourceKinds(
      $binding['operation'], $binding['object_class']);
    if ($kinds === null) {
      return null;
    }
    $result = array();
    foreach ($kinds as $kind) {
      if (in_array($kind, array('user.account', 'auth.session',
          'app.capability', 'org.membership', 'org.appointment'), true)) {
        foreach (array('actor', 'executor', 'approver') as $role) {
          $id = $binding[$role];
          if ($kind === 'auth.session') {
            $id = $binding[$role.'_session'];
          } else if ($kind === 'app.capability') {
            $id = $binding['object_class'].':'.$id;
          }
          $result[$role.'.'.$kind] = array('kind' => $kind, 'id' => $id);
        }
      } else {
        $result['target.'.$kind] = array(
          'kind' => $kind,
          'id' => $binding['target'],
        );
      }
    }
    return $result;
  }

  public static function compare(
    PhorgeGovernanceReadSetSnapshot $expected,
    PhorgeGovernanceReadSetSnapshot $current) {

    $requirements = self::getRequiredSources($expected->getBinding());
    if ($requirements === null) {
      return self::result('blocked', 'unsupported_operation');
    }
    if ($expected->getBinding() !== $current->getBinding()) {
      return self::result('blocked', 'binding_changed');
    }
    $binding = $expected->getBinding();
    if ($binding['approver'] === $binding['actor'] ||
        $binding['approver'] === $binding['executor']) {
      return self::result('blocked', 'approver_not_independent');
    }
    foreach (array($expected, $current) as $snapshot) {
      $error = self::validateSources($snapshot, $requirements);
      if ($error !== null) {
        return self::result('blocked', $error);
      }
    }
    if (!hash_equals($expected->getDigest(), $current->getDigest())) {
      return self::result('blocked', 'sources_changed');
    }
    return self::result('matched-fixture-only', 'native_adapter_missing');
  }

  private static function validateSources(
    PhorgeGovernanceReadSetSnapshot $snapshot,
    array $requirements) {

    $seen = array();
    $stores = array();
    $binding = $snapshot->getBinding();
    $kinds = self::getRequiredSourceKinds(
      $binding['operation'], $binding['object_class']);
    foreach ($snapshot->getSources() as $source) {
      $row = $source->getData();
      if (isset($seen[$row['role']])) {
        return 'duplicate_source_role';
      }
      $seen[$row['role']] = true;
      if (!in_array($row['kind'], $kinds, true)) {
        return 'unsupported_source_kind';
      }
      if (isset($requirements[$row['role']])) {
        $required = $requirements[$row['role']];
        if ($row['kind'] !== $required['kind'] ||
            $row['id'] !== $required['id']) {
          return 'source_identity_changed';
        }
      } else if (strncmp($row['role'], 'dependency.', 11)) {
        return 'unexpected_source_role';
      }
      if ($row['eligibility'] !== 'eligible') {
        return 'source_'.$row['eligibility'];
      }
      $stores[$row['store']] = true;
    }
    foreach ($requirements as $role => $requirement) {
      if (!isset($seen[$role])) {
        return 'missing_source';
      }
    }
    if (count($stores) !== 1) {
      return 'cross_store_consistency_unproven';
    }
    return null;
  }

  private static function result($status, $reason) {
    return array(
      'status' => $status,
      'reason' => $reason,
      'coverage' => 'partial-fixture-only',
      'can_execute' => false,
    );
  }

}
