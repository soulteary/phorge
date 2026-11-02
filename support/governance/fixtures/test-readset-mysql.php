<?php

// Creates and drops only this invocation's random synthetic database.
require_once dirname(__DIR__, 2).'/runtime/php-version.php';
$stage = 'unsupported-version';
$database = null;
$owned = false;
$admin = null;
$other = null;
$stale = null;
$store = null;
$checks = array();
$failure = null;

function readset_check($ok, $name) {
  global $checks, $stage;
  $stage = $name;
  if (!$ok) {
    throw new RuntimeException('Synthetic read-set assertion failed.');
  }
  $checks[] = $name;
}

function readset_sql(mysqli $connection, $sql, array $parameters) {
  $statement = $connection->prepare($sql);
  $statement->execute($parameters);
  return $statement;
}

function readset_blocked($expected, $current) {
  $result = PhorgeGovernanceReadSetValidator::compare($expected, $current);
  return $result['status'] === 'blocked' && $result['can_execute'] === false;
}

function readset_create(mysqli $connection, $database, &$owned) {
  $owned = false;
  if (!preg_match('/\Agovernance_fixture_[a-f0-9]{16}\z/', $database)) {
    throw new InvalidArgumentException('Expected a random fixture schema.');
  }
  $connection->query('CREATE DATABASE `'.$database.
    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  $owned = true;
}

function readset_drop(mysqli $connection, $database, $owned) {
  if (!$owned) {
    return false;
  }
  if (!preg_match('/\Agovernance_fixture_[a-f0-9]{16}\z/', $database)) {
    throw new InvalidArgumentException('Refusing unowned schema cleanup.');
  }
  $connection->query('DROP DATABASE `'.$database.'`');
  return true;
}

try {
  phorge_assert_supported_php_version(PHP_VERSION);
  $stage = 'isolation-preflight';
  if (PHP_SAPI !== 'cli' ||
      getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
    throw new LogicException('Isolated CLI test opt-in required.');
  }
  require_once dirname(__DIR__, 2).'/runtime/bootstrap-cli.php';
  $source_root = dirname(__DIR__, 3).'/src/infrastructure/governance/';
  require_once $source_root.'directory/PhorgeGovernanceIsolatedDirectoryStore.php';
  foreach (array('PhorgeGovernanceSourceSnapshot',
    'PhorgeGovernanceReadSetSnapshot', 'PhorgeGovernanceReadSetValidator',
    'PhorgeGovernanceIsolatedReadSetStore') as $class) {
    require_once $source_root.'readset/'.$class.'.php';
  }
  $dsn = getenv('PHORGE_GOVERNANCE_FIXTURE_DSN');
  PhorgeGovernanceIsolatedDirectoryStore::validateFixtureDSN($dsn);
  $options = array();
  foreach (explode(';', substr($dsn, 6)) as $part) {
    if ($part !== '') {
      list($key, $value) = explode('=', $part, 2);
      $options[$key] = $value;
    }
  }
  $username = getenv('PHORGE_GOVERNANCE_FIXTURE_USER');
  $password = getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD');
  if (!is_string($username) || !is_string($password)) {
    throw new LogicException('Explicit fixture credentials required.');
  }
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $port = isset($options['port']) ? (int)$options['port'] : 3306;
  $admin = new mysqli($options['host'], $username, $password, '', $port);
  $admin->set_charset('utf8mb4');
  $database = 'governance_fixture_'.bin2hex(random_bytes(8));
  readset_create($admin, $database, $owned);
  $admin->select_db($database);
  foreach (explode(';', file_get_contents(__DIR__.'/readset-schema.sql'))
    as $sql) {
    if (trim($sql) !== '') {
      $admin->query($sql);
    }
  }
  $collision_owned = false;
  $collision_failed = false;
  try {
    readset_create($admin, $database, $collision_owned);
  } catch (mysqli_sql_exception $ex) {
    if ($ex->getCode() !== 1007) {
      throw $ex;
    }
    $collision_failed = true;
  }
  readset_check($collision_failed && !$collision_owned &&
    !readset_drop($admin, $database, $collision_owned),
    'failed-create-never-owns-or-drops-existing-schema');
  $binding = array(
    'operation_id' => 'synthetic-operation',
    'actor' => 'synthetic-actor',
    'executor' => 'synthetic-executor',
    'approver' => 'synthetic-approver',
    'target' => 'synthetic-project',
    'operation' => 'project.description',
    'object_class' => 'PhabricatorProject',
    'parameters_digest' => hash('sha256', 'synthetic-parameters'),
    'actor_session' => 'actor-session',
    'executor_session' => 'executor-session',
    'approver_session' => 'approver-session',
  );
  readset_sql($admin,
    'INSERT INTO readset_binding (binding_id,'.implode(',', array_keys($binding)).
    ') VALUES ('.implode(',', array_fill(0, count($binding) + 1, '?')).')',
    array_merge(array('binding-1'), array_values($binding)));
  $specs = PhorgeGovernanceReadSetValidator::getRequiredSources($binding);
  $ids = array();
  foreach ($specs as $role => $spec) {
    readset_sql($admin,
      'INSERT INTO readset_source (source_role,source_kind,source_id,'.
      'store_name,revision,eligibility) VALUES (?,?,?,?,?,?)',
      array($role, $spec['kind'], $spec['id'],
        'fixture-primary', 'v1', 'eligible'));
    $ids[$role] = $admin->insert_id;
    readset_sql($admin,
      'INSERT INTO readset_manifest (binding_id,source_row_id) VALUES (?,?)',
      array('binding-1', $ids[$role]));
  }
  $fixture_dsn = 'mysql:host='.$options['host'].';port='.$port.
    ';dbname='.$database.';charset=utf8mb4';
  $store = PhorgeGovernanceIsolatedReadSetStore::newFixture(
    $fixture_dsn, $username, $password);
  $expected = $store->capture('binding-1');
  $match = PhorgeGovernanceReadSetValidator::compare(
    $expected, $store->capture('binding-1'));
  readset_check($match['status'] === 'matched-fixture-only' &&
    !$match['can_execute'] && $match['coverage'] === 'partial-fixture-only',
    'single-statement-fixture-readset-matches-without-execution');
  $other = new mysqli(
    $options['host'], $username, $password, $database, $port);
  $other->set_charset('utf8mb4');
  foreach (array('actor.org.membership', 'executor.org.appointment',
    'actor.user.account', 'target.policy.named', 'target.space.policy',
    'target.project.parent', 'target.project.member') as $role) {
    readset_sql($other,
      'UPDATE readset_source SET revision = ? WHERE source_role = ?',
      array('v2', $role));
    $current = $store->capture('binding-1');
    $target = readset_sql($other,
      'SELECT revision FROM readset_source WHERE source_role = ?',
      array('target.project.policy'))->get_result()->fetch_row();
    readset_check($target[0] === 'v1' && readset_blocked($expected, $current),
      'dependency-change-with-unchanged-target-'.str_replace('.', '-', $role));
    readset_sql($other,
      'UPDATE readset_source SET revision = ? WHERE source_role = ?',
      array('v1', $role));
  }
  foreach (array('ineligible', 'unknown') as $eligibility) {
    readset_sql($other,
      'UPDATE readset_source SET eligibility = ? WHERE source_role = ?',
      array($eligibility, 'approver.user.account'));
    readset_check(readset_blocked($expected, $store->capture('binding-1')),
      'current-approver-'.$eligibility.'-blocks');
  }
  readset_sql($other,
    'UPDATE readset_source SET eligibility = ? WHERE source_role = ?',
    array('eligible', 'approver.user.account'));
  readset_sql($other,
    'UPDATE readset_source SET source_id = ? WHERE source_role = ?',
    array('synthetic-actor', 'executor.user.account'));
  readset_check(readset_blocked($expected, $store->capture('binding-1')),
    'same-account-kind-cannot-replace-executor-role-identity');
  readset_sql($other,
    'UPDATE readset_source SET source_id = ? WHERE source_role = ?',
    array('synthetic-executor', 'executor.user.account'));
  $missing_role = 'executor.user.account';
  readset_sql($other,
    'DELETE FROM readset_manifest WHERE binding_id = ? AND source_row_id = ?',
    array('binding-1', $ids[$missing_role]));
  readset_check(readset_blocked($expected, $store->capture('binding-1')),
    'one-required-role-missing-even-when-other-accounts-present');
  readset_sql($other,
    'INSERT INTO readset_manifest (binding_id,source_row_id) VALUES (?,?)',
    array('binding-1', $ids[$missing_role]));
  readset_sql($other, 'DELETE FROM readset_source WHERE row_id = ?',
    array($ids[$missing_role]));
  readset_check(readset_blocked($expected, $store->capture('binding-1')),
    'deleted-source-dangling-manifest-blocks');
  readset_sql($other,
    'INSERT INTO readset_source (row_id,source_role,source_kind,source_id,'.
    'store_name,revision,eligibility) VALUES (?,?,?,?,?,?,?)',
    array($ids[$missing_role], $missing_role, $specs[$missing_role]['kind'],
      $specs[$missing_role]['id'], 'fixture-primary', 'v1', 'eligible'));
  readset_sql($other,
    'INSERT INTO readset_source (source_role,source_kind,source_id,'.
    'store_name,revision,eligibility) VALUES (?,?,?,?,?,?)',
    array('dependency.named.extra', 'policy.named', 'extra-policy',
      'fixture-primary', 'v1', 'eligible'));
  $extra_id = $other->insert_id;
  readset_sql($other,
    'INSERT INTO readset_manifest (binding_id,source_row_id) VALUES (?,?)',
    array('binding-1', $extra_id));
  $added = $store->capture('binding-1');
  readset_sql($other,
    'DELETE FROM readset_source WHERE row_id = ?', array($extra_id));
  readset_check(readset_blocked($expected, $store->capture('binding-1')),
    'dangling-extra-manifest-blocks-with-all-required-sources-present');
  readset_sql($other,
    'INSERT INTO readset_source (row_id,source_role,source_kind,source_id,'.
    'store_name,revision,eligibility) VALUES (?,?,?,?,?,?,?)',
    array($extra_id, 'dependency.named.extra', 'policy.named', 'extra-policy',
      'fixture-primary', 'v1', 'eligible'));
  readset_check(readset_blocked($expected, $added),
    'additional-known-source-invalidates-manifest');
  readset_sql($other,
    'DELETE FROM readset_manifest WHERE binding_id = ? AND source_row_id = ?',
    array('binding-1', $extra_id));
  readset_sql($other, 'DELETE FROM readset_source WHERE row_id = ?',
    array($extra_id));
  readset_check(readset_blocked($added, $store->capture('binding-1')),
    'removed-additional-source-invalidates-manifest');
  readset_sql($other,
    'UPDATE readset_source SET store_name = ? WHERE source_role = ?',
    array('fixture-secondary', 'target.space.policy'));
  $cross_store = $store->capture('binding-1');
  $cross_result = PhorgeGovernanceReadSetValidator::compare(
    $cross_store, $cross_store);
  readset_check($cross_result['reason'] === 'cross_store_consistency_unproven',
    'cross-store-identical-rows-still-have-no-consistency-proof');
  readset_sql($other,
    'UPDATE readset_source SET store_name = ? WHERE source_role = ?',
    array('fixture-primary', 'target.space.policy'));
  readset_sql($other,
    'UPDATE readset_binding SET operation = ? WHERE binding_id = ?',
    array('project.members', 'binding-1'));
  readset_check(readset_blocked($expected, $store->capture('binding-1')),
    'same-target-different-operation-blocks');
  readset_sql($other,
    'UPDATE readset_binding SET operation = ? WHERE binding_id = ?',
    array('project.description', 'binding-1'));

  // Demonstrate why a caller-owned repeatable-read transaction is not current.
  $stale = new mysqli(
    $options['host'], $username, $password, $database, $port);
  $stale->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $stale->begin_transaction();
  $old = readset_sql($stale,
    'SELECT revision FROM readset_source WHERE source_role = ?',
    array('actor.org.membership'))->get_result()->fetch_row();
  readset_sql($other,
    'UPDATE readset_source SET revision = ? WHERE source_role = ?',
    array('v2', 'actor.org.membership'));
  $still_old = readset_sql($stale,
    'SELECT revision FROM readset_source WHERE source_role = ?',
    array('actor.org.membership'))->get_result()->fetch_row();
  readset_check($old[0] === 'v1' && $still_old[0] === 'v1',
    'caller-repeatable-read-view-is-demonstrably-stale');
  readset_check(readset_blocked($expected, $store->capture('binding-1')),
    'private-fresh-autocommit-reader-detects-committed-change');
  $rejected = false;
  try {
    PhorgeGovernanceIsolatedReadSetStore::newFixture(
      $stale, $username, $password);
  } catch (InvalidArgumentException $ex) {
    $rejected = true;
  }
  readset_check($rejected, 'caller-transaction-not-accepted-as-observation');
  $stale->rollback();
  readset_sql($other,
    'UPDATE readset_source SET revision = ? WHERE source_role = ?',
    array('v1', 'actor.org.membership'));
  foreach (array(
    array('binding-task', 'ManiphestTask', 'task.title', 'task.policy'),
    array('binding-wiki', 'PhrictionDocument', 'wiki.content', 'wiki.policy'))
    as $case) {
    $family_binding = $binding;
    $family_binding['operation_id'] = $case[0];
    $family_binding['object_class'] = $case[1];
    $family_binding['operation'] = $case[2];
    $family_binding['target'] = 'synthetic-'.$case[0];
    readset_sql($admin,
      'INSERT INTO readset_binding (binding_id,'.
      implode(',', array_keys($family_binding)).') VALUES ('.
      implode(',', array_fill(0, count($family_binding) + 1, '?')).')',
      array_merge(array($case[0]), array_values($family_binding)));
    $family_ids = array();
    foreach (PhorgeGovernanceReadSetValidator::getRequiredSources(
      $family_binding) as $role => $spec) {
      readset_sql($admin,
        'INSERT INTO readset_source (source_role,source_kind,source_id,'.
        'store_name,revision,eligibility) VALUES (?,?,?,?,?,?)',
        array($role, $spec['kind'], $spec['id'],
          'fixture-primary', 'v1', 'eligible'));
      $family_ids[$role] = $admin->insert_id;
      readset_sql($admin,
        'INSERT INTO readset_manifest (binding_id,source_row_id) VALUES (?,?)',
        array($case[0], $family_ids[$role]));
    }
    $family_expected = $store->capture($case[0]);
    $family_match = PhorgeGovernanceReadSetValidator::compare(
      $family_expected, $store->capture($case[0]));
    readset_check($family_match['status'] === 'matched-fixture-only',
      $case[0].'-fixed-catalog-captured');
    foreach (array('target.project.member', 'target.project.parent') as $role) {
      readset_sql($other,
        'UPDATE readset_source SET revision = ? WHERE row_id = ?',
        array('v2', $family_ids[$role]));
      $policy = readset_sql($other,
        'SELECT revision FROM readset_source WHERE row_id = ?',
        array($family_ids['target.'.$case[3]]))->get_result()->fetch_row();
      readset_check($policy[0] === 'v1' &&
        readset_blocked($family_expected, $store->capture($case[0])),
        $case[0].'-group-dependency-'.str_replace('.', '-', $role));
      readset_sql($other,
        'UPDATE readset_source SET revision = ? WHERE row_id = ?',
        array('v1', $family_ids[$role]));
    }
  }
  $admin->query('UPDATE fixture_guard SET marker = \'wrong-contract\'');
  $rejected = false;
  try {
    PhorgeGovernanceIsolatedReadSetStore::newFixture(
      $fixture_dsn, $username, $password);
  } catch (LogicException $ex) {
    $rejected = true;
  }
  readset_check($rejected, 'synthetic-readset-marker-required');
} catch (Throwable $ex) {
  $failure = array('stage' => $stage, 'error_type' => get_class($ex));
} finally {
  $store = null;
  if ($stale) {
    $stale->close();
  }
  if ($other) {
    $other->close();
  }
  if ($admin && $owned && $database !== null) {
    try {
      readset_drop($admin, $database, $owned);
      $checks[] = 'owned-random-readset-schema-removed';
    } catch (Throwable $ex) {
      $failure = array('stage' => 'fixture-cleanup', 'error_type' => get_class($ex));
    }
  }
}
echo json_encode(array(
  'contract' => 'N07.READSET.v1',
  'status' => $failure === null ? 'passed' : 'failed',
  'php_version' => PHP_VERSION,
  'storage' => 'real-mysql-innodb',
  'check_count' => count($checks),
  'checks' => $checks,
  'failure' => $failure,
  'scope' => 'synthetic-only; partial native coverage; no writer/executor',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($failure === null ? 0 : 1);
