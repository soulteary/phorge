<?php

require_once dirname(__DIR__,2).'/runtime/php-version.php';
require_once dirname(__DIR__).'/completion/fixture.php';
$root = dirname(__DIR__,3);
$arguments=array_slice($argv ?? array(),1);
$phases=array('--recovery-organization-lifecycle-only'=>'recovery-organization-lifecycle-only','--recovery-organization-metadata-only'=>'recovery-organization-metadata-only','--recovery-organization-only'=>'recovery-organization-only','--recovery-policy-only'=>'recovery-policy-only','--legacy-sql-compatibility'=>'legacy-sql-compatibility',
  '--http-wire-hold'=>'http-wire-hold','--private-mail-wire-hold'=>'private-mail-wire-hold',
  '--private-mail-pending-hold'=>'private-mail-pending-hold');
$phase=$arguments===array()?'all':
  (count($arguments)===1&&isset($phases[$arguments[0]])?$phases[$arguments[0]]:'invalid');
$private_mail_pending=$phase==='private-mail-pending-hold';
$private_mail_wire=$phase==='private-mail-wire-hold'||$private_mail_pending;
$legacy_compatibility=$phase==='legacy-sql-compatibility';
$wire_hold=$phase==='http-wire-hold';
$fixture = null;
$control = null;
$environment_state = null;
$write_guard = null;
$cache_state = null;
$checks = array();
$stage = 'preflight';
$failure = null;
$cleanup_failures = array();
$failure_cleanup_rollbacks = 0;
$console_state = null;
$console_owned = false;
$capture_active = false;
$cleanup = array();
$pipeline = null;
$consistency = null;
$file_service = null;
$file_service_cleanup = null;
$gorge_publication = null;
$queue_profile = getenv('PHORGE_GOVERNANCE_COMPLETION_QUEUE_PROFILE');
$queue_profile = $queue_profile === false || $queue_profile === '' ? 'native' : $queue_profile;
$summary = array();
$native=null;
$reader_adapter_exercised=false;
$worker_cleanup=null;
$superglobal_state=array('_COOKIE'=>$_COOKIE,'_SERVER'=>$_SERVER);
$check = function($condition,$name) use (&$checks,&$stage) {
  $stage = $name;
  if (!$condition) { throw new RuntimeException('Completion fixture assertion failed.'); }
  $checks[] = $name;
};
try {
  phorge_assert_supported_php_version(PHP_VERSION);
  if ($phase === 'invalid') { throw new LogicException('Unknown completion CLI arguments refused.'); }
  if (!in_array($queue_profile,array('native','gorge'),true)) {
    throw new LogicException('Unsupported completion queue profile refused.');
  }
  if (PHP_SAPI !== 'cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
    throw new LogicException('Explicit isolated CLI completion required.');
  }
  foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $selector) {
    $selected = getenv($selector);
    if ($selected !== false && $selected !== '') {
      throw new LogicException('Deployment selectors refused.');
    }
  }
  foreach (array('local.json','deployment.json','ENVIRONMENT') as $name) {
    if (file_exists($root.'/conf/local/'.$name)) {
      throw new LogicException('Deployment configuration refused.');
    }
  }
  require_once $root.'/support/runtime/bootstrap-cli.php';
  // The native CLI bootstrap intentionally clears pre-existing output buffers.
  // Own our JSON capture only after that bootstrap has completed.
  ob_start();
  $capture_active = true;
  phutil_load_library($root.'/src/');
  $console_property = new ReflectionProperty(PhutilConsole::class,'console');
  $console_state = $console_property->getValue();
  $quiet_console = PhutilConsole::newLocalConsole();
  $quiet_console->disableOut();
  PhutilConsole::setConsole($quiet_console);
  $console_owned = true;

  $dsn = getenv('PHORGE_GOVERNANCE_FIXTURE_DSN');
  PhorgeGovernanceIsolatedDirectoryStore::validateFixtureDSN($dsn);
  $options = array();
  foreach (explode(';',substr($dsn,6)) as $part) {
    if ($part !== '') { list($key,$value)=explode('=',$part,2); $options[$key]=$value; }
  }
  if (($options['host'] ?? null) !== '127.0.0.1') {
    throw new LogicException('Disposable IPv4 loopback required.');
  }
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  $port = (int)($options['port'] ?? 3306);
  $control = new mysqli('127.0.0.1',getenv('PHORGE_GOVERNANCE_FIXTURE_USER'),
    getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD'),'',$port);
  $control->set_charset('utf8mb4');
  $stage = 'full-native-source-restore';
  $fixture = new PhorgeGovernanceCompletionFixture($control,$root,'127.0.0.1',$port);
  $fixture->create();
  $summary = $fixture->getSummary();
  $check($summary['quickstart_source_table_count'] === 500 &&
    $summary['app_schema_count'] === 54 &&
    $summary['patch_audit']['current_patch_ledger_complete'],
    'full-current-source-quickstart-and-patch-ledger-restored');
  if (!$legacy_compatibility) {
    $reader_proof = PhorgeGovernanceNativeSourceReader::newFixture($fixture->getReaderConfig());
    $reader_proof = null;
    $reader_adapter_exercised=true;
    $check(true,'completion-reader-real-select-only-grants-and-lineage-proved');
  }
  // The focused legacy profile still checks actual restricted writer/reader
  // privileges below, but does not exercise the optional source-reader adapter.

  $privilege_probe_mode=(new mysqli_driver())->report_mode;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    foreach (array('writer'=>$fixture->getWriterConfig(),'reader'=>$fixture->getReaderConfig()) as $role=>$role_config) {
      $role_connection = new mysqli($role_config['host'],$role_config['user'],
        $role_config['password'],'',$role_config['port']);
      try {
        $sql = $role === 'writer' ?
          'CREATE TABLE `'.$role_config['namespace'].'_user`.`forbidden_completion_ddl` (id INT)' :
          'UPDATE '.$fixture->table('user','user').' SET dateModified=dateModified WHERE 1=0';
        $denied = false;
        try { $role_connection->query($sql); }
        catch (mysqli_sql_exception $error) { $denied=in_array($error->getCode(),array(1142,1044),true); }
        $check($denied,'completion-'.$role.'-forbidden-privilege-denied');
        $denied = false;
        try { $role_connection->query('UPDATE '.$fixture->table('governance','fixture_guard').
          ' SET quickstart_sha256=quickstart_sha256 WHERE 1=0'); }
        catch (mysqli_sql_exception $error) { $denied=in_array($error->getCode(),array(1142,1044),true); }
        $check($denied,'completion-'.$role.'-cannot-modify-ownership-marker');
      } finally { $role_connection->close(); }
    }
  } finally { mysqli_report($privilege_probe_mode); }
  if (PhabricatorEnv::getConfigSourceStack() !== null ||
      (new ReflectionProperty(LiskDAO::class,'connections'))->getValue()) {
    throw new LogicException('Inherited native SQL/configuration refused before ownership.');
  }
  $environment_state = (new ReflectionClass(PhabricatorEnv::class))->getStaticProperties();
  $cache_property = new ReflectionProperty(PhabricatorCaches::class,'requestCache');
  $cache_state = $cache_property->getValue();
  $cache_property->setValue(null,null);
  $writer = $fixture->getWriterConfig();
  $stack = new PhabricatorConfigStackSource();
  $stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host'=>$writer['host'],'mysql.port'=>$writer['port'],
    'mysql.user'=>$writer['user'],'mysql.pass'=>$writer['password'],
    'storage.default-namespace'=>$writer['namespace'],
    'phabricator.cache-namespace'=>$writer['namespace'],
    'cluster.databases'=>array(),'cluster.search'=>array(),
    'phabricator.base-uri'=>'http://fixture.invalid/',
    'phabricator.silent'=>false,'policy.allow-public'=>false,
    'governance.organization.enabled'=>!$legacy_compatibility,
    'governance.media-exchange.enabled'=>!$legacy_compatibility,
    'governance.media-exchange.issuer-key'=>bin2hex(random_bytes(32)),
    'governance.media-exchange.incarnation'=>bin2hex(random_bytes(16)),
    'projects.default-navigation'=>'flat','project.history-state'=>'off',
    'project.workspace-mode'=>'native','project.history-task-drafts'=>false,
    'gorge.search.source-scan'=>false,'gorge.search.projection-shadow'=>false,
    'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,
    'gorge.notification.outbox'=>false,'notification.servers'=>array(),
    'gorge.db.uri'=>null,'gorge.file.uri'=>null,'gorge.image.uri'=>null,
    'gorge.render.uri'=>null,
  )));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  if (AphrontWriteGuard::isGuardActive() ||
      (new ReflectionProperty(AphrontWriteGuard::class,'allowUnguardedWrites'))->getValue()) {
    throw new LogicException('Inherited or globally unguarded writer refused.');
  }
  $write_guard = new AphrontWriteGuard(function() use ($fixture,$writer) {
    $fixture->assertOwned();
    foreach (array('mysql.host'=>'host','mysql.port'=>'port','mysql.user'=>'user',
      'mysql.pass'=>'password','storage.default-namespace'=>'namespace') as $key=>$field) {
      $actual=PhabricatorEnv::getEnvConfig($key);
      $expected=$writer[$field];
      if ($key==='mysql.port') {
        $actual=PhorgeProjectExecutionConfigSource::normalizePort($actual);
        $expected=PhorgeProjectExecutionConfigSource::normalizePort($expected);
      }
      if ($actual !== $expected) {
        throw new RuntimeException('Owned native writer route refused.');
      }
    }
  });
  if($phase==='recovery-organization-lifecycle-only'){
    $stage='focused-indexed-organization-lifecycle';
    require_once __DIR__.'/native-cases.php';
    require_once dirname(__DIR__).'/recovery/organization-lifecycle-focused.php';
    $native=governance_recovery_org_lifecycle_focused($fixture,$check);
  }elseif($phase==='recovery-organization-metadata-only'){
    $stage='focused-live-organization-metadata';
    require_once __DIR__.'/native-cases.php';
    require_once dirname(__DIR__).'/recovery/organization-live-metadata-focused.php';
    $native=governance_recovery_org_metadata_focused($fixture,$check);
  }elseif($phase==='recovery-organization-only'){
    $stage='focused-native-recovery-organization-producer';
    require_once __DIR__.'/native-cases.php';
    require_once dirname(__DIR__).'/recovery/organization-focused-cases.php';
    $native=governance_recovery_organization_focused_cases($fixture,$check);
  }elseif($phase==='recovery-policy-only'){
    $stage='focused-native-recovery-policy-range';
    require_once __DIR__.'/native-cases.php';
    require_once dirname(__DIR__).'/recovery/policy-focused-cases.php';
    $native=governance_recovery_policy_focused_cases($fixture,$check);
  }elseif ($legacy_compatibility) {
    $stage='focused-native-legacy-role-sql-compatibility';
    require_once __DIR__.'/legacy-sql-compatibility-cases.php';
    $native=governance_native_legacy_sql_compatibility_cases($fixture,$check);
  } else {
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';
    $file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],
      'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='native-organization-and-media-positive';
    if($wire_hold){
      require_once __DIR__.'/media-wire-fixture.php';$native=governance_native_media_wire_hold($fixture,$check);
    }elseif($private_mail_wire){
      $stage='private-native-mail-wire-fixture';
      if($private_mail_pending){require_once dirname(__DIR__).'/notification/pending-wire-hold.php';$native=governance_private_mail_pending_hold($fixture,$check);}
      else{require_once dirname(__DIR__).'/notification/wire-hold.php';$native=governance_private_mail_wire_hold($fixture,$check);}
    }else{
      require_once __DIR__.'/native-cases.php';$native=governance_native_organization_cases($fixture,$check);
    }
  }
} catch (Throwable $error) {
  $failure = array('stage'=>$stage,'error_type'=>get_class($error),
    'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  if ($error instanceof mysqli_sql_exception) { $failure['sql_errno']=$error->getCode(); }
  if(isset($GLOBALS['governance_private_business_child_failure'])){
    $failure['child_diagnostic']=$GLOBALS['governance_private_business_child_failure'];
  }
} finally {
  $_COOKIE=$superglobal_state['_COOKIE']; $_SERVER=$superglobal_state['_SERVER'];
  if ($fixture !== null) { $summary = $fixture->getSummary(); }
  if (function_exists('governance_native_cleanup_workers')) {
    try {
      $worker_cleanup=governance_native_cleanup_workers();
      if ($worker_cleanup['cleanup_failures']!==0 ||
          $worker_cleanup['private_directories_remaining']!==0 ||
          $worker_cleanup['live_processes_after_cleanup']!==0) {
        $cleanup_failures[]=array('stage'=>'owned-worker-cleanup','error_type'=>'RuntimeException');
      }
    } catch (Throwable $error) {
      $cleanup_failures[]=array('stage'=>'owned-worker-cleanup','error_type'=>get_class($error));
    }
  }

  $cleanup_error = function($stage,$error) use (&$cleanup_failures) {
    $cleanup_failures[] = array('stage'=>$stage,'error_type'=>get_class($error),
      'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  };
  // A terminal native Throwable may bypass the editor's Exception rollback.
  // Clean only this invocation's already-owned writer pool, while its write
  // guard still proves the writer route. This never turns a failed run green.
  if ($environment_state !== null && $failure !== null) {
    try {
      $pool = (new ReflectionProperty(LiskDAO::class,'connections'))->getValue();
      $seen = array();
      foreach ($pool as $connection) {
        $id = spl_object_id($connection);
        if (isset($seen[$id])) { continue; } $seen[$id]=true;
        $limit = 64;
        while ($connection->isInsideTransaction() && $limit-- > 0) {
          $connection->killTransaction(); $failure_cleanup_rollbacks++;
        }
        $limit = 64;
        while ($connection->isReadLocking() && $limit-- > 0) { $connection->endReadLocking(); }
        $limit = 64;
        while ($connection->isWriteLocking() && $limit-- > 0) { $connection->endWriteLocking(); }
      }
    } catch (Throwable $error) { $cleanup_error('owned-failure-rollback',$error); }
  }
  if ($write_guard !== null) {
    try { $write_guard->dispose(); $checks[]='owned-native-write-guard-disposed'; }
    catch (Throwable $error) { $cleanup_error('write-guard-cleanup',$error); }
  }
  if ($environment_state !== null) {
    try { LiskDAO::closeAllConnections(); }
    catch (Throwable $error) { $cleanup_error('owned-writer-pool-cleanup',$error); }
    try {
      foreach ($environment_state as $key=>$value) {
        (new ReflectionProperty(PhabricatorEnv::class,$key))->setValue(null,$value);
      }
      (new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,$cache_state);
      $checks[] = 'owned-writer-environment-and-cache-restored';
    } catch (Throwable $error) { $cleanup_error('environment-cleanup',$error); }
  }
  if ($file_service !== null) {
    try { $file_service_cleanup=governance_completion_stop_gorge($file_service); }
    catch (Throwable $error) { $cleanup_error('owned-file-service-cleanup',$error); }
  }
  if ($fixture !== null) {
    try { $cleanup = $fixture->cleanup(); $checks[]='all-owned-full-native-schemas-and-principals-cleaned'; }
    catch (Throwable $error) { $cleanup_error('owned-cleanup',$error); }
  }
  if ($control !== null) { $control->close(); }
  if ($console_owned) {
    (new ReflectionProperty(PhutilConsole::class,'console'))->setValue(null,$console_state);
  }
}
$native_output = $capture_active ? ob_get_clean() : '';
if (preg_match('/(?:PHP Warning|Warning:|Fatal error:|PHLOG:|UNSAFE: Raw string)/',$native_output) && $failure === null) {
  $failure = array('stage'=>'unexpected-native-runtime-output','error_type'=>'RuntimeWarning');
}
$passed = $failure === null && !$cleanup_failures;
echo json_encode(array('batch'=>$phase==='recovery-organization-lifecycle-only'?'native-recovery-organization-lifecycle-component':($phase==='recovery-organization-metadata-only'?'native-recovery-organization-live-metadata-component':($phase==='recovery-organization-only'?'native-recovery-organization-producer-component':($phase==='recovery-policy-only'?'native-recovery-policy-range-component':($private_mail_wire?'private-native-mail-wire-preparation':($wire_hold?'native-media-wire-fixture-preparation':($legacy_compatibility?'native-legacy-role-sql-compatibility':'native-organization-media-integration')))))),'passed'=>$passed,
  'scope'=>$private_mail_wire?'owned-private-native-mail-wire-preparation':($wire_hold?'owned-native-media-wire-hold-synthetic-accounts':($legacy_compatibility?'owned-full-native-legacy-role-hooks-only':'owned-full-native-source-synthetic-accounts')),'checks'=>$checks,'check_count'=>count($checks),
  'php_version'=>PHP_VERSION,'source_restore'=>$summary,'native'=>$native,
  'optional_source_reader_adapter_exercised'=>$reader_adapter_exercised,
  'isolated_optional_features_enabled'=>!$legacy_compatibility,
  'worker_cleanup'=>$worker_cleanup,'file_service_cleanup'=>$file_service_cleanup,
  'successful_owned_file_service_startups'=>count(array_intersect($checks,array('completion-real-gorge-file-ready-loopback'))),
  'cleanup'=>$cleanup,'failure'=>$failure,'cleanup_failures'=>$cleanup_failures,
  'terminal_failure_cleanup_rollbacks'=>$failure_cleanup_rollbacks,
  'captured_native_output_bytes'=>strlen($native_output),
  'captured_native_output_sha256'=>hash('sha256',$native_output),
  'production_deployment'=>false,'production_executor'=>false,
  'production_optional_features_enabled'=>false,
  'native_permission_axes_changed'=>false,
  'remaining_blocked'=>$legacy_compatibility
    ? array('optional-backend-mariadb-compatibility','full-database-version-matrix','global-governance-completion')
    : array('media-http-and-cookie-integration','file-object-guards','notification-producers','global-governance-completion')),
  JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($passed?0:1);
