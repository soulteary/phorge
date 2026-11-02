<?php

/** Real transactional writes exclusively in an owned synthetic organization DB. */
final class PhorgeGovernanceOrganizationStore extends Phobject {

  private $connection;
  private $database;
  private $ownerNonce;
  private $transactionNonce;
  private $closed = false;
  private $cleanupFailed = false;
  private $oldReportMode;

  private function __construct(mysqli $connection, array $config, $mode) {
    $this->connection = $connection;
    $this->database = $config['dbname'];
    $this->ownerNonce = $config['owner_nonce'];
    $this->oldReportMode = $mode;
  }
  private function __clone() {}
  public function __debugInfo() { return array('scope' => 'isolated-organization-writer'); }
  public function __serialize() { throw new RuntimeException('organization_serialize_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('organization_serialize_refused'); }

  public static function assertFixtureScope() {
    require_once dirname(__DIR__, 4).'/support/runtime/php-version.php';
    phorge_assert_supported_php_version(PHP_VERSION);
    if (PHP_SAPI !== 'cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new RuntimeException('organization_isolated_scope_required');
    }
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG', 'PHORGE_CONTROL_PLANE') as $name) {
      $value = getenv($name);
      if ($value !== false && $value !== '') {
        throw new RuntimeException('organization_deployment_refused');
      }
    }
    foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $file) {
      if (file_exists(dirname(__DIR__, 4).'/conf/local/'.$file)) {
        throw new RuntimeException('organization_deployment_refused');
      }
    }
    PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  }

  public static function validateFixtureConfig(array $config) {
    $keys = array_keys($config); sort($keys);
    if ($keys !== array('dbname', 'host', 'owner_nonce', 'password', 'port', 'user') ||
        $config['host'] !== '127.0.0.1' || !is_int($config['port']) ||
        $config['port'] < 1 || $config['port'] > 65535 ||
        !is_string($config['dbname']) ||
        !preg_match('/\Agovernance_org_[a-f0-9]{16}\z/', $config['dbname']) ||
        !is_string($config['owner_nonce']) ||
        !preg_match('/\A[a-f0-9]{32}\z/', $config['owner_nonce']) ||
        !is_string($config['user']) || $config['user'] === '' ||
        !is_string($config['password'])) {
      throw new InvalidArgumentException('organization_fixture_config_refused');
    }
    $copy = array(); foreach ($keys as $key) { $copy[$key] = $config[$key]; }
    return $copy;
  }

  public static function newFixture(array $config) {
    self::assertFixtureScope();
    $config = self::validateFixtureConfig($config);
    $mode = (new mysqli_driver())->report_mode;
    $connection = null;
    $store = null;
    try {
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      $connection = mysqli_init();
      $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
      $connection->options(MYSQLI_OPT_READ_TIMEOUT, 5);
      $connection->real_connect($config['host'], $config['user'], $config['password'],
        $config['dbname'], $config['port']);
      $connection->set_charset('utf8mb4');
      $store = new self($connection, $config, $mode);
      $store->assertGuard(); $store->assertGrants();
      $store->begin(); $store->finish(false);
      return $store;
    } catch (Throwable $error) {
      if ($store !== null) {
        try { $store->close(); } catch (Throwable $cleanup) {}
      } else if ($connection !== null) {
        try { $connection->close(); } catch (Throwable $cleanup) {}
      }
      mysqli_report($mode);
      throw new RuntimeException('organization_factory_refused');
    }
  }

  private function query($sql, array $values = array()) {
    $statement = $this->connection->prepare($sql);
    if ($values) {
      $types = str_repeat('s', count($values));
      $arguments = array($types);
      foreach ($values as &$value) { $arguments[] = &$value; }
      unset($value);
      $statement->bind_param(...$arguments);
    }
    $statement->execute();
    $result = $statement->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : array();
    $statement->close();
    return $rows;
  }

  private function assertGuard($locking = false) {
    $rows = $this->query('SELECT * FROM fixture_guard WHERE id=1'.($locking ? ' FOR UPDATE' : ''));
    $guard = $rows[0] ?? array();
    if (($guard['marker'] ?? null) !== 'N03.ORG-WRITE.v1:synthetic-only' ||
        (int)($guard['schema_version'] ?? 0) !== 1 ||
        ($guard['owner_nonce'] ?? null) !== $this->ownerNonce ||
        ($guard['schema_sha256'] ?? null) !== hash_file('sha256',
          dirname(__DIR__, 4).'/support/governance/organization/schema.sql')) {
      throw new RuntimeException('organization_guard_refused');
    }
  }

  private function assertGrants() {
    $allowed = array('fixture_guard' => array('SELECT', 'UPDATE(EPOCH_VERSION)'),
      'org_user' => array('SELECT'), 'org_unit' => array('SELECT', 'UPDATE(VERSION)'),
      'org_member' => array('SELECT', 'INSERT', 'UPDATE'),
      'org_appointment' => array('SELECT', 'INSERT', 'UPDATE'),
      'org_operation' => array('SELECT', 'INSERT'), 'org_audit' => array('SELECT', 'INSERT'),
      'org_approval' => array('SELECT', 'INSERT', 'UPDATE'));
    $seen = array();
    foreach ($this->connection->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM) as $row) {
      $grant = $row[0];
      if (str_contains($grant, 'WITH GRANT OPTION')) {
        throw new RuntimeException('organization_grants_refused');
      }
      if (preg_match('/\AGRANT USAGE ON \*\.\* TO /', $grant)) { continue; }
      if (!preg_match('/\AGRANT (.+) ON `([^`]+)`\.`([^`]+)` TO /', $grant, $match) ||
          str_replace('\\_', '_', $match[2]) !== $this->database ||
          !isset($allowed[$match[3]])) {
        throw new RuntimeException('organization_grants_refused');
      }
      foreach (preg_split('/,(?![^()]*\))/', $match[1]) as $privilege) {
        $privilege = strtoupper(preg_replace('/[\s`]+/', '', $privilege));
        if (!in_array($privilege, $allowed[$match[3]], true)) {
          throw new RuntimeException('organization_grants_refused');
        }
        $seen[$match[3]][$privilege] = true;
      }
    }
    foreach ($allowed as $table => $privileges) {
      foreach ($privileges as $privilege) {
        if (!isset($seen[$table][$privilege])) {
          throw new RuntimeException('organization_missing_grants');
        }
      }
    }
  }

  public static function getIdentifierColumns() {
    return array('fixture_guard' => array('schema_sha256', 'owner_nonce'),
      'org_user' => array('user_id'),
      'org_unit' => array('unit_id', 'kind', 'state', 'parent_id', 'department_id'),
      'org_member' => array('team_id', 'user_id', 'state'),
      'org_appointment' => array('team_id', 'user_id', 'role', 'state'),
      'org_operation' => array('operation_id', 'request_digest', 'actor_id'),
      'org_audit' => array('operation_id', 'actor_id', 'action', 'target_id'),
      'org_approval' => array('approval_id', 'operation_id', 'request_digest',
        'state', 'approver_id', 'approver_digest', 'consumed_by_operation'));
  }

  private function assertTransactionalTables() {
    foreach (array('fixture_guard', 'org_user', 'org_unit', 'org_member',
      'org_appointment', 'org_operation', 'org_audit', 'org_approval') as $table) {
      // Actual read takes an MDL through this transaction, preventing a later
      // engine switch between verification and writes/rollback.
      $this->query('SELECT COUNT(*) AS n FROM `'.$table.'`');
      $row = $this->query('SELECT ENGINE FROM information_schema.TABLES '.
        'WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array($this->database, $table))[0] ?? null;
      if ($row === null || $row['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException('organization_transactional_schema_required');
      }
      $columns = $this->query('SELECT COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME '.
        'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
        array($this->database, $table));
      $columns = array_column($columns, null, 'COLUMN_NAME');
      foreach (self::getIdentifierColumns()[$table] as $field) {
        if (!isset($columns[$field]) || $columns[$field]['CHARACTER_SET_NAME'] !== 'ascii' ||
            $columns[$field]['COLLATION_NAME'] !== 'ascii_bin') {
          throw new RuntimeException('organization_binary_identity_schema_required');
        }
      }
    }
  }

  private function begin() {
    self::assertFixtureScope();
    if ($this->closed || $this->transactionNonce !== null) {
      throw new RuntimeException('organization_transaction_scope_refused');
    }
    $this->assertGrants();
    $this->connection->autocommit(false);
    $this->connection->begin_transaction();
    $this->transactionNonce = bin2hex(random_bytes(16));
    $this->assertGuard(true);
    $this->assertTransactionalTables();
  }

  private function finish($commit) {
    try {
      if ($commit) { $this->connection->commit(); }
      else { $this->connection->rollback(); }
    } finally {
      $this->transactionNonce = null;
      $this->connection->autocommit(true);
    }
  }

  public static function eligible(array $user) {
    if (!isset($user['user_id'], $user['version']) ||
        !PhorgeGovernanceOrganizationRequest::userID($user['user_id'])) {
      return false;
    }
    foreach (array('is_admin', 'is_approved', 'is_disabled', 'is_system_agent',
      'is_mailing_list') as $field) {
      if (!isset($user[$field]) || !in_array($user[$field], array(0, 1, '0', '1'), true)) {
        return false;
      }
    }
    return self::rowVersion($user['version']) !== null &&
      (int)$user['is_approved'] === 1 && (int)$user['is_disabled'] === 0 &&
      (int)$user['is_system_agent'] === 0 && (int)$user['is_mailing_list'] === 0;
  }

  public static function rowVersion($value) {
    if (is_int($value)) { return $value > 0 && $value < PHP_INT_MAX ? $value : null; }
    if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/', $value) ||
        strlen($value) > strlen((string)(PHP_INT_MAX - 1)) ||
        (strlen($value) === strlen((string)(PHP_INT_MAX - 1)) &&
          strcmp($value, (string)(PHP_INT_MAX - 1)) > 0)) { return null; }
    return (int)$value;
  }

  private static function validateRelationship($row, $appointment = false) {
    if ($row === null) { return null; }
    if (!in_array($row['state'] ?? null, array('active', 'ended', 'suspended'), true) ||
        self::rowVersion($row['version'] ?? null) === null ||
        ($appointment && ($row['role'] ?? null) !== 'team_admin')) {
      throw new RuntimeException('organization_relationship_source_invalid');
    }
    return $row;
  }

  private function user($id, $require_eligible = true) {
    $rows = $this->query('SELECT * FROM org_user WHERE user_id=? FOR SHARE', array($id));
    if (!$rows || $rows[0]['user_id'] !== $id ||
        ($require_eligible && !self::eligible($rows[0]))) {
      throw new RuntimeException('organization_account_ineligible');
    }
    return $rows[0];
  }

  private function membership($team, $user) {
    $row = self::validateRelationship($this->query(
      'SELECT * FROM org_member WHERE team_id=? AND user_id=? FOR UPDATE',
      array($team, $user))[0] ?? null);
    if ($row !== null && ($row['team_id'] !== $team || $row['user_id'] !== $user)) {
      throw new RuntimeException('organization_relationship_source_invalid');
    }
    return $row;
  }

  private function appointment($team, $user) {
    $row = self::validateRelationship($this->query(
      'SELECT * FROM org_appointment WHERE team_id=? AND user_id=? FOR UPDATE',
      array($team, $user))[0] ?? null, true);
    if ($row !== null && ($row['team_id'] !== $team || $row['user_id'] !== $user)) {
      throw new RuntimeException('organization_relationship_source_invalid');
    }
    return $row;
  }

  private static function active($row, $now) {
    return $row !== null && $row['state'] === 'active' &&
      (int)$row['valid_from'] <= $now &&
      ($row['valid_until'] === null || (int)$row['valid_until'] > $now);
  }

  private function authorize($actor, $team, $sensitive, $now) {
    $user = $this->user($actor);
    $unit = $this->query('SELECT * FROM org_unit WHERE unit_id=? FOR UPDATE', array($team))[0] ?? null;
    if ($unit === null || $unit['unit_id'] !== $team || $unit['kind'] !== 'team' || $unit['state'] !== 'active' ||
        self::rowVersion($unit['version']) === null) {
      throw new RuntimeException('organization_team_unavailable');
    }
    if ((int)$user['is_admin'] !== 1) {
      $member = $this->membership($team, $actor);
      $appointment = $this->appointment($team, $actor);
      $now = (int)$this->query('SELECT UNIX_TIMESTAMP() AS n')[0]['n'];
      if ($sensitive || !self::active($member, $now) ||
          !self::active($appointment, $now) || $appointment['role'] !== 'team_admin') {
        throw new RuntimeException('organization_actor_forbidden');
      }
    }
    return $unit;
  }

  private function audit($operation, $actor, $action, $target, $before, $after, $now) {
    $this->query('INSERT INTO org_audit(operation_id,actor_id,action,target_id,'.
      'before_json,after_json,created_at) VALUES(?,?,?,?,?,?,?)', array($operation,
        $actor, $action, $target, json_encode($before, JSON_THROW_ON_ERROR),
        json_encode($after, JSON_THROW_ON_ERROR), $now));
  }

  public function execute(array $request) {
    $request = PhorgeGovernanceOrganizationRequest::normalize($request);
    $digest = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
    try {
      $this->begin();
      $now = (int)$this->query('SELECT UNIX_TIMESTAMP() AS n')[0]['n'];
      $teams = array();
      foreach ($request['mutations'] as $mutation) {
        $team = $mutation['team'];
        $unit = $this->authorize($request['actor'], $team,
          strncmp($mutation['action'], 'appointment.', 12) === 0, $now);
        $teams[$team] = $unit;
      }
      // Revalidate current caller before even looking up a historical op ID.
      $replay = $this->query('SELECT * FROM org_operation WHERE operation_id=? FOR SHARE',
        array($request['operation_id']))[0] ?? null;
      if ($replay !== null) {
        if ($replay['operation_id'] !== $request['operation_id'] ||
            $replay['request_digest'] !== $digest || $replay['actor_id'] !== $request['actor']) {
          throw new RuntimeException('organization_operation_conflict');
        }
        $result = json_decode($replay['result_json'], true, 32, JSON_THROW_ON_ERROR);
        $keys = is_array($result) ? array_keys($result) : array(); sort($keys);
        if ($keys !== array('can_execute', 'isolated_writes_expected', 'operation_id',
          'production_execution', 'status', 'team_versions') ||
            $result['operation_id'] !== $request['operation_id'] ||
            $result['can_execute'] !== false || $result['production_execution'] !== false ||
            $result['isolated_writes_expected'] !== true || $result['status'] !== 'applied' ||
            !is_array($result['team_versions'])) {
          throw new RuntimeException('organization_operation_record_invalid');
        }
        foreach ($request['mutations'] as $mutation) {
          $this->authorize($request['actor'], $mutation['team'],
            strncmp($mutation['action'], 'appointment.', 12) === 0, $now);
        }
        $result['status'] = 'replayed';
        $this->finish(true); return $result;
      }
      foreach ($request['mutations'] as $mutation) {
        if ((int)$teams[$mutation['team']]['version'] !== $mutation['expected_team_version']) {
          throw new RuntimeException('organization_stale_version');
        }
        $this->apply($request, $mutation, $now);
      }
      $versions = array();
      foreach ($teams as $team => $unit) {
        $this->query('UPDATE org_unit SET version=version+1 WHERE unit_id=?', array($team));
        $versions[$team] = (int)$unit['version'] + 1;
      }
      $this->query('UPDATE fixture_guard SET epoch_version=epoch_version+1 WHERE id=1');
      $result = array('status' => 'applied', 'operation_id' => $request['operation_id'],
        'team_versions' => $versions, 'isolated_writes_expected' => true,
        'can_execute' => false, 'production_execution' => false);
      $this->query('INSERT INTO org_operation VALUES(?,?,?,?,?)', array($request['operation_id'],
        $digest, $request['actor'], json_encode($result, JSON_THROW_ON_ERROR), $now));
      foreach ($request['mutations'] as $mutation) {
        $this->authorize($request['actor'], $mutation['team'],
          strncmp($mutation['action'], 'appointment.', 12) === 0, $now);
        $this->recheckTarget($mutation);
        if (strncmp($mutation['action'], 'appointment.', 12) === 0) {
          PhorgeGovernanceOrganizationApprovalAdapter::forLockedStore($this)
            ->assertConsumedCurrent($mutation['approval_id'], $request['operation_id']);
        }
      }
      $this->finish(true); return $result;
    } catch (Throwable $error) {
      if ($this->transactionNonce !== null) { $this->finish(false); }
      if ($error instanceof RuntimeException &&
          preg_match('/\Aorganization_[a-z_]+\z/', $error->getMessage())) { throw $error; }
      throw new RuntimeException('organization_write_failed');
    }
  }

  private function apply(array $request, array $mutation, $now) {
    $team = $mutation['team']; $subject = $mutation['target_user'];
    $member = $this->membership($team, $subject);
    $appointment = $this->appointment($team, $subject);
    if ((int)($member['version'] ?? 0) !== $mutation['expected_member_version'] ||
        (int)($appointment['version'] ?? 0) !== $mutation['expected_appointment_version']) {
      throw new RuntimeException('organization_stale_version');
    }
    $action = $mutation['action'];
    $this->user($subject, in_array($action, array('member.add', 'member.update',
      'appointment.grant'), true));
    $now = (int)$this->query('SELECT UNIX_TIMESTAMP() AS n')[0]['n'];
    if (strncmp($action, 'member.', 7) === 0) {
      $active = in_array($action, array('member.add', 'member.update'), true);
      if (($action === 'member.add' && $member !== null && $member['state'] === 'active') ||
          ($action !== 'member.add' && $member === null)) {
        throw new RuntimeException('organization_member_state_conflict');
      }
      $from = $active ? $mutation['valid_from'] : (int)$member['valid_from'];
      $until = $active ? $mutation['valid_until'] : ($action === 'member.end' ? max($now, (int)$member['valid_from']) : $member['valid_until']);
      $state = $active ? 'active' : ($action === 'member.end' ? 'ended' : 'suspended');
      if ($active && $until !== null && $until <= $now) {
        throw new RuntimeException('organization_period_expired');
      }
      if ($appointment !== null && $appointment['state'] === 'active' &&
          ($appointment['valid_until'] === null || (int)$appointment['valid_until'] > $now) &&
          ($state !== 'active' || $from > (int)$appointment['valid_from'] ||
            ($until !== null && ($appointment['valid_until'] === null ||
              $until < (int)$appointment['valid_until'])))) {
        throw new RuntimeException('organization_appointment_member_prerequisite');
      }
      $after = array('state' => $state, 'valid_from' => $from, 'valid_until' => $until,
        'version' => (int)($member['version'] ?? 0) + 1);
      if ($member === null) {
        $this->query('INSERT INTO org_member VALUES(?,?,?,?,?,?)',
          array($team, $subject, $state, $from, $until, $after['version']));
      } else {
        $this->query('UPDATE org_member SET state=?,valid_from=?,valid_until=?,version=? '.
          'WHERE team_id=? AND user_id=?', array($state, $from, $until, $after['version'], $team, $subject));
      }
      $this->audit($request['operation_id'], $request['actor'], $action,
        $team.'/'.$subject, $member, $after, $now);
      return;
    }
    $grant = $action === 'appointment.grant';
    if ($grant && (!self::active($member, $now) ||
        (int)$member['valid_from'] > $mutation['valid_from'] ||
        ($member['valid_until'] !== null && ($mutation['valid_until'] === null ||
          (int)$member['valid_until'] < $mutation['valid_until'])) ||
        ($mutation['valid_until'] !== null && $mutation['valid_until'] <= $now))) {
      throw new RuntimeException('organization_appointment_member_prerequisite');
    }
    if ((!$grant && ($appointment === null || $appointment['state'] !== 'active')) ||
        ($grant && $appointment !== null && $appointment['state'] === 'active')) {
      throw new RuntimeException('organization_appointment_state_conflict');
    }
    PhorgeGovernanceOrganizationApprovalAdapter::forLockedStore($this)->consume(
      $mutation['approval_id'], $request['operation_id'], $request['actor'], $team,
      $grant ? 'team.admin.grant' : 'team.admin.revoke', array('subject' => $subject,
        'valid_from' => $mutation['valid_from'], 'valid_until' => $mutation['valid_until']));
    $state = $grant ? 'active' : 'ended';
    $from = $grant ? $mutation['valid_from'] : (int)$appointment['valid_from'];
    $until = $grant ? $mutation['valid_until'] : max($now, $from);
    $version = (int)($appointment['version'] ?? 0) + 1;
    if ($appointment === null) {
      $this->query('INSERT INTO org_appointment VALUES(?,?,?,?,?,?,?)',
        array($team, $subject, 'team_admin', $state, $from, $until, $version));
    } else {
      $this->query('UPDATE org_appointment SET state=?,valid_from=?,valid_until=?,version=? '.
        'WHERE team_id=? AND user_id=?', array($state, $from, $until, $version, $team, $subject));
    }
    $this->audit($request['operation_id'], $request['actor'], $action,
      $team.'/'.$subject, $appointment, array('team_id' => $team, 'user_id' => $subject,
        'role' => 'team_admin', 'state' => $state, 'valid_from' => $from,
        'valid_until' => $until, 'version' => $version), $now);
  }

  private function recheckTarget(array $mutation) {
    $member = $this->membership($mutation['team'], $mutation['target_user']);
    $appointment = $this->appointment($mutation['team'], $mutation['target_user']);
    $now = (int)$this->query('SELECT UNIX_TIMESTAMP() AS n')[0]['n'];
    if (in_array($mutation['action'], array('member.add', 'member.update'), true) &&
        ($member['valid_until'] !== null && (int)$member['valid_until'] <= $now)) {
      throw new RuntimeException('organization_period_expired');
    }
    if ($mutation['action'] === 'appointment.grant' && !self::active($member, $now)) {
      throw new RuntimeException('organization_appointment_member_prerequisite');
    }
    if ($appointment !== null && $appointment['state'] === 'active' &&
        ($appointment['valid_until'] === null || (int)$appointment['valid_until'] > $now)) {
      if ($member === null || $member['state'] !== 'active' ||
          (int)$member['valid_from'] > (int)$appointment['valid_from'] ||
          ($member['valid_until'] !== null && ($appointment['valid_until'] === null ||
            (int)$member['valid_until'] < (int)$appointment['valid_until'])) ||
          ((int)$appointment['valid_from'] <= $now && !self::active($member, $now))) {
        throw new RuntimeException('organization_appointment_member_prerequisite');
      }
    } else if ($mutation['action'] === 'appointment.grant') {
      throw new RuntimeException('organization_period_expired');
    }
  }

  public function proposeApproval(array $request) {
    $keys = array_keys($request); sort($keys);
    if ($keys !== array('action', 'actor', 'approval_id', 'operation_id',
      'target_user', 'team', 'ttl_seconds', 'valid_from', 'valid_until') ||
        !PhorgeGovernanceOrganizationRequest::userID($request['actor']) ||
        !PhorgeGovernanceOrganizationRequest::userID($request['target_user']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($request['team'], 64) ||
        !PhorgeGovernanceOrganizationRequest::identifier($request['operation_id']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($request['approval_id']) ||
        !in_array($request['action'], array('appointment.grant', 'appointment.revoke'), true)) {
      throw new InvalidArgumentException('organization_approval_request_refused');
    }
    try {
      $this->begin();
      $now = (int)$this->query('SELECT UNIX_TIMESTAMP() AS n')[0]['n'];
      $this->authorize($request['actor'], $request['team'], true, $now);
      $receipt = PhorgeGovernanceOrganizationApprovalAdapter::forLockedStore($this)->propose(
        $request['approval_id'], $request['operation_id'], $request['actor'],
        $request['actor'], $request['team'],
        $request['action'] === 'appointment.grant' ? 'team.admin.grant' : 'team.admin.revoke',
        array('subject' => $request['target_user'], 'valid_from' => $request['valid_from'],
          'valid_until' => $request['valid_until']), $request['ttl_seconds']);
      if (!$receipt['replayed']) {
        $this->audit('approval-propose:'.hash('sha256', $request['approval_id']),
          $request['actor'], 'approval.propose', $request['approval_id'], null, $receipt, $now);
      }
      PhorgeGovernanceOrganizationApprovalAdapter::forLockedStore($this)
        ->assertApprovalCurrent($request['approval_id']);
      $this->finish(true); return $receipt;
    } catch (Throwable $error) {
      if ($this->transactionNonce !== null) { $this->finish(false); }
      if (($error instanceof RuntimeException || $error instanceof InvalidArgumentException) &&
          preg_match('/\A(?:organization_|approval_)[a-z_]+\z/', $error->getMessage())) { throw $error; }
      throw new RuntimeException('organization_approval_write_failed');
    }
  }

  public function approveApproval(array $request) {
    $keys = array_keys($request); sort($keys);
    if ($keys !== array('actor', 'approval_id') ||
        !PhorgeGovernanceOrganizationRequest::userID($request['actor']) ||
        !PhorgeGovernanceOrganizationRequest::identifier($request['approval_id'])) {
      throw new InvalidArgumentException('organization_approval_request_refused');
    }
    try {
      $this->begin();
      $user = $this->user($request['actor']);
      if ((int)$user['is_admin'] !== 1) {
        throw new RuntimeException('organization_actor_forbidden');
      }
      $receipt = PhorgeGovernanceOrganizationApprovalAdapter::forLockedStore($this)
        ->approve($request['approval_id'], $request['actor']);
      if (!$receipt['replayed']) {
        $now = (int)$this->query('SELECT UNIX_TIMESTAMP() AS n')[0]['n'];
        $this->audit('approval-approve:'.hash('sha256', $request['approval_id']),
          $request['actor'], 'approval.approve', $request['approval_id'], null, $receipt, $now);
      }
      PhorgeGovernanceOrganizationApprovalAdapter::forLockedStore($this)
        ->assertApprovalCurrent($request['approval_id']);
      $this->finish(true); return $receipt;
    } catch (Throwable $error) {
      if ($this->transactionNonce !== null) { $this->finish(false); }
      if (($error instanceof RuntimeException || $error instanceof InvalidArgumentException) &&
          preg_match('/\A(?:organization_|approval_)[a-z_]+\z/', $error->getMessage())) { throw $error; }
      throw new RuntimeException('organization_approval_write_failed');
    }
  }

  public function close() {
    if ($this->closed) {
      if ($this->cleanupFailed) { throw new RuntimeException('organization_cleanup_failed'); }
      return;
    }
    $this->closed = true;
    $failed = false;
    try {
      if ($this->transactionNonce !== null) { $this->finish(false); }
    } catch (Throwable $error) { $failed = true; }
    try { $this->connection->close(); }
    catch (Throwable $error) { $failed = true; }
    finally { mysqli_report($this->oldReportMode); }
    $this->cleanupFailed = $failed;
    if ($failed) { throw new RuntimeException('organization_cleanup_failed'); }
  }
  public function __destruct() {
    try { $this->close(); } catch (Throwable $cleanup) {}
  }
}
