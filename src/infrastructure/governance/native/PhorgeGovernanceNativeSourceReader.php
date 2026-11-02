<?php

/**
 * Isolated, genuinely native-table observation. Not a native policy evaluator,
 * source-closure certificate, target writer or production connection factory.
 */
final class PhorgeGovernanceNativeSourceReader extends Phobject {

  private $connection;
  private $namespace;
  private $options;
  private $quickstartHash;
  private $active = false;
  private $started;
  private $observedAt;
  private $binding;
  private $rows = array();
  private $schemas = array();
  private $bytes = 0;

  private function __construct(mysqli $connection, array $config, $hash) {
    $this->connection = $connection;
    $this->namespace = $config['namespace'];
    $this->options = $config;
    unset($this->options['password'], $this->options['user']);
    $this->quickstartHash = $hash;
  }

  public static function validateConfig(array $config) {
    $required = array('host', 'port', 'namespace', 'user', 'password');
    $optional = array('row_limit', 'edge_limit', 'byte_limit', 'seconds');
    if (array_diff($required, array_keys($config)) ||
        array_diff(array_keys($config), array_merge($required, $optional)) ||
        $config['host'] !== '127.0.0.1' ||
        !is_int($config['port']) || $config['port'] < 1 || $config['port'] > 65535 ||
        !is_string($config['namespace']) ||
        !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/', $config['namespace']) ||
        !is_string($config['user']) || $config['user'] === '' ||
        !is_string($config['password'])) {
      throw new InvalidArgumentException('Invalid isolated native-source configuration.');
    }
    foreach (array('row_limit' => 64, 'edge_limit' => 256,
      'byte_limit' => 262144, 'seconds' => 5) as $key => $ceiling) {
      $value = array_key_exists($key, $config) ? $config[$key] : $ceiling;
      $minimum = $key === 'byte_limit' ? 1024 : 1;
      if (!is_int($value) || $value < $minimum || $value > $ceiling) {
        throw new InvalidArgumentException('Invalid native-source budget.');
      }
      $config[$key] = $value;
    }
    // Rebuild slots to avoid retaining caller references to credentials/budgets.
    $copy = array();
    foreach ($config as $key => $value) {
      $copy[$key] = $value;
    }
    return $copy;
  }

  private static function assertIsolatedScope() {
    if (PHP_SAPI !== 'cli' ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new RuntimeException('native_source_scope_refused');
    }
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
      'PHORGE_CONTROL_PLANE') as $variable) {
      $value = getenv($variable);
      if ($value !== false && $value !== '') {
        throw new RuntimeException('native_source_deployment_scope_refused');
      }
    }
    foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $file) {
      if (file_exists(dirname(__DIR__, 4).'/conf/local/'.$file)) {
        throw new RuntimeException('native_source_deployment_scope_refused');
      }
    }
  }

  public static function newFixture(array $config) {
    require_once dirname(__DIR__, 4).'/support/runtime/php-version.php';
    phorge_assert_supported_php_version(PHP_VERSION);
    self::assertIsolatedScope();
    $config = self::validateConfig($config);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = null;
    $reader = null;
    $stage = 'native_source_connection_refused';
    try {
      $connection = mysqli_init();
      $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
      $connection->options(MYSQLI_OPT_READ_TIMEOUT, $config['seconds']);
      $connection->real_connect(
        $config['host'], $config['user'], $config['password'], null, $config['port']);
      $connection->set_charset('utf8mb4');
      $hash = hash_file('sha256', dirname(__DIR__, 4).'/resources/sql/quickstart.sql');
      $reader = new self($connection, $config, $hash);
      $stage = 'native_source_grants_refused';
      $reader->verifyReadOnlyGrants();
      $stage = 'native_source_fixture_lineage_refused';
      $guard = $connection->query('SELECT marker, quickstart_sha256 FROM `'.
        $config['namespace'].'_governance`.`fixture_guard` WHERE id = 1')
        ->fetch_assoc();
      if (!$guard || $guard['marker'] !== 'N07.NATIVE-SOURCE.v1:synthetic-only' ||
          !hash_equals($hash, $guard['quickstart_sha256'])) {
        throw new RuntimeException('Native fixture lineage refused.');
      }
      $connection->query('SET SESSION MAX_EXECUTION_TIME = '.
        ($config['seconds'] * 1000));
      return $reader;
    } catch (Throwable $error) {
      if ($reader) {
        $reader->disconnect();
      } else if ($connection) {
        try { $connection->close(); } catch (Throwable $ignored) {}
      }
      // No previous exception: mysqli messages/arguments can contain secrets.
      throw new RuntimeException($stage);
    }
  }

  private function verifyReadOnlyGrants() {
    $allowed = array();
    foreach (array('user', 'project', 'maniphest', 'phriction',
      'policy', 'spaces', 'config', 'governance') as $app) {
      $allowed[$this->namespace.'_'.$app] = true;
    }
    $seen = array();
    foreach ($this->connection->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM)
      as $row) {
      $grant = $row[0];
      if (strpos($grant, 'WITH GRANT OPTION') !== false) {
        throw new RuntimeException('native_source_grant_option_refused');
      }
      if (preg_match('/\AGRANT USAGE ON \*\.\* TO /', $grant)) {
        continue;
      }
      if (!preg_match('/\AGRANT SELECT ON `([^`]+)`\.\* TO /', $grant, $match)) {
        throw new RuntimeException('native_source_select_only_required');
      }
      $database = str_replace('\\_', '_', $match[1]);
      if (!isset($allowed[$database]) ||
          $match[1] !== str_replace('_', '\\_', $database)) {
        throw new RuntimeException('native_source_exact_schema_grants_required');
      }
      $seen[$database] = true;
    }
    if (count($seen) !== count($allowed)) {
      throw new RuntimeException('native_source_missing_read_grants');
    }
  }

  public static function getNativeTableContract() {
    return array(
      'user.user' => array('id', 'phid', 'isAdmin', 'isDisabled', 'isApproved',
        'isSystemAgent', 'isMailingList', 'dateModified', 'accountSecret'),
      'user.phabricator_session' => array('id', 'userPHID', 'type', 'sessionKey',
        'sessionStart', 'sessionExpires', 'isPartial'),
      'user.edge' => array('src', 'type', 'dst', 'dateCreated', 'seq', 'dataID'),
      'project.project' => array('id', 'phid', 'viewPolicy', 'editPolicy',
        'joinPolicy', 'parentProjectPHID', 'milestoneNumber', 'hasSubprojects',
        'isMembershipLocked', 'spacePHID', 'status', 'properties', 'dateModified',
        'projectPath', 'projectDepth', 'projectPathKey'),
      'project.edge' => array('src', 'type', 'dst', 'dateCreated', 'seq', 'dataID'),
      'maniphest.maniphest_task' => array('id', 'phid', 'authorPHID', 'ownerPHID',
        'status', 'viewPolicy', 'editPolicy', 'spacePHID', 'dateModified',
        'title', 'description', 'properties'),
      'phriction.phriction_document' => array('id', 'phid', 'slug', 'depth',
        'status', 'viewPolicy', 'editPolicy', 'spacePHID', 'contentPHID',
        'editedEpoch', 'maxVersion'),
      'policy.policy' => array('id', 'phid', 'rules', 'defaultAction', 'dateModified'),
      'policy.policy_namedpolicy' => array('id', 'phid', 'effectivePolicy',
        'viewPolicy', 'editPolicy', 'targetObjectType', 'dateModified'),
      'spaces.spaces_namespace' => array('id', 'phid', 'viewPolicy', 'editPolicy',
        'isDefaultNamespace', 'isArchived', 'dateModified'),
      'config.config_entry' => array('id', 'namespace', 'configKey', 'value',
        'isDeleted', 'dateModified'),
    );
  }

  public function capture(array $binding) {
    $this->beginCapture($binding);
    return $this->finishCapture();
  }

  /** Opens a new snapshot and observes accounts; permits no caller connection. */
  public function beginCapture(array $binding) {
    if ($this->active) {
      throw new RuntimeException('native_source_capture_already_open');
    }
    try {
      self::assertIsolatedScope();
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      $validated = new PhorgeGovernanceReadSetSnapshot($binding, array());
      $binding = $validated->getBinding();
      if (PhorgeGovernanceReadSetValidator::getRequiredSources($binding) === null) {
        throw new RuntimeException('Unsupported operation.');
      }
      $types = array('PhabricatorProject' => 'PROJ',
        'ManiphestTask' => 'TASK', 'PhrictionDocument' => 'WIKI');
      foreach (array('actor', 'executor', 'approver') as $role) {
        if (!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/', $binding[$role]) ||
            !preg_match('/\A[a-f0-9]{64}\z/', $binding[$role.'_session'])) {
          throw new RuntimeException('Invalid native principal binding.');
        }
      }
      if (!preg_match('/\APHID-'.$types[$binding['object_class']].
          '-[a-zA-Z0-9]{20}\z/', $binding['target'])) {
        throw new RuntimeException('Invalid native target binding.');
      }
      $this->started = hrtime(true);
      $this->verifyReadOnlyGrants();
      $this->connection->autocommit(true);
      $this->connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
      $this->connection->query(
        'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
      $this->active = true;
      $this->observedAt = time();
      $this->binding = $binding;
      $this->rows = array();
      $this->schemas = array();
      $this->bytes = 0;
      $this->readTable('user.user');
    } catch (Throwable $error) {
      $this->abortCapture();
      throw new RuntimeException('native_source_begin_refused_or_incomplete');
    }
  }

  public function finishCapture() {
    if (!$this->active) {
      throw new RuntimeException('native_source_no_open_capture');
    }
    try {
      self::assertIsolatedScope();
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      foreach (self::getNativeTableContract() as $table => $columns) {
        if (!isset($this->rows[$table])) {
          $this->readTable($table);
        }
      }
      $this->assertTimeBudget();
      $capture = $this->buildCapture();
      $this->assertTimeBudget();
      return $capture;
    } catch (Throwable $error) {
      throw new RuntimeException('native_source_capture_refused_or_incomplete');
    } finally {
      if (!$this->abortCapture()) {
        throw new RuntimeException('native_source_snapshot_cleanup_failed');
      }
    }
  }

  /** Isolated bridge only: observe sources without ending the owned snapshot. */
  public function sampleOpenReadOnlySnapshot() {
    if (!$this->active) {
      throw new RuntimeException('native_source_no_open_capture');
    }
    try {
      self::assertIsolatedScope();
      $this->verifyReadOnlyGrants();
      $this->rows = array();
      $this->schemas = array();
      $this->bytes = 0;
      foreach (self::getNativeTableContract() as $table => $columns) {
        $this->readTable($table);
      }
      $this->assertTimeBudget();
      $capture = $this->buildCapture();
      $this->assertTimeBudget();
      return $capture;
    } catch (Throwable $error) {
      $this->abortCapture();
      throw new RuntimeException('native_source_open_snapshot_refused');
    }
  }

  private function abortCapture() {
    $clean = true;
    if ($this->active) {
      try {
        $this->connection->rollback();
      } catch (Throwable $ignored) {
        $clean = false;
        try { $this->connection->close(); } catch (Throwable $also_ignored) {}
        $this->connection = null;
      }
      $this->active = false;
    }
    $this->rows = array();
    $this->binding = null;
    return $clean;
  }

  private function remainingMilliseconds() {
    $remaining = $this->options['seconds'] * 1000000000 -
      (hrtime(true) - $this->started);
    if ($remaining <= 0) {
      throw new RuntimeException('native_source_time_budget');
    }
    return max(1, (int)floor($remaining / 1000000));
  }

  private function assertTimeBudget() {
    $this->remainingMilliseconds();
  }

  private function readTable($logical) {
    $this->assertTimeBudget();
    list($app, $table) = explode('.', $logical, 2);
    $database = $this->namespace.'_'.$app;
    $qualified = '`'.$database.'`.`'.$table.'`';
    $columns = self::getNativeTableContract()[$logical];
    $definitions = $this->connection->query('SHOW COLUMNS FROM '.$qualified)
      ->fetch_all(MYSQLI_ASSOC);
    $available = array_column($definitions, 'Type', 'Field');
    foreach ($columns as $column) {
      if (!isset($available[$column]) || !self::supportedColumn($column,
          $available[$column], $logical)) {
        throw new RuntimeException('native_source_schema_unsupported');
      }
    }
    $this->assertTimeBudget();
    $this->schemas[$logical] = hash('sha256', json_encode($definitions));
    $long = array('rules', 'value', 'properties');
    $hash = array('accountSecret', 'sessionKey', 'title', 'description',
      'projectPath', 'projectPathKey');
    $fields = array();
    foreach ($columns as $column) {
      if (in_array($column, $long, true)) {
        $fields[] = 'CASE WHEN OCTET_LENGTH(`'.$column.'`) <= 8192 '.
          'THEN `'.$column.'` ELSE NULL END AS `'.$column.'`';
        $fields[] = 'OCTET_LENGTH(`'.$column.'`) AS `__bytes_'.$column.'`';
      } else if (in_array($column, $hash, true)) {
        $fields[] = 'SHA2(`'.$column.'`, 256) AS `'.$column.'Digest`';
      } else {
        $fields[] = '`'.$column.'`';
      }
    }
    $edge = $table === 'edge';
    $where = '';
    if ($edge) {
      $where = ' WHERE type IN ('.
        PhabricatorProjectProjectHasMemberEdgeType::EDGECONST.','.
        PhabricatorProjectMemberOfProjectEdgeType::EDGECONST.','.
        PhabricatorProjectMaterializedMemberEdgeType::EDGECONST.')';
    } else if ($logical === 'config.config_entry') {
      $where = " WHERE configKey IN ('phabricator.application-settings',".
        "'policy.locked','policy.allow-public','phabricator.require-approval')";
    }
    $limit = $edge ? $this->options['edge_limit'] : $this->options['row_limit'];
    $ordering = $edge ? 'src, type, dst' : 'id';
    $this->connection->query('SET SESSION MAX_EXECUTION_TIME = '.
      $this->remainingMilliseconds());
    $rows = $this->connection->query('SELECT '.implode(', ', $fields).
      ' FROM '.$qualified.$where.' ORDER BY '.$ordering.' LIMIT '.($limit + 1))
      ->fetch_all(MYSQLI_ASSOC);
    $this->assertTimeBudget();
    // SELECT holds the table metadata lock until this transaction finishes.
    // Checking the engine afterwards prevents a concurrent conversion between
    // a precheck and a nontransactional table read being accepted as coherent.
    $check = $this->connection->prepare('SELECT ENGINE FROM information_schema.TABLES '.
      'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $check->execute(array($database, $table));
    $engine = $check->get_result()->fetch_assoc();
    $check->close();
    $this->assertTimeBudget();
    if (!$engine || $engine['ENGINE'] !== 'InnoDB' || count($rows) > $limit) {
      throw new RuntimeException('native_source_engine_or_row_budget');
    }
    foreach ($rows as &$row) {
      foreach ($long as $column) {
        if (array_key_exists('__bytes_'.$column, $row)) {
          if ((int)$row['__bytes_'.$column] > 8192) {
            throw new RuntimeException('native_source_value_byte_budget');
          }
          unset($row['__bytes_'.$column]);
        }
      }
    }
    unset($row);
    $this->bytes += strlen(json_encode($rows, JSON_THROW_ON_ERROR));
    if ($this->bytes > $this->options['byte_limit']) {
      throw new RuntimeException('native_source_total_byte_budget');
    }
    $this->assertTimeBudget();
    $this->rows[$logical] = $rows;
  }

  private static function supportedColumn($column, $type, $logical) {
    if ($column === 'accountSecret') {
      return strtolower($type) === 'binary(64)';
    }
    if ($column === 'projectPathKey') {
      return strtolower($type) === 'binary(4)';
    }
    if ($column === 'sessionKey') {
      return strtolower($type) === 'varchar(64)';
    }
    if (in_array($column, array('rules', 'properties', 'value'), true)) {
      return strtolower($type) === 'longtext';
    }
    if (in_array($column, array('title', 'description'), true)) {
      return strtolower($type) === 'longtext';
    }
    $integer = array('id', 'isAdmin', 'isDisabled', 'isApproved', 'isSystemAgent',
      'isMailingList', 'dateModified', 'dateCreated', 'sessionStart',
      'sessionExpires', 'isPartial', 'seq', 'dataID', 'milestoneNumber',
      'hasSubprojects', 'isMembershipLocked', 'depth', 'projectDepth', 'editedEpoch',
      'maxVersion', 'isDefaultNamespace', 'isArchived', 'isDeleted');
    if (in_array($column, $integer, true) ||
        ($column === 'type' && substr($logical, -5) === '.edge')) {
      return (bool)preg_match('/\A(?:tinyint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?\z/',
        strtolower($type));
    }
    return preg_match('/\A(?:varbinary|varchar)\(([0-9]+)\)\z/',
      strtolower($type), $match) && (int)$match[1] > 0 && (int)$match[1] <= 255;
  }

  private static function fingerprint($value) {
    return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
  }

  private function buildCapture() {
    $unknown = array('native_rule_evaluator_unimplemented',
      'native_hierarchy_evaluator_unimplemented',
      'runtime_code_and_local_configuration_not_observed',
      'organization_runtime_sources_unimplemented');
    $projects = array_column($this->rows['project.project'], null, 'phid');
    $accounts = array_column($this->rows['user.user'], null, 'phid');
    $tables = array('PhabricatorProject' => 'project.project',
      'ManiphestTask' => 'maniphest.maniphest_task',
      'PhrictionDocument' => 'phriction.phriction_document');
    $objects = array_column($this->rows[$tables[$this->binding['object_class']]],
      null, 'phid');
    $target = isset($objects[$this->binding['target']]) ?
      $objects[$this->binding['target']] : null;
    if ($target === null) {
      $unknown[] = 'missing_native_target';
    }
    foreach ($this->rows['policy.policy'] as $policy) {
      $rules = json_decode($policy['rules'], true);
      if (!is_array($rules) || !array_is_list($rules)) {
        $unknown[] = 'malformed_native_policy_rules';
        continue;
      }
      foreach ($rules as $rule) {
        if (!is_array($rule) || !isset($rule['rule'], $rule['action']) ||
            !in_array($rule['action'], array('allow', 'deny'), true) ||
            !array_key_exists('value', $rule) ||
            !in_array($rule['rule'], array('PhabricatorUsersPolicyRule',
              'PhabricatorProjectsPolicyRule', 'PhabricatorProjectsAllPolicyRule',
              'PhabricatorProjectMembersPolicyRule'), true)) {
          $unknown[] = 'unsupported_native_policy_rule';
          continue;
        }
        $subject_reason = self::ruleSubjectReason($rule, $accounts, $projects);
        if ($subject_reason !== null) {
          $unknown[] = $subject_reason;
        }
      }
    }
    // Presence checks are not rule evaluation: missing native dependencies are
    // recorded explicitly instead of being silently treated as empty grants.
    $known_policies = array_fill_keys(array_merge(
      array('public', 'users', 'admin', 'no-one'), array_keys($accounts),
      array_keys($projects),
      array_column($this->rows['policy.policy'], 'phid'),
      array_column($this->rows['policy.policy_namedpolicy'], 'phid')), true);
    foreach (array('project.project', 'maniphest.maniphest_task',
      'phriction.phriction_document', 'spaces.spaces_namespace',
      'policy.policy_namedpolicy') as $table) {
      foreach ($this->rows[$table] as $row) {
        foreach (array('viewPolicy', 'editPolicy', 'joinPolicy', 'effectivePolicy')
          as $field) {
          if (isset($row[$field]) && !isset($known_policies[$row[$field]])) {
            $unknown[] = 'missing_native_policy_reference';
          }
        }
      }
    }
    foreach ($projects as $project) {
      $seen = array();
      $cursor = $project;
      while ($cursor['parentProjectPHID'] !== null) {
        $parent = $cursor['parentProjectPHID'];
        if (isset($seen[$parent])) {
          $unknown[] = 'cyclic_project_parent';
          break;
        }
        $seen[$parent] = true;
        if (!isset($projects[$parent])) {
          $unknown[] = 'missing_project_parent';
          break;
        }
        $cursor = $projects[$parent];
      }
    }
    $sources = array();
    $observed = 0;
    $unknown_sources = 0;
    foreach (PhorgeGovernanceReadSetValidator::getRequiredSources($this->binding)
      as $role => $required) {
      $parts = explode('.', $role, 2);
      $actor = isset($accounts[$this->binding[$parts[0]] ?? '']) ?
        $accounts[$this->binding[$parts[0]]] : null;
      $eligibility = 'unknown';
      $data = null;
      switch ($required['kind']) {
        case 'user.account':
          $data = $actor;
          $eligibility = $actor && (int)$actor['isApproved'] === 1 &&
            (int)$actor['isDisabled'] === 0 && (int)$actor['isSystemAgent'] === 0 &&
            (int)$actor['isMailingList'] === 0 ? 'eligible' : 'ineligible';
          if ($parts[0] === 'approver' && (!$actor || !(int)$actor['isAdmin'])) {
            $eligibility = 'ineligible';
          }
          if ($actor === null) {
            $unknown[] = 'missing_native_account';
          }
          break;
        case 'auth.session':
          foreach ($this->rows['user.phabricator_session'] as $session) {
            if (hash_equals($required['id'], $session['sessionKeyDigest'])) {
              $data = $session;
              break;
            }
          }
          $eligibility = $data &&
            $data['userPHID'] === $this->binding[$parts[0]] &&
            $data['type'] === 'web' && !(int)$data['isPartial'] &&
            (int)$data['sessionStart'] <= $this->observedAt &&
            (int)$data['sessionExpires'] > $this->observedAt ? 'eligible' : 'ineligible';
          if ($data === null) {
            $unknown[] = 'missing_native_session';
          }
          break;
        case 'app.capability':
          $data = $this->rows['config.config_entry'];
          $unknown[] = 'application_runtime_settings_unverified';
          break;
        case 'project.policy':
        case 'task.policy':
        case 'wiki.policy':
          $data = $target;
          break;
        case 'task.owner':
          $owner = $target['ownerPHID'] ?? null;
          $data = array($owner, $accounts[$owner ?? ''] ?? null);
          break;
        case 'project.member':
          $data = array($this->rows['project.edge'], $this->rows['user.edge']);
          break;
        case 'project.parent':
          $data = $this->rows['project.project'];
          break;
        case 'wiki.parent':
          $data = $this->rows['phriction.phriction_document'];
          break;
        case 'space.policy':
          $data = $this->rows['spaces.spaces_namespace'];
          break;
        case 'policy.named':
          $data = array($this->rows['policy.policy'],
            $this->rows['policy.policy_namedpolicy']);
          break;
      }
      if ($data !== null) {
        $observed++;
      }
      if ($eligibility === 'unknown') {
        $unknown_sources++;
      }
      $sources[] = new PhorgeGovernanceSourceSnapshot(array(
        'role' => $role, 'kind' => $required['kind'], 'id' => $required['id'],
        'store' => 'one-native-mysql-snapshot',
        'revision' => self::fingerprint(array($required['kind'], $data)),
        'eligibility' => $eligibility,
      ));
    }
    $snapshot = new PhorgeGovernanceReadSetSnapshot($this->binding, $sources);
    $counts = array();
    $fingerprints = array();
    foreach ($this->rows as $table => $rows) {
      $counts[$table] = count($rows);
      $fingerprints[$table] = self::fingerprint($rows);
    }
    $unknown = array_values(array_unique($unknown));
    sort($unknown, SORT_STRING);
    return new PhorgeGovernanceNativeSourceCapture($snapshot, array(
      'status' => 'native-observed-blocked',
      'native_closure_complete' => false,
      'can_execute' => false,
      'read_only_principal_verified' => true,
      'same_server_single_connection_snapshot' => true,
      'execution_atomicity_proved' => false,
      'table_rows' => $counts,
      'table_fingerprints' => $fingerprints,
      'schema_fingerprints' => $this->schemas,
      'snapshot_fingerprint' => $snapshot->getDigest(),
      'quickstart_sha256' => $this->quickstartHash,
      'observed_source_count' => $observed,
      'unobserved_source_count' => count($sources) - $observed,
      'unknown_source_count' => $unknown_sources,
      'unknown_reasons' => $unknown,
    ));
  }

  private static function ruleSubjectReason(
    array $rule, array $accounts, array $projects) {
    $class = $rule['rule'];
    $value = $rule['value'];
    if ($class === PhabricatorProjectMembersPolicyRule::class) {
      // Native control-none rules have no external subject list. Preserve
      // canonical null/empty representations instead of inventing a subject.
      return in_array($value, array(null, array(), ''), true) ? null :
        'malformed_native_policy_rule_subjects';
    }
    if (!is_array($value) || !array_is_list($value)) {
      return 'malformed_native_policy_rule_subjects';
    }
    if (count($value) > 16) {
      return 'native_policy_rule_subject_budget';
    }
    $is_user = $class === PhabricatorUsersPolicyRule::class;
    $type = $is_user ? 'USER' : 'PROJ';
    $present = $is_user ? $accounts : $projects;
    foreach ($value as $subject) {
      if (!is_string($subject) ||
          !preg_match('/\APHID-'.$type.'-[a-zA-Z0-9]{20}\z/', $subject)) {
        return 'malformed_native_policy_rule_subjects';
      }
      if (!isset($present[$subject])) {
        return 'missing_native_policy_rule_subject';
      }
    }
    return null;
  }

  private function disconnect() {
    $this->abortCapture();
    if ($this->connection) {
      try { $this->connection->close(); } catch (Throwable $ignored) {}
      $this->connection = null;
    }
  }

  public function __destruct() {
    $this->disconnect();
  }

}
