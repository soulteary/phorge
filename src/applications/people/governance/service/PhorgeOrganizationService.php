<?php

/** Typed People-owned organization transactions; never changes native policies. */
final class PhorgeOrganizationService extends Phobject {
  private $context;
  private $connection;
  private $actorSnapshot;
  private $transactionNonce;
  private $accounts = array();
  private $readStartedAt;
  private $readDeadline;

  private function __construct(PhorgeGovernanceNativeActorContext $context) {
    $this->context = $context;
  }
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('organization_serialize_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('organization_serialize_refused'); }
  public function __debugInfo() { return array('scope' => 'native-people-directory'); }

  public static function newForActor(PhorgeGovernanceNativeActorContext $context) {
    self::assertEnabled();
    return new self($context);
  }

  public static function assertEnabled() {
    if (PhabricatorEnv::getEnvConfig('governance.organization.enabled') !== true) {
      throw new RuntimeException('organization_disabled');
    }
  }

  private function table($name) {
    if (!in_array($name, array('guard', 'unit', 'member', 'appointment',
      'primarydepartment', 'operation', 'audit', 'approval'), true)) {
      throw new RuntimeException('organization_table_refused');
    }
    return 'organization_'.$name;
  }

  private function begin(array $subjects) {
    self::assertEnabled();
    if ($this->connection !== null) {
      throw new RuntimeException('organization_nested_operation_refused');
    }
    $conn = id(new PhabricatorUser())->establishConnection('w');
    if (get_class($conn) !== AphrontMySQLiDatabaseConnection::class ||
        $conn->isInsideTransaction()) {
      throw new RuntimeException('organization_existing_transaction_refused');
    }
    $default = queryfx_one($conn, 'SELECT @@SESSION.transaction_isolation AS isolationLevel');
    $restore = array(
      'READ-UNCOMMITTED' => 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED' => 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ' => 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      'SERIALIZABLE' => 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE',
    );
    if (!$default || !isset($restore[$default['isolationLevel']])) {
      throw new RuntimeException('organization_transaction_isolation_unknown');
    }
    $queued = false;
    $this->connection = $conn;
    try {
      // One next transaction only. Do not change SESSION isolation on a pooled
      // native connection. Metadata/source reads before the mutex must not pin
      // an older RR view for later organization projection queries.
      queryfx($conn, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
      $queued = true;
      $conn->openTransaction();
      $queued = false;
      $this->transactionNonce = bin2hex(random_bytes(16));
      // Planning identities are not authority. Accounts are current-read locked
      // in canonical order before the organization mutex or mutable sources.
      $subjects[] = $this->context->getUserPHID();
      $subjects = array_unique($subjects); sort($subjects, SORT_STRING);
      foreach ($subjects as $phid) {
        $this->accounts[$phid] =
          PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($phid, $conn);
      }
      $this->actorSnapshot = PhorgeGovernanceNativeIdentitySource::lockCurrent(
        $this->context, $conn);
      foreach (array('guard', 'unit', 'member', 'appointment', 'primarydepartment',
        'operation', 'audit', 'approval') as $table) {
        $key = in_array($table, array('operation', 'approval'), true)
          ? ($table === 'operation' ? 'operationID' : 'approvalID') : 'id';
        // A consistent COUNT here would create a stale RR read view before
        // waiting for the organization mutex. MDL must be a current read.
        queryfx_one($conn, 'SELECT %C FROM %T WHERE 1=0 LOCK IN SHARE MODE',
          $key, $this->table($table));
      }
      $database_row = queryfx_one($conn, 'SELECT DATABASE() AS n');
      $database = $database_row['n'];
      $rows = queryfx_all($conn,
        'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES '.
        'WHERE TABLE_SCHEMA=%s AND TABLE_NAME IN (%Ls)', $database,
        array_map(array($this, 'table'), array('guard', 'unit', 'member',
          'appointment', 'primarydepartment', 'operation', 'audit', 'approval')));
      if (count($rows) !== 8) {
        throw new RuntimeException('organization_schema_missing');
      }
      foreach ($rows as $row) {
        if ($row['ENGINE'] !== 'InnoDB') {
          throw new RuntimeException('organization_transactional_schema_required');
        }
      }
      $guard = queryfx_one($conn,
        'SELECT * FROM %T WHERE id=1 FOR UPDATE', $this->table('guard'));
      if (!$guard || self::version($guard['version']) < 1) {
        throw new RuntimeException('organization_guard_refused');
      }
      PhorgeGovernanceNativeIdentitySource::revalidate($this->actorSnapshot, $conn);
    } catch (Throwable $error) {
      try { $this->finish(false); }
      finally {
        // START failure must not leave a next-transaction override queued for
        // another native caller. Restore the known SESSION default; failure is
        // propagated rather than accepting uncertain pooled-connection state.
        if ($queued) { queryfx($conn, $restore[$default['isolationLevel']]); }
      }
      throw $error;
    }
  }

  private function finish($commit) {
    $conn = $this->connection;
    try {
      if ($conn !== null) {
        if ($commit) {
          self::assertEnabled();
          PhorgeGovernanceNativeIdentitySource::revalidate($this->actorSnapshot, $conn);
          if ($this->readStartedAt !== null) {
            $now = $this->now();
            if ($now < $this->readStartedAt ||
                ($this->readDeadline !== null && $now >= $this->readDeadline)) {
              throw new RuntimeException('organization_read_authority_expired');
            }
          }
          $conn->saveTransaction();
        } else if ($conn->isInsideTransaction()) {
          $conn->killTransaction();
        }
      }
    } catch (Throwable $error) {
      if ($conn !== null && $conn->isInsideTransaction()) { $conn->killTransaction(); }
      throw $error;
    } finally {
      $this->connection = null;
      $this->actorSnapshot = null;
      $this->transactionNonce = null;
      $this->accounts = array();
      $this->readStartedAt = null;
      $this->readDeadline = null;
    }
  }

  public static function version($value) {
    if (is_int($value)) { $value = (string)$value; }
    if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) ||
        strlen($value) > strlen((string)PHP_INT_MAX) ||
        (strlen($value) === strlen((string)PHP_INT_MAX) &&
          strcmp($value, (string)PHP_INT_MAX) >= 0)) {
      throw new RuntimeException('organization_version_refused');
    }
    return (int)$value;
  }

  private function now() {
    $row = queryfx_one($this->connection, 'SELECT UNIX_TIMESTAMP() n');
    return self::version($row['n']);
  }

  private function actor() { return $this->actorSnapshot->getUserPHID(); }

  private function requireAdmin() {
    PhorgeGovernanceNativeIdentitySource::revalidate(
      $this->actorSnapshot, $this->connection);
    if (!$this->actorSnapshot->isSystemAdmin()) {
      throw new RuntimeException('organization_system_admin_required');
    }
  }

  private function unit($id) {
    $unit = queryfx_one($this->connection,
      'SELECT * FROM %T WHERE unitID=%s FOR UPDATE', $this->table('unit'), $id);
    if (!$unit || $unit['unitID'] !== $id ||
        !in_array($unit['kind'], array('team', 'department'), true) ||
        !in_array($unit['state'], array('active', 'archived'), true) ||
        self::version($unit['version']) < 1) {
      throw new RuntimeException('organization_unit_refused');
    }
    return $unit;
  }

  private function relationship($table, $unit, $user) {
    $row = queryfx_one($this->connection,
      'SELECT * FROM %T WHERE unitID=%s AND userPHID=%s FOR UPDATE',
      $this->table($table), $unit, $user);
    if ($row && ($row['unitID'] !== $unit || $row['userPHID'] !== $user ||
        !in_array($row['state'], array('active', 'ended', 'suspended'), true) ||
        self::version($row['version']) < 1)) {
      throw new RuntimeException('organization_relationship_refused');
    }
    return $row;
  }

  public static function isCurrentRelation(array $row, $now) {
    return $row['state'] === 'active' && self::version($row['validFrom']) <= $now &&
      ($row['validUntil'] === null || self::version($row['validUntil']) > $now);
  }

  private function authorize($unit) {
    PhorgeGovernanceNativeIdentitySource::revalidate(
      $this->actorSnapshot, $this->connection);
    if ($this->actorSnapshot->isSystemAdmin()) { return; }
    if ($unit['kind'] !== 'team' || $unit['state'] !== 'active') {
      throw new RuntimeException('organization_actor_forbidden');
    }
    $member = $this->relationship('member', $unit['unitID'], $this->actor());
    $appointment = $this->relationship('appointment', $unit['unitID'], $this->actor());
    $now = $this->now();
    if (!$member || !$appointment || $appointment['role'] !== 'team_admin' ||
        !self::isCurrentRelation($member, $now) || !self::isCurrentRelation($appointment, $now)) {
      throw new RuntimeException('organization_actor_forbidden');
    }
  }

  private function replay($operation, $digest) {
    $row = queryfx_one($this->connection,
      'SELECT * FROM %T WHERE operationID=%s LOCK IN SHARE MODE',
      $this->table('operation'), $operation);
    if (!$row) { return null; }
    if ($row['operationID'] !== $operation || $row['actorPHID'] !== $this->actor() ||
        !hash_equals($row['requestDigest'], $digest) || strlen($row['resultJSON']) > 16384) {
      throw new RuntimeException('organization_operation_conflict');
    }
    $result = phutil_json_decode($row['resultJSON']);
    $keys = array_keys($result); sort($keys);
    if ($keys !== array('replayed', 'status', 'versions') ||
        $result['status'] !== 'committed' || $result['replayed'] !== false ||
        !is_array($result['versions'])) {
      throw new RuntimeException('organization_operation_refused');
    }
    $result['replayed'] = true;
    return $result;
  }

  private function audit($operation, $action, $unit, $subject, $before, $after) {
    queryfx($this->connection,
      'INSERT INTO %T (operationID,actorPHID,action,unitID,subjectPHID,'.
      'beforeJSON,afterJSON,createdAt) VALUES (%s,%s,%s,%s,%ns,%s,%s,%d)',
      $this->table('audit'), $operation, $this->actor(), $action, $unit, $subject,
      phutil_json_encode($before), phutil_json_encode($after), $this->now());
  }

  private function record($operation, $digest, array $versions) {
    queryfx($this->connection, 'UPDATE %T SET version=version+1 WHERE id=1',
      $this->table('guard'));
    $result = array('status' => 'committed', 'replayed' => false, 'versions' => $versions);
    queryfx($this->connection,
      'INSERT INTO %T (operationID,actorPHID,requestDigest,resultJSON,createdAt) '.
      'VALUES (%s,%s,%s,%s,%d)', $this->table('operation'), $operation,
      $this->actor(), $digest, phutil_json_encode($result), $this->now());
    return $result;
  }

  public function createUnit(array $input) {
    $input = PhorgeOrganizationRequest::unit($input);
    $this->begin(array());
    try {
      $this->requireAdmin();
      $digest = hash('sha256', phutil_json_encode(array('create-unit', $input)));
      $replay = $this->replay($input['operation_id'], $digest);
      if ($replay !== null) { $this->finish(true); return $replay; }
      $cursor = $input['parent_id']; $seen = array($input['unit_id'] => true);
      while ($cursor !== null) {
        if (isset($seen[$cursor]) || count($seen) >= 64) {
          throw new RuntimeException('organization_parent_cycle_or_budget');
        }
        $seen[$cursor] = true; $parent = $this->unit($cursor);
        if ($parent['kind'] !== $input['kind'] || $parent['state'] !== 'active') {
          throw new RuntimeException('organization_parent_refused');
        }
        $cursor = $parent['parentID'];
      }
      queryfx($this->connection,
        'INSERT INTO %T (unitID,kind,state,name,parentID,version) '.
        'VALUES (%s,%s,%s,%s,%ns,1)', $this->table('unit'),
        $input['unit_id'], $input['kind'], 'active', $input['name'], $input['parent_id']);
      $this->audit($input['operation_id'], 'unit.create', $input['unit_id'], null,
        null, $this->unit($input['unit_id']));
      $result = $this->record($input['operation_id'], $digest, array($input['unit_id'] => 1));
      $this->requireAdmin(); $this->finish(true); return $result;
    } catch (Throwable $error) { $this->finish(false); throw $error; }
  }

  public function assignPrimaryDepartment(array $input) {
    $input = PhorgeOrganizationRequest::primaryDepartment($input);
    $this->begin(array($input['user_phid']));
    try {
      $this->requireAdmin();
      if ($this->accounts[$input['user_phid']]['eligible'] !== true) {
        throw new RuntimeException('organization_subject_ineligible');
      }
      $unit = $this->unit($input['unit_id']);
      if ($unit['kind'] !== 'department' || $unit['state'] !== 'active') {
        throw new RuntimeException('organization_department_required');
      }
      $digest = hash('sha256', phutil_json_encode(array('primary-department', $input)));
      $replay = $this->replay($input['operation_id'], $digest);
      if ($replay !== null) { $this->finish(true); return $replay; }
      $before = queryfx_one($this->connection,
        'SELECT * FROM %T WHERE userPHID=%s FOR UPDATE',
        $this->table('primarydepartment'), $input['user_phid']);
      $version = $before ? self::version($before['version']) : 0;
      if ($version !== $input['expected_version']) {
        throw new RuntimeException('organization_stale_version');
      }
      if ($before) {
        queryfx($this->connection,
          'UPDATE %T SET unitID=%s,version=version+1 WHERE userPHID=%s',
          $this->table('primarydepartment'), $input['unit_id'], $input['user_phid']);
      } else {
        queryfx($this->connection,
          'INSERT INTO %T (userPHID,unitID,version) VALUES (%s,%s,1)',
          $this->table('primarydepartment'), $input['user_phid'], $input['unit_id']);
      }
      $this->audit($input['operation_id'], 'department.assign', $input['unit_id'],
        $input['user_phid'], $before, array('unitID' => $input['unit_id'], 'version' => $version + 1));
      $result = $this->record($input['operation_id'], $digest,
        array($input['user_phid'] => $version + 1));
      $this->requireAdmin(); $this->finish(true); return $result;
    } catch (Throwable $error) { $this->finish(false); throw $error; }
  }

  public function execute(array $input) {
    $input = PhorgeOrganizationRequest::normalize($input);
    $subjects = array();
    foreach ($input['mutations'] as $mutation) {
      $subjects[] = $mutation['target_user'];
    }
    $approvals = array();
    foreach ($input['mutations'] as $mutation) {
      if (strncmp($mutation['action'], 'appointment.', 12) === 0) {
        $approvals[] = $mutation['approval_id'];
      }
    }
    if ($approvals) {
      $conn = id(new PhabricatorUser())->establishConnection('w');
      $subjects = array_merge($subjects,
        PhorgeGovernanceNativeApprovalAdapter::planningPHIDs($approvals, $conn));
    }
    $this->begin($subjects);
    try {
      if ($approvals) { $this->requireAdmin(); }
      $units = array();
      foreach ($input['mutations'] as $mutation) {
        $unit = $this->unit($mutation['team']);
        if ($unit['kind'] !== 'team' || $unit['state'] !== 'active') {
          throw new RuntimeException('organization_team_required');
        }
        $this->authorize($unit);
        $units[$unit['unitID']] = $unit;
      }
      $digest = hash('sha256', phutil_json_encode(array('member-mutations', $input)));
      $replay = $this->replay($input['operation_id'], $digest);
      if ($replay !== null) {
        foreach ($units as $unit) { $this->authorize($unit); }
        $this->finish(true); return $replay;
      }
      $consumed = array();
      foreach ($input['mutations'] as $mutation) {
        $unit = $units[$mutation['team']];
        if (self::version($unit['version']) !== $mutation['expected_team_version']) {
          throw new RuntimeException('organization_stale_version');
        }
        $before = $this->relationship('member', $unit['unitID'], $mutation['target_user']);
        $appointment = $this->relationship('appointment', $unit['unitID'], $mutation['target_user']);
        $member_version = $before ? self::version($before['version']) : 0;
        $appointment_version = $appointment ? self::version($appointment['version']) : 0;
        if ($member_version !== $mutation['expected_member_version'] ||
            $appointment_version !== $mutation['expected_appointment_version']) {
          throw new RuntimeException('organization_stale_version');
        }
        if (strncmp($mutation['action'], 'appointment.', 12) === 0) {
          $this->requireAdmin();
          $adapter = PhorgeGovernanceNativeApprovalAdapter::forLockedService($this);
          $adapter->consume($mutation['approval_id'], $input['operation_id'],
            $unit['unitID'], $mutation['action'], $mutation['target_user'],
            $mutation['valid_from'], $mutation['valid_until']);
          $consumed[] = $mutation['approval_id'];
          $this->applyAppointment($input['operation_id'], $mutation, $before, $appointment);
          continue;
        }
        if (($mutation['action'] === 'member.add') === ($before !== null)) {
          throw new RuntimeException('organization_member_shape_refused');
        }
        $timed = in_array($mutation['action'], array('member.add', 'member.update'), true);
        if ($timed) {
          $identity = $this->accounts[$mutation['target_user']];
          if ($identity['userPHID'] !== $mutation['target_user'] || $identity['eligible'] !== true) {
            throw new RuntimeException('organization_subject_ineligible');
          }
          $state = 'active'; $from = $mutation['valid_from']; $until = $mutation['valid_until'];
          if ($until !== null && $until <= $this->now()) {
            throw new RuntimeException('organization_period_expired');
          }
        } else {
          $state = $mutation['action'] === 'member.end' ? 'ended' : 'suspended';
          $from = $before['validFrom']; $until = $before['validUntil'];
        }
        $this->preserveAppointment($appointment, $state, $from, $until);
        if ($before === null) {
          queryfx($this->connection,
            'INSERT INTO %T (unitID,userPHID,state,validFrom,validUntil,version) '.
            'VALUES (%s,%s,%s,%d,%nd,1)', $this->table('member'), $unit['unitID'],
            $mutation['target_user'], $state, $from, $until);
        } else {
          queryfx($this->connection,
            'UPDATE %T SET state=%s,validFrom=%d,validUntil=%nd,version=version+1 '.
            'WHERE unitID=%s AND userPHID=%s', $this->table('member'), $state,
            $from, $until, $unit['unitID'], $mutation['target_user']);
        }
        $this->audit($input['operation_id'], $mutation['action'], $unit['unitID'],
          $mutation['target_user'], $before,
          $this->relationship('member', $unit['unitID'], $mutation['target_user']));
      }
      $versions = array();
      foreach ($units as $unit) {
        queryfx($this->connection, 'UPDATE %T SET version=version+1 WHERE unitID=%s',
          $this->table('unit'), $unit['unitID']);
        $versions[$unit['unitID']] = self::version($unit['version']) + 1;
      }
      $result = $this->record($input['operation_id'], $digest, $versions);
      foreach ($units as $unit) { $this->authorize($unit); }
      foreach ($input['mutations'] as $mutation) {
        if (in_array($mutation['action'], array('member.add', 'member.update'), true)) {
          $member = $this->relationship('member', $mutation['team'], $mutation['target_user']);
          if ($member['validUntil'] !== null && self::version($member['validUntil']) <= $this->now()) {
            throw new RuntimeException('organization_period_expired');
          }
        }
      }
      foreach ($consumed as $approval) {
        PhorgeGovernanceNativeApprovalAdapter::forLockedService($this)
          ->assertCurrent($approval, $input['operation_id']);
      }
      $this->finish(true); return $result;
    } catch (Throwable $error) { $this->finish(false); throw $error; }
  }


  private function applyAppointment($operation, array $mutation, $member, $before) {
    $grant = $mutation['action'] === 'appointment.grant';
    if ($grant) {
      if ($this->accounts[$mutation['target_user']]['eligible'] !== true || !$member ||
          !self::isCurrentRelation($member, $this->now()) ||
          $mutation['valid_from'] < self::version($member['validFrom']) ||
          ($member['validUntil'] !== null && ($mutation['valid_until'] === null ||
            $mutation['valid_until'] > self::version($member['validUntil']))) ||
          ($mutation['valid_until'] !== null && $mutation['valid_until'] <= $this->now())) {
        throw new RuntimeException('organization_appointment_prerequisite');
      }
      $state = 'active'; $from = $mutation['valid_from']; $until = $mutation['valid_until'];
    } else {
      if (!$before) { throw new RuntimeException('organization_appointment_missing'); }
      $state = 'ended'; $from = $before['validFrom']; $until = $before['validUntil'];
    }
    if ($before) {
      queryfx($this->connection,
        'UPDATE %T SET role=%s,state=%s,validFrom=%d,validUntil=%nd,version=version+1 '.
        'WHERE unitID=%s AND userPHID=%s', $this->table('appointment'), 'team_admin',
        $state, $from, $until, $mutation['team'], $mutation['target_user']);
    } else {
      queryfx($this->connection,
        'INSERT INTO %T (unitID,userPHID,role,state,validFrom,validUntil,version) '.
        'VALUES (%s,%s,%s,%s,%d,%nd,1)', $this->table('appointment'), $mutation['team'],
        $mutation['target_user'], 'team_admin', $state, $from, $until);
    }
    $this->audit($operation, $mutation['action'], $mutation['team'],
      $mutation['target_user'], $before,
      $this->relationship('appointment', $mutation['team'], $mutation['target_user']));
  }

  public function proposeApproval(array $input) {
    $input = PhorgeOrganizationRequest::approval($input);
    $subjects = array($input['target_user']);
    $conn = id(new PhabricatorUser())->establishConnection('w');
    $existing = queryfx_one($conn,
      'SELECT approvalID FROM organization_approval WHERE BINARY approvalID=BINARY %s',
      $input['approval_id']);
    if ($existing) {
      $subjects = array_merge($subjects,
        PhorgeGovernanceNativeApprovalAdapter::planningPHIDs(array($input['approval_id']), $conn));
    }
    $this->begin($subjects);
    try {
      $this->requireAdmin();
      $adapter = PhorgeGovernanceNativeApprovalAdapter::forLockedService($this);
      $result = $adapter->propose($input['approval_id'], $input['operation_id'],
        $input['team'], $input['action'], $input['target_user'],
        $input['valid_from'], $input['valid_until'], $input['ttl_seconds']);
      if (!$result['replayed']) {
        $this->audit($input['operation_id'], 'approval.propose', $input['team'],
          $input['target_user'], null, array('approvalID' => $input['approval_id']));
      }
      $adapter->assertCurrent($input['approval_id']);
      $this->finish(true); return $result;
    } catch (Throwable $error) { $this->finish(false); throw $error; }
  }

  public function approveApproval($approval) {
    if (!PhorgeGovernanceOrganizationRequest::identifier($approval)) {
      throw new InvalidArgumentException('organization_approval_request_refused');
    }
    $conn = id(new PhabricatorUser())->establishConnection('w');
    $subjects = PhorgeGovernanceNativeApprovalAdapter::planningPHIDs(array($approval), $conn);
    $this->begin($subjects);
    try {
      $this->requireAdmin();
      $adapter = PhorgeGovernanceNativeApprovalAdapter::forLockedService($this);
      $result = $adapter->approve($approval);
      if (!$result['replayed']) {
        $this->audit('approval:'.hash('sha256', $approval), 'approval.approve', '', null, null,
          array('approvalID' => $approval));
      }
      $adapter->assertCurrent($approval);
      $this->finish(true); return $result;
    } catch (Throwable $error) { $this->finish(false); throw $error; }
  }

  private function preserveAppointment($appointment, $state, $from, $until) {
    if (!$appointment) { return; }
    if ($appointment['role'] !== 'team_admin') {
      throw new RuntimeException('organization_appointment_refused');
    }
    $now = $this->now();
    if ($appointment['state'] !== 'active' ||
        ($appointment['validUntil'] !== null &&
          self::version($appointment['validUntil']) <= $now)) { return; }
    if ($state !== 'active' || $from > self::version($appointment['validFrom']) ||
        ($until !== null && ($appointment['validUntil'] === null ||
          $until < self::version($appointment['validUntil'])))) {
      throw new RuntimeException('organization_appointment_prerequisite');
    }
  }



  public function updateUnit(array $input) {
    $input = PhorgeOrganizationRequest::updateUnit($input);
    $this->begin(array());
    try {
      $this->requireAdmin();
      $before = $this->unit($input['unit_id']);
      $digest = hash('sha256', phutil_json_encode(array('update-unit', $input)));
      $replay = $this->replay($input['operation_id'], $digest);
      if ($replay !== null) { $this->finish(true); return $replay; }
      if (self::version($before['version']) !== $input['expected_version']) {
        throw new RuntimeException('organization_stale_version');
      }
      if ($before['state'] === 'archived' && $input['state'] === 'active') {
        throw new RuntimeException('organization_restore_workflow_required');
      }
      $cursor = $input['parent_id']; $seen = array($input['unit_id'] => true);
      while ($cursor !== null) {
        if (isset($seen[$cursor]) || count($seen) >= 64) {
          throw new RuntimeException('organization_parent_cycle_or_budget');
        }
        $seen[$cursor] = true; $parent = $this->unit($cursor);
        if ($parent['kind'] !== $before['kind'] || $parent['state'] !== 'active') {
          throw new RuntimeException('organization_parent_refused');
        }
        $cursor = $parent['parentID'];
      }
      queryfx($this->connection,
        'UPDATE %T SET name=%s,state=%s,parentID=%ns,version=version+1 WHERE unitID=%s',
        $this->table('unit'), $input['name'], $input['state'],
        $input['parent_id'], $input['unit_id']);
      $after = $this->unit($input['unit_id']);
      $this->audit($input['operation_id'], 'unit.update', $input['unit_id'], null, $before, $after);
      $result = $this->record($input['operation_id'], $digest,
        array($input['unit_id'] => self::version($after['version'])));
      $this->requireAdmin(); $this->finish(true); return $result;
    } catch (Throwable $error) { $this->finish(false); throw $error; }
  }

  private function startRead() { $this->readStartedAt = $this->now(); }

  private function fenceRead($until) {
    if ($until !== null) {
      $until = self::version($until);
      $this->readDeadline = $this->readDeadline === null
        ? $until : min($until, $this->readDeadline);
    }
  }

  private function fenceVisibleUnit($id) {
    if ($this->readStartedAt === null || $this->actorSnapshot->isSystemAdmin()) { return; }
    $member = $this->relationship('member', $id, $this->actor());
    $appointment = $this->relationship('appointment', $id, $this->actor());
    $now = $this->now();
    if (!$member || !$appointment || $appointment['role'] !== 'team_admin' ||
        !self::isCurrentRelation($member, $now) || !self::isCurrentRelation($appointment, $now)) {
      throw new RuntimeException('organization_read_authority_expired');
    }
    $this->fenceRead($member['validUntil']); $this->fenceRead($appointment['validUntil']);
  }

  private static function adminPredicate(AphrontDatabaseConnection $connection) {
    return qsprintf($connection, '%d=%d', 1, 1);
  }

  private function visibleSQL() {
    if ($this->actorSnapshot->isSystemAdmin()) {
      return self::adminPredicate($this->connection);
    }
    return qsprintf($this->connection,
      'u.kind=%s AND EXISTS (SELECT 1 FROM %T m JOIN %T a '.
      'ON a.unitID=m.unitID AND a.userPHID=m.userPHID '.
      'WHERE m.unitID=u.unitID AND m.userPHID=%s AND m.state=%s '.
      'AND a.state=%s AND a.role=%s AND m.version>0 AND a.version>0 '.
      'AND m.validFrom<=UNIX_TIMESTAMP() AND a.validFrom<=UNIX_TIMESTAMP() '.
      'AND (m.validUntil IS NULL OR m.validUntil>UNIX_TIMESTAMP()) '.
      'AND (a.validUntil IS NULL OR a.validUntil>UNIX_TIMESTAMP()))',
      'team', $this->table('member'), $this->table('appointment'),
      $this->actor(), 'active', 'active', 'team_admin');
  }

  private function visibleUnit($id) {
    $row = queryfx_one($this->connection,
      'SELECT u.* FROM %T u WHERE u.unitID=%s AND u.state=%s '.
      'AND u.kind IN (%Ls) AND u.version>0 AND (%Q)',
      $this->table('unit'), $id, 'active', array('team', 'department'), $this->visibleSQL());
    if ($row && $row['unitID'] === $id) {
      $this->fenceVisibleUnit($id);
      return $row;
    }
    return null;
  }

  private function publicUnit(array $row) {
    $this->fenceVisibleUnit($row['unitID']);
    return array('unit_id' => $row['unitID'], 'kind' => $row['kind'],
      'name' => $row['name'], 'version' => self::version($row['version']),
      'parent_id' => $row['parentID'] !== null && $this->visibleUnit($row['parentID'])
        ? $row['parentID'] : null);
  }

  private function limit($limit) {
    if (!is_int($limit) || $limit < 1 || $limit > 100) {
      throw new InvalidArgumentException('organization_limit_refused');
    }
  }

  public function readActorCapabilities() {
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      $this->begin(array());
      try {
        $result = array('manage_units' => $this->actorSnapshot->isSystemAdmin());
        $this->finish(true); return $result;
      } catch (Throwable $error) { $this->finish(false); throw $error; }

    } finally { unset($unguarded); }
  }

  public function readUnits($limit = 100, $after = null) {
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      $this->limit($limit);
      if ($after !== null && !PhorgeGovernanceOrganizationRequest::identifier($after, 64)) {
        throw new InvalidArgumentException('organization_cursor_refused');
      }
      $this->begin(array());
      try {
        $this->startRead();
        $rows = queryfx_all($this->connection,
          'SELECT u.* FROM %T u WHERE u.state=%s AND u.kind IN (%Ls) '.
          'AND u.version>0 AND u.unitID>%s AND (%Q) ORDER BY u.unitID LIMIT %d',
          $this->table('unit'), 'active', array('team', 'department'),
          $after === null ? '' : $after, $this->visibleSQL(), $limit);
        $result = array(); foreach ($rows as $row) { $result[] = $this->publicUnit($row); }
        // A role expiring while producing a response does not return a stale list.
        foreach ($rows as $row) {
          if (!$this->visibleUnit($row['unitID'])) {
            throw new RuntimeException('organization_read_authority_expired');
          }
        }
        $this->finish(true); return $result;
      } catch (Throwable $error) { $this->finish(false); throw $error; }

    } finally { unset($unguarded); }
  }

  private function captureUnitCount() {
    if ($this->actorSnapshot->isSystemAdmin()) {
      $deadlines = qsprintf($this->connection, 'NULL AS memberDeadline,NULL AS appointmentDeadline');
    } else {
      $deadlines = qsprintf($this->connection,
        '(SELECT m.validUntil FROM %T m WHERE m.unitID=u.unitID AND m.userPHID=%s) AS memberDeadline,'.
        '(SELECT a.validUntil FROM %T a WHERE a.unitID=u.unitID AND a.userPHID=%s) AS appointmentDeadline',
        $this->table('member'), $this->actor(), $this->table('appointment'), $this->actor());
    }
    $row = queryfx_one($this->connection,
      'SELECT COUNT(*) n,MIN(q.memberDeadline) memberDeadline,MIN(q.appointmentDeadline) appointmentDeadline '.
      'FROM (SELECT %Q FROM %T u WHERE u.state=%s AND u.kind IN (%Ls) '.
      'AND u.version>0 AND (%Q)) q', $deadlines, $this->table('unit'), 'active',
      array('team', 'department'), $this->visibleSQL());
    $this->fenceRead($row['memberDeadline']); $this->fenceRead($row['appointmentDeadline']);
    return self::version($row['n']);
  }

  public function countUnits() {
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      $this->begin(array());
      try {
        $this->startRead();
        $result = $this->captureUnitCount(); $this->finish(true); return $result;
      } catch (Throwable $error) { $this->finish(false); throw $error; }

    } finally { unset($unguarded); }
  }

  public function readMembers($unitID, $limit = 100) {
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      $this->limit($limit);
      if (!PhorgeGovernanceOrganizationRequest::identifier($unitID, 64)) {
        throw new InvalidArgumentException('organization_unit_refused');
      }
      $this->begin(array());
      try {
        $this->startRead();
        $unit = $this->visibleUnit($unitID);
        if (!$unit) { throw new RuntimeException('organization_not_visible'); }
        if ($unit['kind'] === 'department') {
          $rows = queryfx_all($this->connection,
            'SELECT d.userPHID,d.version primaryVersion,p.isDisabled,p.isApproved,p.isSystemAgent,p.isMailingList '.
            'FROM %T d JOIN %R p ON p.phid=d.userPHID '.
            'WHERE d.unitID=%s AND d.version>0 ORDER BY d.userPHID LIMIT %d',
            $this->table('primarydepartment'), new PhabricatorUser(), $unitID, $limit);
        } else {
          $rows = queryfx_all($this->connection,
            'SELECT m.userPHID,m.version memberVersion,m.validFrom,m.validUntil,'.
            '(SELECT a.version FROM %T a WHERE a.unitID=m.unitID AND a.userPHID=m.userPHID) appointmentVersion,'.
            'p.isDisabled,p.isApproved,p.isSystemAgent,p.isMailingList '.
            'FROM %T m JOIN %R p ON p.phid=m.userPHID '.
            'WHERE m.unitID=%s AND m.state=%s AND m.version>0 '.
            'AND m.validFrom<=UNIX_TIMESTAMP() '.
            'AND (m.validUntil IS NULL OR m.validUntil>UNIX_TIMESTAMP()) '.
            'ORDER BY m.userPHID LIMIT %d', $this->table('appointment'), $this->table('member'),
            new PhabricatorUser(), $unitID, 'active', $limit);
        }
        $result = array();
        foreach ($rows as $row) {
          if (array_key_exists('validUntil', $row)) { $this->fenceRead($row['validUntil']); }
          $eligible = (string)$row['isDisabled'] === '0' &&
            (string)$row['isApproved'] === '1' && (string)$row['isSystemAgent'] === '0' &&
            (string)$row['isMailingList'] === '0';
          $result[] = array('user_phid' => $row['userPHID'], 'account_eligible' => $eligible,
            'member_version' => isset($row['memberVersion']) ? self::version($row['memberVersion']) : null,
            'appointment_version' => isset($row['appointmentVersion']) ? self::version($row['appointmentVersion']) : 0,
            'primary_department_version' => isset($row['primaryVersion']) ? self::version($row['primaryVersion']) : null,
            'valid_from' => isset($row['validFrom']) ? self::version($row['validFrom']) : null,
            'valid_until' => isset($row['validUntil']) ? self::version($row['validUntil']) : null);
        }
        if (!$this->visibleUnit($unitID)) {
          throw new RuntimeException('organization_read_authority_expired');
        }
        $this->finish(true); return $result;
      } catch (Throwable $error) { $this->finish(false); throw $error; }

    } finally { unset($unguarded); }
  }

  public function readUserTeams($subject, $limit = 100) {
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      $this->limit($limit);
      if (!PhorgeGovernanceOrganizationRequest::userID($subject)) {
        throw new InvalidArgumentException('organization_subject_refused');
      }
      $this->begin(array($subject));
      try {
        $this->startRead();
        $rows = queryfx_all($this->connection,
          'SELECT u.*,m.validUntil AS subjectMemberUntil FROM %T u JOIN %T m ON m.unitID=u.unitID '.
          'JOIN %R p ON p.phid=m.userPHID WHERE m.userPHID=%s '.
          'AND p.isApproved=1 AND p.isDisabled=0 AND p.isSystemAgent=0 '.
          'AND p.isMailingList=0 AND m.state=%s AND m.version>0 '.
          'AND m.validFrom<=UNIX_TIMESTAMP() '.
          'AND (m.validUntil IS NULL OR m.validUntil>UNIX_TIMESTAMP()) '.
          'AND u.kind=%s AND u.state=%s AND u.version>0 AND (%Q) '.
          'ORDER BY u.unitID LIMIT %d', $this->table('unit'), $this->table('member'),
          new PhabricatorUser(), $subject, 'active', 'team', 'active',
          $this->visibleSQL(), $limit);
        $result = array(); foreach ($rows as $row) {
          $this->fenceRead($row['subjectMemberUntil']);
          $result[] = $this->publicUnit($row);
        }
        foreach ($rows as $row) {
          if (!$this->visibleUnit($row['unitID'])) {
            throw new RuntimeException('organization_read_authority_expired');
          }
        }
        $this->finish(true); return $result;
      } catch (Throwable $error) { $this->finish(false); throw $error; }

    } finally { unset($unguarded); }
  }
}
