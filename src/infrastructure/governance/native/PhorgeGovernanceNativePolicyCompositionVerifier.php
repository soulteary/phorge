<?php

/** Two source samples around an actual native filter, never an atomic grant. */
final class PhorgeGovernanceNativePolicyCompositionVerifier extends Phobject {

  private $binding;
  private $capabilities;
  private $before;
  private $finished = false;

  private function __construct(array $binding, array $capabilities, $before) {
    // Detach caller references; the source capture has validated scalar binding.
    $this->binding = json_decode(json_encode($binding, JSON_THROW_ON_ERROR), true);
    $this->capabilities = array();
    foreach ($capabilities as $capability) {
      $this->capabilities[] = $capability;
    }
    $this->before = $before;
  }

  public static function validCapabilities(array $capabilities) {
    if (!array_is_list($capabilities) || !$capabilities ||
        count($capabilities) > 3) {
      return false;
    }
    foreach ($capabilities as $capability) {
      if (!is_string($capability) || !in_array($capability, array(
        PhabricatorPolicyCapability::CAN_VIEW,
        PhabricatorPolicyCapability::CAN_EDIT,
        PhabricatorPolicyCapability::CAN_JOIN), true)) {
        return false;
      }
    }
    return count(array_unique($capabilities)) === count($capabilities);
  }

  private static function result($reason) {
    return array('status' => 'blocked', 'reason' => $reason,
      'native_closure_complete' => false, 'risk_complete' => false,
      'can_execute' => false, 'execution_atomicity_proved' => false,
      'source_and_native_observation_atomic' => false,
      'source_consistency' => 'unproved-blocked');
  }

  private static function target(array $binding) {
    $types = array(PhabricatorProject::class => 'PROJ',
      ManiphestTask::class => 'TASK', PhrictionDocument::class => 'WIKI');
    $class = $binding['object_class'] ?? null;
    if (!is_string($class) || !isset($types[$class]) ||
        !is_string($binding['target'] ?? null) ||
        !preg_match('/\APHID-'.$types[$class].'-[a-zA-Z0-9]{20}\z/',
          $binding['target'])) {
      throw new InvalidArgumentException('native_composition_binding_refused');
    }
    return new $class();
  }

  private static function sample(array $binding) {
    $object = self::target($binding);
    $context = PhorgeGovernanceNativeCapabilityContext::newIsolated($object);
    try {
      $reader = PhorgeGovernanceNativeSourceReader::newFixture(array(
        'host' => PhabricatorEnv::getEnvConfig('mysql.host'),
        'port' => PhabricatorEnv::getEnvConfig('mysql.port'),
        'user' => PhabricatorEnv::getEnvConfig('mysql.user'),
        'password' => PhabricatorEnv::getEnvConfig('mysql.pass'),
        'namespace' => PhabricatorEnv::getEnvConfig('storage.default-namespace'),
      ));
      $capture = $reader->capture($binding);
      $reader = null;
      if (!$context->isCurrent()) {
        throw new RuntimeException('native_composition_scope_changed');
      }
    } finally {
      if (!$context->close()) {
        throw new RuntimeException('native_composition_cleanup_failed');
      }
    }
    return array('capture' => $capture,
      'routing_digest' => self::routingDigest(),
      'effective_policy_digest' => self::effectivePolicyDigest());
  }

  private static function routingDigest() {
    $keys = array('mysql.host', 'mysql.port', 'mysql.user', 'mysql.pass',
      'storage.default-namespace', 'cluster.databases', 'gorge.db.uri');
    $values = array();
    foreach ($keys as $key) {
      $values[$key] = PhabricatorEnv::getEnvConfig($key);
    }
    // Private comparison only, never a public credential or routing digest.
    return hash('sha256', serialize($values));
  }

  public static function getEffectivePolicyKeys() {
    return array('phabricator.application-settings', 'policy.allow-public',
      'policy.locked', 'phabricator.show-prototypes',
      'phabricator.uninstalled-applications', 'phabricator.serious-business');
  }

  private static function effectivePolicyDigest() {
    $values = array();
    $budget = array('nodes' => 0, 'bytes' => 0);
    foreach (self::getEffectivePolicyKeys() as $key) {
      $values[$key] = self::copyConfigValue(
        PhabricatorEnv::getEnvConfig($key), 0, $budget);
    }
    // Fixed effective policy/app settings only; not a full runtime closure.
    // Never include this private digest or its original values in receipts.
    return hash('sha256', serialize($values));
  }

  private static function copyConfigValue($value, $depth, &$budget) {
    if (++$budget['nodes'] > 2048 || $depth > 12) {
      throw new RuntimeException('native_composition_config_budget');
    }
    if (is_array($value)) {
      $copy = array();
      foreach ($value as $key => $item) {
        if (is_string($key) && strlen($key) > 256) {
          throw new RuntimeException('native_composition_config_key');
        }
        $budget['bytes'] += is_string($key) ? strlen($key) : 8;
        if ($budget['bytes'] > 262144) {
          throw new RuntimeException('native_composition_config_budget');
        }
        $copy[$key] = self::copyConfigValue($item, $depth + 1, $budget);
      }
      return $copy;
    }
    if ($value === null || is_bool($value) || is_int($value) ||
        (is_string($value) && strlen($value) <= 65536)) {
      $budget['bytes'] += is_string($value) ? strlen($value) : 8;
      if ($budget['bytes'] > 262144) {
        throw new RuntimeException('native_composition_config_budget');
      }
      return $value;
    }
    throw new RuntimeException('native_composition_config_type');
  }

  public static function begin(array $binding, array $capabilities) {
    if (!self::validCapabilities($capabilities)) {
      throw new InvalidArgumentException('native_composition_capabilities_refused');
    }
    return new self($binding, $capabilities, self::sample($binding));
  }

  public static function observe(array $binding, array $capabilities) {
    try {
      return self::begin($binding, $capabilities)->finish();
    } catch (Throwable $error) {
      return self::result('native_composition_prerequisite_unproved');
    }
  }

  public function finish() {
    if ($this->finished) {
      return self::result('native_composition_already_finished');
    }
    $this->finished = true;
    try {
      $rejection = self::captureRejection($this->before['capture']);
      if ($rejection !== null) {
        return self::result($rejection);
      }
      $native_unknown = false;
      try {
        $observations = $this->observeNative();
      } catch (Throwable $error) {
        $native_unknown = true;
      }
      $after = self::sample($this->binding);
      $before = $this->before['capture']->getSummary();
      $after_summary = $after['capture']->getSummary();
      if (!hash_equals($this->before['routing_digest'], $after['routing_digest']) ||
          !hash_equals($this->before['effective_policy_digest'],
            $after['effective_policy_digest']) ||
          !hash_equals($before['snapshot_fingerprint'],
          $after_summary['snapshot_fingerprint'])) {
        $result = self::result('native_sources_changed_between_samples');
        $result['source_consistency'] = 'changed-blocked';
        return $result;
      }
      $rejection = self::captureRejection($after['capture']);
      if ($rejection !== null) {
        return self::result($rejection);
      }
      if ($native_unknown) {
        return self::result('native_composition_prerequisite_unproved');
      }
      $result = self::result(null);
      $result['status'] = 'observed-native-policy-composition';
      $result['source_consistency'] = 'stable-sampled-not-atomic';
      $result['observations'] = $observations;
      $result['sample_digest'] = $before['snapshot_fingerprint'];
      $result['remaining_unknown_reasons'] = array_merge(
        $before['unknown_reasons'], array('source_samples_do_not_exclude_aba',
          'native_filter_read_views_not_bound_to_source_snapshot',
          'native_query_resource_budget_not_proved',
          'effective_policy_keys_sampled_not_full_runtime_closure'));
      return $result;
    } catch (Throwable $error) {
      return self::result('native_composition_prerequisite_unproved');
    }
  }

  private static function captureRejection(
    PhorgeGovernanceNativeSourceCapture $capture) {
    foreach ($capture->getSources() as $source) {
      $data = $source->getData();
      if (in_array($data['kind'], array('user.account', 'auth.session'), true) &&
          $data['eligibility'] !== 'eligible') {
        return 'native_composition_account_or_session_unproved';
      }
    }
    $summary = $capture->getSummary();
    foreach (array('missing_native_target', 'missing_project_parent',
      'cyclic_project_parent', 'missing_native_policy_reference',
      'malformed_native_policy_rules', 'unsupported_native_policy_rule',
      'malformed_native_policy_rule_subjects', 'missing_native_policy_rule_subject',
      'native_policy_rule_subject_budget') as $reason) {
      if (in_array($reason, $summary['unknown_reasons'], true)) {
        return $reason;
      }
    }
    return null;
  }

  private function observeNative() {
    $context = PhorgeGovernanceNativeCapabilityContext::newIsolated(
      self::target($this->binding));
    try {
      $viewer = (new PhabricatorPeopleQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($this->binding['actor']))->setLimit(1)->executeOne();
      if (!$viewer || !$viewer->getIsApproved() || $viewer->getIsDisabled() ||
          $viewer->getIsSystemAgent() || $viewer->getIsMailingList()) {
        throw new RuntimeException('native_composition_actor_unproved');
      }
      $queries = array(PhabricatorProject::class => PhabricatorProjectQuery::class,
        ManiphestTask::class => ManiphestTaskQuery::class,
        PhrictionDocument::class => PhrictionDocumentQuery::class);
      $class = $queries[$this->binding['object_class']];
      $query = (new $class())->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($this->binding['target']))->setLimit(1);
      if ($class === PhabricatorProjectQuery::class) {
        $query->needMembers(true)->needAncestorMembers(true);
      }
      $object = $query->executeOne();
      if (!$object) {
        throw new RuntimeException('native_composition_target_unproved');
      }
      $observations = array();
      foreach ($this->capabilities as $capability) {
        if (!in_array($capability, $object->getCapabilities(), true)) {
          throw new RuntimeException('native_composition_capability_unproved');
        }
        $allowed = PhabricatorPolicyFilter::hasCapability($viewer, $object,
          $capability);
        $automatic = $object->hasAutomaticCapability($capability, $viewer);
        if (!is_bool($allowed) || !is_bool($automatic)) {
          throw new RuntimeException('native_composition_result_unproved');
        }
        $observations[$capability] = array(
          'policy_filter' => $allowed ? 'allow' : 'deny',
          'automatic_capability' => $automatic);
      }
      if (!$context->isCurrent()) {
        throw new RuntimeException('native_composition_scope_changed');
      }
    } finally {
      if (!$context->close()) {
        throw new RuntimeException('native_composition_cleanup_failed');
      }
    }
    return $observations;
  }
}
