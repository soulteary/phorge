<?php

/** Fixed native rule observations in owned fixtures, never authorization. */
final class PhorgeGovernanceNativeRuleHierarchyVerifier extends Phobject {

  public static function validateRule($class, array $value) {
    $classes = array(
      PhabricatorUsersPolicyRule::class => 'USER',
      PhabricatorProjectsPolicyRule::class => 'PROJ',
      PhabricatorProjectsAllPolicyRule::class => 'PROJ',
      PhabricatorProjectMembersPolicyRule::class => null,
    );
    if (!is_string($class) || !array_key_exists($class, $classes) ||
        !array_is_list($value) || count($value) > 16) {
      return false;
    }
    $type = $classes[$class];
    if ($type === null) {
      return !$value;
    }
    foreach ($value as $phid) {
      if (!is_string($phid) ||
          !preg_match('/\APHID-'.$type.'-[a-zA-Z0-9]{20}\z/', $phid)) {
        return false;
      }
    }
    return count(array_unique($value)) === count($value);
  }

  private static function result($reason) {
    return array('status' => 'blocked', 'reason' => $reason,
      'native_rule_result' => 'unknown', 'native_closure_complete' => false,
      'risk_complete' => false, 'can_execute' => false,
      'execution_atomicity_proved' => false,
      'source_and_native_observation_atomic' => false);
  }

  public static function observe(
    array $binding,
    PhabricatorUser $viewer,
    PhabricatorPolicyInterface $object,
    $rule_class,
    array $value) {

    if (!self::validateRule($rule_class, $value)) {
      return self::result('unsupported_native_rule_or_value');
    }
    $allowed = array(PhabricatorProject::class, ManiphestTask::class,
      PhrictionDocument::class);
    if (!in_array(get_class($object), $allowed, true) ||
        $object->getID() === null ||
        !isset($binding['target'], $binding['object_class'], $binding['actor']) ||
        $binding['target'] !== $object->getPHID() ||
        $binding['object_class'] !== get_class($object) ||
        $binding['actor'] !== $viewer->getPHID() ||
        !$viewer->isLoggedIn() || $viewer->isOmnipotent() ||
        !$viewer->getIsApproved() || $viewer->getIsDisabled() ||
        $viewer->getIsSystemAgent() || $viewer->getIsMailingList()) {
      return self::result('unsupported_native_rule_context');
    }
    if ($rule_class === PhabricatorProjectMembersPolicyRule::class &&
        get_class($object) !== PhabricatorProject::class) {
      return self::result('unsupported_native_rule_object');
    }
    $context = null;
    $result = self::result('unknown_native_rule_or_hierarchy');
    try {
      $context = PhorgeGovernanceNativeCapabilityContext::newIsolated($object);
      $result = self::observeWithContext(
        $context, $binding, $viewer, $object, $rule_class, $value);
    } catch (Throwable $error) {
      $result = self::result('unknown_native_rule_or_hierarchy');
    } finally {
      if ($context !== null && !$context->close()) {
        $result = self::result('native_context_cleanup_failed');
      }
    }
    return $result;
  }
  private static function observeWithContext(
    PhorgeGovernanceNativeCapabilityContext $context,
    array $binding, PhabricatorUser $viewer,
    PhabricatorPolicyInterface $object, $rule_class, array $value) {
    $reader = PhorgeGovernanceNativeSourceReader::newFixture(array(
      'host' => PhabricatorEnv::getEnvConfig('mysql.host'),
      'port' => PhabricatorEnv::getEnvConfig('mysql.port'),
      'user' => PhabricatorEnv::getEnvConfig('mysql.user'),
      'password' => PhabricatorEnv::getEnvConfig('mysql.pass'),
      'namespace' => PhabricatorEnv::getEnvConfig('storage.default-namespace'),
    ));
    $capture = $reader->capture($binding);
    $summary = $capture->getSummary();
    foreach ($capture->getSources() as $source) {
      $data = $source->getData();
      if (in_array($data['kind'], array('user.account', 'auth.session'), true) &&
          $data['eligibility'] !== 'eligible') {
        return self::result('native_account_or_session_unresolved');
      }
    }
    $reader = null;
    foreach (array('missing_project_parent', 'cyclic_project_parent',
      'missing_native_target', 'missing_native_policy_reference',
      'unsupported_native_policy_rule', 'malformed_native_policy_rules')
      as $blocking) {
      if (in_array($blocking, $summary['unknown_reasons'], true)) {
        $result = self::result($blocking);
        return $result;
      }
    }
    // Actual native query links parents and loads direct/materialized and
    // milestone parent memberships. No local replacement hierarchy algorithm.
    if ($rule_class === PhabricatorUsersPolicyRule::class && $value) {
      $users = (new PhabricatorPeopleQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs($value)->setLimit(17)->execute();
      if (count($users) !== count($value)) {
        return self::result('missing_native_rule_user');
      }
    }
    $phids = $rule_class === PhabricatorUsersPolicyRule::class ? array() : $value;
    if (get_class($object) === PhabricatorProject::class) {
      $phids[] = $object->getPHID();
    }
    $phids = array_values(array_unique($phids));
    $projects = array();
    if ($phids) {
      $projects = (new PhabricatorProjectQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs($phids)->needMembers(true)->needAncestorMembers(true)
        ->setLimit(18)->execute();
    }
    if (count($projects) !== count($phids)) {
      return self::result('missing_or_unresolved_native_project');
    }
    $observed = array();
    $ancestor_count = 0;
    foreach ($projects as $project) {
      $chain = array($project);
      $cursor = $project->getParentProject();
      $seen = array($project->getPHID() => true);
      while ($cursor !== null) {
        if (isset($seen[$cursor->getPHID()]) || count($chain) >= 9) {
          return self::result('native_hierarchy_cycle_or_depth_budget');
        }
        $seen[$cursor->getPHID()] = true;
        $chain[] = $cursor;
        $cursor = $cursor->getParentProject();
        $ancestor_count++;
      }
      foreach ($chain as $node) {
        if (!self::hasConsistentNativeParent($node)) {
          return self::result('inconsistent_native_hierarchy_attachment');
        }
        $members = $node->getMemberPHIDs();
        if (count($members) > 256) {
          return self::result('native_member_observation_budget');
        }
        sort($members, SORT_STRING);
        $observed[$node->getPHID()] = array($node->getParentProjectPHID(),
          $node->getProjectDepth(), bin2hex($node->getProjectPath()),
          $node->getMilestoneNumber(), $members);
      }
    }
    // Reuse the freshly native-loaded target, avoiding caller attachments.
    if (get_class($object) === PhabricatorProject::class) {
      foreach ($projects as $project) {
        if ($project->getPHID() === $object->getPHID()) {
          $object = $project;
          break;
        }
      }
    }
    $rule = new $rule_class();
    $rule->willApplyRules($viewer, array($value), array($object));
    $native = $rule->applyRule($viewer, $value, $object);
    if (!is_bool($native) || !$context->isCurrent()) {
      return self::result('unknown_native_rule_or_context_changed');
    }
    ksort($observed, SORT_STRING);
    $result = self::result(null);
    $result['status'] = 'observed-native-rule-and-hierarchy';
    $result['native_rule_result'] = $native ? 'allow' : 'deny';
    $result['project_count'] = count($projects);
    $result['ancestor_count'] = $ancestor_count;
    $result['dependency_digest'] = hash('sha256', json_encode(array(
      $summary['snapshot_fingerprint'], $rule_class, $value, $observed)));
    $result['coverage'] = 'selected-rule-and-bounded-native-project-query-only';
    $result['remaining_unknown_reasons'] = array_merge(
      $summary['unknown_reasons'], array(
        'selected_rule_not_complete_ordered_policy',
        'native_rule_query_time_and_concurrent_growth_unbounded',
        'source_and_rule_read_views_not_atomic'));
    return $result;
  }

  private static function hasConsistentNativeParent(PhabricatorProject $node) {
    // Validate native save() structural invariants on native Query attachments;
    // this does not synthesize a replacement parent graph or grant policy.
    $parent = $node->getParentProject();
    $parent_phid = $node->getParentProjectPHID();
    $key = $node->getProjectPathKey();
    $path = $node->getProjectPath();
    $depth = self::nativeDepth($node->getProjectDepth());
    if (!is_string($key) || strlen($key) !== 4 || !is_string($path) ||
        $depth === null || $depth < 0 ||
        $depth >= PhabricatorProject::getProjectDepthLimit()) {
      return false;
    }
    if ($parent === null) {
      return $parent_phid === null && $depth === 0 && $path === $key;
    }
    $parent_depth = self::nativeDepth($parent->getProjectDepth());
    return $parent_depth !== null && $parent_phid === $parent->getPHID() &&
      $depth === $parent_depth + 1 &&
      $path === $parent->getProjectPath().$key;
  }

  private static function nativeDepth($value) {
    // Native Lisk numeric columns may hydrate as canonical decimal strings.
    if (is_int($value)) {
      return $value;
    }
    if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]?)\z/', $value)) {
      return (int)$value;
    }
    return null;
  }

}
