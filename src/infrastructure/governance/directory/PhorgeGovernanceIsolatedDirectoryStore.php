<?php

/**
 * Explicitly fixture-only mysqli reader. Never reads PhabricatorEnv, deployment
 * configuration, native user tables or a production schema patch registry.
 */
final class PhorgeGovernanceIsolatedDirectoryStore {

  private $connection;

  private function __construct(mysqli $connection) {
    $this->connection = $connection;
  }

  public static function validateFixtureDSN($dsn) {
    if (!is_string($dsn) || strncmp($dsn, 'mysql:', 6)) {
      throw new InvalidArgumentException('An explicit fixture MySQL DSN is required.');
    }
    $values = array();
    foreach (explode(';', substr($dsn, 6)) as $part) {
      if ($part === '') {
        continue;
      }
      $pair = explode('=', $part, 2);
      if (count($pair) !== 2 || isset($values[$pair[0]])) {
        throw new InvalidArgumentException('Invalid fixture connection options.');
      }
      $values[$pair[0]] = $pair[1];
    }
    $allowed = array('host', 'port', 'dbname', 'charset');
    if (array_diff(array_keys($values), $allowed) ||
        !isset($values['host'], $values['dbname']) ||
        !in_array($values['host'], array('127.0.0.1', '::1'), true) ||
        !preg_match('/\Agovernance_fixture_[a-z0-9_]{1,40}\z/', $values['dbname']) ||
        (isset($values['port']) &&
          (!ctype_digit($values['port']) || (int)$values['port'] < 1 ||
            (int)$values['port'] > 65535)) ||
        (isset($values['charset']) && $values['charset'] !== 'utf8mb4')) {
      throw new InvalidArgumentException('Only loopback fixture databases are accepted.');
    }
    return $values['dbname'];
  }

  public static function newFixture($dsn, $username, $password) {
    require_once dirname(__DIR__, 4).'/support/runtime/php-version.php';
    phorge_assert_supported_php_version(PHP_VERSION);
    if (PHP_SAPI !== 'cli' ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new LogicException('The isolated CLI test opt-in is required.');
    }
    $database = self::validateFixtureDSN($dsn);
    $options = array();
    foreach (explode(';', substr($dsn, 6)) as $part) {
      if ($part !== '') {
        list($key, $value) = explode('=', $part, 2);
        $options[$key] = $value;
      }
    }
    $connection = new mysqli(
      $options['host'],
      $username,
      $password,
      $database,
      isset($options['port']) ? (int)$options['port'] : 3306);
    $connection->set_charset('utf8mb4');
    $selected = $connection->query('SELECT DATABASE()')->fetch_row();
    $marker = $connection->query(
      'SELECT marker FROM fixture_guard WHERE id = 1')->fetch_row();
    if ($selected[0] !== $database ||
        $marker[0] !== 'N01.ORG-SCHEMA.v1:synthetic-only') {
      throw new LogicException('Not an initialized synthetic fixture schema.');
    }
    return new self($connection);
  }

  private static function eligible($alias) {
    return '('.$alias.'.is_approved = 1 AND '.$alias.'.is_disabled = 0 '.
      'AND '.$alias.'.is_system_agent = 0)';
  }

  private static function activeMembership($alias, $now) {
    return '('.$alias.'.state = \'active\' AND '.$alias.'.valid_from <= '.$now.
      ' AND ('.$alias.'.valid_until IS NULL OR '.$alias.'.valid_until > '.$now.'))';
  }

  private static function canViewTeam($team, $now) {
    return '(actor.is_admin = 1 OR ('.$team.'.state = \'active\' AND EXISTS '.
      '(SELECT 1 FROM admin_appointment ap '.
      'JOIN team_membership am ON am.team_id = ap.team_id '.
      'AND am.user_phid = ap.user_phid '.
      'WHERE ap.team_id = '.$team.'.id AND ap.user_phid = actor.phid '.
      'AND ap.state = \'active\' AND '.self::activeMembership('am', $now).')))';
  }

  private function read($sql, array $parameters) {
    $statement = $this->connection->prepare($sql);
    $statement->execute($parameters);
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
  }

  private function teamSQL($now) {
    return 'SELECT t.id, t.phid, t.name, t.state, t.version, '.
      'CASE WHEN '.self::canViewTeam('pt', $now).
      ' THEN pt.phid ELSE NULL END AS parentTeamPHID, '.
      'CASE WHEN actor.is_admin = 1 THEN d.phid ELSE NULL END '.
      'AS homeDepartmentPHID '.
      'FROM directory_team t '.
      'LEFT JOIN directory_team pt ON pt.id = t.parent_id '.
      'LEFT JOIN directory_department d ON d.id = t.home_department_id '.
      'JOIN fixture_user actor ON actor.phid = ? '.
      'WHERE '.self::eligible('actor').' AND '.self::canViewTeam('t', $now);
  }

  public function readTeams($viewer, $after_id, $limit, $query) {
    PhorgeGovernanceDirectoryReader::validateOptions(
      $viewer, $after_id, $limit, $query);
    $now = time();
    // LOCATE treats %, _ and backslashes literally, not as wildcard syntax.
    return $this->read($this->teamSQL($now).
      ' AND t.id > ? AND LOCATE(?, t.name) > 0 ORDER BY t.id LIMIT '.(int)$limit,
      array($viewer, $after_id, $query));
  }

  public function readDepartments($viewer, $after_id, $limit) {
    PhorgeGovernanceDirectoryReader::validateOptions(
      $viewer, $after_id, $limit, '');
    return $this->read(
      'SELECT d.id, d.phid, d.name, d.state, d.version, p.phid AS parentPHID '.
      'FROM directory_department d '.
      'LEFT JOIN directory_department p ON p.id = d.parent_id '.
      'JOIN fixture_user actor ON actor.phid = ? '.
      'WHERE '.self::eligible('actor').' AND actor.is_admin = 1 '.
      'AND d.id > ? ORDER BY d.id LIMIT '.(int)$limit,
      array($viewer, $after_id));
  }

  public function readMembers($viewer, $team_phid, $after_id, $limit) {
    PhorgeGovernanceDirectoryReader::validateOptions(
      $viewer, $after_id, $limit, '');
    $now = time();
    return $this->read(
      'SELECT m.id, m.user_phid, m.state, m.valid_from, m.valid_until, '.
      'CASE WHEN '.self::eligible('member_user').
      ' THEN 1 ELSE 0 END AS accountEligible '.
      'FROM team_membership m JOIN directory_team t ON t.id = m.team_id '.
      'JOIN fixture_user member_user ON member_user.phid = m.user_phid '.
      'JOIN fixture_user actor ON actor.phid = ? '.
      'WHERE '.self::eligible('actor').' AND '.self::canViewTeam('t', $now).
      ' AND '.self::activeMembership('m', $now).
      ' AND t.phid = ? AND m.id > ? ORDER BY m.id LIMIT '.(int)$limit,
      array($viewer, $team_phid, $after_id));
  }

  public function readUserTeams($viewer, $subject, $after_id, $limit) {
    PhorgeGovernanceDirectoryReader::validateOptions(
      $viewer, $after_id, $limit, '');
    $now = time();
    return $this->read($this->teamSQL($now).
      ' AND t.id > ? AND EXISTS (SELECT 1 FROM team_membership sm '.
      'JOIN fixture_user subject_user ON subject_user.phid = sm.user_phid '.
      'WHERE sm.team_id = t.id AND sm.user_phid = ? AND '.
      self::eligible('subject_user').' AND '.
      self::activeMembership('sm', $now).') '.
      'ORDER BY t.id LIMIT '.(int)$limit,
      array($viewer, $after_id, $subject));
  }

}
