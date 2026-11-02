<?php

/**
 * Synthetic SQL observation adapter only. Owns a private fresh autocommit
 * connection; never accepts a caller's transaction or repeatable-read snapshot.
 */
final class PhorgeGovernanceIsolatedReadSetStore extends Phobject {

  private $connection;

  private function __construct(mysqli $connection) {
    $this->connection = $connection;
  }

  public static function newFixture($dsn, $username, $password) {
    require_once dirname(__DIR__, 4).'/support/runtime/php-version.php';
    phorge_assert_supported_php_version(PHP_VERSION);
    if (PHP_SAPI !== 'cli' ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new LogicException('Explicit isolated CLI fixture opt-in required.');
    }
    $database = PhorgeGovernanceIsolatedDirectoryStore::validateFixtureDSN($dsn);
    if (!is_string($username) || !is_string($password)) {
      throw new InvalidArgumentException('Explicit fixture credentials required.');
    }
    $options = array();
    foreach (explode(';', substr($dsn, 6)) as $part) {
      if ($part !== '') {
        list($key, $value) = explode('=', $part, 2);
        $options[$key] = $value;
      }
    }
    $connection = new mysqli(
      $options['host'], $username, $password, $database,
      isset($options['port']) ? (int)$options['port'] : 3306);
    $connection->set_charset('utf8mb4');
    $connection->autocommit(true);
    $connection->query(
      'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $selected = $connection->query('SELECT DATABASE()')->fetch_row();
    $marker = $connection->query(
      'SELECT marker FROM fixture_guard WHERE id = 1')->fetch_row();
    if ($selected[0] !== $database || !$marker ||
        $marker[0] !== 'N07.READSET.v1:synthetic-only') {
      $connection->close();
      throw new LogicException('Expected the synthetic read-set fixture.');
    }
    return new self($connection);
  }

  public function capture($binding_id) {
    if (!is_string($binding_id) ||
        !preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $binding_id)) {
      throw new InvalidArgumentException('Invalid synthetic binding ID.');
    }
    // All binding, manifest and source rows come from one new statement view.
    // This proves only a synthetic observation, not a native locked writer.
    $statement = $this->connection->prepare(
      'SELECT b.operation_id, b.actor, b.executor, b.approver, b.target, '.
      'b.operation, b.object_class, b.parameters_digest, b.actor_session, '.
      'b.executor_session, b.approver_session, '.
      'm.source_row_id AS manifest_source_id, '.
      's.source_role, s.source_kind, s.source_id, s.store_name, '.
      's.revision, s.eligibility '.
      'FROM readset_binding b '.
      'LEFT JOIN readset_manifest m ON m.binding_id = b.binding_id '.
      'LEFT JOIN readset_source s ON s.row_id = m.source_row_id '.
      'WHERE b.binding_id = ? ORDER BY s.source_role, s.row_id LIMIT 257');
    $statement->execute(array($binding_id));
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    if (!$rows || count($rows) > 256) {
      throw new LogicException('Missing or unbounded synthetic read set.');
    }
    $binding = array();
    foreach (array('operation_id', 'actor', 'executor', 'approver', 'target',
      'operation', 'object_class', 'parameters_digest', 'actor_session',
      'executor_session', 'approver_session') as $key) {
      $binding[$key] = $rows[0][$key];
    }
    $sources = array();
    foreach ($rows as $row) {
      if ($row['source_role'] === null) {
        if ($row['manifest_source_id'] !== null) {
          // A dangling optional dependency is unknown, not absent.
          $sources[] = new PhorgeGovernanceSourceSnapshot(array(
            'role' => 'dependency.missing.'.$row['manifest_source_id'],
            'kind' => 'unknown.source',
            'id' => (string)$row['manifest_source_id'],
            'store' => 'fixture-unknown',
            'revision' => 'missing',
            'eligibility' => 'unknown',
          ));
        }
        continue;
      }
      $sources[] = new PhorgeGovernanceSourceSnapshot(array(
        'role' => $row['source_role'],
        'kind' => $row['source_kind'],
        'id' => $row['source_id'],
        'store' => $row['store_name'],
        'revision' => $row['revision'],
        'eligibility' => $row['eligibility'],
      ));
    }
    return new PhorgeGovernanceReadSetSnapshot($binding, $sources);
  }

}
