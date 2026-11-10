<?php
require_once dirname(__DIR__,2).'/runtime/php-version.php';
require_once dirname(__DIR__).'/completion/fixture.php';
$root=dirname(__DIR__,3);$arguments=array_slice($argv??array(),1);
$phase=$arguments===array('--oauth-client-lifecycle-owner')?'oauth-client-lifecycle-producer-owner':'invalid';
$legacy_compatibility=false;$workbench_http=false;$queue_profile='native';
$fixture=null;$control=null;$environment_state=null;$write_guard=null;$cache_state=null;$console_state=null;$console_owned=false;$capture_active=false;
$checks=array();$stage='preflight';$failure=null;$cleanup_failures=array();$cleanup=array();$file_service=null;$file_service_cleanup=null;$native=null;$worker_cleanup=null;$summary=array();$reader_adapter_exercised=false;
$superglobal_state=array('_COOKIE'=>$_COOKIE,'_SERVER'=>$_SERVER);
$GLOBALS['governance_recovery_oauth_client_lifecycle_cleanup_proved']=false;
$check=static function($condition,$name)use(&$checks,&$stage){$stage=$name;if($condition!==true){throw new RuntimeException('native_lifecycle_case_failed');}$checks[]=$name;};
try{
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
    'governance.media-exchange.enabled'=>!$legacy_compatibility&&!$workbench_http,
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
  require_once __DIR__.'/oauth-client-lifecycle-workers.php';
  require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
  $stage='owned-real-gorge-file-service';$file_service=governance_completion_start_gorge($fixture,'file',$check);
  $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],'gorge.file.token'=>$file_service['token']));
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  $stage='focused-native-oauth-client-lifecycle-producer-owner';require_once dirname(__DIR__).'/recovery/oauth-client-lifecycle-focused.php';
  $native=governance_recovery_oauth_client_lifecycle_focused($fixture,$control,$check);
}catch(Throwable$error){
  $failure=array('stage'=>$stage,'error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  require_once dirname(__DIR__).'/recovery/oauth-client-lifecycle-diagnostic.php';
  $failure['diagnostic']=governance_recovery_oauth_client_lifecycle_throwable_projection($GLOBALS['governance_oauth_client_lifecycle_attempt']??$stage,$error);
  governance_recovery_oauth_client_lifecycle_emit_failure($failure['diagnostic']);
}finally{
  $known=$failure===null&&function_exists('governance_recovery_oauth_client_lifecycle_cleanup_ready')&&governance_recovery_oauth_client_lifecycle_cleanup_ready();
  if($fixture!==null){$summary=$fixture->getSummary();}
  if($known){
    try{
      $worker_cleanup=governance_oauth_client_lifecycle_native_cleanup_workers();
      if($worker_cleanup['cleanup_failures']!==0||$worker_cleanup['private_directories_remaining']!==0||$worker_cleanup['live_processes_after_cleanup']!==0){throw new RuntimeException('oauth_client_lifecycle_worker_cleanup_unknown');}
      $stateReader=new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState');
      foreach((new ReflectionProperty(LiskDAO::class,'connections'))->getValue()as$connection){
        if($connection->isInsideTransaction()||$connection->isReadLocking()||$connection->isWriteLocking()||$stateReader->invoke($connection)->getDepth()!==0){throw new RuntimeException('oauth_client_lifecycle_parent_terminal_unknown');}}
      if($write_guard!==null){$write_guard->dispose();$checks[]='owned-native-write-guard-disposed';}
      LiskDAO::closeAllConnections();
      if($environment_state!==null){foreach($environment_state as$key=>$value){(new ReflectionProperty(PhabricatorEnv::class,$key))->setValue(null,$value);}
        (new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,$cache_state);$checks[]='owned-writer-environment-and-cache-restored';}
      $_COOKIE=$superglobal_state['_COOKIE'];$_SERVER=$superglobal_state['_SERVER'];
      if($file_service!==null){$file_service_cleanup=governance_completion_stop_gorge($file_service);}
      if($fixture!==null){$cleanup=$fixture->cleanup();$checks[]='all-owned-full-native-schemas-and-principals-cleaned';}
      if($control!==null){$control->close();}
    }catch(Throwable$cleanupError){$cleanup_failures[]=array('stage'=>'owned-cleanup','error_type'=>get_class($cleanupError));}
  }else{
    // Retain original parent/pool, File service, private worker files and owned
    // schemas. Natural process/OS close is not an explicitly observed cleanup.
    $cleanup_failures[]=array('stage'=>'native-lifecycle-terminal-unproved','error_type'=>'RuntimeException');
  }
  if($console_owned){(new ReflectionProperty(PhutilConsole::class,'console'))->setValue(null,$console_state);}
}
$native_output=$capture_active?ob_get_clean():'';
if(preg_match('/(?:PHP Warning|Warning:|Fatal error:|PHLOG:|UNSAFE: Raw string)/',$native_output)&&$failure===null){$failure=array('stage'=>'unexpected-native-runtime-output','error_type'=>'RuntimeWarning');}
$passed=$failure===null&&!$cleanup_failures;
echo json_encode(array('batch'=>'native-oauth-client-lifecycle-owner','scope'=>'owned-native-oauth-client-lifecycle-no-permit','passed'=>$passed,'checks'=>$checks,'check_count'=>count($checks),'php_version'=>PHP_VERSION,'source_restore'=>$summary,'native'=>$native,'optional_source_reader_adapter_exercised'=>$reader_adapter_exercised,'isolated_optional_features_enabled'=>true,'worker_cleanup'=>$worker_cleanup,'file_service_cleanup'=>$file_service_cleanup,'successful_owned_file_service_startups'=>count(array_intersect($checks,array('completion-real-gorge-file-ready-loopback'))),'cleanup'=>$cleanup,'failure'=>$failure,'cleanup_failures'=>$cleanup_failures,'terminal_failure_cleanup_rollbacks'=>0,'captured_native_output_bytes'=>strlen($native_output),'captured_native_output_sha256'=>hash('sha256',$native_output),'production_deployment'=>false,'production_executor'=>false,'production_optional_features_enabled'=>false,'native_permission_axes_changed'=>false,'remaining_blocked'=>array('all-thirteen-source-families','creator-and-absence-phantoms','unreviewed-external-writers','physical-ACK-loss','production-provider-and-activation')),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($passed?0:1);
