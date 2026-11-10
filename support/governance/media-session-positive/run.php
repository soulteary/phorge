<?php
/** Explicit isolated SQL entry; never connects without --execute and private profile. */
require_once __DIR__.'/profile.php';
require_once __DIR__.'/diagnostic.php';
$root=dirname(__DIR__,3);$stage='preflight';$fixture=null;$admin=null;$writer=null;$guard=null;$cleanup=null;$passed=false;$failure=false;
$diagnostic=null;$cleanup_diagnostic=null;
$GLOBALS['media_positive_checks']=array();
$capture=(object)array('bytes'=>0,'finalized'=>false,'closed'=>false);
ob_start(static function($chunk,$phase)use($capture){if(($phase&PHP_OUTPUT_HANDLER_FINAL)!==0){$capture->finalized=true;}$capture->bytes+=strlen($chunk);return '';},1);
$captureDepth=ob_get_level();
try {
  if ($argc!==3 || $argv[1]!=='--execute' || PHP_SAPI!=='cli' ||
      getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1') { throw new RuntimeException('media_positive_explicit_scope'); }
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){if(getenv($key)!==false&&getenv($key)!==''){throw new RuntimeException('media_positive_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('media_positive_deployment_refused');}}
  $p=media_positive_profile_file($argv[2]);
  $manifest=file_get_contents(__DIR__.'/source-pins.json');
  if(hash('sha256',$manifest)!=='5d708748e4bf47af83ae97edac0fb9f37ac89d41ce11b1d7d371fb595e24d222'){throw new RuntimeException('media_positive_source_manifest');}
  foreach(json_decode($manifest,true,32,JSON_THROW_ON_ERROR)as$relative=>$expected){if(!hash_equals($expected,hash_file('sha256',$root.'/'.$relative))){throw new RuntimeException('media_positive_source_changed');}}
  require_once $root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  require_once $root.'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($root.'/src');
  require_once $root.'/support/governance/completion/fixture.php';
  require_once $root.'/support/governance/native-organization/directory-restore-progress.php';
  require_once __DIR__.'/cases.php';
  if(PhabricatorEnv::getConfigSourceStack()!==null||(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()){throw new RuntimeException('media_positive_inherited_state');}
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  $admin=mysqli_init();$admin->options(MYSQLI_OPT_CONNECT_TIMEOUT,5);$admin->options(MYSQLI_OPT_READ_TIMEOUT,5);
  $admin->real_connect($p['host'],$p['user'],$p['password'],'',$p['port']);$admin->set_charset('utf8mb4');
  $process=false;foreach($admin->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM)as$row){if(preg_match('/\AGRANT (?:ALL PRIVILEGES|.*\bPROCESS\b.*) ON \*\.\* TO /',$row[0])){$process=true;}}
  if(!$process){throw new RuntimeException('media_positive_process_observer_required');}
  $stage='owned-native-fixture-create';$fixture=new PhorgeGovernanceCompletionFixture($admin,$root,$p['host'],$p['port']);
  $restore=governance_directory_capture_restore_progress(function()use($fixture){$fixture->create();},$root);
  $writer=$fixture->getWriterConfig();
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array(
    'mysql.host'=>$writer['host'],'mysql.port'=>$writer['port'],'mysql.user'=>$writer['user'],'mysql.pass'=>$writer['password'],
    'storage.default-namespace'=>$writer['namespace'],'phabricator.cache-namespace'=>$writer['namespace'],
    'cluster.databases'=>array(),'cluster.search'=>array(),'phabricator.base-uri'=>'https://app.example.com/',
    'security.alternate-file-domain'=>'https://files.example.com/','cluster.instance'=>null,
    'governance.media-exchange.enabled'=>true,'governance.media-exchange.http-enabled'=>true,
    'governance.media-exchange.issuer-key'=>bin2hex(random_bytes(32)),'governance.media-exchange.incarnation'=>bin2hex(random_bytes(16)),
    'gorge.file.uri'=>null,'gorge.image.uri'=>null,'gorge.db.uri'=>null,'gorge.taskqueue.uri'=>null,
    'gorge.notification.outbox'=>false,'notification.servers'=>array(),'gorge.search.source-scan'=>false,
    'governance.private-mail.enabled'=>false,'governance.organization.enabled'=>false,
  )));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  if(AphrontWriteGuard::isGuardActive()){throw new RuntimeException('media_positive_inherited_write_guard');}
  $guard=new AphrontWriteGuard(function()use($fixture,$writer){
    $fixture->assertOwned();foreach(array('mysql.host'=>'host','mysql.port'=>'port','mysql.user'=>'user','mysql.pass'=>'password','storage.default-namespace'=>'namespace')as$key=>$field){if(PhabricatorEnv::getEnvConfig($key)!==$writer[$field]){throw new RuntimeException('media_positive_writer_route');}}
  });
  $stage='native-controller-positive-cases';media_positive_cases($fixture);$passed=true;
} catch(Throwable $e) { $failure=true;$diagnostic=media_positive_diagnostic($e,$root,'primary'); }
finally {
  // Never DROP while native writer ownership/terminal state is unproved.
  if($passed&&!$failure&&$fixture!==null&&$writer!==null&&$admin!==null){
    try {
      unset($guard);LiskDAO::closeAllConnections();
      $fixture->closeOwnedWriterConnectionForHTTP();
      $deadline=microtime(true)+5;$zero=false;
      do{
        $s=$admin->prepare('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?');$s->execute(array($writer['user']));$count=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
        if($count===0){$zero=true;break;}usleep(20000);
      }while(microtime(true)<$deadline);
      if(!$zero){throw new RuntimeException('media_positive_native_sessions_remain');}
      $s=$admin->prepare('SELECT COUNT(*) n FROM performance_schema.metadata_locks m JOIN performance_schema.threads t ON t.THREAD_ID=m.OWNER_THREAD_ID WHERE t.PROCESSLIST_USER=?');$s->execute(array($writer['user']));$mdl=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
      if($mdl!==0){throw new RuntimeException('media_positive_native_metadata_locks_remain');}
      $cleanup=$fixture->cleanup();$cleanup['native_writer_sessions_zero']=true;$cleanup['native_writer_MDL_zero']=true;
    }catch(Throwable $e){$failure=true;$passed=false;$cleanup_diagnostic=media_positive_diagnostic($e,$root,'cleanup-native-writer');}
  }
  if($fixture!==null&&$cleanup===null){$passed=false;$failure=true;}
  if($admin!==null){try{$admin->close();}catch(Throwable $e){$passed=false;$failure=true;if($cleanup_diagnostic===null){$cleanup_diagnostic=media_positive_diagnostic($e,$root,'cleanup-control-close');}}}
}
if(!$capture->finalized&&ob_get_level()===$captureDepth){ob_end_clean();$capture->closed=$capture->finalized;}
if(!$capture->closed||$capture->bytes!==0){$passed=false;$failure=true;}
echo json_encode(array('schema'=>'native-media-session-positive-sql-v1','passed'=>$passed&&!$failure,'stage'=>$stage,
  'diagnostic'=>$diagnostic,'cleanup_diagnostic'=>$cleanup_diagnostic,'checks'=>$GLOBALS['media_positive_checks'],'unexpected_output_bytes'=>$capture->bytes,'cleanup'=>$cleanup,'failed_resources_retained'=>$fixture!==null&&$cleanup===null,'unknown_commit_reconciled'=>false,'browser_tested'=>false,'password_form_login_tested'=>false,
  'TLS_tested'=>false,'file_authorization_tested'=>false,'production_changed'=>false),JSON_THROW_ON_ERROR)."\n";
exit($passed&&!$failure?0:1);
