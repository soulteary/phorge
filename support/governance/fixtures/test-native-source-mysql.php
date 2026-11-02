<?php

/**
 * Third-batch disposable native-DDL observations. Privileged setup is limited
 * to schemas/users successfully created by this invocation; observation uses
 * a separate SELECT-only principal. Never loads a deployment configuration.
 */
require_once dirname(__DIR__, 2).'/runtime/php-version.php';
require_once __DIR__.'/PhorgeGovernanceNativeFixture.php';
$root = dirname(__DIR__, 3);
$stage = 'preflight';
$checks = array();
$failure = null;
$fixture = null;
$admin = null;
$native_reader = null;
$bounded = null;
$deadline_reader = null;
$reader_writes = 0;
$cleanup = array();
$definitions = array();
$environment_state = null;
$request_cache_state = null;
$request_cache_saved = false;

function native_source_check($condition, $name) {
  global $stage, $checks;
  $stage = $name;
  if (!$condition) {
    throw new RuntimeException('Native fixture assertion failed.');
  }
  $checks[] = $name;
}

function native_source_rejects($callback, $name) {
  $rejected = false;
  try {
    $callback();
  } catch (RuntimeException $exception) {
    $rejected = true;
  }
  native_source_check($rejected, $name);
}

function native_source_sql(mysqli $connection, $sql, array $values = array()) {
  $statement = $connection->prepare($sql);
  $statement->execute($values);
  return $statement;
}

function native_source_row(mysqli $reader, $table, $phid) {
  $statement = native_source_sql($reader,
    'SELECT * FROM '.$table.' WHERE phid = ?', array($phid));
  $row = $statement->get_result()->fetch_assoc();
  $statement->close();
  if (!$row) {
    throw new RuntimeException('Missing synthetic native row.');
  }
  return $row;
}

function native_source_capability($observer, $viewer, $object, $capability,
  $expected, $name) {
  $result = $observer->observeCapabilities($viewer, $object, array($capability));
  native_source_check($result['status'] === 'observed-native-capability' &&
    $result['can_execute'] === false &&
    $result['observations'][$capability]['policy_filter'] === $expected, $name);
  return $result;
}

function native_source_revision($capture, $kind) {
  foreach ($capture->getSources() as $source) {
    $row = $source->getData();
    if ($row['kind'] === $kind) {
      return $row['revision'];
    }
  }
  throw new RuntimeException('Missing expected native source kind.');
}

function native_source_bind($ids, $operation, $object_class, $target) {
  return array(
    'operation_id' => 'synthetic-native-observation',
    'actor' => $ids['actor'], 'executor' => $ids['executor'],
    'approver' => $ids['approver'], 'target' => $target,
    'operation' => $operation, 'object_class' => $object_class,
    'parameters_digest' => hash('sha256', 'synthetic-native-parameters'),
    'actor_session' => $ids['actor_session'],
    'executor_session' => $ids['executor_session'],
    'approver_session' => $ids['approver_session'],
  );
}

try {
  $stage = 'runtime-preflight';
  phorge_assert_supported_php_version(PHP_VERSION);
  if (PHP_SAPI !== 'cli' ||
      getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
      getenv('PHABRICATOR_ENV') || getenv('PHORGE_DEPLOYMENT_CONFIG') ||
      getenv('PHORGE_CONTROL_PLANE')) {
    throw new LogicException('Explicit isolated CLI context required.');
  }
  foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $name) {
    if (file_exists($root.'/conf/local/'.$name)) {
      throw new LogicException('Deployment configuration refused.');
    }
  }
  require_once $root.'/support/runtime/bootstrap-cli.php';
  phutil_load_library($root.'/src/');
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
    throw new LogicException('Explicit isolated fixture credentials required.');
  }
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $port = isset($options['port']) ? (int)$options['port'] : 3306;
  $admin = new mysqli($options['host'], $username, $password, '', $port);
  $admin->set_charset('utf8mb4');
  $fixture = new PhorgeGovernanceNativeFixture($admin, $options['host'], $port);
  $stage = 'create-native-ddl';
  $fixture->create($root.'/resources/sql/quickstart.sql');
  $definitions = $fixture->getDefinitionHashes();
  native_source_check(count($definitions['sourceDefinitionHashes']) === 12,
    'twelve-tables-extracted-from-current-native-quickstart');
  $ids = $fixture->seedBaseline();
  $parent_direct = native_source_sql($admin,
    'SELECT COUNT(*) FROM '.$fixture->table('project', 'edge').
    ' WHERE src = ? AND type = 13', array($ids['parent']))
    ->get_result()->fetch_row();
  $parent_inverse = native_source_sql($admin,
    'SELECT COUNT(*) FROM '.$fixture->table('user', 'edge').
    ' WHERE dst = ? AND type = 14', array($ids['parent']))
    ->get_result()->fetch_row();
  $parent_materialized = native_source_sql($admin,
    'SELECT COUNT(*) FROM '.$fixture->table('project', 'edge').
    ' WHERE src = ? AND type = 60', array($ids['parent']))
    ->get_result()->fetch_row();
  native_source_check((int)$parent_direct[0] === 0 &&
    (int)$parent_inverse[0] === 0 && (int)$parent_materialized[0] === 1,
    'legal-parent-fixture-has-materialized-only-membership');
  $reader = $fixture->createReader();
  $config = $fixture->getReaderConfig();
  $edge = $fixture->table('project', 'edge');
  foreach (array(
    'insert' => 'INSERT INTO '.$edge.
      ' (src,type,dst,dateCreated,seq) VALUES (\'synthetic\',13,\'synthetic\',0,0)',
    'update' => 'UPDATE '.$edge.' SET dateCreated=dateCreated WHERE 1=0',
    'delete' => 'DELETE FROM '.$edge.' WHERE 1=0',
    'alter' => 'ALTER TABLE '.$edge.' ADD COLUMN forbidden_fixture INT',
    'drop' => 'DROP TABLE '.$edge,
    'truncate' => 'TRUNCATE TABLE '.$edge,
  ) as $kind => $sql) {
    $denied = false;
    try {
      $reader->query($sql);
      $reader_writes++;
    } catch (mysqli_sql_exception $exception) {
      $denied = in_array($exception->getCode(), array(1142, 1044), true);
    }
    native_source_check($denied, 'select-only-principal-denies-'.$kind);
  }
  $baseline_fingerprint = $fixture->fingerprint();
  $stage = 'native-observer-setup';
  // Only the test configuration source is injected. We do not initialize a
  // normal environment (which could read local/deployment/default SQL first),
  // and do not substitute any native policy method or result.
  if (PhabricatorEnv::getConfigSourceStack() !== null) {
    throw new LogicException('Fresh isolated configuration source required.');
  }
  // Refuse an inherited SQL context before acquiring any restoration/cleanup
  // ownership. In particular, finally must never close someone else's Lisk
  // connection merely because this one-shot fixture refused to run.
  $connections_property = new ReflectionProperty(LiskDAO::class, 'connections');
  if ($connections_property->getValue()) {
    throw new LogicException('Existing native SQL connections refused.');
  }
  $environment_state = (new ReflectionClass(PhabricatorEnv::class))->getStaticProperties();
  $cache_property = new ReflectionProperty(PhabricatorCaches::class, 'requestCache');
  $request_cache_state = $cache_property->getValue();
  $request_cache_saved = true;
  $cache_property->setValue(null, null);
  $stack = new PhabricatorConfigStackSource();
  $stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host' => $config['host'], 'mysql.port' => $config['port'],
    'mysql.user' => $config['user'], 'mysql.pass' => $config['password'],
    'storage.default-namespace' => $config['namespace'],
    'policy.allow-public' => false, 'phabricator.silent' => true,
    'phabricator.base-uri' => 'http://fixture.invalid/',
    'projects.default-navigation' => 'flat', 'project.history-state' => 'off',
    'project.workspace-mode' => 'native', 'project.history-task-drafts' => false,
    'gorge.search.source-scan' => false, 'gorge.search.projection-shadow' => false,
  )));
  $property = new ReflectionProperty(PhabricatorEnv::class, 'sourceStack');
  $property->setValue(null, $stack);
  // Normal bootstrap initializes this cache; our bounded stack injection must
  // initialize it too, without bootstrapping a default database connection.
  (new ReflectionProperty(PhabricatorEnv::class, 'cache'))->setValue(null, array());
  foreach (array(
    array('user', 'user', PhabricatorUser::class),
    array('user', 'phabricator_session', PhabricatorAuthSession::class),
    array('project', 'project', PhabricatorProject::class),
    array('maniphest', 'maniphest_task', ManiphestTask::class),
    array('phriction', 'phriction_document', PhrictionDocument::class),
    array('phriction', 'phriction_content', PhrictionContent::class),
    array('policy', 'policy', PhabricatorPolicy::class),
    array('policy', 'policy_namedpolicy', PhorgeNamedPolicy::class),
    array('spaces', 'spaces_namespace', PhabricatorSpacesNamespace::class),
    array('config', 'config_entry', PhabricatorConfigEntry::class),
  ) as $schema) {
    $class = $schema[2];
    $expected_columns = array_keys((new $class())->getSchemaColumns());
    $actual_columns = array_column($reader->query('SHOW COLUMNS FROM '.
      $fixture->table($schema[0], $schema[1]))->fetch_all(MYSQLI_ASSOC), 'Field');
    sort($expected_columns);
    sort($actual_columns);
    native_source_check($expected_columns === $actual_columns,
      'native-model-column-set-matches-quickstart-'.$schema[0].'-'.$schema[1]);
  }
  $users = array();
  foreach (array('actor', 'executor', 'approver', 'outsider', 'disabled') as $name) {
    $users[$name] = (new PhabricatorUser())->loadFromArray(
      native_source_row($reader, $fixture->table('user', 'user'), $ids[$name]));
  }
  $projects = array();
  foreach (array('parent', 'project', 'milestone', 'restricted_project') as $name) {
    $project = (new PhabricatorProject())->loadFromArray(
      native_source_row($reader, $fixture->table('project', 'project'), $ids[$name]));
    $result = native_source_sql($reader, 'SELECT dst FROM '.$edge.
      ' WHERE src = ? AND type = 60 ORDER BY dst', array($ids[$name]));
    $project->attachMemberPHIDs(array_column(
      $result->get_result()->fetch_all(MYSQLI_ASSOC), 'dst'));
    $result->close();
    $project->attachParentProject(
      $name === 'project' ? $projects['parent'] :
      ($name === 'milestone' ? $projects['project'] : null));
    $projects[$name] = $project;
  }
  native_source_check($projects['project']->getAncestorProjectPaths() ===
      array($projects['parent']->getProjectPath()) &&
    $projects['milestone']->getAncestorProjectPaths() === array(
      $projects['project']->getProjectPath(), $projects['parent']->getProjectPath()),
    'fixture-paths-follow-actual-native-ancestor-key-representation');
  $task = (new ManiphestTask())->loadFromArray(
    native_source_row($reader, $fixture->table('maniphest', 'maniphest_task'),
      $ids['task']));
  $wiki = (new PhrictionDocument())->loadFromArray(
    native_source_row($reader, $fixture->table('phriction', 'phriction_document'),
      $ids['wiki']));
  $policy_task = (new ManiphestTask())->loadFromArray(
    native_source_row($reader, $fixture->table('maniphest', 'maniphest_task'),
      $ids['policy_task']));
  $named_task = (new ManiphestTask())->loadFromArray(
    native_source_row($reader, $fixture->table('maniphest', 'maniphest_task'),
      $ids['named_task']));
  $observer = PhorgeGovernanceNativeObservationVerifier::newIsolated();
  $view = PhabricatorPolicyCapability::CAN_VIEW;
  $edit = PhabricatorPolicyCapability::CAN_EDIT;
  native_source_capability($observer, $users['actor'], $projects['project'],
    $view, 'allow', 'native-project-materialized-member-view-observed');
  native_source_capability($observer, $users['actor'], $projects['project'],
    $edit, 'allow', 'native-ancestor-edit-observed');
  native_source_capability($observer, $users['actor'], $projects['milestone'],
    $view, 'allow', 'native-milestone-parent-membership-observed');
  native_source_capability($observer, $users['approver'],
    $projects['restricted_project'], $view, 'deny',
    'native-system-admin-does-not-bypass-object-or-space');
  native_source_capability($observer, $users['actor'],
    $projects['restricted_project'], $view, 'deny',
    'native-space-denies-even-automatic-project-member');
  native_source_capability($observer, $users['actor'], $task, $view, 'allow',
    'native-task-owner-automatic-view-observed');
  native_source_capability($observer, $users['actor'], $task, $edit, 'allow',
    'native-task-owner-automatic-edit-observed');
  native_source_capability($observer, $users['outsider'], $task, $view, 'deny',
    'native-nonowner-noone-policy-denied');
  native_source_capability($observer, $users['approver'], $task, $view, 'deny',
    'native-system-admin-does-not-bypass-object-noone-policy');
  native_source_capability($observer, $users['actor'], $wiki, $view, 'allow',
    'native-wiki-users-view-observed');
  native_source_capability($observer, $users['actor'], $policy_task,
    $view, 'allow', 'native-custom-project-rule-sql-member-allowed');
  native_source_capability($observer, $users['outsider'], $policy_task,
    $view, 'deny', 'native-custom-project-rule-sql-nonmember-denied');
  native_source_capability($observer, $users['actor'], $named_task,
    $view, 'allow', 'native-named-policy-effective-custom-rule-allowed');
  native_source_capability($observer, $users['outsider'], $named_task,
    $view, 'deny', 'native-named-policy-effective-custom-rule-nonmember-denied');
  $dictionary = $stack->getStack()[0];
  $env_cache = new ReflectionProperty(PhabricatorEnv::class, 'cache');
  foreach (array(
    'storage.default-namespace' => 'governance_native_0000000000000000',
    'cluster.databases' => array(array('role' => 'master', 'host' => '127.0.0.1')),
  ) as $key => $untrusted_value) {
    PhabricatorEnv::getEnvConfig($key);
    $saved_cache = $env_cache->getValue();
    $saved_dictionary = $dictionary->getKeys(array($key));
    try {
      // Keep the old effective config cache deliberately: the guard must
      // compare underlying sources too, not trust a previously cached label.
      $dictionary->setKeys(array($key => $untrusted_value));
      $refused = $observer->observeCapabilities(
        $users['actor'], $projects['project'], array($view));
      native_source_check($refused['status'] === 'blocked' &&
        ($refused['reason'] ?? null) === 'native_context_unverified' &&
        $refused['can_execute'] === false && !isset($refused['observations']),
        'native-context-rejects-stale-routing-'.$key);
    } finally {
      if (array_key_exists($key, $saved_dictionary)) {
        $dictionary->setKeys($saved_dictionary);
      } else {
        $dictionary->deleteKeys(array($key));
      }
      $env_cache->setValue(null, $saved_cache);
    }
  }
  $cache_property = new ReflectionProperty(PhabricatorCaches::class, 'requestCache');
  $saved_request_cache = $cache_property->getValue();
  $poison = new PhutilInRequestKeyValueCache();
  $poison->setKeys(array(
    PhabricatorDatabaseRef::KEY_REFS => array('untrusted-cache-ref'),
    PhabricatorDatabaseRef::KEY_INDIVIDUAL => 'untrusted-cache-ref',
    PhabricatorSpacesNamespaceQuery::KEY_ALL => array(),
  ));
  $report_mode = (new mysqli_driver())->report_mode;
  $cache_property->setValue(null, $poison);
  mysqli_report(MYSQLI_REPORT_OFF);
  try {
    native_source_capability($observer, $users['actor'], $projects['project'],
      $view, 'allow', 'native-context-isolates-inherited-dbref-and-space-cache');
    native_source_check($cache_property->getValue() === $poison &&
      $poison->getKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL) ===
        'untrusted-cache-ref' &&
      (new mysqli_driver())->report_mode === MYSQLI_REPORT_OFF,
      'native-context-restores-original-cache-and-mysqli-report-mode');
  } finally {
    $cache_property->setValue(null, $saved_request_cache);
    mysqli_report($report_mode);
  }
  native_source_check($fixture->fingerprint() === $baseline_fingerprint,
    'native-policy-observations-do-not-write-native-tables');

  $stage = 'native-source-reader';
  $privileged_refused = false;
  try {
    PhorgeGovernanceNativeSourceReader::newFixture(array_merge($config,
      array('user' => $username, 'password' => $password)));
  } catch (RuntimeException $exception) {
    $privileged_refused = $exception->getMessage() === 'native_source_grants_refused';
  }
  native_source_check($privileged_refused,
    'privileged-principal-refused-by-actual-show-grants');
  $native_reader = PhorgeGovernanceNativeSourceReader::newFixture($config);
  $binding = native_source_bind($ids, 'project.description',
    PhabricatorProject::class, $ids['project']);
  $first = $native_reader->capture($binding);
  $summary = $first->getSummary();
  native_source_check($summary['status'] === 'native-observed-blocked' &&
    $summary['native_closure_complete'] === false &&
    $summary['can_execute'] === false,
    'real-native-source-observation-keeps-closure-and-execution-blocked');
  native_source_check($summary['quickstart_sha256'] ===
    $definitions['quickstartSHA256'], 'reader-proves-current-native-ddl-source');
  $session_sources = array();
  foreach ($first->getSources() as $source) {
    $row = $source->getData();
    if ($row['kind'] === 'auth.session') {
      $session_sources[$row['role']] = $row['eligibility'];
    }
  }
  ksort($session_sources, SORT_STRING);
  native_source_check($session_sources === array(
    'actor.auth.session' => 'eligible',
    'approver.auth.session' => 'eligible',
    'executor.auth.session' => 'eligible',
  ), 'actual-stored-session-key-digests-bind-three-eligible-native-sessions');
  native_source_check($fixture->fingerprint() === $baseline_fingerprint,
    'select-only-native-source-observation-does-not-write');
  foreach (array(
    array('task.title', ManiphestTask::class, $ids['task']),
    array('wiki.content', PhrictionDocument::class, $ids['wiki']),
  ) as $case) {
    $observed = $native_reader->capture(native_source_bind(
      $ids, $case[0], $case[1], $case[2]))->getSummary();
    native_source_check($observed['can_execute'] === false &&
      $observed['native_closure_complete'] === false,
      'native-source-family-'.($case[1] === ManiphestTask::class ? 'task' : 'wiki').
      '-remains-blocked');
  }
  // One private cross-schema snapshot: user.user is read before another
  // connection mutates project/task rows. finish observes the old statement
  // epoch, and a fresh capture observes the committed changes. This is an
  // observation consistency test, never a lock held through a native write.
  $native_reader->beginCapture($binding);
  native_source_sql($admin, 'UPDATE '.$fixture->table('project', 'project').
    ' SET viewPolicy = ? WHERE phid = ?', array('users', $ids['project']))->close();
  native_source_sql($admin, 'UPDATE '.$fixture->table('maniphest', 'maniphest_task').
    ' SET ownerPHID = ? WHERE phid = ?', array($ids['outsider'], $ids['task']))->close();
  $held = $native_reader->finishCapture()->getSummary();
  $fresh = $native_reader->capture($binding)->getSummary();
  native_source_check($held['table_fingerprints']['project.project'] ===
      $summary['table_fingerprints']['project.project'] &&
    $held['table_fingerprints']['maniphest.maniphest_task'] ===
      $summary['table_fingerprints']['maniphest.maniphest_task'],
    'native-cross-schema-held-snapshot-consistent-after-other-commit');
  native_source_check($fresh['table_fingerprints']['project.project'] !==
      $held['table_fingerprints']['project.project'] &&
    $fresh['table_fingerprints']['maniphest.maniphest_task'] !==
      $held['table_fingerprints']['maniphest.maniphest_task'],
    'native-fresh-capture-detects-committed-policy-and-owner-change');
  native_source_sql($admin, 'UPDATE '.$fixture->table('project', 'project').
    ' SET viewPolicy = ? WHERE phid = ?', array('no-one', $ids['project']))->close();
  native_source_sql($admin, 'UPDATE '.$fixture->table('maniphest', 'maniphest_task').
    ' SET ownerPHID = ? WHERE phid = ?', array($ids['actor'], $ids['task']))->close();

  $project_table = $fixture->table('project', 'project');
  $original_project_row = native_source_row($reader, $project_table, $ids['project']);
  foreach (array('projectPath' => $original_project_row['projectPath'].'zzzz',
    'projectDepth' => (int)$original_project_row['projectDepth'] + 1,
    'projectPathKey' => 'zzzz') as $column => $changed_value) {
    try {
      native_source_sql($admin, 'UPDATE '.$project_table.' SET `'.$column.
        '` = ? WHERE phid = ?', array($changed_value, $ids['project']))->close();
      $changed = $native_reader->capture($binding);
      native_source_check(native_source_revision($changed, 'project.parent') !==
          native_source_revision($first, 'project.parent') &&
        $changed->getSummary()['table_fingerprints']['project.project'] !==
          $summary['table_fingerprints']['project.project'],
        'native-project-index-change-observed-'.$column);
    } finally {
      native_source_sql($admin, 'UPDATE '.$project_table.' SET `'.$column.
        '` = ? WHERE phid = ?',
        array($original_project_row[$column], $ids['project']))->close();
    }
  }
  $deadline_reader = PhorgeGovernanceNativeSourceReader::newFixture(
    array_merge($config, array('seconds' => 1)));
  $deadline_reader->beginCapture($binding);
  usleep(1100000);
  native_source_rejects(function() use ($deadline_reader) {
    $deadline_reader->finishCapture();
  }, 'native-elapsed-budget-across-begin-finish-refuses');
  $deadline_reader = null;
  $policy_table = $fixture->table('policy', 'policy');
  $old_rules = native_source_row($reader, $policy_table, $ids['policy'])['rules'];
  native_source_sql($admin, 'UPDATE '.$policy_table.' SET rules = ? WHERE phid = ?',
    array(json_encode(array(array('action' => 'allow',
      'rule' => 'UnsupportedSyntheticNativeRule', 'value' => array()))),
      $ids['policy']))->close();
  $unknown = $native_reader->capture($binding)->getSummary();
  native_source_check(in_array('unsupported_native_policy_rule',
      $unknown['unknown_reasons'], true) && !$unknown['can_execute'],
    'unknown-native-policy-rule-is-explicitly-unsupported');
  native_source_sql($admin, 'UPDATE '.$policy_table.' SET rules = ? WHERE phid = ?',
    array($old_rules, $ids['policy']))->close();
  $config_table = $fixture->table('config', 'config_entry');
  $hidden_table = str_replace('`config_entry`', '`config_entry_missing`', $config_table);
  $admin->query('RENAME TABLE '.$config_table.' TO '.$hidden_table);
  try {
    native_source_rejects(function() use ($native_reader, $binding) {
      $native_reader->capture($binding);
    }, 'missing-native-table-refuses-observation');
  } finally {
    $admin->query('RENAME TABLE '.$hidden_table.' TO '.$config_table);
  }
  $admin->query('ALTER TABLE '.$config_table.' ENGINE=MyISAM');
  try {
    native_source_rejects(function() use ($native_reader, $binding) {
      $native_reader->capture($binding);
    }, 'non-innodb-table-refuses-consistent-observation');
  } finally {
    $admin->query('ALTER TABLE '.$config_table.' ENGINE=InnoDB');
  }
  $project_table = $fixture->table('project', 'project');
  $admin->query('ALTER TABLE '.$project_table.' MODIFY viewPolicy LONGTEXT NOT NULL');
  try {
    native_source_rejects(function() use ($native_reader, $binding) {
      $native_reader->capture($binding);
    }, 'native-policy-column-type-drift-refuses-before-unbounded-values');
  } finally {
    $admin->query('ALTER TABLE '.$project_table.' MODIFY viewPolicy VARBINARY(64) NOT NULL');
  }
  $native_reader->beginCapture($binding);
  putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=0');
  try {
    native_source_rejects(function() use ($native_reader) {
      $native_reader->finishCapture();
    }, 'isolation-scope-revoked-between-begin-and-finish-refuses');
  } finally {
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');
  }
  $after_scope = $native_reader->capture($binding)->getSummary();
  native_source_check($after_scope['table_fingerprints'] ===
    $summary['table_fingerprints'],
    'scope-refusal-rolls-back-and-allows-new-fresh-observation');
  $bounded = PhorgeGovernanceNativeSourceReader::newFixture(
    array_merge($config, array('row_limit' => 1)));
  native_source_rejects(function() use ($bounded, $binding) {
    $bounded->capture($binding);
  }, 'native-row-budget-overflow-refuses-observation');
  native_source_check($fixture->fingerprint() === $baseline_fingerprint,
    'all-readers-and-negative-observations-leave-native-rows-unchanged');
  $stage = 'report-redaction';
  $serialized = json_encode(array($summary, $fresh, $unknown));
  foreach (array_values($ids) as $private_value) {
    native_source_check(!str_contains($serialized, $private_value),
      'safe-source-summary-excludes-synthetic-identity-'.count($checks));
  }
  native_source_check(!str_contains($serialized, $config['namespace']) &&
    !str_contains($serialized, $config['user']) &&
    !str_contains($serialized, $config['password']),
    'safe-source-summary-excludes-connection-and-credential-values');
} catch (Throwable $exception) {
  $failure = array('stage' => $stage, 'error_type' => get_class($exception),
    'source_file' => basename($exception->getFile()),
    'source_line' => $exception->getLine());
} finally {
  $native_reader = null;
  $bounded = null;
  $deadline_reader = null;
  if ($environment_state !== null) {
    try {
      LiskDAO::closeAllConnections();
      foreach ($environment_state as $key => $value) {
        (new ReflectionProperty(PhabricatorEnv::class, $key))->setValue(null, $value);
      }
      if ($request_cache_saved) {
        (new ReflectionProperty(PhabricatorCaches::class, 'requestCache'))
          ->setValue(null, $request_cache_state);
      }
      $checks[] = 'injected-native-environment-state-restored';
    } catch (Throwable $exception) {
      $failure = array('stage' => 'environment-restoration',
        'error_type' => get_class($exception));
    }
  }
  if ($fixture) {
    try {
      $cleanup = $fixture->cleanup();
      $checks[] = 'only-owned-native-schemas-and-reader-cleaned';
    } catch (Throwable $exception) {
      $failure = array('stage' => 'owned-cleanup',
        'error_type' => get_class($exception));
    }
  }
  if ($admin) {
    $admin->close();
  }
}
echo json_encode(array(
  'passed' => $failure === null, 'checks' => $checks, 'check_count' => count($checks),
  'php_version' => PHP_VERSION, 'readerWritesPerformed' => $reader_writes,
  'sourceDefinitionHashes' => $definitions['sourceDefinitionHashes'] ?? array(),
  'quickstartSHA256' => $definitions['quickstartSHA256'] ?? null,
  'cleanup' => $cleanup, 'failure' => $failure,
  'scope' => 'owned-native-ddl-and-synthetic-rows; partial-native-methods; '.
    'no-production-config-no-native-closure-no-writer-no-executor',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($failure === null ? 0 : 1);
