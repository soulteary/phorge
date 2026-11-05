<?php
// One request per process. Never include this entry point or reuse its DAO cache.
require_once __DIR__.'/native-writer-rr-protocol.php';
$root=dirname(__DIR__,3);$directory=$argv[1]??null;$scope=null;$component=null;$guard=null;$observer=null;$control=null;$payload=null;$result=null;$buffer=false;
try{
  if(PHP_SAPI!=='cli'||count($argv)!==2||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){throw new RuntimeException('native_rr_worker_scope_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){if(getenv($key)!==false&&getenv($key)!==''){throw new RuntimeException('native_rr_worker_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('native_rr_worker_deployment_refused');}}
  $stat=governance_native_rr_directory($directory);$owner=governance_native_rr_read($directory,'owner',64);
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$owner)){throw new RuntimeException('native_rr_worker_owner_refused');}
  $payload=json_decode(governance_native_rr_read($directory,'payload.json'),true,16,JSON_THROW_ON_ERROR);$keys=array_keys($payload);sort($keys);
  if($keys!==array('classmap_sha256','directory_dev','directory_ino','ending','fixture_nonce','incarnation','metadata_owner','observer','owner_token','quickstart_sha256','source_revision','subject_phid','variant','writer')||$payload['directory_dev']!==$stat['dev']||$payload['directory_ino']!==$stat['ino']||$payload['owner_token']!==$owner||!in_array($payload['ending'],array('commit','rollback'),true)||!in_array($payload['variant'],array('candidate','missing-select','missing-lock'),true)||!is_string($payload['subject_phid'])||!preg_match('/\APHID-USER-[a-z0-9]{20}\z/D',$payload['subject_phid'])){throw new RuntimeException('native_rr_worker_payload_refused');}
  foreach(array('fixture_nonce'=>64,'incarnation'=>32,'source_revision'=>40,'classmap_sha256'=>64,'quickstart_sha256'=>64)as$key=>$length){if(!is_string($payload[$key])||!preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$payload[$key])){throw new RuntimeException('native_rr_worker_payload_refused');}}
  if($payload['source_revision']!==getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION')||!hash_equals($payload['quickstart_sha256'],hash_file('sha256',$root.'/resources/sql/quickstart.sql'))||!hash_equals($payload['classmap_sha256'],hash_file('sha256',$root.'/src/__phutil_library_map__.php'))){throw new RuntimeException('native_rr_worker_source_refused');}
  foreach(array('writer','observer')as$role){$config=$payload[$role];$keys=array_keys($config);sort($keys);
    if($keys!==array('host','namespace','password','port','user')||$config['host']!=='127.0.0.1'||!is_int($config['port'])||$config['port']<1||$config['port']>65535||!is_string($config['namespace'])||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$config['namespace'])||!is_string($config['password'])||!preg_match('/\A[a-f0-9]{48}\z/D',$config['password'])||!is_string($config['user'])||!preg_match($role==='writer'?'/\Agov_c_writer_[a-f0-9]{12}\z/D':'/\Agov_c_metadata_[a-f0-9]{16}\z/D',$config['user'])){throw new RuntimeException('native_rr_worker_config_refused');}}
  $config=$payload['writer'];$meta=$payload['observer'];if($meta['port']!==$config['port']||$meta['namespace']!==$config['namespace']){throw new RuntimeException('native_rr_worker_primary_refused');}
  $expected=$payload['metadata_owner'];$keys=array_keys($expected);sort($keys);
  if($keys!==array('governance_fixture_namespace','governance_fixture_owner','governance_run_nonce','governance_source_revision')||$expected['governance_fixture_namespace']!==$config['namespace']||$expected['governance_source_revision']!==$payload['source_revision']||!is_string($expected['governance_fixture_owner'])||!preg_match('/\A[a-f0-9]{64}\z/D',$expected['governance_fixture_owner'])||!is_string($expected['governance_run_nonce'])||!preg_match('/\A[a-f0-9]{32}\z/D',$expected['governance_run_nonce'])){throw new RuntimeException('native_rr_worker_metadata_owner_refused');}
  require_once $root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);require_once $root.'/support/runtime/bootstrap-cli.php';ob_start();$buffer=true;phutil_load_library($root.'/src');
  $console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
  if(PhabricatorEnv::getConfigSourceStack()!==null||(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()!==array()||(new ReflectionProperty(PhabricatorLiskDAO::class,'namespaceStack'))->getValue()!==array()||AphrontWriteGuard::isGuardActive()){throw new RuntimeException('native_rr_worker_inherited_cache_refused');}
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$control=new mysqli($meta['host'],$meta['user'],$meta['password'],'',$meta['port']);$control->set_charset('utf8mb4');
  $assertOwned=static function()use($control,$payload,$config,$expected,$meta){
    $row=$control->query('SELECT marker,quickstart_sha256,owner_nonce FROM `'.$config['namespace'].'_governance`.fixture_guard WHERE id=1')->fetch_assoc();
    if(!$row||$row['marker']!=='N07.NATIVE-SOURCE.v1:synthetic-only'||!hash_equals($payload['quickstart_sha256'],$row['quickstart_sha256'])||!hash_equals($payload['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('native_rr_worker_lineage_refused');}
    $row=$control->query("SELECT ATTRIBUTE attributes FROM information_schema.USER_ATTRIBUTES WHERE USER='".$meta['user']."' AND HOST='%'")->fetch_assoc();
    if(!$row){throw new RuntimeException('native_rr_worker_metadata_owner_refused');}$actual=json_decode($row['attributes'],true,16,JSON_THROW_ON_ERROR);foreach($expected as$key=>$value){if(($actual[$key]??null)!==$value){throw new RuntimeException('native_rr_worker_metadata_owner_refused');}}
  };$assertOwned();
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(array('mysql.host'=>$config['host'],'mysql.port'=>$config['port'],'mysql.user'=>$config['user'],'mysql.pass'=>$config['password'],'storage.default-namespace'=>$config['namespace'],'cluster.databases'=>array(),'cluster.search'=>array(),'gorge.db.uri'=>null,'gorge.file.uri'=>null,'gorge.image.uri'=>null,'gorge.render.uri'=>null,'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'governance.account-recovery.enabled'=>true,'governance.account-recovery.owned-rr-scope.enabled'=>true,'governance.account-recovery.incarnation'=>$payload['incarnation'],'governance.notifications.enabled'=>false,'governance.private-mail.enabled'=>false,'notification.servers'=>array(),'phabricator.silent'=>true)));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  $guard=new AphrontWriteGuard($assertOwned);$subject=(new PhabricatorUser())->loadOneWhere('phid = %s',$payload['subject_phid']);if(!$subject){throw new RuntimeException('native_rr_worker_subject_missing');}
  $writer=$subject->establishConnection('w');$grants=queryfx_all($writer,'SHOW GRANTS FOR CURRENT_USER()');$lines=array();foreach($grants as$row){if(count($row)!==1){throw new RuntimeException('native_rr_grants_refused');}$lines[]=head($row);}governance_native_rr_writer_grants($lines,$config['namespace'],$payload['variant']);
  $observer=new AphrontMySQLiDatabaseConnection(array('host'=>$meta['host'],'port'=>$meta['port'],'database'=>'','user'=>$meta['user'],'pass'=>new PhutilOpaqueEnvelope($meta['password']),'timeout'=>3));
  $writerIdentity=queryfx_one($writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid uuid,CURRENT_USER() principal,DATABASE() db');
  $observerIdentity=queryfx_one($observer,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid uuid,CURRENT_USER() principal');
  $controlIdentity=$control->query('SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid uuid,CURRENT_USER() principal')->fetch_assoc();
  governance_native_rr_write($directory,'identity.json',array('owner'=>$owner,'source_revision'=>$payload['source_revision'],'writer'=>$writerIdentity,'observer'=>$observerIdentity,'control'=>$controlIdentity),$owner,$stat['dev'],$stat['ino']);
  $scope=PhorgeRecoveryOwnedRRScope::begin($observer);require_once __DIR__.'/native-writer-rr-mdl-component.php';
  $component=GovernanceNativeWriterRRMDLComponent::begin($scope,$subject,$observer,$control,$payload['ending']);$component->writeInnocuousMarker();
  $ready=$component->privateHandshake();$ready['owner']=$owner;$ready['source_revision']=$payload['source_revision'];
  governance_native_rr_write($directory,'ready.json',$ready,$owner,$stat['dev'],$stat['ino']);
  $deadline=hrtime(true)+15000000000;
  while(!is_file($directory.'/finish')&&hrtime(true)<$deadline){governance_native_rr_directory($directory,$stat['dev'],$stat['ino']);usleep(20000);}
  if(!is_file($directory.'/finish')||!hash_equals($owner,governance_native_rr_read($directory,'finish',64))){throw new RuntimeException('native_rr_worker_finish_unproved');}
  $terminal=$component->finish();$wanted=$payload['ending']==='commit'?'committed':'rolled-back';
  if($terminal['state']!==$wanted||$terminal['cleanup_proved']!==true){throw new RuntimeException('native_rr_worker_terminal_unproved');}
  $result=array('status'=>'finished','ending'=>$payload['ending'],'terminal'=>$terminal,'native_enable_executed'=>false,'restoration_authorized'=>false);
}catch(Throwable$error){$result=array('status'=>'failed','error_type'=>get_class($error),'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine(),'native_enable_executed'=>false,'restoration_authorized'=>false);}
finally{
  $terminal=null;$cleanup=array();
  try{if($component){$terminal=$component->abort();}elseif($scope){$terminal=$scope->abort();}}catch(Throwable$error){$cleanup[]='scope-abort-unproved';}
  try{if($guard){$guard->dispose();}}catch(Throwable$error){$cleanup[]='write-guard-dispose-unproved';}
  try{if($observer){$observer->close();}}catch(Throwable$error){$cleanup[]='observer-close-unproved';}
  try{if($control){$control->close();}}catch(Throwable$error){$cleanup[]='control-close-unproved';}
  $out=$buffer?ob_get_clean():'';if(!is_string($out)){$out='';$cleanup[]='capture-unavailable';}$result['captured_output_bytes']=strlen($out);$result['captured_output_sha256']=hash('sha256',$out);$result['cleanup_failures']=$cleanup;
  $result['failure_terminal']=$terminal;$result['child_must_exit']=true;$result['parent_SQL_cleanup_required']=true;
  if($cleanup||$out!==''){$result['status']='failed';}
  if(is_array($payload)){try{governance_native_rr_write($directory,'result.json',$result,$payload['owner_token'],$payload['directory_dev'],$payload['directory_ino']);}catch(Throwable$error){$result['status']='failed';}}
}
// Unknown/observed committed are never relabeled as a rollback or permission denial.
exit(($result['status']??null)==='finished'?0:1);
