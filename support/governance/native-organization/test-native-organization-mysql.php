<?php

require_once dirname(__DIR__,2).'/runtime/php-version.php';
require_once dirname(__DIR__).'/completion/fixture.php';
$root = dirname(__DIR__,3);
$arguments=array_slice($argv ?? array(),1);
$phases=array('--calendar-reminder-wire-hold'=>'calendar-reminder-wire-hold','--conduit-producer-owner'=>'conduit-producer-owner','--calendar-reminders'=>'calendar-reminders','--session-borrowed-owner'=>'session-borrowed-owner','--file-parent-policy'=>'file-parent-policy','--session-producer-lease'=>'session-producer-lease','--recovery-partial-composition'=>'recovery-partial-composition','--organization-approval-workbench-http-hold'=>'organization-approval-workbench-http-hold','--organization-approval-workbench'=>'organization-approval-workbench','--file-source-facts'=>'file-source-facts','--task-owner-source-facts'=>'task-owner-source-facts','--spaces-source-facts'=>'spaces-source-facts','--project-source-facts'=>'project-source-facts','--application-config-facts'=>'application-config-facts','--native-user-rr-mdl-only'=>'native-user-rr-mdl-only','--api-credential-concurrency'=>'api-credential-concurrency','--recovery-organization-lifecycle-only'=>'recovery-organization-lifecycle-only','--recovery-organization-metadata-only'=>'recovery-organization-metadata-only','--recovery-organization-only'=>'recovery-organization-only','--recovery-policy-only'=>'recovery-policy-only','--legacy-sql-compatibility'=>'legacy-sql-compatibility',
  '--http-wire-remote-hold'=>'http-wire-remote-hold','--http-wire-hold'=>'http-wire-hold','--private-mail-wire-hold'=>'private-mail-wire-hold',
  '--private-mail-pending-hold'=>'private-mail-pending-hold','--governance-notice-history-wire-hold'=>'governance-notice-history-wire-hold','--governance-notice-wire-hold'=>'governance-notice-wire-hold','--notice-raw-orm-aba'=>'notice-raw-orm-aba','--notice-admin-grant'=>'notice-admin-grant','--notice-admin-grant-wire-hold'=>'notice-admin-grant-wire-hold');
$phase=$arguments===array()?'all':
  (count($arguments)===1&&isset($phases[$arguments[0]])?$phases[$arguments[0]]:'invalid');
$calendar_wire=$phase==='calendar-reminder-wire-hold';
$workbench_http=$phase==='organization-approval-workbench-http-hold';
$GLOBALS['governance_workbench_http_cleanup_permitted']=true;
$GLOBALS['governance_recovery_conduit_cleanup_proved']=$phase!=='conduit-producer-owner';
if($calendar_wire){$GLOBALS['governance_workbench_http_cleanup_permitted']=false;}
$GLOBALS['governance_session_lease_cleanup']=array('cleanup_proved'=>true);
$native_rr=$phase==='native-user-rr-mdl-only';
$notice_raw_orm=$phase==='notice-raw-orm-aba';
$notice_admin_wire=$phase==='notice-admin-grant-wire-hold';
$notice_history=$phase==='governance-notice-history-wire-hold';
$notice_wire=$phase==='governance-notice-wire-hold'||$notice_admin_wire||$notice_history;
$credential_concurrency=$phase==='api-credential-concurrency';
$private_mail_pending=$phase==='private-mail-pending-hold';
$private_mail_wire=$phase==='private-mail-wire-hold'||$private_mail_pending||$notice_wire;
$legacy_compatibility=$phase==='legacy-sql-compatibility';
$media_remote=$phase==='http-wire-remote-hold';
$wire_hold=$phase==='http-wire-hold'||$media_remote;
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
  if($calendar_wire){
    $stage='calendar-wire-pre-ready-identity';
    require_once dirname(__DIR__).'/notification/calendar-wire-identity-source.php';
    require_once dirname(__DIR__).'/notification/calendar-wire-cleanup-identity.php';
    governance_calendar_wire_publish_cleanup_identity(governance_calendar_wire_fixture_identity($fixture));
  }
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
  if($workbench_http){
    require_once __DIR__.'/workers.php'; // Actual empty registry for the HTTP file1 cleanup profile.
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';
    $file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='owned-workbench-basic-http-hold';require_once dirname(__DIR__).'/organization/workbench-http-hold.php';
    $native=governance_workbench_http_hold($fixture,$control,$check,$file_service);
  }elseif($calendar_wire){
    require_once __DIR__.'/workers.php';
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';$file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='owned-calendar-reminder-wire-hold';require_once dirname(__DIR__).'/notification/calendar-wire-hold.php';
    $native=governance_calendar_wire_hold($fixture,$check);
  }elseif($phase==='calendar-reminders'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';$file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-calendar-reminders';require_once dirname(__DIR__).'/notification/calendar-reminder-cases.php';
    $native=governance_calendar_reminder_cases($fixture,$check);
  }elseif($phase==='organization-approval-workbench'){
    $stage='focused-organization-approval-workbench';
    require_once dirname(__DIR__).'/organization/approval-workbench-cases.php';
    $native=governance_approval_workbench_focused($fixture,$check);
  }elseif($phase==='file-parent-policy'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';
    $file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],
      'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-File-parent-policy';require_once dirname(__DIR__).'/recovery/file-parent-policy-focused.php';$native=governance_recovery_file_parent_policy_focused($fixture,$control,$check);
  }elseif($phase==='file-source-facts'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';
    $file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],
      'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-File-source';require_once dirname(__DIR__).'/recovery/file-source-focused.php';$native=governance_recovery_file_source_focused($fixture,$control,$check);
  }elseif($phase==='task-owner-source-facts'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';
    $file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],
      'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-Task-owner-source';require_once dirname(__DIR__).'/recovery/task-owner-source-focused.php';$native=governance_recovery_task_owner_focused($fixture,$control,$check);
  }elseif($phase==='spaces-source-facts'){
    $stage='focused-native-spaces-source-facts';require_once dirname(__DIR__).'/recovery/spaces-source-focused.php';
    $native=governance_recovery_spaces_source_focused($fixture,$check);
  }elseif($phase==='project-source-facts'){
    $stage='focused-native-project-source-facts';
    require_once dirname(__DIR__).'/recovery/project-source-focused.php';
    $native=governance_recovery_project_source_focused($fixture,$check);
  }elseif($native_rr){
    require_once __DIR__.'/workers.php'; // Explicit empty registry, not an invented zero receipt.
    $stage='focused-same-native-User-writer-RR-MDL';
    require_once dirname(__DIR__).'/recovery/native-writer-rr-focused.php';
    $native=governance_native_rr_focused($fixture,$check);
    $native['final_owned_cleanup']=$GLOBALS['governance_native_rr_cleanup'];
  }elseif($phase==='conduit-producer-owner'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';$file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-conduit-producer-owner';require_once dirname(__DIR__).'/recovery/conduit-focused.php';
    $native=governance_recovery_conduit_focused($fixture,$control,$check);
  }elseif($phase==='session-borrowed-owner'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';$file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-borrowed-session-owner';require_once dirname(__DIR__).'/recovery/borrowed-session-focused.php';
    $native=governance_recovery_borrowed_focused($fixture,$control,$check);
  }elseif($phase==='session-producer-lease'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';
    $file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],
      'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-session-producer-lease';
    require_once dirname(__DIR__).'/recovery/session-lease-focused.php';
    $native=governance_recovery_session_lease_focused($fixture,$control,$check);
  }elseif($phase==='recovery-partial-composition'){
    require_once dirname(__DIR__).'/completion/pipeline-gorge.php';
    $stage='owned-real-gorge-file-service';
    $file_service=governance_completion_start_gorge($fixture,'file',$check);
    $stack->getStack()[0]->setKeys(array('gorge.file.uri'=>$file_service['uri'],
      'gorge.file.token'=>$file_service['token']));
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $stage='focused-native-recovery-partial-composition';
    require_once dirname(__DIR__).'/recovery/partial-composition-focused.php';
    $native=governance_recovery_partial_composition_focused($fixture,$check);
  }elseif($phase==='application-config-facts'){
    $stage='focused-native-application-config-facts';
    require_once dirname(__DIR__).'/recovery/application-config-focused.php';
    $native=governance_recovery_application_config_focused($fixture,$check);
  }elseif($phase==='recovery-organization-lifecycle-only'){
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
    if($phase==='notice-raw-orm-aba'){
      require_once dirname(__DIR__).'/notification/notice-raw-orm-aba-cases.php';$native=governance_notice_raw_orm_aba_cases($fixture,$check);
    }elseif($phase==='notice-admin-grant'){
      require_once dirname(__DIR__).'/notification/notice-admin-grant-cases.php';$native=governance_notice_admin_grant_cases($fixture,$check);
    }elseif($wire_hold){
      require_once __DIR__.'/media-wire-fixture.php';
      if($media_remote){require_once __DIR__.'/media-remote-profile.php';$native=governance_native_media_wire_hold($fixture,$check,governance_media_remote_profile());}
      else{$native=governance_native_media_wire_hold($fixture,$check);}
    }elseif($private_mail_wire){
      $stage='private-native-mail-wire-fixture';
      if($notice_admin_wire){require_once dirname(__DIR__).'/notification/notice-admin-wire-hold.php';$native=governance_notice_admin_wire_hold($fixture,$check);}
      elseif($notice_history){require_once dirname(__DIR__).'/notification/notice-history-browser.php';
        require_once dirname(__DIR__).'/notification/notice-wire-hold.php';
        $native=governance_notice_wire_hold($fixture,$check,'governance_notice_history_prepare_browser');}
      elseif($notice_wire){require_once dirname(__DIR__).'/notification/notice-wire-hold.php';$native=governance_notice_wire_hold($fixture,$check);}
      elseif($private_mail_pending){require_once dirname(__DIR__).'/notification/pending-wire-hold.php';$native=governance_private_mail_pending_hold($fixture,$check);}
      else{require_once dirname(__DIR__).'/notification/wire-hold.php';$native=governance_private_mail_wire_hold($fixture,$check);}
    }elseif($phase==='api-credential-concurrency'){
      require_once dirname(__DIR__).'/recovery/api-concurrency-cases.php';
      $native=governance_recovery_api_concurrency_cases($fixture,$check);
    }else{
      require_once __DIR__.'/native-cases.php';$native=governance_native_organization_cases($fixture,$check);
    }
  }
} catch (Throwable $error) {
  $attempt=$phase==='recovery-partial-composition'?($GLOBALS['governance_recovery_partial_attempt']??null):null;
  $failure = array('stage'=>is_string($attempt)?$attempt:$stage,'error_type'=>get_class($error),
    'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  if($phase==='recovery-partial-composition'&&isset($GLOBALS['governance_recovery_partial_source_difference'])) {
    $failure['source_difference']=governance_recovery_partial_source_projection($GLOBALS['governance_recovery_partial_source_difference']);
  }
  if($phase==='organization-approval-workbench'&&isset($GLOBALS['governance_approval_workbench_GET_failure'])){try{$failure['workbench_GET_diagnostic']=governance_approval_workbench_get_projection($GLOBALS['governance_approval_workbench_GET_failure']);}catch(Throwable$projection_error){$failure['workbench_GET_diagnostic']=array('projection_unproved'=>true);}}
  if($phase==='spaces-source-facts'&&isset($GLOBALS['governance_spaces_editor_failure'])){$failure['spaces_editor_diagnostic']=$GLOBALS['governance_spaces_editor_failure'];}
  if($phase==='calendar-reminders'&&isset($GLOBALS['governance_calendar_egress_probe'])){
    $failure['calendar_egress_probe']=$GLOBALS['governance_calendar_egress_probe'];
    if(($GLOBALS['governance_calendar_attempt']??null)==='calendar-private-each-original-event-current-query-and-egress-allow'){$failure['stage']='calendar-private-each-original-event-current-query-and-egress-allow';}
  }
  if ($error instanceof mysqli_sql_exception) { $failure['sql_errno']=$error->getCode(); }
  if(isset($GLOBALS['governance_notice_admin_grant_cleanup_projection'])){$failure['notice_admin_grant_cleanup']=$GLOBALS['governance_notice_admin_grant_cleanup_projection'];}
  if(isset($GLOBALS['governance_notice_raw_orm_queue_cleanup_proved'])){$failure['notice_raw_orm_queue_cleanup_proved']=$GLOBALS['governance_notice_raw_orm_queue_cleanup_proved'];}
  if(isset($GLOBALS['governance_notice_raw_orm_facts'])){$failure['notice_raw_orm_facts']=$GLOBALS['governance_notice_raw_orm_facts'];}
  if(isset($GLOBALS['governance_notice_wait_failure'])){$failure['notice_wait_diagnostic']=$GLOBALS['governance_notice_wait_failure'];}
  if(isset($GLOBALS['governance_api_concurrency_child_failure'])){
    $failure['api_concurrency_child_failure']=$GLOBALS['governance_api_concurrency_child_failure'];
  }
  if(isset($GLOBALS['governance_session_producer_child_failure'])){
    $failure['session_producer_child_failure']=$GLOBALS['governance_session_producer_child_failure'];
  }
  if(isset($GLOBALS['governance_private_business_child_failure'])){
    $failure['child_diagnostic']=$GLOBALS['governance_private_business_child_failure'];
  }
  if($phase==='session-borrowed-owner'&&isset($GLOBALS['governance_borrowed_worker_diagnostic'])){$failure['borrowed_worker']=$GLOBALS['governance_borrowed_worker_diagnostic'];}
  if($phase==='conduit-producer-owner'){
    require_once dirname(__DIR__).'/recovery/conduit-diagnostic.php';
    $failure['conduit_diagnostic']=governance_recovery_conduit_failure_projection($GLOBALS['governance_conduit_failure_diagnostic']??$failure);
    governance_recovery_conduit_emit_failure($failure['conduit_diagnostic']);
  }
  if($native_rr&&isset($GLOBALS['governance_native_rr_failure'])){$failure['native_rr_child']=$GLOBALS['governance_native_rr_failure'];}
} finally {
  $borrowed_unknown=$phase==='session-borrowed-owner'&&(!function_exists('governance_recovery_borrowed_cleanup_ready')||!governance_recovery_borrowed_cleanup_ready());
  if(($phase??null)==='conduit-producer-owner'&&(!function_exists('governance_recovery_conduit_cleanup_ready')||!governance_recovery_conduit_cleanup_ready())){$borrowed_unknown=true;}
  if(!$borrowed_unknown){$_COOKIE=$superglobal_state['_COOKIE']; $_SERVER=$superglobal_state['_SERVER'];}
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
  if(!$borrowed_unknown){
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
  } // Unknown NEW borrowed phase retains parent refs/environment until PHP shutdown.
  if ($file_service !== null) {
    try { $file_service_cleanup=governance_completion_stop_gorge($file_service); }
    catch (Throwable $error) { $cleanup_error('owned-file-service-cleanup',$error); }
  }
  if($borrowed_unknown){
    $cleanup_failures[]=array('stage'=>'borrowed-parent-or-observer-terminal-unproved','error_type'=>'RuntimeException');
    // No explicit rollback/close/DROP. PHP exit may naturally close/rollback; not a live lease forever.
  }elseif($native_rr&&($GLOBALS['governance_native_rr_cleanup']['cleanup_proved']??null)!==true){
    $cleanup_failures[]=array('stage'=>'native-rr-child-or-SQL-session-cleanup-unproved','error_type'=>'RuntimeException');
    // Retain owned schemas/principals for external owner reconciliation; never drop around unknown live child locks.
  }elseif(($media_remote??false)&&($GLOBALS['governance_workbench_http_cleanup_permitted']??null)!==true){
    $cleanup_failures[]=array('stage'=>'media-remote-parent-or-live-writer-cleanup-unproved','error_type'=>'RuntimeException');
    // Retain this exact remote-only fixture until independent writerSQL0.
  }elseif(($calendar_wire??false)&&($GLOBALS['governance_workbench_http_cleanup_permitted']??null)!==true){
    $cleanup_failures[]=array('stage'=>'calendar-wire-parent-or-live-writer-cleanup-unproved','error_type'=>'RuntimeException');
    // Only the Calendar parent-stop/quiesce/independent-SQL0 release can permit cleanup.
  }elseif(($workbench_http||$notice_history)&&($GLOBALS['governance_workbench_http_cleanup_permitted']??null)!==true){
    $cleanup_failures[]=array('stage'=>'workbench-http-parent-or-live-writer-cleanup-unproved','error_type'=>'RuntimeException');
    // Retain ownership/schema evidence until the parent proves its server stopped.
  }elseif($phase==='session-producer-lease'&&($GLOBALS['governance_session_lease_cleanup']['cleanup_proved']??null)!==true){
    $cleanup_failures[]=array('stage'=>'session-lease-observer-or-physical-cleanup-unproved','error_type'=>'RuntimeException');
    // Retain this owned fixture for Root reconciliation; never drop around unknown sessions.
  }elseif(($GLOBALS['governance_policy_nested_cleanup_permitted']??null)===false){
    $cleanup_failures[]=array('stage'=>'policy-nested-child-or-SQL-terminal-unproved','error_type'=>'RuntimeException');
    // Keep exact owned schemas/principals if the quarantined child is unknown.
  }elseif ($fixture !== null) {
    try { $cleanup = $fixture->cleanup(); $checks[]='all-owned-full-native-schemas-and-principals-cleaned'; }
    catch (Throwable $error) { $cleanup_error('owned-cleanup',$error); }
  }
  if(!$borrowed_unknown){if ($control !== null) { $control->close(); }}
  if ($console_owned) {
    (new ReflectionProperty(PhutilConsole::class,'console'))->setValue(null,$console_state);
  }
}
$native_output = $capture_active ? ob_get_clean() : '';
if (preg_match('/(?:PHP Warning|Warning:|Fatal error:|PHLOG:|UNSAFE: Raw string)/',$native_output) && $failure === null) {
  $failure = array('stage'=>'unexpected-native-runtime-output','error_type'=>'RuntimeWarning');
}
$passed = $failure === null && !$cleanup_failures;
if($phase==='session-producer-lease'&&!$passed){require_once dirname(__DIR__).'/recovery/session-lease-diagnostic.php';$diagnostic=governance_recovery_session_lease_failure_projection($phase,$passed,$failure,$GLOBALS['governance_session_producer_child_failure']??null);fwrite(STDERR,'native-session-lease-failure-v1 '.json_encode($diagnostic,JSON_UNESCAPED_SLASHES)."\n");}
$batch=$native_rr?'native-same-User-RR-MDL-component':($notice_raw_orm?'native-notice-raw-orm-ABA-profile':($phase==='recovery-organization-lifecycle-only'?'native-recovery-organization-lifecycle-component':($phase==='recovery-organization-metadata-only'?'native-recovery-organization-live-metadata-component':($phase==='recovery-organization-only'?'native-recovery-organization-producer-component':($phase==='recovery-policy-only'?'native-recovery-policy-range-component':($private_mail_wire?'private-native-mail-wire-preparation':($wire_hold?'native-media-wire-fixture-preparation':($legacy_compatibility?'native-legacy-role-sql-compatibility':'native-organization-media-integration'))))))));
$batch=$calendar_wire?'native-calendar-wire-fixture-preparation':$batch;
$batch=$phase==='calendar-reminders'?'native-private-calendar-reminders':$batch;
$batch=$workbench_http?'native-workbench-basic-http-hold':$batch;
$batch=$phase==='notice-admin-grant'?'native-notice-admin-grant-profile':$batch;
$batch=$phase==='project-source-facts'?'native-project-source-facts':$batch;
$batch=$phase==='organization-approval-workbench'?'native-organization-approval-workbench':$batch;
$batch=$phase==='session-borrowed-owner'?'native-borrowed-session-owner':$batch;
$batch=$phase==='conduit-producer-owner'?'native-conduit-producer-owner':$batch;
$batch=$phase==='session-producer-lease'?'native-session-producer-lease':$batch;
$batch=$phase==='file-parent-policy'?'native-File-parent-policy-endpoints':$batch;
$batch=$phase==='file-source-facts'?'native-file-source-facts':$batch;
$batch=$phase==='task-owner-source-facts'?'native-task-owner-source-facts':$batch;
$batch=$phase==='spaces-source-facts'?'native-spaces-source-facts':$batch;
$batch=$phase==='recovery-partial-composition'?'native-recovery-partial-composition':$batch;
$batch=$phase==='application-config-facts'?'native-application-config-facts':$batch;
$batch=$credential_concurrency?'native-api-credential-concurrency':$batch;
$receipt_scope=$phase==='notice-admin-grant'?'owned-native-admin-grant-and-transaction-outbox':($notice_raw_orm?'owned-notice-raw-native-DAO-restoration-off':($private_mail_wire?'owned-private-native-mail-wire-preparation':($wire_hold?'owned-native-media-wire-hold-synthetic-accounts':($legacy_compatibility?'owned-full-native-legacy-role-hooks-only':'owned-full-native-source-synthetic-accounts'))));
$receipt_scope=$calendar_wire?'owned-native-calendar-wire-hold-synthetic-accounts':$receipt_scope;
$receipt_scope=$phase==='calendar-reminders'?'owned-native-calendar-reminders-no-SMTP':$receipt_scope;
$receipt_scope=$workbench_http?'owned-workbench-basic-http-synthetic-roles':$receipt_scope;
$receipt_scope=$phase==='project-source-facts'?'owned-native-project-source-facts-no-lease':$receipt_scope;
$receipt_scope=$phase==='organization-approval-workbench'?'owned-native-organization-approval-workbench':$receipt_scope;
$receipt_scope=$phase==='session-borrowed-owner'?'owned-native-borrowed-session-owner-no-permit':$receipt_scope;
$receipt_scope=$phase==='conduit-producer-owner'?'owned-native-conduit-producer-owner-no-permit':$receipt_scope;
$receipt_scope=$phase==='session-producer-lease'?'owned-native-session-producer-lease-no-permit':$receipt_scope;
$receipt_scope=$phase==='file-parent-policy'?'owned-native-File-parent-policy-endpoints-no-permit':$receipt_scope;
$receipt_scope=$phase==='file-source-facts'?'owned-native-File-source-partial':$receipt_scope;
$receipt_scope=$phase==='task-owner-source-facts'?'owned-native-task-owner-source-partial':$receipt_scope;
$receipt_scope=$phase==='spaces-source-facts'?'owned-native-spaces-source-facts-no-lease':$receipt_scope;
$receipt_scope=$phase==='recovery-partial-composition'?'owned-native-partial-composition-no-permit':$receipt_scope;
$receipt_scope=$phase==='application-config-facts'?'owned-native-config-source-facts-no-lease':$receipt_scope;
$receipt_scope=$credential_concurrency?'owned-native-credential-writer-reader-observation':$receipt_scope;
echo json_encode(array('batch'=>$batch,'passed'=>$passed,
  'scope'=>$receipt_scope,'checks'=>$checks,'check_count'=>count($checks),
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
  'remaining_blocked'=>$credential_concurrency
    ? array('api-two-producer-deadlock-stress','oauth-governance-current-reader','all-thirteen-source-families','restoration-permit','production-deployment')
    : ($legacy_compatibility
    ? array('optional-backend-mariadb-compatibility','full-database-version-matrix','global-governance-completion')
    : array('media-http-and-cookie-integration','file-object-guards','notification-producers','global-governance-completion'))),
  JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
if($phase==='recovery-partial-composition'&&$passed===false){
  require_once dirname(__DIR__).'/recovery/partial-failure-diagnostic.php';
  $diagnostic=governance_recovery_partial_failure_projection($phase,$passed,$failure);
  fwrite(STDERR,json_encode($diagnostic,JSON_UNESCAPED_SLASHES)."\n");
}
if($phase==='session-borrowed-owner'&&$passed===false){require_once dirname(__DIR__).'/recovery/borrowed-session-diagnostic.php';fwrite(STDERR,json_encode(governance_recovery_borrowed_failure_projection($failure),JSON_UNESCAPED_SLASHES)."\n");}
exit($passed?0:1);
