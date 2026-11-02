<?php

require_once dirname(__DIR__,2).'/runtime/php-version.php';
require_once __DIR__.'/fixture.php';
$root = dirname(__DIR__,3);
$arguments=array_slice($argv ?? array(),1);
$phase=$arguments === array() ? 'all' :
  ($arguments === array('--readview-only') ? 'readview-only' : 'invalid');
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
  $reader_proof = PhorgeGovernanceNativeSourceReader::newFixture($fixture->getReaderConfig());
  $reader_proof = null;
  $check(true,'completion-reader-real-select-only-grants-and-lineage-proved');
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
      if (PhabricatorEnv::getEnvConfig($key) !== $writer[$field]) {
        throw new RuntimeException('Owned native writer route refused.');
      }
    }
  });
  if ($phase === 'all') {
  require_once __DIR__.'/pipeline-gorge.php';
  $stage = 'real-gorge-file-service';
  $file_service = governance_completion_start_gorge($fixture,'file',$check);
  $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],
    'gorge.file.token'=>$file_service['token']));
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  $stage = 'native-pipeline';
  require_once __DIR__.'/pipeline.php';
  $pipeline = governance_completion_pipeline($fixture,$check);
  $check($pipeline['writes_expected'] === true &&
    $pipeline['worker_delivery_executed'] === false,
    'native-pipeline-commits-intentional-no-daemon-execution');
  if ($queue_profile === 'gorge') {
    $stage = 'real-gorge-publication-profile';
    $gorge_publication = governance_completion_gorge_publication($fixture,$check);
  }
  }
  $stage = 'native-consistent-read-view';
  require_once __DIR__.'/fixture-consistency.php';
  $consistency = governance_completion_consistency($fixture,$check);
} catch (Throwable $error) {
  $failure = array('stage'=>$stage,'error_type'=>get_class($error),
    'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
} finally {
  if ($fixture !== null) { $summary = $fixture->getSummary(); }
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
if (preg_match('/(?:PHP Warning|Warning:|Fatal error:)/',$native_output) && $failure === null) {
  $failure = array('stage'=>'unexpected-native-runtime-output','error_type'=>'RuntimeWarning');
}
$passed = $failure === null && !$cleanup_failures;
echo json_encode(array('batch'=>'native-governance-completion','phase'=>$phase,'passed'=>$passed,
  'acceptance_scope'=>$phase === 'all' ? 'full-current-profile-attempt' :
    'diagnostic-readview-only-not-full-acceptance',
  'checks'=>$checks,'check_count'=>count($checks),'php_version'=>PHP_VERSION,
  'source_restore'=>$summary,'pipeline'=>$pipeline,'consistency'=>$consistency,
  'queue_profile'=>$queue_profile,'gorge_publication'=>$gorge_publication,
  'file_service_cleanup'=>$file_service_cleanup,
  'successful_owned_service_startups'=>count(array_intersect($checks,array(
    'completion-real-gorge-file-ready-loopback','completion-real-gorge-taskqueue-ready-loopback'))),
  'writes'=>array('pipeline_and_services'=>$phase === 'all' ? 'intentional-owned-writes' : 'not-executed',
    'fixture_setup_and_concurrency'=>'intentional-owned-writes',
    'read_view_observer'=>'select-only-no-observer-writes'),'cleanup'=>$cleanup,'failure'=>$failure,'cleanup_failures'=>$cleanup_failures,
  'terminal_failure_cleanup_rollbacks'=>$failure_cleanup_rollbacks,
  'captured_native_output_bytes'=>strlen($native_output),
  'captured_native_output_sha256'=>hash('sha256',$native_output),
  'production_deployment'=>false,'production_executor'=>false,
  'optional_features_enabled'=>false),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($passed?0:1);
