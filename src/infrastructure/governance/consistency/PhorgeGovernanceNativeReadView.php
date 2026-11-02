<?php

/** Actual one-connection read-view observation, no production executor. */
final class PhorgeGovernanceNativeReadView extends Phobject {

  private $reader;
  private $context;
  private $pool;
  private $before;
  private $binding;
  private $capabilities;
  private $routing;
  private $policyConfig;
  private $fixtureConfig;
  private $runtimeProfile;
  private $closed = false;
  private $cleanupSucceeded = true;
  private $environment;
  private $reportMode;

  private function __construct() {}

  private function __clone() {}

  public function __serialize() {
    throw new RuntimeException('native_owned_scope_serialization_refused');
  }

  public function __unserialize(array $data) {
    throw new RuntimeException('native_owned_scope_serialization_refused');
  }

  public function __debugInfo() {
    return array('scope' => 'isolated-owned-read-only', 'closed' => $this->closed);
  }

  private static function contract($method, array $arguments = array()) {
    return (new ReflectionMethod(
      PhorgeGovernanceNativePolicyCompositionVerifier::class, $method))
      ->invokeArgs(null, $arguments);
  }

  private static function result($reason) {
    return array('status' => 'blocked', 'reason' => $reason,
      'same_physical_connection' => false,
      'same_consistent_read_view' => false,
      'native_closure_complete' => false, 'risk_complete' => false,
      'can_execute' => false, 'execution_atomicity_proved' => false,
      'execution_freshness_proved' => false);
  }

  public static function begin(array $binding, array $capabilities, array $config) {
    if (!PhorgeGovernanceNativePolicyCompositionVerifier::validCapabilities(
      $capabilities)) {
      throw new RuntimeException('native_read_view_capabilities_refused');
    }
    $scope = new self();
    $scope->reportMode = (new mysqli_driver())->report_mode;
    try {
      $scope->runtimeProfile = PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
      $config = PhorgeGovernanceNativeSourceReader::validateConfig($config);
      $scope->installReaderEnvironment($config);
      foreach (array('project.history-state' => 'off',
        'project.workspace-mode' => 'native', 'project.history-task-drafts' => false,
        'gorge.search.source-scan' => false,
        'gorge.search.projection-shadow' => false) as $key => $expected) {
        if (PhabricatorEnv::getEnvConfig($key) !== $expected) {
          throw new RuntimeException('native_read_view_optional_feature_refused');
        }
      }
      $object = self::contract('target', array($binding));
      $scope->context = PhorgeGovernanceNativeCapabilityContext::newIsolated($object);
      $scope->routing = self::contract('routingDigest');
      $scope->policyConfig = self::contract('effectivePolicyDigest');
      $scope->fixtureConfig = self::fixtureDictionaryDigest();
      $scope->reader = PhorgeGovernanceNativeSourceReader::newFixture($config);
      $scope->reader->beginCapture($binding);
      $scope->before = $scope->reader->sampleOpenReadOnlySnapshot();
      $scope->binding = $scope->before->getSnapshot()->getBinding();
      $scope->capabilities = array();
      foreach ($capabilities as $capability) {
        $scope->capabilities[] = $capability;
      }
      // Exact reviewed private field: no public raw-connection getter exists.
      $physical = (new ReflectionProperty(
        PhorgeGovernanceNativeSourceReader::class, 'connection'))
        ->getValue($scope->reader);
      $scope->pool = array();
      foreach (array('user', 'project', 'maniphest', 'phriction', 'policy',
        'spaces', 'config', 'governance') as $app) {
        $database = $config['namespace'].'_'.$app;
        $class = new ReflectionClass(PhorgeGovernanceSnapshotConnection::class);
        $bridge = $class->newInstanceWithoutConstructor();
        $started = (new ReflectionProperty(
          PhorgeGovernanceNativeSourceReader::class, 'started'))
          ->getValue($scope->reader);
        $class->getConstructor()->invoke($bridge, $physical, $database,
          $started + $config['seconds'] * 1000000000);
        foreach (array('r', 'w') as $mode) {
          $scope->pool[$database.':'.$mode] = $bridge;
        }
      }
      (new ReflectionProperty(LiskDAO::class, 'connections'))
        ->setValue(null, $scope->pool);
      return $scope;
    } catch (Throwable $error) {
      $scope->close();
      throw new RuntimeException('native_read_view_begin_refused');
    }
  }

  public static function observe(array $binding, array $capabilities, array $config) {
    try {
      return self::begin($binding, $capabilities, $config)->finish();
    } catch (Throwable $error) {
      return self::result('native_read_view_prerequisite_unproved');
    }
  }

  private function installReaderEnvironment(array $config) {
    // Prove factory scope/grants before acquiring any mutable context ownership.
    $proof = PhorgeGovernanceNativeSourceReader::newFixture($config);
    $proof = null;
    if ((new ReflectionProperty(LiskDAO::class, 'connections'))->getValue() !== array()) {
      throw new RuntimeException('native_read_view_inherited_pool_refused');
    }
    $stack = PhabricatorEnv::getConfigSourceStack();
    if (!is_object($stack) || get_class($stack) !== PhabricatorConfigStackSource::class) {
      throw new RuntimeException('native_read_view_initial_stack_refused');
    }
    $sources = $stack->getStack();
    if (count($sources) !== 2 ||
        get_class($sources[0]) !== PhabricatorConfigDictionarySource::class ||
        get_class($sources[1]) !== PhabricatorConfigDefaultSource::class) {
      throw new RuntimeException('native_read_view_dynamic_stack_refused');
    }
    $values = $sources[0]->getAllKeys();
    $values['mysql.host'] = $config['host'];
    $values['mysql.port'] = $config['port'];
    $values['mysql.user'] = $config['user'];
    $values['mysql.pass'] = $config['password'];
    $values['storage.default-namespace'] = $config['namespace'];
    // Alternate cluster/Gorge routes are refused, never silently removed.
    if (PhabricatorEnv::getEnvConfig('cluster.databases') !== array() ||
        !in_array(PhabricatorEnv::getEnvConfig('gorge.db.uri'), array(null, ''), true)) {
      throw new RuntimeException('native_read_view_alternate_route_refused');
    }
    $this->environment = (new ReflectionClass(PhabricatorEnv::class))->getStaticProperties();
    $owned = new PhabricatorConfigStackSource();
    $owned->pushSource(new PhabricatorConfigDefaultSource());
    $owned->pushSource(new PhabricatorConfigDictionarySource($values));
    (new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))->setValue(null, $owned);
    (new ReflectionProperty(PhabricatorEnv::class, 'cache'))->setValue(null, array());
  }

  private static function fixtureDictionaryDigest() {
    $sources = PhabricatorEnv::getConfigSourceStack()->getStack();
    $values = $sources[0]->getAllKeys();
    $budget = array('nodes' => 0, 'bytes' => 0);
    $method = new ReflectionMethod(
      PhorgeGovernanceNativePolicyCompositionVerifier::class, 'copyConfigValue');
    $arguments = array($values, 0, &$budget);
    $copy = $method->invokeArgs(null, $arguments);
    // Private only, freezes optional/product-cut settings as well as routing.
    return hash('sha256', serialize($copy));
  }

  private function isCurrent() {
    return !$this->closed &&
      PhorgeGovernanceNativeRuntimeProfile::assertCurrent() === $this->runtimeProfile &&
      $this->context->isCurrent() &&
      (new ReflectionProperty(LiskDAO::class, 'connections'))->getValue() ===
        $this->pool &&
      hash_equals($this->routing, self::contract('routingDigest')) &&
      hash_equals($this->policyConfig, self::contract('effectivePolicyDigest')) &&
      hash_equals($this->fixtureConfig, self::fixtureDictionaryDigest());
  }

  public function finish() {
    if ($this->closed) {
      return self::result('native_read_view_already_closed');
    }
    $result = self::result('native_read_view_prerequisite_unproved');
    try {
      $result = $this->observeOpen();
    } catch (Throwable $error) {
      $result = self::result('native_read_view_prerequisite_unproved');
    } finally {
      if (!$this->close()) {
        $result = self::result('native_read_view_cleanup_failed');
      }
    }
    return $result;
  }

  private function observeOpen() {
    if (!$this->isCurrent()) {
      return self::result('native_read_view_context_changed');
    }
    $rejection = self::contract('captureRejection', array($this->before));
    if ($rejection !== null) {
      return self::result($rejection);
    }
    $viewer = (new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($this->binding['actor']))->setLimit(1)->executeOne();
    if (!$viewer || !$viewer->getIsApproved() || $viewer->getIsDisabled() ||
        $viewer->getIsSystemAgent() || $viewer->getIsMailingList()) {
      return self::result('native_read_view_actor_unproved');
    }
    $query = $this->newTargetQuery(PhabricatorUser::getOmnipotentUser());
    $object = $query->executeOne();
    if (!$object) {
      return self::result('native_read_view_target_unproved');
    }
    // Real consumer query can enforce ancestor constraints absent from a
    // target's local PolicyFilter (notably Wiki). Keep these facts distinct.
    try {
      $actor_query_visibility = $this->newTargetQuery($viewer)->executeOne() !== null;
    } catch (PhabricatorPolicyException $denied) {
      // Native Query consumers may throw their canonical policy denial rather
      // than returning an empty page (for example a private Wiki ancestor).
      if (get_class($denied) !== PhabricatorPolicyException::class) {
        throw $denied;
      }
      $actor_query_visibility = false;
    }
    $observations = array();
    foreach ($this->capabilities as $capability) {
      if (!in_array($capability, $object->getCapabilities(), true)) {
        return self::result('native_read_view_capability_unproved');
      }
      $allowed = PhabricatorPolicyFilter::hasCapability($viewer, $object, $capability);
      $automatic = $object->hasAutomaticCapability($capability, $viewer);
      if (!is_bool($allowed) || !is_bool($automatic)) {
        return self::result('native_read_view_result_unproved');
      }
      $observations[$capability] = array('policy_filter' => $allowed ? 'allow' : 'deny',
        'automatic_capability' => $automatic);
    }
    $after = $this->reader->sampleOpenReadOnlySnapshot();
    $before_summary = $this->before->getSummary();
    $after_summary = $after->getSummary();
    if (!$this->isCurrent() || !hash_equals($before_summary['snapshot_fingerprint'],
        $after_summary['snapshot_fingerprint'])) {
      return self::result('native_read_view_scope_or_sources_changed');
    }
    $query_count = 0;
    $seen = array();
    foreach ($this->pool as $bridge) {
      $id = spl_object_id($bridge);
      if (isset($seen[$id])) {
        continue;
      }
      $seen[$id] = true;
      $observation = $bridge->getObservation();
      if (!$observation['same_physical_connection']) {
        return self::result('native_read_view_physical_connection_changed');
      }
      $query_count += $observation['query_count'];
    }
    if (!$query_count) {
      return self::result('native_read_view_no_native_query_evidence');
    }
    $result = self::result(null);
    $result['status'] = 'observed-native-single-read-view';
    $result['same_physical_connection'] = true;
    $result['same_consistent_read_view'] = true;
    $result['native_query_count'] = $query_count;
    $result['observations'] = $observations;
    $result['actor_query_visibility'] = $actor_query_visibility ? 'allow' : 'deny';
    $result['source_digest'] = $before_summary['snapshot_fingerprint'];
    $result['remaining_unknown_reasons'] = $before_summary['unknown_reasons'];
    $result['runtime_profile'] = $this->runtimeProfile;
    $result['boundary'] = 'read-view-consistency-only-not-execution-freshness';
    return $result;
  }

  private function newTargetQuery(PhabricatorUser $viewer) {
    $queries = array(PhabricatorProject::class => PhabricatorProjectQuery::class,
      ManiphestTask::class => ManiphestTaskQuery::class,
      PhrictionDocument::class => PhrictionDocumentQuery::class);
    $class = $queries[$this->binding['object_class']];
    $query = (new $class())->setViewer($viewer)
      ->withPHIDs(array($this->binding['target']))->setLimit(1);
    if ($class === PhabricatorProjectQuery::class) {
      $query->needMembers(true)->needAncestorMembers(true);
    }
    return $query;
  }

  private static function foreignPool(array $current, array $owned) {
    $identities = array();
    foreach ($owned as $connection) {
      $identities[spl_object_id($connection)] = true;
    }
    $foreign = array();
    foreach ($current as $key => $connection) {
      if (!is_object($connection) ||
          !isset($identities[spl_object_id($connection)])) {
        $foreign[$key] = $connection;
      }
    }
    return $foreign;
  }

  public function close() {
    if ($this->closed) {
      return $this->cleanupSucceeded;
    }
    $this->closed = true;
    $clean = true;
    if ($this->context !== null) {
      $property = new ReflectionProperty(LiskDAO::class, 'connections');
      $owned = is_array($this->pool) ? $this->pool : array();
      $foreign = self::foreignPool($property->getValue(), $owned);
      try {
        // The legacy context closes its visible pool. Restrict that view to
        // our original bridge identities, including removed/replaced entries.
        $property->setValue(null, $owned);
        $clean = $this->context->close();
      } catch (Throwable $error) {
        $clean = false;
      } finally {
        // Later foreign entries remain caller-owned, even at an owned key.
        $property->setValue(null, $foreign);
        $this->context = null;
      }
    }
    if ($this->reader !== null) {
      try {
        $this->reader->finishCapture();
      } catch (Throwable $error) {
        $clean = false;
      }
      $this->reader = null;
    }
    if ($this->environment !== null) {
      foreach ($this->environment as $key => $value) {
        (new ReflectionProperty(PhabricatorEnv::class, $key))->setValue(null, $value);
      }
      $this->environment = null;
    }
    if ($this->reportMode !== null) {
      mysqli_report($this->reportMode);
    }
    $this->cleanupSucceeded = $clean;
    return $clean;
  }

  public function __destruct() { $this->close(); }
}
