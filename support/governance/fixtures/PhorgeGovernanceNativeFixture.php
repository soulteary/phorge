<?php

/**
 * Privileged setup helper for one invocation's native-DDL test namespaces.
 * Not autoloaded, not an application, patch, identity provider or policy rule.
 */
final class PhorgeGovernanceNativeFixture {

  private $admin;
  private $namespace;
  private $host;
  private $port;
  private $databases = array();
  private $readerUser;
  private $readerPassword;
  private $readerCreated = false;
  private $reader;
  private $definitions = array();
  private $quickstartHash;

  const MARKER = 'N07.NATIVE-SOURCE.v1:synthetic-only';

  private static $tables = array(
    'user' => array('user', 'phabricator_session', 'edge'),
    'project' => array('project', 'edge'),
    'maniphest' => array('maniphest_task'),
    'phriction' => array('phriction_document', 'phriction_content'),
    'policy' => array('policy', 'policy_namedpolicy'),
    'spaces' => array('spaces_namespace'),
    'config' => array('config_entry'),
  );

  public function __construct(mysqli $admin, $host, $port) {
    if (PHP_SAPI !== 'cli' ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
        !in_array($host, array('127.0.0.1', '::1'), true) ||
        !is_int($port) || $port < 1 || $port > 65535) {
      throw new LogicException('Explicit isolated loopback fixture required.');
    }
    $this->admin = $admin;
    $this->host = $host;
    $this->port = $port;
    $this->namespace = 'governance_native_'.bin2hex(random_bytes(8));
  }

  public function create($quickstart_path) {
    $sql = file_get_contents($quickstart_path);
    if (!is_string($sql) || !$sql) {
      throw new LogicException('Native quickstart source required.');
    }
    $this->quickstartHash = hash('sha256', $sql);
    foreach (self::$tables as $app => $tables) {
      $database = $this->namespace.'_'.$app;
      // Ownership is acquired only after a successful CREATE, never on name
      // assignment. A collision cannot cause cleanup of a foreign database.
      $this->admin->query('CREATE DATABASE `'.$database.
        '` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
      $this->databases[] = $database;
      foreach ($tables as $table) {
        $pattern = '/USE `\{\$NAMESPACE\}_'.preg_quote($app, '/').
          '`;\s*(CREATE TABLE `'.preg_quote($table, '/').
          '` \(.*?\) ENGINE=[^;]+;)/s';
        if (!preg_match($pattern, $sql, $match)) {
          throw new LogicException('Native quickstart definition missing.');
        }
        $this->definitions[$app.'.'.$table] = hash('sha256', $match[1]);
        $ddl = strtr($match[1], array(
          '{$CHARSET}' => 'utf8mb4',
          '{$CHARSET_SORT}' => 'utf8mb4',
          '{$CHARSET_FULLTEXT}' => 'utf8mb4',
          '{$COLLATE_TEXT}' => 'utf8mb4_bin',
          '{$COLLATE_SORT}' => 'utf8mb4_general_ci',
          '{$COLLATE_FULLTEXT}' => 'utf8mb4_general_ci',
        ));
        $ddl = preg_replace('/\ACREATE TABLE `'.preg_quote($table, '/').'`/',
          'CREATE TABLE `'.$database.'`.`'.$table.'`', $ddl);
        $this->admin->query($ddl);
      }
    }
    $database = $this->namespace.'_governance';
    $this->admin->query('CREATE DATABASE `'.$database.'`');
    $this->databases[] = $database;
    $this->admin->query('CREATE TABLE `'.$database.'`.`fixture_guard` '.
      '(id INT UNSIGNED PRIMARY KEY, marker VARCHAR(80) NOT NULL, '.
      'quickstart_sha256 CHAR(64) NOT NULL) ENGINE=InnoDB');
    $this->admin->query('INSERT INTO `'.$database.'`.`fixture_guard` '.
      '(id,marker,quickstart_sha256) VALUES (1,\''.self::MARKER.'\',\''.
      $this->quickstartHash.'\')');
  }

  public function table($app, $table) {
    if (!isset(self::$tables[$app]) ||
        !in_array($table, self::$tables[$app], true)) {
      throw new InvalidArgumentException('Unreviewed fixture table.');
    }
    return '`'.$this->namespace.'_'.$app.'`.`'.$table.'`';
  }

  public function seed($app, $table, array $values) {
    $qualified = $this->table($app, $table);
    $columns = $this->admin->query('SHOW COLUMNS FROM '.$qualified)
      ->fetch_all(MYSQLI_ASSOC);
    $known = array_column($columns, 'Field');
    if (array_diff(array_keys($values), $known)) {
      throw new InvalidArgumentException('Unknown native fixture column.');
    }
    $row = array();
    foreach ($columns as $column) {
      $key = $column['Field'];
      if (array_key_exists($key, $values)) {
        $row[$key] = $values[$key];
      } else if ($column['Default'] !== null) {
        $row[$key] = $column['Default'];
      } else if ($column['Null'] === 'YES') {
        $row[$key] = null;
      } else if (preg_match('/int|decimal|double|float/', $column['Type'])) {
        $row[$key] = 0;
      } else {
        // Only synthetic setup. Explicit semantic fields are supplied below;
        // opaque mandatory fields get harmless ASCII, never real credentials.
        $row[$key] = 'fixture';
      }
    }
    $names = array_map(static function($name) { return '`'.$name.'`'; },
      array_keys($row));
    $statement = $this->admin->prepare('INSERT INTO '.$qualified.' ('.
      implode(',', $names).') VALUES ('.
      implode(',', array_fill(0, count($row), '?')).')');
    $statement->execute(array_values($row));
    $statement->close();
  }

  public static function phid($type, $letter) {
    if (!preg_match('/\A[A-Z]{4}\z/', $type) ||
        !preg_match('/\A[a-z]\z/', $letter)) {
      throw new InvalidArgumentException('Synthetic PHID input required.');
    }
    return 'PHID-'.$type.'-'.str_repeat($letter, 20);
  }

  public function seedBaseline() {
    $ids = array();
    foreach (array('actor' => 'a', 'executor' => 'b', 'approver' => 'c',
      'outsider' => 'd', 'disabled' => 'e') as $name => $letter) {
      $ids[$name] = self::phid('USER', $letter);
      $this->seed('user', 'user', array(
        'id' => ord($letter) - 96, 'phid' => $ids[$name],
        'userName' => 'fixture_'.$name, 'realName' => 'Synthetic '.$name,
        'isAdmin' => $name === 'approver' ? 1 : 0,
        'isApproved' => 1, 'isDisabled' => $name === 'disabled' ? 1 : 0,
        'isEmailVerified' => 1, 'isMailingList' => 0,
        'accountSecret' => str_repeat('x', 64),
      ));
      $this->seed('user', 'phabricator_session', array(
        'id' => ord($letter) - 96, 'phid' => self::phid('SESS', $letter),
        'userPHID' => $ids[$name], 'type' => 'web',
        'sessionKey' => hash('sha256', 'synthetic-'.$name),
        'sessionStart' => time() - 60, 'sessionExpires' => time() + 3600,
      ));
      $ids[$name.'_session'] = hash('sha256',
        hash('sha256', 'synthetic-'.$name));
    }
    $ids['space'] = self::phid('SPCE', 'a');
    $ids['restricted_space'] = self::phid('SPCE', 'b');
    foreach (array(1 => 'space', 2 => 'restricted_space') as $id => $name) {
      $this->seed('spaces', 'spaces_namespace', array(
        'id' => $id, 'phid' => $ids[$name], 'namespaceName' => 'Synthetic '.$name,
        'viewPolicy' => $id === 1 ? 'users' : 'no-one',
        'editPolicy' => 'no-one', 'isArchived' => 0,
        'isDefaultNamespace' => $id === 1 ? 1 : null,
      ));
    }
    $project_paths = array();
    $project_depths = array();
    foreach (array(1 => 'parent', 2 => 'project', 3 => 'milestone',
      4 => 'restricted_project') as $id => $name) {
      $ids[$name] = self::phid('PROJ', chr(96 + $id));
      $parent_name = $id === 2 ? 'parent' : ($id === 3 ? 'project' : null);
      // Match the native save() representation without calling save(): each
      // node contributes its four-byte PHID-derived path key to the parent path.
      $path_key = substr(PhabricatorHash::digestForIndex($ids[$name]), 0, 4);
      $project_paths[$name] = ($parent_name === null ? '' :
        $project_paths[$parent_name]).$path_key;
      $project_depths[$name] = $parent_name === null ? 0 :
        $project_depths[$parent_name] + 1;
      $this->seed('project', 'project', array(
        'id' => $id, 'phid' => $ids[$name], 'name' => 'Synthetic '.$name,
        'authorPHID' => $ids['actor'],
        'status' => PhabricatorProjectStatus::STATUS_ACTIVE,
        'viewPolicy' => 'no-one',
        'editPolicy' => $name === 'parent' ? 'users' : 'no-one',
        'joinPolicy' => 'no-one', 'icon' => 'fa-briefcase', 'color' => 'blue',
        'mailKey' => str_repeat('m', 20), 'primarySlug' => $name,
        'parentProjectPHID' => $id === 1 || $id === 4 ? null :
          ($id === 2 ? $ids['parent'] : $ids['project']),
        'hasSubprojects' => $id === 1 ? 1 : 0,
        'hasMilestones' => $id === 2 ? 1 : 0,
        'milestoneNumber' => $id === 3 ? 1 : null,
        'projectPath' => $project_paths[$name],
        'projectDepth' => $project_depths[$name],
        'projectPathKey' => $path_key, 'properties' => '{}',
        'spacePHID' => $id === 4 ? $ids['restricted_space'] : $ids['space'],
        'subtype' => 'default',
      ));
    }
    foreach (array(array('project', 'actor'), array('parent', 'actor'),
      array('restricted_project', 'actor')) as $pair) {
      // Native parent projects derive membership from descendants: no direct
      // type-13 row or inverse type-14 row belongs in this legal baseline.
      $types = $pair[0] === 'parent' ? array(60) : array(13, 60);
      foreach ($types as $type) {
        $this->seed('project', 'edge', array('src' => $ids[$pair[0]],
          'type' => $type, 'dst' => $ids[$pair[1]],
          'dateCreated' => time(), 'seq' => 0));
      }
      if ($pair[0] !== 'parent') {
        $this->seed('user', 'edge', array('src' => $ids[$pair[1]],
          'type' => 14, 'dst' => $ids[$pair[0]],
          'dateCreated' => time(), 'seq' => 0));
      }
    }
    $ids['task'] = self::phid('TASK', 'a');
    $this->seed('maniphest', 'maniphest_task', array(
      'id' => 1, 'phid' => $ids['task'], 'authorPHID' => $ids['actor'],
      'ownerPHID' => $ids['actor'], 'status' => 'open', 'priority' => 50,
      'title' => 'Synthetic task', 'description' => 'Synthetic content',
      'mailKey' => str_repeat('m', 20), 'viewPolicy' => 'no-one',
      'editPolicy' => 'no-one', 'spacePHID' => $ids['space'],
      'properties' => '{}', 'subtype' => 'default',
    ));
    $ids['wiki'] = self::phid('WIKI', 'a');
    $ids['wiki_content'] = self::phid('WIKC', 'a');
    $this->seed('phriction', 'phriction_content', array(
      'id' => 1, 'version' => 1, 'authorPHID' => $ids['actor'],
      'title' => 'Synthetic wiki', 'slug' => 'synthetic/',
      'content' => 'Synthetic wiki content', 'description' => 'Synthetic edit',
      'phid' => $ids['wiki_content'], 'documentPHID' => $ids['wiki'],
    ));
    $this->seed('phriction', 'phriction_document', array(
      'id' => 1, 'phid' => $ids['wiki'], 'slug' => 'synthetic/', 'depth' => 1,
      'status' => 'active', 'viewPolicy' => 'users', 'editPolicy' => 'no-one',
      'spacePHID' => $ids['space'], 'contentPHID' => $ids['wiki_content'],
      'editedEpoch' => time(), 'maxVersion' => 1,
    ));
    $ids['policy'] = self::phid('PLCY', 'a');
    $this->seed('policy', 'policy', array(
      'id' => 1, 'phid' => $ids['policy'],
      'rules' => json_encode(array(array('action' => 'allow',
        'rule' => 'PhabricatorProjectsPolicyRule', 'value' => array($ids['project'])))),
      'defaultAction' => 'deny',
    ));
    $ids['named_policy'] = self::phid('NPLC', 'a');
    $this->seed('policy', 'policy_namedpolicy', array(
      'id' => 1, 'phid' => $ids['named_policy'],
      'effectivePolicy' => $ids['policy'], 'name' => 'Synthetic named policy',
      'description' => 'Synthetic restricted definition',
      'viewPolicy' => 'no-one', 'editPolicy' => 'no-one',
      'targetObjectType' => 'TASK',
    ));
    foreach (array(2 => 'policy_task', 3 => 'named_task') as $id => $name) {
      $ids[$name] = self::phid('TASK', chr(96 + $id));
      $this->seed('maniphest', 'maniphest_task', array(
        'id' => $id, 'phid' => $ids[$name], 'authorPHID' => $ids['actor'],
        'ownerPHID' => null, 'status' => 'open', 'priority' => 50,
        'title' => 'Synthetic '.$name, 'description' => 'Synthetic policy content',
        'mailKey' => str_repeat('m', 20),
        'viewPolicy' => $name === 'policy_task' ? $ids['policy'] : $ids['named_policy'],
        'editPolicy' => 'no-one', 'spacePHID' => $ids['space'],
        'properties' => '{}', 'subtype' => 'default',
      ));
    }
    return $ids;
  }

  public function createReader() {
    $this->readerUser = 'gov_native_ro_'.bin2hex(random_bytes(6));
    $this->readerPassword = bin2hex(random_bytes(24));
    $this->admin->query('CREATE USER \''.$this->readerUser.
      '\'@\'%\' IDENTIFIED BY \''.$this->readerPassword.'\'');
    $this->readerCreated = true;
    foreach ($this->databases as $database) {
      $grant_name = str_replace('_', '\\_', $database);
      $this->admin->query('GRANT SELECT ON `'.$grant_name.'`.* TO \''.
        $this->readerUser.'\'@\'%\'');
    }
    $this->reader = new mysqli($this->host, $this->readerUser,
      $this->readerPassword, '', $this->port);
    $this->reader->set_charset('utf8mb4');
    return $this->reader;
  }

  public function getReaderConfig() {
    if (!$this->readerCreated) {
      throw new LogicException('Reader has not been created.');
    }
    return array('host' => $this->host, 'port' => $this->port,
      'namespace' => $this->namespace, 'user' => $this->readerUser,
      'password' => $this->readerPassword);
  }

  public function getDefinitionHashes() {
    return array('quickstartSHA256' => $this->quickstartHash,
      'sourceDefinitionHashes' => $this->definitions);
  }

  public function fingerprint() {
    $observations = array();
    foreach (self::$tables as $app => $tables) {
      foreach ($tables as $table) {
        $rows = $this->admin->query('SELECT * FROM '.$this->table($app, $table))
          ->fetch_all(MYSQLI_ASSOC);
        usort($rows, static function($a, $b) {
          return strcmp(serialize($a), serialize($b));
        });
        $observations[$app.'.'.$table] = $rows;
      }
    }
    return hash('sha256', serialize($observations));
  }

  public function cleanup() {
    $errors = array();
    if ($this->reader) {
      $this->reader->close();
      $this->reader = null;
    }
    if ($this->readerCreated) {
      try {
        $this->admin->query('DROP USER \''.$this->readerUser.'\'@\'%\'');
        $this->readerCreated = false;
      } catch (Throwable $ex) {
        $errors[] = 'reader-cleanup';
      }
    }
    foreach (array_reverse($this->databases) as $database) {
      try {
        $this->admin->query('DROP DATABASE `'.$database.'`');
      } catch (Throwable $ex) {
        $errors[] = 'database-cleanup';
      }
    }
    if ($errors) {
      throw new RuntimeException('Owned fixture cleanup failed.');
    }
    return array('ownedSchemasRemoved' => count($this->databases),
      'readerRemoved' => !$this->readerCreated);
  }

}
