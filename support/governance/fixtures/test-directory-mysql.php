<?php

/**
 * Explicit local MySQL fixture entry point. Creates and drops only a random
 * governance_fixture_* schema. Does not load application/deployment config.
 * Credentials are supplied through the test environment and never printed.
 */
require_once dirname(__DIR__, 2).'/runtime/php-version.php';

require_once dirname(__DIR__, 3).
  '/src/infrastructure/governance/directory/' .
  'PhorgeGovernanceIsolatedDirectoryStore.php';
require_once dirname(__DIR__, 3).
  '/src/infrastructure/governance/directory/' .
  'PhorgeGovernanceDirectoryReader.php';

$stage = 'preflight';
$database = null;
$created_by_this_run = false;
$admin = null;
$checks = array();
$failure = null;

function directory_check($condition, $name) {
  global $checks, $stage;
  $stage = $name;
  if (!$condition) {
    throw new RuntimeException('Fixture assertion failed.');
  }
  $checks[] = $name;
}

function directory_rejects($callback, $name, $expected_class, $error_code = null) {
  $caught = false;
  try {
    $callback();
  } catch (Throwable $ex) {
    if (!($ex instanceof $expected_class) ||
        ($error_code !== null && $ex->getCode() !== $error_code)) {
      throw $ex;
    }
    $caught = true;
  }
  directory_check($caught, $name);
}

function directory_create_owned_schema(mysqli $connection, $database, &$owned) {
  $owned = false;
  if (!preg_match('/\Agovernance_fixture_[a-f0-9]{16}\z/', $database)) {
    throw new InvalidArgumentException('Expected a randomized owned schema.');
  }
  $connection->query('CREATE DATABASE `'.$database.
    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  $owned = true;
}

function directory_drop_owned_schema(mysqli $connection, $database, $owned) {
  if (!$owned) {
    return false;
  }
  if (!preg_match('/\Agovernance_fixture_[a-f0-9]{16}\z/', $database)) {
    throw new InvalidArgumentException('Refusing unowned schema cleanup.');
  }
  $connection->query('DROP DATABASE `'.$database.'`');
  return true;
}

function directory_phid($type, $letter) {
  return 'PHID-'.$type.'-'.str_repeat($letter, 20);
}

try {
  $stage = 'unsupported-version';
  phorge_assert_supported_php_version(PHP_VERSION);
  $stage = 'isolation-preflight';
  if (PHP_SAPI !== 'cli' ||
      getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
    throw new LogicException('Isolated test opt-in required.');
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
  if ($username === false || $password === false) {
    throw new LogicException('Explicit fixture credentials required.');
  }
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $admin = new mysqli($options['host'], $username, $password, '',
    isset($options['port']) ? (int)$options['port'] : 3306);
  $admin->set_charset('utf8mb4');
  $database = 'governance_fixture_'.bin2hex(random_bytes(8));
  directory_create_owned_schema($admin, $database, $created_by_this_run);
  $admin->select_db($database);
  $fixture_dsn = 'mysql:host='.$options['host'].';port='.
    (isset($options['port']) ? (int)$options['port'] : 3306).
    ';dbname='.$database.';charset=utf8mb4';
  $sql = file_get_contents(__DIR__.'/directory-schema.sql');
  foreach (array(1, 2) as $installation) {
    foreach (explode(';', $sql) as $statement) {
      if (trim($statement) !== '') {
        $admin->query($statement);
      }
    }
  }
  $tables = $admin->query(
    'SELECT COUNT(*) FROM information_schema.tables '.
    'WHERE table_schema = DATABASE() AND ENGINE = \'InnoDB\'')->fetch_row();
  directory_check((int)$tables[0] === 9, 'real-innodb-schema-repeat-install');
  // Failure injection: collision must not acquire ownership or drop a schema.
  $collision_owned = false;
  directory_rejects(function() use ($admin, $database, &$collision_owned) {
    directory_create_owned_schema($admin, $database, $collision_owned);
  }, 'create-collision-does-not-acquire-ownership', 'mysqli_sql_exception', 1007);
  directory_check(!$collision_owned &&
    !directory_drop_owned_schema($admin, $database, $collision_owned) &&
    (int)$admin->query('SELECT COUNT(*) FROM fixture_guard')->fetch_row()[0] === 1,
    'failed-create-cleanup-does-not-drop-existing-schema');


  $users = array();
  foreach (array('admin' => 'a', 'manager' => 'b', 'ordinary' => 'c',
    'outsider' => 'd', 'disabled' => 'e', 'unapproved' => 'f',
    'agent' => 'g') as $label => $letter) {
    $users[$label] = directory_phid('USER', $letter);
    $statement = $admin->prepare(
      'INSERT INTO fixture_user '.
      '(phid,is_admin,is_approved,is_disabled,is_system_agent) '.
      'VALUES (?,?,?,?,?)');
    $statement->execute(array($users[$label], $label === 'admin' ? 1 : 0,
      $label === 'unapproved' ? 0 : 1, $label === 'disabled' ? 1 : 0,
      $label === 'agent' ? 1 : 0));
  }
  foreach (array(1, 2) as $id) {
    $statement = $admin->prepare(
      'INSERT INTO directory_department (id,phid,name) VALUES (?,?,?)');
    $statement->execute(array($id, directory_phid('GOVD', chr(96 + $id)),
      'Department '.$id));
  }
  $teams = array();
  foreach (array(1 => 'Parent', 2 => 'Managed', 3 => 'Hidden',
    4 => 'Archived') as $id => $name) {
    $teams[$id] = directory_phid('GOVT', chr(96 + $id));
    $statement = $admin->prepare(
      'INSERT INTO directory_team '.
      '(id,phid,name,parent_id,home_department_id,state) VALUES (?,?,?,?,?,?)');
    $statement->execute(array($id, $teams[$id], $name,
      $id === 2 ? 1 : null, 1, $id === 4 ? 'archived' : 'active'));
  }
  $now = time();
  foreach (array(1, 2, 3, 4) as $team_id) {
    foreach (array('manager', 'ordinary', 'disabled', 'unapproved', 'agent')
      as $label) {
      $statement = $admin->prepare(
        'INSERT INTO team_membership '.
        '(team_id,user_phid,valid_from) VALUES (?,?,?)');
      $statement->execute(array($team_id, $users[$label], $now - 30));
    }
  }
  foreach (array(2, 4) as $team_id) {
    foreach (array('manager', 'disabled', 'unapproved', 'agent') as $label) {
      $statement = $admin->prepare(
        'INSERT INTO admin_appointment (team_id,user_phid) VALUES (?,?)');
      $statement->execute(array($team_id, $users[$label]));
    }
  }
  $store = PhorgeGovernanceIsolatedDirectoryStore::newFixture(
    $fixture_dsn, $username, $password);
  $reader = new PhorgeGovernanceDirectoryReader($store);
  directory_check(count($reader->listTeams($users['admin'])) === 4,
    'eligible-system-admin-sees-directory');
  directory_check(count($reader->listDepartments($users['admin'])) === 2,
    'system-admin-sees-departments');
  $visible = $reader->listTeams($users['manager']);
  directory_check(count($visible) === 1 && $visible[0]['phid'] === $teams[2],
    'manager-only-effective-managed-team');
  directory_check($visible[0]['parentTeamPHID'] === null &&
    $visible[0]['homeDepartmentPHID'] === null,
    'hidden-parent-and-home-department-redacted');
  directory_check($reader->listDepartments($users['manager']) === array(),
    'team-management-does-not-grant-department-view');
  directory_check($reader->listTeams($users['ordinary']) === array() &&
    $reader->listUserTeams($users['ordinary'], $users['ordinary']) === array(),
    'ordinary-member-has-no-self-directory-exception');
  directory_check($reader->listMembers($users['ordinary'], $teams[2]) === array(),
    'ordinary-membership-does-not-grant-roster');
  directory_check($reader->listTeams($users['outsider']) === array(),
    'unrelated-viewer-has-no-directory');
  foreach (array('disabled', 'unapproved', 'agent') as $label) {
    directory_check($reader->listTeams($users[$label]) === array() &&
      $reader->listMembers($users[$label], $teams[2]) === array(),
      'ineligible-manager-'.$label.'-denied');
  }
  directory_check(count($reader->listMembers($users['manager'], $teams[2])) === 5,
    'manager-can-read-managed-roster');
  directory_check($reader->listMembers($users['manager'], $teams[1]) === array() &&
    $reader->listMembers($users['manager'], $teams[3]) === array(),
    'no-parent-or-other-team-roster-inheritance');
  $relations = $reader->listUserTeams($users['manager'], $users['ordinary']);
  directory_check(count($relations) === 1 && $relations[0]['phid'] === $teams[2],
    'other-user-relations-filtered-per-team');
  directory_check($reader->listTeams($users['manager'], 0, 50, 'Hidden') === array(),
    'search-does-not-reveal-hidden-name');
  directory_check($reader->listTeams($users['manager'], 0, 50, '%') === array(),
    'search-wildcards-treated-literally');
  $page1 = $reader->listTeams($users['admin'], 0, 2);
  $page2 = $reader->listTeams($users['admin'], (int)$page1[1]['id'], 2);
  directory_check(count($page1) === 2 && count($page2) === 2 &&
    $page1[1]['id'] < $page2[0]['id'], 'stable-bounded-database-keyset-pages');

  $manager_roster = $reader->listMembers($users['manager'], $teams[2]);
  $disabled_flags = array();
  foreach ($manager_roster as $member_row) {
    if ($member_row['user_phid'] === $users['disabled']) {
      $disabled_flags[] = (int)$member_row['accountEligible'];
    }
  }
  directory_check($disabled_flags === array(0),
    'disabled-stored-membership-marked-not-account-eligible');
  directory_check($reader->listUserTeams(
    $users['admin'], $users['disabled']) === array(),
    'disabled-subject-has-no-effective-current-user-teams');
  $statement = $admin->prepare(
    'UPDATE team_membership SET valid_from = ? '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($now + 3600, $users['ordinary']));
  directory_check(count($reader->listMembers($users['manager'], $teams[2])) === 4 &&
    count($reader->listUserTeams($users['admin'], $users['ordinary'])) === 3,
    'future-subject-membership-excluded-from-current-reads');
  $statement = $admin->prepare(
    'UPDATE team_membership SET valid_from = ?, state = \'suspended\' '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($now - 30, $users['ordinary']));
  directory_check(count($reader->listMembers($users['manager'], $teams[2])) === 4 &&
    count($reader->listUserTeams($users['admin'], $users['ordinary'])) === 3,
    'suspended-subject-membership-excluded-from-current-reads');
  $statement = $admin->prepare(
    'UPDATE team_membership SET state = \'active\' '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($users['ordinary']));

  // A second real connection changes authorization. Reusing the reader must
  // not reuse cached actor, membership or appointment objects.
  $other = new mysqli($options['host'], $username, $password, $database,
    isset($options['port']) ? (int)$options['port'] : 3306);
  $other->set_charset('utf8mb4');
  $statement = $other->prepare(
    'UPDATE admin_appointment SET state = \'ended\' '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($users['manager']));
  directory_check($reader->listTeams($users['manager']) === array(),
    'second-connection-revocation-not-cached');
  $statement = $other->prepare(
    'UPDATE admin_appointment SET state = \'active\' '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($users['manager']));
  $statement = $other->prepare(
    'UPDATE team_membership SET valid_until = ? '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($now - 1, $users['manager']));
  directory_check($reader->listTeams($users['manager']) === array(),
    'expired-membership-invalidates-appointment');
  $statement = $other->prepare(
    'UPDATE team_membership SET valid_until = NULL, state = \'ended\' '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($users['manager']));
  directory_check($reader->listTeams($users['manager']) === array(),
    'ended-membership-does-not-auto-revive-appointment');
  $current_relations = $reader->listUserTeams(
    $users['admin'], $users['manager']);
  directory_check(count($current_relations) === 3 &&
    count($reader->listMembers($users['admin'], $teams[2])) === 4,
    'ended-subject-relation-excluded-from-current-user-teams');
  $statement = $other->prepare(
    'UPDATE team_membership SET state = \'active\', valid_until = ? '.
    'WHERE team_id = 2 AND user_phid = ?');
  $statement->execute(array($now - 1, $users['manager']));
  directory_check(count($reader->listUserTeams(
    $users['admin'], $users['manager'])) === 3 &&
    count($reader->listMembers($users['admin'], $teams[2])) === 4,
    'expired-subject-relation-excluded-from-current-user-teams');
  directory_rejects(function() use ($admin, $users) {
    $statement = $admin->prepare(
      'INSERT INTO admin_appointment (team_id,user_phid) VALUES (3,?)');
    $statement->execute(array($users['outsider']));
  }, 'appointment-cannot-create-missing-membership', 'mysqli_sql_exception', 1452);
  $statement = $admin->prepare(
    'SELECT COUNT(*) FROM team_membership WHERE team_id = 3 AND user_phid = ?');
  $statement->execute(array($users['outsider']));
  directory_check((int)$statement->get_result()->fetch_row()[0] === 0,
    'failed-appointment-does-not-auto-add-member');
  $statement = $admin->prepare(
    'INSERT INTO current_primary_department (user_phid,department_id) VALUES (?,1)');
  $statement->execute(array($users['ordinary']));
  directory_rejects(function() use ($other, $users) {
    $statement = $other->prepare(
      'INSERT INTO current_primary_department (user_phid,department_id) VALUES (?,2)');
    $statement->execute(array($users['ordinary']));
  }, 'database-enforces-one-current-primary-department', 'mysqli_sql_exception', 1062);
  $other->close();
  $admin->query('UPDATE fixture_guard SET marker = \'not-the-fixture-contract\'');
  directory_rejects(function() use ($fixture_dsn, $username, $password) {
    PhorgeGovernanceIsolatedDirectoryStore::newFixture(
      $fixture_dsn, $username, $password);
  }, 'fixture-contract-marker-required', 'LogicException');
} catch (Throwable $ex) {
  $failure = array('stage' => $stage, 'error_type' => get_class($ex));
} finally {
  if ($admin && $database !== null && $created_by_this_run) {
    try {
      directory_drop_owned_schema($admin, $database, $created_by_this_run);
      $checks[] = 'owned-random-fixture-schema-removed';
    } catch (Throwable $ex) {
      $failure = array('stage' => 'fixture-cleanup', 'error_type' => get_class($ex));
    }
  }
}

echo json_encode(array(
  'contract' => 'N01.ORG-SCHEMA.v1/N02.DIRECTORY-VIEW.v1',
  'status' => $failure === null ? 'passed' : 'failed',
  'storage' => 'real-mysql-innodb',
  'php_version' => PHP_VERSION,
  'check_count' => count($checks),
  'checks' => $checks,
  'failure' => $failure,
  'scope' => 'synthetic-only; no production registration or authorization writes',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($failure === null ? 0 : 1);
