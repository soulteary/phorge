<?php

/** Isolated current account capture. No recovery permit and no source writes. */
final class PhorgeRecoveryNativeCaptureScope extends Phobject {
  private $connection;
  private $snapshot;
  private $context;
  private $target;
  private $facts;
  private $route;
  private $runtime;
  private $ownsTransaction = false;
  private $closed = false;
  private $started;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('recovery_scope_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('recovery_scope_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'isolated-account-capture-no-permit'); }

  private static function routing() {
    if (PHP_SAPI !== 'cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new RuntimeException('recovery_isolated_scope_required');
    }
    foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $key) {
      $value = getenv($key);
      if ($value !== false && $value !== '') { throw new RuntimeException('recovery_deployment_scope_refused'); }
    }
    $root = dirname(__DIR__, 4);
    foreach (array('/conf/local/local.json','/conf/local/deployment.json','/conf/local/ENVIRONMENT') as $path) {
      if (file_exists($root.$path)) { throw new RuntimeException('recovery_deployment_scope_refused'); }
    }
    if (LiskDAO::shouldIsolateAllLiskEffectsToCurrentProcess() ||
        LiskDAO::shouldIsolateAllLiskEffectsToTransactions() ||
        (new ReflectionProperty(PhabricatorLiskDAO::class, 'namespaceStack'))->getValue() !== array()) {
      throw new RuntimeException('recovery_native_effect_scope_unknown');
    }
    $keys = array('mysql.host','mysql.port','mysql.user','mysql.pass',
      'storage.default-namespace','cluster.databases','gorge.db.uri');
    $stack = (new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))->getValue();
    if (!$stack || get_class($stack) !== PhabricatorConfigStackSource::class) {
      throw new RuntimeException('recovery_config_scope_unknown');
    }
    $classes = array();
    foreach ($stack->getStack() as $source) { $classes[] = get_class($source); }
    if ($classes !== array(PhabricatorConfigDictionarySource::class, PhabricatorConfigDefaultSource::class)) {
      throw new RuntimeException('recovery_config_scope_unknown');
    }
    $configured = $stack->getKeys($keys); $values = array();
    foreach ($keys as $key) {
      $values[$key] = PhabricatorEnv::getEnvConfig($key);
      if (!array_key_exists($key, $configured) || $values[$key] !== $configured[$key]) {
        throw new RuntimeException('recovery_config_scope_unknown');
      }
    }
    if (!in_array($values['mysql.host'], array('127.0.0.1','::1','localhost'), true) ||
        !is_string($values['mysql.user']) || !preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/D', $values['mysql.user']) ||
        !is_string($values['storage.default-namespace']) ||
        !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D', $values['storage.default-namespace']) ||
        $values['cluster.databases'] !== array() || !in_array($values['gorge.db.uri'], array('',null), true) ||
        PhabricatorEnv::isReadOnly() !== false) {
      throw new RuntimeException('recovery_fixture_routing_required');
    }
    return $values;
  }

  public static function open(PhorgeGovernanceNativeActorContext $context, $target) {
    if (get_class($context) !== PhorgeGovernanceNativeActorContext::class ||
        !is_string($target) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D', $target)) {
      throw new RuntimeException('recovery_actor_or_target_required');
    }
    $scope = new self(); $scope->route = self::routing();
    $scope->runtime = PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    $scope->context = $context; $scope->target = $target;
    $user = new PhabricatorUser();
    if ((new ReflectionProperty(LiskDAO::class, 'forcedConnection'))->getValue($user) !== null) {
      throw new RuntimeException('recovery_native_writer_unknown');
    }
    $conn = $user->establishConnection('w');
    if (get_class($conn) !== AphrontMySQLiDatabaseConnection::class || $conn->isInsideTransaction() ||
        $conn->isReadLocking() || $conn->isWriteLocking()) {
      throw new RuntimeException('recovery_outer_transaction_refused');
    }
    // SET TRANSACTION (not SET SESSION) is rejected by MySQL when a
    // physical transaction is already active, including an untracked raw START.
    $default = queryfx_one($conn, 'SELECT @@SESSION.transaction_isolation level');
    $restore = array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if (!$default || !isset($restore[$default['level']])) {
      throw new RuntimeException('recovery_isolation_unknown');
    }
    $queued = false;
    $scope->connection = $conn;
    try {
      queryfx($conn, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
      $queued = true;
      $conn->openTransaction(); $scope->ownsTransaction = true; $queued = false;
      $database = queryfx_one($conn, 'SELECT DATABASE() db,@@server_uuid uuid,UNIX_TIMESTAMP() n');
      if (!$database || $database['db'] !== $scope->route['storage.default-namespace'].'_user' ||
          !is_string($database['uuid']) || $database['uuid'] === '') {
        throw new RuntimeException('recovery_native_writer_unknown');
      }
      $scope->started = (int)$database['n'];
      $scope->assertMarker();
      $ids = array_unique(array($context->getUserPHID(), $target)); sort($ids, SORT_STRING);
      foreach ($ids as $id) {
        $scope->preflight($id);
        $identity = PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($id, $conn);
        if ($id === $context->getUserPHID() && $identity['accountEpoch'] === null) {
          // lockCurrent initializes an absent issuer epoch. Never invoke that
          // write-capable branch from this strictly non-writing capture.
          throw new RuntimeException('recovery_actor_epoch_uninitialized');
        }
      }
      $scope->snapshot = PhorgeGovernanceNativeIdentitySource::lockCurrent($context, $conn);
      if (!$scope->snapshot->isSystemAdmin()) { throw new RuntimeException('recovery_current_admin_required'); }
      $scope->facts = $scope->readAccount();
      $scope->assertCurrent();
      return $scope;
    } catch (Throwable $error) {
      try { $scope->close(); } catch (Throwable $cleanup) {
        throw new RuntimeException('recovery_capture_cleanup_failed', 0, $cleanup);
      } finally {
        if ($queued) { queryfx($conn, $restore[$default['level']]); }
      }
      throw $error;
    }
  }

  private function assertMarker() {
    $namespace = $this->route['storage.default-namespace'];
    $rows = queryfx_all($this->connection,
      'SELECT marker,quickstart_sha256 FROM %T.fixture_guard WHERE id=1 LOCK IN SHARE MODE', $namespace.'_governance');
    if (count($rows) !== 1 || $rows[0]['marker'] !== 'N07.NATIVE-SOURCE.v1:synthetic-only' ||
        $rows[0]['quickstart_sha256'] !== hash_file('sha256', dirname(__DIR__,4).'/resources/sql/quickstart.sql')) {
      throw new RuntimeException('recovery_fixture_lineage_unknown');
    }
  }

  private function preflight($phid) {
    // Bound selected variable-width columns on the server before transferring
    // security fields. Real native identity reads then acquire current locks.
    $rows = queryfx_all($this->connection,
      'SELECT phid,OCTET_LENGTH(accountSecret) a,OCTET_LENGTH(conduitCertificate) c FROM %R WHERE BINARY phid=BINARY %s LIMIT 2 LOCK IN SHARE MODE',
      new PhabricatorUser(), $phid);
    $row = count($rows) === 1 ? head($rows) : null;
    if (!$row || $row['phid'] !== $phid || (int)$row['a'] > 4096 || (int)$row['c'] > 4096) {
      throw new RuntimeException('recovery_account_budget_unknown');
    }
  }

  private function readAccount() {
    $this->preflight($this->target);
    $row = queryfx_one($this->connection,
      'SELECT phid,isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList,accountSecret,conduitCertificate FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',
      new PhabricatorUser(), $this->target);
    if (!$row || $row['phid'] !== $this->target) { throw new RuntimeException('recovery_account_missing'); }
    $generation = queryfx_one($this->connection,
      'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
      new PhabricatorUserTransaction(), $this->target);
    $epoch = queryfx_one($this->connection,
      'SELECT userPHID,epoch FROM %R WHERE BINARY userPHID=BINARY %s LOCK IN SHARE MODE',
      new PhorgeGovernanceIdentityEpoch(), $this->target);
    if ($epoch && $epoch['userPHID'] !== $this->target) { throw new RuntimeException('recovery_generation_unknown'); }
    return PhorgeRecoveryNativeAccountFacts::decode($row, $generation ? $generation['id'] : '0',
      $epoch ? $epoch['epoch'] : null);
  }

  private function assertCurrent() {
    if ($this->closed || !$this->ownsTransaction || !$this->connection->isInsideTransaction() ||
        self::routing() !== $this->route ||
        PhorgeGovernanceNativeRuntimeProfile::assertCurrent() !== $this->runtime ||
        $this->connection !== (new PhabricatorUser())->establishConnection('w')) {
      throw new RuntimeException('recovery_capture_context_changed');
    }
    $this->assertMarker();
    PhorgeGovernanceNativeIdentitySource::revalidate($this->snapshot, $this->connection);
    $now = (int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n'];
    if ($now < $this->started || $now - $this->started > 10) {
      throw new RuntimeException('recovery_capture_deadline');
    }
  }

  public function finish() {
    try {
      $this->assertCurrent();
      if ($this->readAccount() !== $this->facts) { throw new RuntimeException('recovery_account_source_changed'); }
      // Locks prevent source writes, not credential expiry while reads wait.
      $this->assertCurrent();
      $families = array_fill_keys(PhorgeGovernanceRecoverySourceInventory::requiredFamilies(), 'uncovered');
      return array('schema_version'=>1, 'capture'=>'actual-locked-native-account',
        'account_facts'=>$this->facts, 'account_adapter'=>'captured-partial',
        'families'=>$families, 'all_sources_complete'=>false, 'can_execute'=>false,
        'future_execution_atomic'=>false, 'source_writes'=>false);
    } finally { $this->close(); }
  }

  public function close() {
    if ($this->closed) { return; }
    $this->closed = true;
    if ($this->ownsTransaction) {
      $this->ownsTransaction = false;
      // Always end our observation by rollback. No effects were executed and
      // no rollback claim is made about hypothetical native external effects.
      $this->connection->killTransaction();
    }
  }
  public function __destruct() {
    try { $this->close(); } catch (Throwable $ignored) {}
  }
}
