<?php

/**
 * Fourth-batch disposable native-DDL observations. Privileged setup is limited
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
  $extra = $fixture->seedBatch4Branches($ids);
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

  $actor = (new PhabricatorUser())->loadFromArray(native_source_row($reader,
    $fixture->table('user', 'user'), $ids['actor']));
  $project = (new PhabricatorProject())->loadFromArray(native_source_row($reader,
    $fixture->table('project', 'project'), $ids['project']));
  $binding = native_source_bind($ids, 'project.description', PhabricatorProject::class,
    $ids['project']);
  $observe = function($class, $value, $expected, $name) use ($actor, $project, $binding) {
    $r = PhorgeGovernanceNativeRuleHierarchyVerifier::observe(
      $binding, $actor, $project, $class, $value);
    native_source_check($r['status'] === 'observed-native-rule-and-hierarchy' &&
      $r['native_rule_result'] === $expected && $r['can_execute'] === false &&
      $r['native_closure_complete'] === false &&
      $r['source_and_native_observation_atomic'] === false, $name);
    return $r;
  };
  $block = function($class, $value, $reasons, $name) use ($actor, $project, $binding) {
    $r = PhorgeGovernanceNativeRuleHierarchyVerifier::observe(
      $binding, $actor, $project, $class, $value);
    native_source_check($r['status'] === 'blocked' &&
      in_array($r['reason'], $reasons, true) && $r['can_execute'] === false, $name);
  };
  $observe(PhabricatorUsersPolicyRule::class, array($ids['actor']), 'allow', 'users-actor-allow');
  $observe(PhabricatorUsersPolicyRule::class, array($ids['outsider']), 'deny', 'users-other-deny');
  $observe(PhabricatorProjectsPolicyRule::class, array($ids['parent']), 'allow', 'any-materialized-parent-allow');
  $observe(PhabricatorProjectsPolicyRule::class, array($extra['unjoined']), 'deny', 'any-unjoined-deny');
  $observe(PhabricatorProjectsAllPolicyRule::class, array($ids['parent'], $ids['project']), 'allow', 'all-parent-child-allow');
  $observe(PhabricatorProjectsAllPolicyRule::class, array($ids['project'], $extra['unjoined']), 'deny', 'all-one-unjoined-deny');
  $observe(PhabricatorProjectsAllPolicyRule::class, array(), 'allow', 'all-empty-local-rule-allow-not-policy');
  $observe(PhabricatorProjectsPolicyRule::class, array(), 'deny', 'any-empty-local-deny');
  $observe(PhabricatorProjectMembersPolicyRule::class, array(), 'allow', 'object-member-inverse-allow');
  foreach (array('parent' => 'deny', 'inverse_only' => 'allow') as $name => $expected) {
    $phid = $ids[$name] ?? $extra[$name];
    $obj = (new PhabricatorProject())->loadFromArray(native_source_row($reader,
      $fixture->table('project', 'project'), $phid));
    $b = native_source_bind($ids, 'project.description', PhabricatorProject::class, $phid);
    $r = PhorgeGovernanceNativeRuleHierarchyVerifier::observe($b, $actor, $obj,
      PhabricatorProjectMembersPolicyRule::class, array());
    native_source_check($r['status'] === 'observed-native-rule-and-hierarchy' &&
      $r['native_rule_result'] === $expected && !$r['can_execute'], 'object-member-'.$name);
  }
  $observe(PhabricatorProjectsPolicyRule::class, array($extra['inverse_only']), 'deny', 'any-ignores-inverse-only');
  $observe(PhabricatorProjectsPolicyRule::class, array($extra['direct_only']), 'deny', 'any-ignores-direct-only');
  $observe(PhabricatorProjectsPolicyRule::class, array($ids['milestone']), 'deny', 'any-milestone-without-materialized-row-denies-despite-parent-members');
  $block('UnknownSyntheticRule', array(), array('unsupported_native_rule_or_value'), 'unknown-rule-blocked');
  $block(PhabricatorUsersPolicyRule::class, array(PhorgeGovernanceNativeFixture::phid('USER','z')),
    array('missing_native_rule_user'), 'missing-user-blocked');
  $block(PhabricatorProjectsPolicyRule::class, array(PhorgeGovernanceNativeFixture::phid('PROJ','z')),
    array('missing_or_unresolved_native_project'), 'missing-project-blocked');
  $block(PhabricatorProjectsPolicyRule::class, array($ids['project'], $ids['project']),
    array('unsupported_native_rule_or_value'), 'duplicate-rule-value-blocked');
  $too_many = array();
  foreach (range('a', 'q') as $letter) { $too_many[] = PhorgeGovernanceNativeFixture::phid('PROJ',$letter); }
  $block(PhabricatorProjectsPolicyRule::class, $too_many,
    array('unsupported_native_rule_or_value'), 'rule-value-budget-blocked');
  $block(PhabricatorProjectsPolicyRule::class, array($extra['deep_leaf']),
    array('native_hierarchy_cycle_or_depth_budget'), 'native-chain-observation-budget-blocked');
  foreach (array('actor', 'executor', 'approver') as $role) {
    $session_table = $fixture->table('user', 'phabricator_session');
    $row = native_source_sql($admin, 'SELECT sessionExpires FROM '.$session_table.' WHERE userPHID=?', array($ids[$role]))->get_result()->fetch_assoc();
    try {
      native_source_sql($admin, 'UPDATE '.$session_table.' SET sessionExpires=1 WHERE userPHID=?',array($ids[$role]));
      $block(PhabricatorProjectsPolicyRule::class,array($ids['project']),
        array('native_account_or_session_unresolved'), 'expired-'.$role.'-session-blocked');
    } finally {
      native_source_sql($admin, 'UPDATE '.$session_table.' SET sessionExpires=? WHERE userPHID=?',array($row['sessionExpires'],$ids[$role]));
    }
  }
  $table = $fixture->table('project', 'project');
  $original = native_source_row($reader, $table, $ids['project']);
  foreach (array('projectPath' => 'bad!', 'projectDepth' => 7, 'projectPathKey' => 'bad!') as $column => $bad) {
    try {
      native_source_sql($admin, 'UPDATE '.$table.' SET `'.$column.'`=? WHERE phid=?', array($bad,$ids['project']));
      $block(PhabricatorProjectsPolicyRule::class, array($ids['project']),
        array('inconsistent_native_hierarchy_attachment','missing_or_unresolved_native_project'), 'corrupt-'.$column.'-blocked');
    } finally {
      native_source_sql($admin, 'UPDATE '.$table.' SET `'.$column.'`=? WHERE phid=?', array($original[$column],$ids['project']));
    }
  }
  foreach (array('cycle' => $ids['project'], 'missing' => PhorgeGovernanceNativeFixture::phid('PROJ','z')) as $name => $parent) {
    try {
      native_source_sql($admin, 'UPDATE '.$table.' SET parentProjectPHID=? WHERE phid=?',array($parent,$ids['project']));
      $block(PhabricatorProjectsPolicyRule::class,array($ids['project']),
        array($name === 'cycle' ? 'cyclic_project_parent' : 'missing_project_parent'), $name.'-parent-blocked');
    } finally {
      native_source_sql($admin, 'UPDATE '.$table.' SET parentProjectPHID=? WHERE phid=?',array($original['parentProjectPHID'],$ids['project']));
    }
  }
  $policy_table = $fixture->table('policy', 'policy');
  $policy_row = native_source_row($reader,$policy_table,$ids['policy']);
  try {
    native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=? WHERE phid=?',
      array(json_encode(array(array('action'=>'allow','rule'=>'UnknownSyntheticRule','value'=>array()))),$ids['policy']));
    $block(PhabricatorUsersPolicyRule::class,array($ids['actor']),
      array('unsupported_native_policy_rule'),'unknown-stored-rule-blocked');
  } finally {
    native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=? WHERE phid=?',array($policy_row['rules'],$ids['policy']));
  }
  $user_table = $fixture->table('user','user');
  try {
    native_source_sql($admin,'UPDATE '.$user_table.' SET isDisabled=1 WHERE phid=?',array($ids['executor']));
    $block(PhabricatorUsersPolicyRule::class,array($ids['actor']),
      array('native_account_or_session_unresolved'),'current-executor-disabled-blocked');
  } finally {
    native_source_sql($admin,'UPDATE '.$user_table.' SET isDisabled=0 WHERE phid=?',array($ids['executor']));
  }
  $wiki = (new PhrictionDocument())->loadFromArray(native_source_row($reader,
    $fixture->table('phriction','phriction_document'),$ids['wiki']));
  $content = (new PhrictionContent())->loadFromArray(native_source_row($reader,
    $fixture->table('phriction','phriction_content'),$ids['wiki_content']));
  $observer = PhorgeGovernanceNativeObservationVerifier::newIsolated();
  $empty = (new PhrictionTransaction())->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)->setNewValue('');
  $missing = $observer->observeWikiBatchExpansion($wiki,array($empty));
  native_source_check($missing['status'] === 'blocked' && $missing['reason'] === 'unsupported_attached_wiki_content','wiki-missing-content-blocked');
  $wiki->attachContent($content);
  $r = $observer->observeWikiBatchExpansion($wiki,array($empty));
  native_source_check($r['status'] === 'observed-native-local-expansion' && count($r['transactions']) === 2 &&
    $r['transactions'][1]['content_delete'] === true && $r['editor_context']['old_content_version'] === 1 &&
    !$r['can_execute'], 'wiki-real-content-empty-batch-expands-delete');
  $move = (new PhrictionTransaction())->setTransactionType(PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE)->setNewValue(clone $wiki);
  $r = $observer->observeWikiBatchExpansion($wiki,array($move,$empty));
  $types = array_column($r['transactions'] ?? array(), 'transaction_type');
  native_source_check($r['status'] === 'observed-native-local-expansion' && $types === array(
    PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE, PhabricatorTransactions::TYPE_VIEW_POLICY,
    PhabricatorTransactions::TYPE_EDIT_POLICY, PhrictionDocumentContentTransaction::TRANSACTIONTYPE,
    PhrictionDocumentDeleteTransaction::TRANSACTIONTYPE), 'wiki-move-empty-real-native-order');
  $bad = clone $content; $bad->setDocumentPHID(PhorgeGovernanceNativeFixture::phid('WIKI','z'));
  $wiki->attachContent($bad);
  $r = $observer->observeWikiBatchExpansion($wiki,array($empty));
  native_source_check($r['status'] === 'blocked' && $r['reason'] === 'unsupported_attached_wiki_content','wiki-mismatched-content-blocked');
  $bad = clone $content; $bad->setPHID(PhorgeGovernanceNativeFixture::phid('WRDS','z'));
  $wiki->attachContent($bad);
  $r = $observer->observeWikiBatchExpansion($wiki,array($empty));
  native_source_check($r['status'] === 'blocked' && $r['reason'] === 'unsupported_attached_wiki_content','wiki-stale-content-identity-blocked');
  $wiki->attachContent($content);
  $unknown = (new PhrictionTransaction())->setTransactionType('unknown.synthetic')->setNewValue('');
  $r = $observer->observeWikiBatchExpansion($wiki,array($unknown));
  native_source_check($r['status'] === 'blocked' && $r['reason'] === 'unsupported_local_closure','wiki-unknown-transaction-blocked');
  foreach (array(PhabricatorTransactions::TYPE_VIEW_POLICY,PhabricatorTransactions::TYPE_EDIT_POLICY) as $type) {
    $tx = (new PhrictionTransaction())->setTransactionType($type)->setNewValue('users');
    $r = $observer->observeWikiLocalExpansion($wiki,array($tx));
    native_source_check($r['status'] === 'observed-native-local-expansion' &&
      count($r['transactions']) === 1 && $r['transactions'][0]['transaction_type'] === $type &&
      !$r['can_execute'] && !$r['risk_complete'], 'wiki-native-policy-passthrough-'.$type);
    $task = (new ManiphestTask())->loadFromArray(native_source_row($reader,
      $fixture->table('maniphest','maniphest_task'),$ids['task']));
    $tx = (new ManiphestTransaction())->setTransactionType($type)->setNewValue('users');
    $r = $observer->observeTaskLocalExpansion($task,$actor,array($tx));
    native_source_check($r['status'] === 'observed-native-local-expansion' &&
      count($r['transactions']) === 1 && !$r['can_execute'] && !$r['risk_complete'],
      'task-native-policy-passthrough-'.$type);
  }
  native_source_check($fixture->fingerprint() === $baseline_fingerprint,'native-observations-and-restored-mutations-preserve-rows');
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
  'batch' => '4-native-rule-branches', 'native_closure_complete' => false,
  'can_execute' => false, 'atomicity_proved' => false,
  'passed' => $failure === null, 'checks' => $checks, 'check_count' => count($checks),
  'php_version' => PHP_VERSION, 'readerWritesPerformed' => $reader_writes,
  'sourceDefinitionHashes' => $definitions['sourceDefinitionHashes'] ?? array(),
  'quickstartSHA256' => $definitions['quickstartSHA256'] ?? null,
  'cleanup' => $cleanup, 'failure' => $failure,
  'scope' => 'owned-native-ddl-and-synthetic-rows; partial-native-methods; '.
    'no-production-config-no-native-closure-no-writer-no-executor',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($failure === null ? 0 : 1);
