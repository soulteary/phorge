<?php

/** Same-schema N02 projections; isolated synthetic identities, never a provider. */
final class PhorgeGovernanceOrganizationReader {

  private $connection;
  private $config;
  private $connected = false;
  private $cleanupSucceeded = true;

  private function __construct() {}
  private function __clone() {}

  public function __serialize() {
    throw new RuntimeException('organization_reader_serialization_refused');
  }

  public function __unserialize(array $data) {
    throw new RuntimeException('organization_reader_serialization_refused');
  }

  public function __debugInfo() {
    return array('scope' => 'isolated-select-only-organization');
  }

  public static function newFixture(array $config) {
    PhorgeGovernanceOrganizationStore::assertFixtureScope();
    $config = PhorgeGovernanceOrganizationStore::validateFixtureConfig($config);
    $reader = new self();
    $reader->config = $config;
    $mode = (new mysqli_driver())->report_mode;
    try {
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      $reader->connection = mysqli_init();
      $reader->connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
      $reader->connection->options(MYSQLI_OPT_READ_TIMEOUT, 5);
      $reader->connection->real_connect($config['host'], $config['user'],
        $config['password'], $config['dbname'], $config['port']);
      $reader->connected = true;
      $reader->connection->set_charset('utf8mb4');
      $reader->verifyGrants();
      $reader->assertGuard();
      $reader->assertTransactionalTables();
      $reader->connection->query('SET SESSION max_execution_time=2000');
      return $reader;
    } catch (Throwable $error) {
      $reader->close();
      throw new RuntimeException('organization_reader_fixture_refused');
    } finally {
      mysqli_report($mode);
    }
  }

  private function verifyGrants() {
    $seen = false;
    foreach ($this->connection->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM) as $row) {
      $grant = $row[0];
      if (str_contains($grant, 'WITH GRANT OPTION')) {
        throw new RuntimeException('organization_reader_grants_refused');
      }
      if (preg_match('/\AGRANT USAGE ON \*\.\* TO /', $grant)) {
        continue;
      }
      if (!preg_match('/\AGRANT SELECT ON `([^`]+)`\.\* TO /', $grant, $match) ||
          $match[1] !== str_replace('_', '\\_', $this->config['dbname'])) {
        throw new RuntimeException('organization_reader_grants_refused');
      }
      $seen = true;
    }
    if (!$seen) {
      throw new RuntimeException('organization_reader_select_grant_missing');
    }
  }

  private function assertGuard() {
    $row = $this->connection->query('SELECT marker,schema_version,schema_sha256,'.
      'owner_nonce FROM fixture_guard WHERE id=1')->fetch_assoc();
    $schema = dirname(__DIR__, 4).'/support/governance/organization/schema.sql';
    if (!$row || $row['marker'] !== 'N03.ORG-WRITE.v1:synthetic-only' ||
        (int)$row['schema_version'] !== 1 ||
        !hash_equals(hash_file('sha256', $schema), $row['schema_sha256']) ||
        !hash_equals($this->config['owner_nonce'], $row['owner_nonce'])) {
      throw new RuntimeException('organization_reader_lineage_refused');
    }
  }

  private function assertTransactionalTables() {
    $statement = $this->connection->prepare('SELECT TABLE_NAME,ENGINE '.
      'FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN '.
      '(\'fixture_guard\',\'org_user\',\'org_unit\',\'org_member\',\'org_appointment\')');
    $statement->execute(array($this->config['dbname']));
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    if (count($rows) !== 5) {
      throw new RuntimeException('organization_reader_tables_refused');
    }
    foreach ($rows as $row) {
      if ($row['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException('organization_reader_engine_refused');
      }
    }
    $expected = array();
    foreach (array('org_user' => array('user_id'),
      'org_unit' => array('unit_id', 'parent_id', 'department_id', 'kind', 'state'),
      'org_member' => array('team_id', 'user_id', 'state'),
      'org_appointment' => array('team_id', 'user_id', 'role', 'state')) as $table => $columns) {
      foreach ($columns as $column) { $expected[$table.'.'.$column] = true; }
    }
    $statement = $this->connection->prepare('SELECT TABLE_NAME,COLUMN_NAME,COLLATION_NAME '.
      'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME IN '.
      '(\'org_user\',\'org_unit\',\'org_member\',\'org_appointment\') AND COLUMN_NAME IN '.
      '(\'user_id\',\'unit_id\',\'parent_id\',\'department_id\',\'team_id\',\'state\',\'kind\',\'role\')');
    $statement->execute(array($this->config['dbname']));
    $columns = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    foreach ($columns as $column) {
      $key = $column['TABLE_NAME'].'.'.$column['COLUMN_NAME'];
      if (!isset($expected[$key]) || $column['COLLATION_NAME'] !== 'ascii_bin') {
        throw new RuntimeException('organization_reader_identity_collation_refused');
      }
      unset($expected[$key]);
    }
    if ($expected) {
      throw new RuntimeException('organization_reader_identity_columns_missing');
    }
  }

  private static function eligible($alias) {
    return $alias.'.is_approved=1 AND '.$alias.'.is_disabled=0 AND '.
      $alias.'.is_system_agent=0 AND '.$alias.'.is_mailing_list=0 AND '.
      $alias.'.is_admin IN (0,1) AND '.$alias.'.version>=1';
  }

  private static function period($alias) {
    return $alias.'.valid_from<=UNIX_TIMESTAMP() AND ('.$alias.
      '.valid_until IS NULL OR '.$alias.'.valid_until>UNIX_TIMESTAMP())';
  }

  private static function equalBytes($left, $right) {
    // ascii_bin still has PAD SPACE semantics. Identity joins must compare
    // complete byte strings, not just case-sensitive collation weights.
    return 'CAST('.$left.' AS BINARY)=CAST('.$right.' AS BINARY)';
  }

  private static function literal($column, $value) {
    // Both inputs are fixed SQL fragments from this class, never user input.
    return self::equalBytes($column, "'".$value."'");
  }

  private static function visible($unit) {
    return '(('.self::literal($unit.'.kind', 'team').' OR '.
      self::literal($unit.'.kind', 'department').') AND ('.
      self::literal($unit.'.state', 'active').' OR '.
      self::literal($unit.'.state', 'archived').') AND '.$unit.
      '.version>=1 AND (actor.is_admin=1 OR ('.self::literal($unit.'.kind', 'team').
      ' AND '.self::literal($unit.'.state', 'active').
      ' AND EXISTS (SELECT 1 FROM org_appointment ap JOIN org_member am ON '.
      self::equalBytes('am.team_id', 'ap.team_id').' AND '.
      self::equalBytes('am.user_id', 'ap.user_id').' WHERE '.
      self::equalBytes('ap.team_id', $unit.'.unit_id').' AND '.
      self::equalBytes('ap.user_id', 'actor.user_id').' AND '.
      self::literal('ap.state', 'active').' AND '.self::literal('ap.role', 'team_admin').
      ' AND ap.version>=1 AND '.self::period('ap').' AND '.
      self::literal('am.state', 'active').' AND am.version>=1 AND '.self::period('am').'))))';
  }

  private function read($sql, array $parameters) {
    PhorgeGovernanceOrganizationStore::assertFixtureScope();
    if ($this->connection === null) {
      throw new RuntimeException('organization_reader_closed');
    }
    $statement = null;
    $mode = (new mysqli_driver())->report_mode;
    try {
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      $this->verifyGrants();
      $this->connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
      $this->connection->begin_transaction(MYSQLI_TRANS_START_READ_ONLY |
        MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
      $this->assertGuard();
      $statement = $this->connection->prepare($sql);
      $statement->execute($parameters);
      $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
      $statement->close();
      $statement = null;
      // Check actual engines while the native SELECT holds its table MDL.
      // A marker alone is not proof of transactional table semantics.
      $this->assertTransactionalTables();
      $this->connection->rollback();
      return $rows;
    } catch (Throwable $error) {
      if ($statement !== null) { $statement->close(); }
      try { $this->connection->rollback(); } catch (Throwable $ignored) {}
      throw new RuntimeException('organization_reader_observation_refused');
    } finally {
      mysqli_report($mode);
    }
  }

  public static function validateOptions($actor, $after, $limit, $query, $kind) {
    PhorgeGovernanceDirectoryReader::validateOptions($actor, 0, $limit, $query);
    if (!is_string($after) || ($after !== '' &&
        !preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', $after)) ||
        !in_array($kind, array('team', 'department'), true)) {
      throw new InvalidArgumentException('organization_reader_page_refused');
    }
  }

  public function listUnits($actor, $kind = 'team', $after = '', $limit = 50, $query = '') {
    self::validateOptions($actor, $after, $limit, $query, $kind);
    return $this->read('SELECT u.unit_id,u.kind,u.state,u.name,u.version,'.
      'CASE WHEN '.self::visible('p').' THEN p.unit_id ELSE NULL END AS parent_id,'.
      'CASE WHEN actor.is_admin=1 THEN d.unit_id ELSE NULL END AS department_id '.
      'FROM org_unit u LEFT JOIN org_unit p ON '.self::equalBytes('p.unit_id', 'u.parent_id').
      ' LEFT JOIN org_unit d ON '.self::equalBytes('d.unit_id', 'u.department_id').
      ' AND '.self::literal('d.kind', 'department').
      ' JOIN org_user actor ON '.self::equalBytes('actor.user_id', '?').
      ' WHERE '.self::eligible('actor').' AND '.self::equalBytes('u.kind', '?').
      ' AND '.self::visible('u').
      ' AND u.unit_id>? AND LOCATE(?,u.name)>0 ORDER BY u.unit_id LIMIT '.(int)$limit,
      array($actor, $kind, $after, $query));
  }

  public function selectUnits($actor, $query, $kind = 'team', $limit = 50) {
    return $this->listUnits($actor, $kind, '', $limit, $query);
  }

  /** One bounded authorized page; callers must not claim a full async export. */
  public function exportUnitsPage($actor, $kind = 'team', $after = '', $limit = 50) {
    return $this->listUnits($actor, $kind, $after, $limit);
  }

  public function countUnits($actor, $kind = 'team') {
    self::validateOptions($actor, '', 1, '', $kind);
    $rows = $this->read('SELECT COUNT(*) AS n FROM org_unit u '.
      'JOIN org_user actor ON '.self::equalBytes('actor.user_id', '?').
      ' WHERE '.self::eligible('actor').' AND '.self::equalBytes('u.kind', '?').
      ' AND '.self::visible('u'),
      array($actor, $kind));
    return (int)$rows[0]['n'];
  }

  public function listMembers($actor, $team, $after = '', $limit = 50) {
    self::validateOptions($actor, $team, $limit, '', 'team');
    if ($team === '') {
      throw new InvalidArgumentException('organization_reader_team_required');
    }
    PhorgeGovernanceDirectoryReader::validateOptions($actor, 0, $limit, '');
    if (!is_string($after) || ($after !== '' &&
        !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/', $after))) {
      throw new InvalidArgumentException('organization_reader_member_cursor_refused');
    }
    return $this->read('SELECT m.user_id,m.state,m.version,'.
      'CASE WHEN '.self::eligible('subject').' THEN 1 ELSE 0 END AS account_eligible '.
      'FROM org_member m JOIN org_unit u ON '.self::equalBytes('u.unit_id', 'm.team_id').
      ' JOIN org_user subject ON '.self::equalBytes('subject.user_id', 'm.user_id').
      ' JOIN org_user actor ON '.self::equalBytes('actor.user_id', '?').
      ' WHERE '.self::eligible('actor').' AND '.self::literal('u.kind', 'team').
      ' AND '.self::literal('u.state', 'active').' AND '.self::visible('u').
      ' AND '.self::equalBytes('u.unit_id', '?').' AND '.self::literal('m.state', 'active').
      ' AND m.version>=1 AND '.self::period('m').
      ' AND m.user_id>? ORDER BY m.user_id LIMIT '.(int)$limit,
      array($actor, $team, $after));
  }

  public function listUserTeams($actor, $subject, $after = '', $limit = 50) {
    self::validateOptions($actor, $after, $limit, '', 'team');
    PhorgeGovernanceDirectoryReader::validateOptions($subject, 0, 1, '');
    return $this->read('SELECT u.unit_id,u.name,u.version FROM org_unit u '.
      'JOIN org_member m ON '.self::equalBytes('m.team_id', 'u.unit_id').
      ' JOIN org_user s ON '.self::equalBytes('s.user_id', 'm.user_id').
      ' JOIN org_user actor ON '.self::equalBytes('actor.user_id', '?').
      ' WHERE '.self::eligible('actor').' AND '.self::eligible('s').
      ' AND '.self::literal('u.kind', 'team').' AND '.self::literal('u.state', 'active').
      ' AND '.self::visible('u').' AND '.self::equalBytes('m.user_id', '?').
      ' AND '.self::literal('m.state', 'active').' AND m.version>=1 AND '.self::period('m').
      ' AND u.unit_id>? ORDER BY u.unit_id LIMIT '.(int)$limit,
      array($actor, $subject, $after));
  }

  public function close() {
    $connection = $this->connection;
    $this->connection = null;
    if ($this->connected) {
      $this->connected = false;
      try { $connection->close(); }
      catch (Throwable $error) { $this->cleanupSucceeded = false; }
    }
    return $this->cleanupSucceeded;
  }

  public function __destruct() { $this->close(); }
}
