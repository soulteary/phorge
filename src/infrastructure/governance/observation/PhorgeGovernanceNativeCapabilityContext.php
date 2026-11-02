<?php

/**
 * Test-only scope for actual PolicyFilter SQL. No caller boolean is a proof:
 * the effective fixture routing, real grants and owned marker are verified.
 */
final class PhorgeGovernanceNativeCapabilityContext extends Phobject {

  private $routing;
  private $stack;
  private $oldCache;
  private $oldReportMode;
  private $closed = false;
  private $object;
  private $ownedCache;

  private function __construct(
    $object, $stack, array $routing, $cache, $owned_cache, $report_mode) {
    $this->object = $object;
    $this->ownedCache = $owned_cache;
    $this->stack = $stack;
    $this->routing = $routing;
    $this->oldCache = $cache;
    $this->oldReportMode = $report_mode;
  }

  public static function newIsolated(PhabricatorLiskDAO $object) {
    require_once dirname(__DIR__, 4).'/support/runtime/php-version.php';
    phorge_assert_supported_php_version(PHP_VERSION);
    if (!self::hasIsolatedScope()) {
      throw new RuntimeException('native_capability_scope_refused');
    }
    // Refuse inherited pools before taking any cleanup ownership.
    $connections = new ReflectionProperty(LiskDAO::class, 'connections');
    $namespaces = new ReflectionProperty(
      PhabricatorLiskDAO::class, 'namespaceStack');
    $forced = new ReflectionProperty(
      PhabricatorLiskDAO::class, 'forcedNamespace');
    $forced_connection = new ReflectionProperty(LiskDAO::class, 'forcedConnection');
    if ($connections->getValue() !== array() ||
        $namespaces->getValue() !== array() ||
        $forced->getValue($object) !== null ||
        $forced_connection->getValue($object) !== null ||
        LiskDAO::shouldIsolateAllLiskEffectsToCurrentProcess() ||
        LiskDAO::shouldIsolateAllLiskEffectsToTransactions()) {
      throw new RuntimeException('native_capability_inherited_context_refused');
    }
    $stack = PhabricatorEnv::getConfigSourceStack();
    $routing = self::readRouting($stack);
    $config = PhorgeGovernanceNativeSourceReader::validateConfig(array(
      'host' => $routing['mysql.host'],
      'port' => $routing['mysql.port'],
      'namespace' => $routing['storage.default-namespace'],
      'user' => $routing['mysql.user'],
      'password' => $routing['mysql.pass'],
    ));
    $report_mode = (new mysqli_driver())->report_mode;
    $cache_property = new ReflectionProperty(
      PhabricatorCaches::class, 'requestCache');
    $cache_owned = false;
    $old_cache = null;
    $proof = null;
    try {
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      // The factory checks actual SELECT-only grants and the current native
      // quickstart marker on the owned fixture namespace. It is not a stub.
      $proof = PhorgeGovernanceNativeSourceReader::newFixture($config);
      $proof = null;
      if (self::readRouting($stack) !== $routing ||
          PhabricatorEnv::getConfigSourceStack() !== $stack) {
        throw new RuntimeException('native_capability_routing_changed');
      }
      $old_cache = $cache_property->getValue();
      // Isolate all DatabaseRef and Space caches, not only cached host labels.
      $cache_property->setValue(null, null);
      $cache_owned = true;
      $owned_cache = PhabricatorCaches::getRequestCache();
      return new self(
        $object, $stack, $routing, $old_cache, $owned_cache, $report_mode);
    } catch (Throwable $error) {
      $proof = null;
      if ($cache_owned) {
        $cache_property->setValue(null, $old_cache);
      }
      mysqli_report($report_mode);
      // No previous exception: underlying messages may contain credentials.
      throw new RuntimeException('native_capability_fixture_unverified');
    }
  }

  private static function hasIsolatedScope() {
    if (PHP_SAPI !== 'cli' ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      return false;
    }
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
      'PHORGE_CONTROL_PLANE') as $selector) {
      $value = getenv($selector);
      if ($value !== false && $value !== '') {
        return false;
      }
    }
    foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $file) {
      if (file_exists(dirname(__DIR__, 4).'/conf/local/'.$file)) {
        return false;
      }
    }
    return true;
  }

  private static function readRouting($stack) {
    if (!is_object($stack) ||
        get_class($stack) !== PhabricatorConfigStackSource::class) {
      throw new RuntimeException('native_capability_stack_refused');
    }
    $classes = array();
    foreach ($stack->getStack() as $source) {
      $classes[] = get_class($source);
    }
    if ($classes !== array(PhabricatorConfigDictionarySource::class,
        PhabricatorConfigDefaultSource::class)) {
      throw new RuntimeException('native_capability_dynamic_sources_refused');
    }
    $keys = array('mysql.host', 'mysql.port', 'mysql.user', 'mysql.pass',
      'storage.default-namespace', 'cluster.databases', 'gorge.db.uri');
    $from_stack = $stack->getKeys($keys);
    $result = array();
    foreach ($keys as $key) {
      if (!array_key_exists($key, $from_stack)) {
        throw new RuntimeException('native_capability_routing_missing');
      }
      $effective = PhabricatorEnv::getEnvConfig($key);
      if ($effective !== $from_stack[$key]) {
        throw new RuntimeException('native_capability_stale_config_cache');
      }
      $result[$key] = $effective;
    }
    if ($result['cluster.databases'] !== array() ||
        !in_array($result['gorge.db.uri'], array(null, ''), true)) {
      throw new RuntimeException('native_capability_alternate_routing_refused');
    }
    return $result;
  }

  public function isCurrent() {
    if ($this->closed) {
      return false;
    }
    try {
      return self::hasIsolatedScope() &&
        !LiskDAO::shouldIsolateAllLiskEffectsToCurrentProcess() &&
        !LiskDAO::shouldIsolateAllLiskEffectsToTransactions() &&
        (new ReflectionProperty(PhabricatorLiskDAO::class, 'namespaceStack'))
          ->getValue() === array() &&
        (new ReflectionProperty(PhabricatorLiskDAO::class, 'forcedNamespace'))
          ->getValue($this->object) === null &&
        (new ReflectionProperty(LiskDAO::class, 'forcedConnection'))
          ->getValue($this->object) === null &&
        (new ReflectionProperty(PhabricatorCaches::class, 'requestCache'))
          ->getValue() === $this->ownedCache &&
        PhabricatorEnv::getConfigSourceStack() === $this->stack &&
        self::readRouting($this->stack) === $this->routing;
    } catch (Throwable $error) {
      return false;
    }
  }

  public function close() {
    if ($this->closed) {
      return true;
    }
    $clean = true;
    try {
      // An empty initial pool was proved before ownership was acquired.
      LiskDAO::closeAllConnections();
    } catch (Throwable $error) {
      $clean = false;
    } finally {
      (new ReflectionProperty(PhabricatorCaches::class, 'requestCache'))
        ->setValue(null, $this->oldCache);
      mysqli_report($this->oldReportMode);
      $this->closed = true;
    }
    return $clean;
  }

  public function __destruct() {
    $this->close();
  }

}
