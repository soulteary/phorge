<?php

// Deliberately does not bootstrap the application, invoke ORM hooks, load
// deployment configuration, or contact a service. Private JSON arrives on stdin.
// Only schema-selected columns and SELECTs are used on a read-only transaction.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = null;
try {
  $config = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
  if (!is_array($config) ||
      ($config['restoredSnapshot'] ?? null) !== true ||
      ($config['host'] ?? null) !== '127.0.0.1' ||
      !preg_match('/\A[a-zA-Z0-9_]+\z/', $config['namespace'] ?? '') ||
      !preg_match('/\A[0-9a-f]{64}\z/', $config['snapshotSHA256'] ?? '') ||
      !preg_match('/\A[0-9a-f]{40}\z/', $config['sourceCommit'] ?? '') ||
      !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $config['snapshotWatermark'] ?? '') ||
      !is_bool($config['allowPublic'] ?? null)) {
    throw new Exception('invalid-input');
  }
  $page = $config['pageSize'] ?? 500;
  $maximum = $config['maxRows'] ?? 100000;
  $seconds = $config['seconds'] ?? 30;
  $port = $config['port'] ?? null;
  if (!is_int($page) || $page < 1 || $page > 2000 ||
      !is_int($maximum) || $maximum < 1 || $maximum > 100000 ||
      !is_int($seconds) || $seconds < 1 || $seconds > 300 ||
      !is_int($port) || $port < 1 || $port > 65535) {
    throw new Exception('invalid-budget');
  }
  $connection = mysqli_init();
  $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
  $connection->options(MYSQLI_OPT_READ_TIMEOUT, min($seconds, 30));
  $connection->real_connect('127.0.0.1', $config['user'], $config['password'], null, $port);
  $connection->set_charset('utf8mb4');
  // Refuse inherited roles, ALL, mutation grants or broader privileges. A
  // false dry-run flag can never substitute for database privilege separation.
  $grants = $connection->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM);
  foreach ($grants as $grant) {
    if (!preg_match('/\AGRANT (USAGE|SELECT) ON /', $grant[0]) ||
        str_contains($grant[0], 'WITH GRANT OPTION')) {
      throw new Exception('read-only-principal-required');
    }
  }
  $connection->query('SET SESSION MAX_EXECUTION_TIME = '.($seconds * 1000));
  $connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $connection->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
  $started = microtime(true);
  $read = 0;
  $queries = 0;
  $schema = array();
  $read_rows = static function($database, $table, array $columns, array $keys)
    use ($connection, $page, $maximum, $seconds, $started, &$read, &$queries, &$schema) {
    $qualified = '`'.$database.'`.`'.$table.'`';
    $stmt = $connection->prepare(
      'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $engine = $stmt->get_result()->fetch_assoc();
    if (($engine['ENGINE'] ?? null) !== 'InnoDB') {
      throw new Exception('transactional-table-required');
    }
    $definitions = $connection->query('SHOW COLUMNS FROM '.$qualified)->fetch_all(MYSQLI_ASSOC);
    $available = array_column($definitions, 'Field');
    foreach (array_merge($columns, $keys) as $column) {
      if (!in_array($column, $available, true)) {
        throw new Exception('unsupported-schema');
      }
    }
    // Only retain a schema fingerprint in the public extraction metadata.
    $schema[$table] = hash('sha256', json_encode($definitions));
    $result = array();
    $cursor = null;
    $fields = implode(', ', array_map(static fn($x) => '`'.$x.'`', $columns));
    $ordering = implode(', ', array_map(static fn($x) => '`'.$x.'`', $keys));
    while (true) {
      if (microtime(true) - $started > $seconds) {
        throw new Exception('extraction-timeout');
      }
      $where = '';
      if ($cursor !== null) {
        $values = array_map(static fn($x) => "'".$connection->real_escape_string((string)$x)."'", $cursor);
        $where = ' WHERE ('.$ordering.') > ('.implode(', ', $values).')';
      }
      $rows = $connection->query('SELECT '.$fields.' FROM '.$qualified.$where.
        ' ORDER BY '.$ordering.' LIMIT '.(int)$page)->fetch_all(MYSQLI_ASSOC);
      $queries++;
      $read += count($rows);
      if ($read > $maximum) {
        throw new Exception('extraction-row-cap');
      }
      foreach ($rows as $row) {
        $result[] = $row;
      }
      if (count($rows) < $page) {
        break;
      }
      $last = end($rows);
      $cursor = array_map(static fn($key) => $last[$key], $keys);
    }
    return $result;
  };
  $namespace = $config['namespace'];
  $projects = $read_rows($namespace.'_project', 'project', array(
    'id', 'phid', 'viewPolicy', 'editPolicy', 'joinPolicy', 'parentProjectPHID',
    'milestoneNumber', 'hasSubprojects', 'isMembershipLocked', 'spacePHID'), array('id'));
  $users = $read_rows($namespace.'_user', 'user', array(
    'id', 'phid', 'isAdmin', 'isDisabled', 'isApproved'), array('id'));
  $edges = $read_rows($namespace.'_project', 'edge', array('src', 'type', 'dst'), array('src', 'type', 'dst'));
  $spaces = $read_rows($namespace.'_spaces', 'space', array('id', 'phid'), array('id'));
  // No raw policies/names are exported. Their presence remains an explicit
  // unsupported-rule boundary; all current accounts are enumerated above.
  $policies = $read_rows($namespace.'_policy', 'policy', array('id', 'phid'), array('id'));
  $connection->rollback();
  foreach ($projects as &$project) {
    foreach (array('id', 'hasSubprojects', 'isMembershipLocked') as $key) {
      $project[$key] = (int)$project[$key];
    }
    $project['milestoneNumber'] = $project['milestoneNumber'] === null ? null : (int)$project['milestoneNumber'];
  }
  unset($project);
  foreach ($users as &$user) {
    foreach (array('id', 'isAdmin', 'isDisabled', 'isApproved') as $key) {
      $user[$key] = (int)$user[$key];
    }
  }
  unset($user);
  echo json_encode(array(
    'schemaVersion' => 1, 'complete' => true, 'restoredSnapshot' => true,
    'sourceCommit' => $config['sourceCommit'],
    'snapshotSHA256' => $config['snapshotSHA256'],
    'snapshotWatermark' => $config['snapshotWatermark'],
    'allowPublic' => $config['allowPublic'],
    'projects' => $projects, 'users' => $users, 'edges' => $edges,
    'spaceCount' => count($spaces), 'storedPolicyCount' => count($policies),
    'schemaFingerprints' => $schema, 'rowsRead' => $read, 'pageQueries' => $queries,
    'readOnlyTransaction' => true,
  ), JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
  // mysqli exception messages may contain hosts, usernames, SQL or PHIDs.
  if ($connection) {
    try { $connection->rollback(); } catch (Throwable $ignored) {}
  }
  fwrite(STDOUT, "{\"schemaVersion\":1,\"complete\":false,\"reason\":\"extraction-refused-or-incomplete\"}\n");
  exit(2);
}
