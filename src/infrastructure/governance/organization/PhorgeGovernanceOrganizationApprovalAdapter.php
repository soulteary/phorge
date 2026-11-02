<?php

/**
 * Durable fixture approval on the OrganizationStore's owned transaction.
 * This is not a production identity adapter or a general execution grant.
 */
final class PhorgeGovernanceOrganizationApprovalAdapter extends Phobject {
  private $store;
  private $connection;
  private $nonce;
  private $database;
  private $ownerNonce;
  private $sourceMembership;

  private function __construct($store, mysqli $connection, $nonce, $database, $owner) {
    $this->store = WeakReference::create($store);
    $this->connection = $connection;
    $this->nonce = $nonce;
    $this->database = $database;
    $this->ownerNonce = $owner;
  }

  private function __clone() {}
  public function __serialize() { throw new RuntimeException('approval_scope_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('approval_scope_serialization_refused'); }
  public function __debugInfo() { return array('scope' => 'isolated-org-approval'); }

  private static function property($store, $name) {
    return (new ReflectionProperty(PhorgeGovernanceOrganizationStore::class, $name))
      ->getValue($store);
  }

  public static function forLockedStore($store) {
    if (!is_object($store) || get_class($store) !== PhorgeGovernanceOrganizationStore::class) {
      throw new RuntimeException('approval_exact_store_required');
    }
    $connection = self::property($store, 'connection');
    $nonce = self::property($store, 'transactionNonce');
    $database = self::property($store, 'database');
    $owner = self::property($store, 'ownerNonce');
    if (!is_object($connection) || get_class($connection) !== mysqli::class ||
        !is_string($nonce) || !preg_match('/\A[a-f0-9]{32}\z/', $nonce) ||
        !is_string($database) || !preg_match('/\Agovernance_org_[a-f0-9]{16}\z/', $database) ||
        !is_string($owner) || !preg_match('/\A[a-f0-9]{32}\z/', $owner)) {
      throw new RuntimeException('approval_locked_store_required');
    }
    $adapter = new self($store, $connection, $nonce, $database, $owner);
    $adapter->assertScope();
    return $adapter;
  }

  private function assertScope() {
    require_once dirname(__DIR__, 4).'/support/runtime/php-version.php';
    phorge_assert_supported_php_version(PHP_VERSION);
    if (PHP_SAPI !== 'cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new RuntimeException('approval_isolated_scope_required');
    }
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG', 'PHORGE_CONTROL_PLANE') as $key) {
      $value = getenv($key);
      if ($value !== false && $value !== '') { throw new RuntimeException('approval_deployment_refused'); }
    }
    foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $file) {
      if (file_exists(dirname(__DIR__, 4).'/conf/local/'.$file)) {
        throw new RuntimeException('approval_deployment_refused');
      }
    }
    $store = $this->store->get();
    if ($store === null || self::property($store, 'connection') !== $this->connection ||
        self::property($store, 'transactionNonce') !== $this->nonce ||
        self::property($store, 'database') !== $this->database ||
        self::property($store, 'ownerNonce') !== $this->ownerNonce) {
      throw new RuntimeException('approval_transaction_ended');
    }
    $scope = $this->row('SELECT DATABASE() AS db, @@SESSION.autocommit AS ac');
    if (!$scope || $scope['db'] !== $this->database || (string)$scope['ac'] !== '0') {
      throw new RuntimeException('approval_owned_transaction_required');
    }
    // With autocommit disabled this actual FOR UPDATE retains the global lock.
    $guard = $this->row('SELECT * FROM fixture_guard WHERE id=1 FOR UPDATE');
    $schema = dirname(__DIR__, 4).'/support/governance/organization/schema.sql';
    if (!$guard || $guard['marker'] !== 'N03.ORG-WRITE.v1:synthetic-only' ||
        (string)$guard['schema_version'] !== '1' ||
        !hash_equals($this->ownerNonce, $guard['owner_nonce']) ||
        !hash_equals(hash_file('sha256', $schema), $guard['schema_sha256'])) {
      throw new RuntimeException('approval_owned_lineage_required');
    }
  }

  private function statement($sql, array $parameters = array()) {
    try {
      $statement = $this->connection->prepare($sql);
      $statement->execute($parameters);
      return $statement;
    } catch (Throwable $error) {
      throw new RuntimeException('approval_storage_unavailable');
    }
  }

  private function row($sql, array $parameters = array()) {
    $statement = $this->statement($sql, $parameters);
    try { return $statement->get_result()->fetch_assoc(); }
    finally { $statement->close(); }
  }

  private function write($sql, array $parameters) {
    $statement = $this->statement($sql, $parameters);
    try {
      if ($statement->affected_rows !== 1) { throw new RuntimeException('approval_concurrent_state_changed'); }
    } finally { $statement->close(); }
  }

  public static function validateIdentifier($value, $user = false) {
    $pattern = $user ? '/\APHID-USER-[a-zA-Z0-9]{20}\z/' : '/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}\z/';
    if (!is_string($value) || !preg_match($pattern, $value)) {
      throw new InvalidArgumentException('approval_invalid_identifier');
    }
    return $value;
  }

  public static function validateParameters($operation, array $parameters) {
    if (!in_array($operation, array('team.admin.grant', 'team.admin.revoke'), true)) {
      throw new InvalidArgumentException('approval_unsupported_operation');
    }
    $keys = array_keys($parameters); sort($keys, SORT_STRING);
    if ($keys !== array('subject', 'valid_from', 'valid_until')) {
      throw new InvalidArgumentException('approval_invalid_parameters');
    }
    $subject = self::validateIdentifier($parameters['subject'], true);
    $from = $parameters['valid_from']; $until = $parameters['valid_until'];
    if ($operation === 'team.admin.revoke') {
      if ($from !== null || $until !== null) { throw new InvalidArgumentException('approval_invalid_revoke_period'); }
    } else if (!is_int($from) || $from < 0 ||
        ($until !== null && (!is_int($until) || $until <= $from))) {
      throw new InvalidArgumentException('approval_invalid_grant_period');
    }
    // Fresh slots detach references held by the calling request array.
    return array('subject' => $subject, 'valid_from' => $from, 'valid_until' => $until);
  }

  public static function validateTTL($seconds) {
    if (!is_int($seconds) || $seconds < 1 || $seconds > 86400) {
      throw new InvalidArgumentException('approval_explicit_test_ttl_required');
    }
    return $seconds;
  }

  public static function canonicalJSON($value) {
    $budget = array('nodes' => 0, 'bytes' => 0);
    return json_encode(self::detach($value, 0, $budget),
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
  }

  private static function detach($value, $depth, array &$budget) {
    if ($depth > 8 || ++$budget['nodes'] > 256) {
      throw new InvalidArgumentException('approval_binding_depth_or_nodes');
    }
    if (is_array($value)) {
      if (count($value) > 32) { throw new InvalidArgumentException('approval_binding_size'); }
      if (!array_is_list($value)) { ksort($value, SORT_STRING); }
      $copy = array();
      foreach ($value as $key => $item) {
        if (is_string($key) && strlen($key) > 256) {
          throw new InvalidArgumentException('approval_binding_key');
        }
        $budget['bytes'] += is_string($key) ? strlen($key) : 8;
        if ($budget['bytes'] > 32768) { throw new InvalidArgumentException('approval_binding_bytes'); }
        $copy[$key] = self::detach($item, $depth + 1, $budget);
      }
      return $copy;
    }
    if ($value === null || is_int($value) || is_bool($value) ||
        (is_string($value) && strlen($value) <= 4096)) {
      $budget['bytes'] += is_string($value) ? strlen($value) : 8;
      if ($budget['bytes'] > 32768) { throw new InvalidArgumentException('approval_binding_bytes'); }
      return $value;
    }
    throw new InvalidArgumentException('approval_binding_value');
  }

  public static function validateVersion($value) {
    if (is_int($value) && $value >= 1) { return (string)$value; }
    if (is_string($value) && preg_match('/\A[1-9][0-9]{0,19}\z/', $value) &&
        (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0)) {
      return $value;
    }
    throw new RuntimeException('approval_unknown_source_version');
  }

  private function now() {
    $row = $this->row('SELECT UNIX_TIMESTAMP() AS n');
    if (!$row || !preg_match('/\A[1-9][0-9]{0,10}\z/', (string)$row['n'])) {
      throw new RuntimeException('approval_unknown_database_time');
    }
    return (int)$row['n'];
  }

  private static function eligible(array $row) {
    foreach (array('is_admin', 'is_approved', 'is_disabled', 'is_system_agent', 'is_mailing_list') as $field) {
      if (!in_array((string)$row[$field], array('0', '1'), true)) {
        throw new RuntimeException('approval_unknown_identity_flags');
      }
    }
    return (string)$row['is_approved'] === '1' && (string)$row['is_disabled'] === '0' &&
      (string)$row['is_system_agent'] === '0' && (string)$row['is_mailing_list'] === '0';
  }

  private function account($id, $admin) {
    self::validateIdentifier($id, true);
    $row = $this->row('SELECT * FROM org_user WHERE user_id=? FOR SHARE', array($id));
    if (!$row || $row['user_id'] !== $id) {
      throw new RuntimeException('approval_current_identity_required');
    }
    $eligible = self::eligible($row);
    if ($admin && (!$eligible || (string)$row['is_admin'] !== '1')) {
      throw new RuntimeException('approval_current_admin_required');
    }
    self::validateVersion($row['version']);
    return $row;
  }

  private function sources($requester, $executor, $team, $operation, array $parameters) {
    self::validateIdentifier($team);
    $requester_row = $this->account($requester, true);
    $executor_row = $this->account($executor, true);
    $subject = $this->account($parameters['subject'], false);
    if ($operation === 'team.admin.grant' && !self::eligible($subject)) {
      throw new RuntimeException('approval_subject_ineligible');
    }
    $unit = $this->row('SELECT * FROM org_unit WHERE unit_id=? FOR UPDATE', array($team));
    if (!$unit || $unit['unit_id'] !== $team ||
        $unit['kind'] !== 'team' || $unit['state'] !== 'active') {
      throw new RuntimeException('approval_current_team_required');
    }
    $member = $this->row('SELECT * FROM org_member WHERE team_id=? AND user_id=? FOR UPDATE',
      array($team, $parameters['subject']));
    $appointment = $this->row('SELECT * FROM org_appointment WHERE team_id=? AND user_id=? FOR UPDATE',
      array($team, $parameters['subject']));
    foreach (array($member, $appointment) as $relationship) {
      if ($relationship !== null && ($relationship['team_id'] !== $team ||
          $relationship['user_id'] !== $parameters['subject'])) {
        throw new RuntimeException('approval_relationship_identity_changed');
      }
    }
    $this->sourceMembership = $member;
    if ($operation === 'team.admin.grant') {
      self::assertMembershipAt($member, $parameters, $this->now());
    }
    $rows = array('identity.requester' => $requester_row, 'identity.executor' => $executor_row,
      'identity.subject' => $subject, 'org.team' => $unit, 'org.membership' => $member,
      'org.appointment' => $appointment);
    $read_set = array();
    foreach ($rows as $kind => $row) {
      if ($row !== null) { self::validateVersion($row['version']); }
      $read_set[$kind] = hash('sha256', self::canonicalJSON($row));
    }
    return array((string)$unit['version'], $read_set);
  }

  private static function assertMembershipAt($member, array $parameters, $now) {
    if (!$member || $member['state'] !== 'active' ||
        (int)$member['valid_from'] > $now ||
        ($member['valid_until'] !== null && (int)$member['valid_until'] <= $now) ||
        (int)$member['valid_from'] > $parameters['valid_from'] ||
        ($member['valid_until'] !== null && ($parameters['valid_until'] === null ||
          (int)$member['valid_until'] < $parameters['valid_until'])) ||
        ($parameters['valid_until'] !== null && $parameters['valid_until'] <= $now)) {
      throw new RuntimeException('approval_current_membership_required');
    }
  }

  private function freshTime(PhorgeGovernanceApprovalRequest $request,
    array $data, $approved_at = null) {
    // This clock is read only after all source locks have been acquired. A
    // stored immutable row does not make an earlier timestamp current.
    $now = $this->now();
    if (!$request->isValidAt($now) ||
        ($approved_at !== null && $now < (int)$approved_at)) {
      throw new RuntimeException('approval_expired_or_invalid_time');
    }
    if ($data['operation'] === 'team.admin.grant') {
      self::assertMembershipAt($this->sourceMembership, $data['parameters'], $now);
    }
    return $now;
  }

  private function binding($op_id, $requester, $executor, $team, $operation,
    array $parameters, $issued, $expires) {
    list($version, $sources) = $this->sources($requester, $executor, $team, $operation, $parameters);
    $data = array('operation_id' => $op_id, 'requester' => $requester, 'executor' => $executor,
      'target' => $team, 'operation' => $operation, 'target_version' => $version,
      'parameters' => $parameters, 'read_set' => $sources, 'issued_at' => $issued,
      'expires_at' => $expires);
    return array($data, new PhorgeGovernanceApprovalRequest($data));
  }

  private function stored($id) {
    $row = $this->row('SELECT approval_id,operation_id,request_digest,state,approver_id,approver_version,approver_digest,approved_at,consumed_at,consumed_by_operation,version,CASE WHEN OCTET_LENGTH(binding_json)<=32768 THEN binding_json ELSE NULL END AS binding_json FROM org_approval WHERE approval_id=? FOR UPDATE', array($id));
    if (!$row || $row['approval_id'] !== $id) {
      throw new RuntimeException('approval_not_available');
    }
    if (!is_string($row['binding_json'])) { throw new RuntimeException('approval_corrupt_binding'); }
    self::validateVersion($row['version']);
    $data = json_decode($row['binding_json'], true, 16, JSON_THROW_ON_ERROR);
    $request = new PhorgeGovernanceApprovalRequest($data);
    if ($row['operation_id'] !== $data['operation_id'] ||
        !in_array($row['state'], array('submitted', 'approved', 'consumed'), true) ||
        !hash_equals($row['request_digest'], $request->getDigest())) {
      throw new RuntimeException('approval_corrupt_binding');
    }
    return array($row, $data, $request);
  }

  private static function receipt(array $row, $replayed) {
    return array('approval_id' => $row['approval_id'], 'state' => $row['state'],
      'version' => (int)$row['version'], 'request_digest' => $row['request_digest'],
      'replayed' => $replayed, 'isolated_approval_write' => !$replayed,
      'production_execution' => false, 'native_account_freshness_proved' => false);
  }

  public function propose($id, $op_id, $requester, $executor, $team, $operation,
    array $parameters, $ttl_seconds) {
    $this->assertScope();
    self::validateIdentifier($id); self::validateIdentifier($op_id);
    $parameters = self::validateParameters($operation, $parameters);
    self::validateTTL($ttl_seconds);
    // Current authority is checked before the existence/replay lookup.
    $this->account($requester, true); $this->account($executor, true);
    $existing = $this->row('SELECT approval_id FROM org_approval WHERE approval_id=? FOR UPDATE', array($id));
    $now = $this->now(); $issued = $now; $expires = $now + $ttl_seconds;
    if ($existing) {
      list($existing, $old, $old_request) = $this->stored($id);
      $issued = $old['issued_at']; $expires = $old['expires_at'];
      if ($expires - $issued !== $ttl_seconds || !$old_request->isValidAt($now)) {
        throw new RuntimeException('approval_expired_or_ttl_changed');
      }
    }
    list($data, $request) = $this->binding($op_id, $requester, $executor, $team,
      $operation, $parameters, $issued, $expires);
    $this->freshTime($request, $data);
    if ($existing) {
      if (!hash_equals($request->getDigest(), $existing['request_digest'])) {
        throw new RuntimeException('approval_stale_or_changed_request');
      }
      return self::receipt($existing, true);
    }
    $this->write('INSERT INTO org_approval (approval_id,operation_id,request_digest,binding_json,state,version) VALUES (?,?,?,?,?,1)',
      array($id, $op_id, $request->getDigest(), self::canonicalJSON($data), 'submitted'));
    return self::receipt($this->row('SELECT * FROM org_approval WHERE approval_id=?', array($id)), false);
  }

  public function approve($id, $approver) {
    $this->assertScope(); self::validateIdentifier($id);
    $approver_row = $this->account($approver, true);
    list($row, $data, $request) = $this->stored($id);
    $now = $this->now();
    if (!$request->isValidAt($now) ||
        ($row['approved_at'] !== null && $now < (int)$row['approved_at'])) {
      throw new RuntimeException('approval_expired_or_invalid_time');
    }
    if ($approver === $data['requester'] || $approver === $data['executor']) {
      throw new RuntimeException('approval_independent_admin_required');
    }
    $parameters = self::validateParameters($data['operation'], $data['parameters']);
    list($_, $current) = $this->binding($data['operation_id'], $data['requester'],
      $data['executor'], $data['target'], $data['operation'], $parameters,
      $data['issued_at'], $data['expires_at']);
    if (!hash_equals($request->getDigest(), $current->getDigest())) {
      throw new RuntimeException('approval_stale_sources');
    }
    $now = $this->freshTime($current, $data, $row['approved_at']);
    $approver_digest = hash('sha256', self::canonicalJSON($approver_row));
    if ($row['state'] === 'approved' && $row['approver_id'] === $approver &&
        hash_equals($approver_digest, $row['approver_digest'])) {
      return self::receipt($row, true);
    }
    if ($row['state'] !== 'submitted') { throw new RuntimeException('approval_invalid_transition'); }
    $this->write('UPDATE org_approval SET state=?,approver_id=?,approver_version=?,approver_digest=?,approved_at=?,version=version+1 WHERE approval_id=? AND state=?',
      array('approved', $approver, $approver_row['version'], $approver_digest, $now, $id, 'submitted'));
    return self::receipt($this->row('SELECT * FROM org_approval WHERE approval_id=?', array($id)), false);
  }

  public function consume($id, $op_id, $executor, $team, $operation, array $parameters) {
    $this->assertScope(); self::validateIdentifier($id); self::validateIdentifier($op_id);
    $this->account($executor, true);
    $parameters = self::validateParameters($operation, $parameters);
    list($row, $data, $request) = $this->stored($id);
    if ($row['state'] === 'consumed') { throw new RuntimeException('approval_already_consumed'); }
    if ($row['state'] !== 'approved') { throw new RuntimeException('approval_not_approved'); }
    $now = $this->now();
    if (!$request->isValidAt($now) || $row['approved_at'] === null ||
        $now < (int)$row['approved_at']) { throw new RuntimeException('approval_expired_or_invalid_time'); }
    if ($data['executor'] !== $executor || $data['operation_id'] !== $op_id ||
        $data['target'] !== $team || $data['operation'] !== $operation ||
        self::canonicalJSON($data['parameters']) !== self::canonicalJSON($parameters)) {
      throw new RuntimeException('approval_execution_binding_changed');
    }
    $approver = $this->account($row['approver_id'], true);
    if ($row['approver_id'] === $data['requester'] || $row['approver_id'] === $executor ||
        (string)$approver['version'] !== (string)$row['approver_version'] ||
        !hash_equals(hash('sha256', self::canonicalJSON($approver)), $row['approver_digest'])) {
      throw new RuntimeException('approval_approver_changed_or_ineligible');
    }
    list($_, $current) = $this->binding($op_id, $data['requester'], $executor, $team,
      $operation, $parameters, $data['issued_at'], $data['expires_at']);
    if (!hash_equals($request->getDigest(), $current->getDigest())) {
      throw new RuntimeException('approval_stale_sources');
    }
    $now = $this->freshTime($current, $data, $row['approved_at']);
    $this->write('UPDATE org_approval SET state=?,consumed_at=?,consumed_by_operation=?,version=version+1 WHERE approval_id=? AND state=?',
      array('consumed', $now, $op_id, $id, 'approved'));
    return self::receipt($this->row('SELECT * FROM org_approval WHERE approval_id=?', array($id)), false);
  }

  public function assertApprovalCurrent($id) {
    $this->assertScope(); self::validateIdentifier($id);
    list($row, $data, $request) = $this->stored($id);
    if (!in_array($row['state'], array('submitted', 'approved'), true)) {
      throw new RuntimeException('approval_invalid_transition');
    }
    $parameters = self::validateParameters($data['operation'], $data['parameters']);
    list($_, $current) = $this->binding($data['operation_id'], $data['requester'],
      $data['executor'], $data['target'], $data['operation'], $parameters,
      $data['issued_at'], $data['expires_at']);
    if (!hash_equals($request->getDigest(), $current->getDigest())) {
      throw new RuntimeException('approval_stale_sources');
    }
    if ($row['state'] === 'approved') {
      $approver = $this->account($row['approver_id'], true);
      if ($row['approved_at'] === null ||
          $row['approver_id'] === $data['requester'] ||
          $row['approver_id'] === $data['executor'] ||
          (string)$row['approver_version'] !== (string)$approver['version'] ||
          !hash_equals($row['approver_digest'], hash('sha256', self::canonicalJSON($approver)))) {
        throw new RuntimeException('approval_commit_gate_changed');
      }
    }
    $this->freshTime($current, $data, $row['approved_at']);
    return array('approval_id' => $id, 'commit_gate_checked' => true,
      'production_execution' => false);
  }

  public function assertConsumedCurrent($id, $op_id) {
    $this->assertScope(); self::validateIdentifier($id); self::validateIdentifier($op_id);
    list($row, $data, $request) = $this->stored($id);
    $requester = $this->account($data['requester'], true);
    $executor = $this->account($data['executor'], true);
    $approver = $this->account($row['approver_id'], true);
    $subject = $this->account($data['parameters']['subject'], false);
    $unit = $this->row('SELECT * FROM org_unit WHERE unit_id=? FOR UPDATE', array($data['target']));
    if (!$unit || $unit['unit_id'] !== $data['target'] ||
        $unit['state'] !== 'active' || $unit['kind'] !== 'team' ||
        $row['state'] !== 'consumed' || $row['consumed_by_operation'] !== $op_id ||
        $data['operation_id'] !== $op_id || $row['approved_at'] === null ||
        $row['consumed_at'] === null || $row['approver_id'] === $data['requester'] ||
        $row['approver_id'] === $data['executor'] ||
        (string)$row['approver_version'] !== (string)$approver['version'] ||
        !hash_equals($row['approver_digest'], hash('sha256', self::canonicalJSON($approver)))) {
      throw new RuntimeException('approval_commit_gate_changed');
    }
    foreach (array('identity.requester' => $requester, 'identity.executor' => $executor,
      'identity.subject' => $subject) as $kind => $identity) {
      if (!hash_equals($data['read_set'][$kind], hash('sha256', self::canonicalJSON($identity)))) {
        throw new RuntimeException('approval_commit_identity_changed');
      }
    }
    $this->sourceMembership = $this->row(
      'SELECT * FROM org_member WHERE team_id=? AND user_id=? FOR UPDATE',
      array($data['target'], $data['parameters']['subject']));
    if ($this->sourceMembership !== null &&
        ($this->sourceMembership['team_id'] !== $data['target'] ||
          $this->sourceMembership['user_id'] !== $data['parameters']['subject'])) {
      throw new RuntimeException('approval_relationship_identity_changed');
    }
    // Target/appointment versions may have changed legitimately in this same
    // transaction; never treat a second consume as a commit-time recheck.
    $now = $this->freshTime($request, $data, $row['approved_at']);
    if ($now < (int)$row['consumed_at']) { throw new RuntimeException('approval_commit_time_reversed'); }
    return array('approval_id' => $id, 'commit_gate_checked' => true,
      'production_execution' => false);
  }

}
