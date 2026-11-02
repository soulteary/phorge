<?php

/**
 * Fifth-batch disposable native-DDL observations. Privileged setup is limited
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

function native_pipeline_orm($class, $phid) {
  if (!in_array($class, array(PhabricatorUser::class, PhabricatorProject::class,
    ManiphestTask::class, PhrictionDocument::class, PhrictionContent::class), true)) {
    throw new LogicException('Unsupported fixed fixture model.');
  }
  $template = new $class();
  $context = null;
  try {
    $context = PhorgeGovernanceNativeCapabilityContext::newIsolated($template);
    $object = $template->loadOneWhere('phid = %s', $phid);
    if ($object === null || !$context->isCurrent()) {
      throw new RuntimeException('Owned native ORM fixture observation failed.');
    }
    return $object;
  } finally {
    if ($context !== null && !$context->close()) {
      throw new RuntimeException('Owned native ORM fixture cleanup failed.');
    }
  }
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

  $observer = PhorgeGovernanceNativeObservationVerifier::newIsolated();
  $member = (new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
    ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
    ->setNewValue(array('+' => array($ids['outsider'] => array())));
  $check_validation = function($name, $txs, $expected, $check) use ($observer, $ids) {
    $project = native_pipeline_orm(PhabricatorProject::class,$ids[$name]);
    $result = $observer->observeProjectLocalValidation($project,$txs);
    native_source_check($result['status'] === 'observed-native-project-local-validation' &&
      $result['local_validation'] === $expected &&
      $result['pipeline_validation_complete'] === false &&
      $result['can_execute'] === false && $result['risk_complete'] === false &&
      ($expected === 'valid' ? $result['validation_error_count'] === 0 :
        $result['validation_error_count'] > 0), $check);
  };
  $check_validation('project',array($member),'valid','native-orm-leaf-project-member-local-valid');
  $check_validation('parent',array($member),'invalid','native-orm-parent-project-member-local-invalid');
  $check_validation('milestone',array($member),'invalid','native-orm-existing-milestone-member-local-invalid');
  $parent_tx = (new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectParentTransaction::TRANSACTIONTYPE)
    ->setNewValue($ids['parent']);
  $milestone_tx = (new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE)
    ->setNewValue($ids['parent']);
  $check_validation('project',array($milestone_tx,$member),'invalid','native-new-milestone-member-local-invalid');
  $check_validation('project',array($parent_tx,$milestone_tx),'invalid','native-parent-and-milestone-local-invalid');
  $clear = clone $milestone_tx; $clear->setNewValue(null);
  $check_validation('project',array($parent_tx,$clear),'valid','native-cleared-milestone-and-parent-local-valid');
  $unknown = (new PhabricatorProjectTransaction())->setTransactionType('unknown.synthetic')->setNewValue(null);
  $result = $observer->observeProjectLocalValidation(
    native_pipeline_orm(PhabricatorProject::class,$ids['project']),array($unknown));
  native_source_check($result['status'] === 'blocked' && !$result['can_execute'],
    'native-project-unknown-transaction-blocked');
  native_source_check($fixture->fingerprint() === $baseline_fingerprint,
    'native-local-validation-does-not-write-observed-native-tables');
  $view = PhabricatorPolicyCapability::CAN_VIEW;
  $edit = PhabricatorPolicyCapability::CAN_EDIT;
  $policy_table = $fixture->table('policy','policy');
  $task_table = $fixture->table('maniphest','maniphest_task');
  $policy_original = native_source_row($reader,$policy_table,$ids['policy']);
  $policy_binding = native_source_bind($ids,'task.description',ManiphestTask::class,$ids['policy_task']);
  $compose = function($binding, $caps, $expected, $name) {
    $r = PhorgeGovernanceNativePolicyCompositionVerifier::observe($binding,$caps);
    native_source_check($r['status'] === 'observed-native-policy-composition' &&
      $r['source_consistency'] === 'stable-sampled-not-atomic' &&
      !$r['native_closure_complete'] && !$r['can_execute'] &&
      !$r['source_and_native_observation_atomic'], $name.'-observed-partial');
    foreach ($expected as $cap => $decision) {
      native_source_check($r['observations'][$cap]['policy_filter'] === $decision,
        $name.'-'.$cap.'-'.$decision);
    }
    return $r;
  };
  $deny_actor = array('action'=>'deny','rule'=>PhabricatorUsersPolicyRule::class,'value'=>array($ids['actor']));
  $allow_group = array('action'=>'allow','rule'=>PhabricatorProjectsPolicyRule::class,'value'=>array($ids['project']));
  $allow_other = array('action'=>'allow','rule'=>PhabricatorUsersPolicyRule::class,'value'=>array($ids['outsider']));
  $allow_empty_all = array('action'=>'allow','rule'=>PhabricatorProjectsAllPolicyRule::class,'value'=>array());
  try {
    foreach (array(
      array('ordered-deny-before-allow',array($deny_actor,$allow_group),'deny','deny'),
      array('ordered-allow-before-deny',array($allow_group,$deny_actor),'deny','allow'),
      array('unmatched-default-deny',array($allow_other),'deny','deny'),
      array('empty-default-allow',array(),'allow','allow'),
      array('empty-default-deny',array(),'deny','deny'),
      array('empty-projects-all-native-filter',array($allow_empty_all),'deny','allow'),
    ) as $case) {
      native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=?,defaultAction=? WHERE phid=?',
        array(json_encode($case[1]),$case[2],$ids['policy']));
      $compose($policy_binding,array($view),array($view=>$case[3]),$case[0]);
    }
  } finally {
    native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=?,defaultAction=? WHERE phid=?',
      array($policy_original['rules'],$policy_original['defaultAction'],$ids['policy']));
  }
  $compose(native_source_bind($ids,'task.description',ManiphestTask::class,$ids['named_task']),
    array($view),array($view=>'allow'),'native-named-policy-indirection');
  $owner_binding = native_source_bind($ids,'task.description',ManiphestTask::class,$ids['task']);
  $automatic = $compose($owner_binding,array($view,$edit),array($view=>'allow',$edit=>'allow'),
    'native-owner-automatic-no-one-policy');
  native_source_check($automatic['observations'][$view]['automatic_capability'] === true &&
    $automatic['observations'][$edit]['automatic_capability'] === true,'native-owner-auto-observed');
  try {
    native_source_sql($admin,'UPDATE '.$task_table.' SET spacePHID=? WHERE phid=?',
      array($ids['restricted_space'],$ids['task']));
    $space = $compose($owner_binding,array($view),array($view=>'deny'),'native-space-precedes-owner-automatic');
    native_source_check($space['observations'][$view]['automatic_capability'] === true,
      'native-space-denial-retains-independent-automatic-observation');
  } finally {
    native_source_sql($admin,'UPDATE '.$task_table.' SET spacePHID=? WHERE phid=?',array($ids['space'],$ids['task']));
  }
  // Explicit in-memory effective application configuration, not a claim that
  // a config_entry row is automatically applied by this bounded fixture.
  $dictionary = $stack->getStack()[0];
  $dictionary_before = $dictionary->getAllKeys();
  try {
    $dictionary->setKeys(array('phabricator.application-settings'=>array(
      'PHID-APPS-PhabricatorManiphestApplication'=>array('policy'=>array($view=>'no-one')))));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $compose($owner_binding,array($view),array($view=>'deny'),'native-effective-application-view-policy-denies');
  } finally {
    $dictionary->deleteKeys(array('phabricator.application-settings'));
    $dictionary->setKeys($dictionary_before);
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  }
  $pending_config = PhorgeGovernanceNativePolicyCompositionVerifier::begin($owner_binding,array($view));
  try {
    $dictionary->setKeys(array('phabricator.application-settings'=>array(
      'PHID-APPS-PhabricatorManiphestApplication'=>array('policy'=>array($view=>'no-one')))));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $config_changed = $pending_config->finish();
    native_source_check($config_changed['status'] === 'blocked' &&
      $config_changed['reason'] === 'native_sources_changed_between_samples' &&
      $config_changed['source_consistency'] === 'changed-blocked' &&
      !isset($config_changed['observations']) && !$config_changed['can_execute'],
      'between-sample-effective-application-config-drift-blocked');
  } finally {
    $dictionary->deleteKeys(array('phabricator.application-settings'));
    $dictionary->setKeys($dictionary_before);
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  }
  foreach (array('policy-default','membership-source') as $mutation) {
    $pending = PhorgeGovernanceNativePolicyCompositionVerifier::begin($policy_binding,array($view));
    try {
      if ($mutation === 'policy-default') {
        native_source_sql($admin,'UPDATE '.$policy_table.' SET defaultAction=? WHERE phid=?',array('allow',$ids['policy']));
      } else {
        native_source_sql($admin,'UPDATE '.$edge.' SET dateCreated=dateCreated+1 WHERE src=? AND type=60',array($ids['project']));
      }
      $changed = $pending->finish();
      native_source_check($changed['status'] === 'blocked' &&
        $changed['reason'] === 'native_sources_changed_between_samples' &&
        $changed['source_consistency'] === 'changed-blocked' &&
        !isset($changed['observations']) && !$changed['can_execute'],
        'between-sample-'.$mutation.'-blocked-without-allow');
      $again = $pending->finish();
      native_source_check($again['status'] === 'blocked' &&
        $again['reason'] === 'native_composition_already_finished','composition-single-use-'.$mutation);
    } finally {
      if ($mutation === 'policy-default') {
        native_source_sql($admin,'UPDATE '.$policy_table.' SET defaultAction=? WHERE phid=?',array($policy_original['defaultAction'],$ids['policy']));
      } else {
        native_source_sql($admin,'UPDATE '.$edge.' SET dateCreated=dateCreated-1 WHERE src=? AND type=60',array($ids['project']));
      }
    }
  }
  $unknown_rules = json_encode(array(array('action'=>'allow',
    'rule'=>'UnknownSyntheticRule','value'=>array())));
  $isolated_optin = getenv('PHORGE_GOVERNANCE_ISOLATED_TEST');
  try {
    native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=? WHERE phid=?',
      array($unknown_rules,$ids['policy']));
    $pending_unknown = PhorgeGovernanceNativePolicyCompositionVerifier::begin($policy_binding,array($view));
    // Removing the opt-in makes any attempted fresh native context fail. The
    // specific captured unknown-rule rejection must precede that context.
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=0');
    $unknown_policy = $pending_unknown->finish();
    native_source_check($unknown_policy['status'] === 'blocked' &&
      $unknown_policy['reason'] === 'unsupported_native_policy_rule' &&
      !isset($unknown_policy['observations']) && !$unknown_policy['can_execute'],
      'before-sample-unknown-rule-rejected-before-native-context');
  } finally {
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST='.$isolated_optin);
    native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=? WHERE phid=?',array($policy_original['rules'],$ids['policy']));
  }
  $pending_unknown_after = PhorgeGovernanceNativePolicyCompositionVerifier::begin($policy_binding,array($view));
  try {
    native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=? WHERE phid=?',array($unknown_rules,$ids['policy']));
    $unknown_after = $pending_unknown_after->finish();
    native_source_check($unknown_after['status'] === 'blocked' &&
      $unknown_after['reason'] === 'native_sources_changed_between_samples' &&
      $unknown_after['source_consistency'] === 'changed-blocked' &&
      !isset($unknown_after['observations']) && !$unknown_after['can_execute'],
      'after-sample-unknown-rule-drift-blocked-without-native-allow');
  } finally {
    native_source_sql($admin,'UPDATE '.$policy_table.' SET rules=? WHERE phid=?',array($policy_original['rules'],$ids['policy']));
  }
  $session_table = $fixture->table('user','phabricator_session');
  $session_original = native_source_sql($admin,'SELECT sessionExpires FROM '.$session_table.
    ' WHERE userPHID=?',array($ids['approver']))->get_result()->fetch_assoc();
  try {
    native_source_sql($admin,'UPDATE '.$session_table.' SET sessionExpires=1 WHERE userPHID=?',array($ids['approver']));
    $pending_expired = PhorgeGovernanceNativePolicyCompositionVerifier::begin($policy_binding,array($view));
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=0');
    $expired = $pending_expired->finish();
    native_source_check($expired['status'] === 'blocked' &&
      $expired['reason'] === 'native_composition_account_or_session_unproved' &&
      !isset($expired['observations']) && !$expired['can_execute'],
      'before-sample-expired-approver-rejected-before-native-context');
  } finally {
    putenv('PHORGE_GOVERNANCE_ISOLATED_TEST='.$isolated_optin);
    native_source_sql($admin,'UPDATE '.$session_table.' SET sessionExpires=? WHERE userPHID=?',
      array($session_original['sessionExpires'],$ids['approver']));
  }
  native_source_check($fixture->fingerprint() === $baseline_fingerprint,
    'native-composition-and-restored-setup-mutations-preserve-native-rows');

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
  'batch' => '5-native-pipeline-composition', 'native_closure_complete' => false,
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
