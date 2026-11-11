<?php
/** Fresh exec only: deliberately quarantined native frames die with this child. */
require_once dirname(__DIR__,2).'/runtime/php-version.php';
$root=dirname(__DIR__,3);$path=$argv[1]??null;
try {
  phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli'||count($argv)!==2||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){
    throw new RuntimeException('nested_worker_scope_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){
    if(($v=getenv($key))!==false&&$v!==''){throw new RuntimeException('nested_worker_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$file){if(file_exists($root.'/conf/local/'.$file)){
    throw new RuntimeException('nested_worker_deployment_refused');}}
  if(!is_string($path)||is_link($path)||realpath(dirname($path))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Agovernance-policy-nested-[a-f0-9]{24}\z/D',basename($path))){throw new RuntimeException('nested_worker_path_refused');}
  $stat=lstat($path);if(!$stat||($stat['mode']&0777)!==0700){throw new RuntimeException('nested_worker_path_refused');}
  foreach(array('owner','payload.json')as$file){$f=$path.'/'.$file;if(is_link($f)||!is_file($f)||
    (fileperms($f)&0777)!==0600||filesize($f)>65536){throw new RuntimeException('nested_worker_payload_refused');}}
  $p=json_decode(file_get_contents($path.'/payload.json'),true,16,JSON_THROW_ON_ERROR);
  $keys=array_keys($p);sort($keys);if($keys!==array('config','dev','execution_config','execution_digest','fixture_nonce','ino','owner','project_id','session','source','ticket')||
    $p['dev']!==$stat['dev']||$p['ino']!==$stat['ino']||!is_string($p['owner'])||!preg_match('/\A[a-f0-9]{64}\z/D',$p['owner'])||
    !hash_equals($p['owner'],file_get_contents($path.'/owner'))||!is_string($p['fixture_nonce'])||
    !preg_match('/\A[a-f0-9]{64}\z/D',$p['fixture_nonce'])||!hash_equals(hash_file('sha256',$root.'/resources/sql/quickstart.sql'),$p['source'])||
    !is_int($p['project_id'])||$p['project_id']<1||!is_string($p['ticket'])||!preg_match('/\A[a-f0-9]{64}\z/D',$p['ticket'])||
    !is_string($p['session'])||strlen($p['session'])>512){throw new RuntimeException('nested_worker_owner_refused');}
  $owned=function()use($path,$p){$s=lstat($path);if(!$s||is_link($path)||$s['dev']!==$p['dev']||$s['ino']!==$p['ino']||
    ($s['mode']&0777)!==0700||is_link($path.'/owner')||(fileperms($path.'/owner')&0777)!==0600||
    !hash_equals($p['owner'],file_get_contents($path.'/owner'))){throw new RuntimeException('nested_worker_owner_refused');}};
  $publish=function($name,array$data)use($path,$owned){$owned();if(!in_array($name,array('ready.json','result.json','terminal-intent.json'),true)){
    throw new RuntimeException('nested_worker_output_refused');}$f=$path.'/.write-'.bin2hex(random_bytes(8));$h=fopen($f,'x');
    if(!$h){throw new RuntimeException('nested_worker_output_refused');}try{chmod($f,0600);$raw=json_encode($data,JSON_THROW_ON_ERROR);
      if(fwrite($h,$raw)!==strlen($raw)||!fflush($h)){throw new RuntimeException('nested_worker_output_refused');}}finally{fclose($h);}
    $owned();if(file_exists($path.'/'.$name)||!rename($f,$path.'/'.$name)){throw new RuntimeException('nested_worker_output_refused');}};
  $wait=function($name)use($path,$p,$owned){$until=microtime(true)+8;do{$owned();$f=$path.'/'.$name;
    if(is_file($f)&&!is_link($f)&&(fileperms($f)&0777)===0600&&filesize($f)===64&&hash_equals($p['owner'],file_get_contents($f))){return;}
    usleep(20000);}while(microtime(true)<$until);throw new RuntimeException('nested_worker_parent_deadline');};
  require_once $root.'/support/runtime/bootstrap-cli.php';phutil_load_library($root.'/src/');
  $quiet=PhutilConsole::newLocalConsole();$quiet->disableOut();PhutilConsole::setConsole($quiet);
  $c=$p['config'];$keys=array_keys($c);sort($keys);if($keys!==array('host','namespace','password','port','user')||
    $c['host']!=='127.0.0.1'||!is_int($c['port'])||$c['port']<1||$c['port']>65535||
    !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$c['namespace'])||!preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/D',$c['user'])||
    !preg_match('/\A[a-f0-9]{48}\z/D',$c['password'])){throw new RuntimeException('nested_worker_config_refused');}
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$proof=new mysqli($c['host'],$c['user'],$c['password'],'',$c['port']);
  $seen_guard=false;$seen_project=false;$seen_user=false;
  foreach($proof->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM)as$entry){$grant=$entry[0];
    if(str_contains($grant,'WITH GRANT OPTION')){throw new RuntimeException('nested_worker_grants_refused');}
    if(preg_match('/\AGRANT USAGE ON \*\.\* TO /',$grant)){continue;}
    if(!preg_match('/\AGRANT (.+) ON `([^`]+)`\.\* TO /',$grant,$m)){throw new RuntimeException('nested_worker_grants_refused');}
    $db=str_replace('\\_','_',$m[2]);if(!preg_match('/\A'.preg_quote($c['namespace'],'/').'_[a-z_]+\z/D',$db)){throw new RuntimeException('nested_worker_grants_refused');}
    $seen_guard=$seen_guard||$db===$c['namespace'].'_governance';$seen_project=$seen_project||$db===$c['namespace'].'_project';$seen_user=$seen_user||$db===$c['namespace'].'_user';}
  if(!$seen_guard||!$seen_project||!$seen_user){throw new RuntimeException('nested_worker_grants_refused');}
  $lineage=function()use($proof,$c,$p){$r=$proof->query('SELECT marker,quickstart_sha256,owner_nonce FROM `'.$c['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$r||$r['marker']!=='N07.NATIVE-SOURCE.v1:synthetic-only'||!hash_equals($r['quickstart_sha256'],$p['source'])||
      !hash_equals($r['owner_nonce'],$p['fixture_nonce'])){throw new RuntimeException('nested_worker_lineage_refused');}};$lineage();
  if(PhabricatorEnv::getConfigSourceStack()!==null||(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()){
    throw new RuntimeException('nested_worker_inherited_pool_refused');}
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
  $settings=array('mysql.host'=>$c['host'],'mysql.port'=>$c['port'],'mysql.user'=>$c['user'],'mysql.pass'=>$c['password'],
    'storage.default-namespace'=>$c['namespace'],'phabricator.cache-namespace'=>$c['namespace'],'cluster.databases'=>array(),
    'cluster.search'=>array(),'phabricator.base-uri'=>'http://fixture.invalid/','policy.allow-public'=>false,
    'governance.project-approval.enabled'=>true,'governance.organization.enabled'=>false,'governance.media-exchange.enabled'=>false,
    'projects.default-navigation'=>'flat','project.history-state'=>'off','project.workspace-mode'=>'native',
    'project.history-task-drafts'=>false,'gorge.search.source-scan'=>false,'gorge.search.projection-shadow'=>false,
    'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'gorge.notification.outbox'=>false,'notification.servers'=>array(),
    'gorge.db.uri'=>null,'gorge.file.uri'=>null,'gorge.image.uri'=>null,'gorge.render.uri'=>null);
  $ek=array_keys($p['execution_config']);$expected=PhorgeProjectExecutionCatalog::configurationKeys();sort($ek);sort($expected);
  if($ek!==$expected||!is_string($p['execution_digest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$p['execution_digest'])){throw new RuntimeException('nested_worker_execution_config_refused');}
  PhorgeProjectExecutionConfigSource::validateControlValues($p['execution_config']);
  foreach(array('mysql.host'=>'host','mysql.user'=>'user','mysql.pass'=>'password','storage.default-namespace'=>'namespace')as$key=>$field){
    if($p['execution_config'][$key]!==$c[$field]){throw new RuntimeException('nested_worker_execution_route_refused');}}
  if(PhorgeProjectExecutionConfigSource::normalizePort($p['execution_config']['mysql.port'])!==$c['port']||
    $p['execution_config']['load-libraries']!==array()||$p['execution_config']['cluster.databases']!==array()||
    !in_array($p['execution_config']['gorge.db.uri'],array(null,''),true)||$p['execution_config']['governance.project-approval.enabled']!==true){
    throw new RuntimeException('nested_worker_execution_route_refused');}
  $settings=array_replace($settings,$p['execution_config']);
  $stack->pushSource(new PhabricatorConfigDictionarySource($settings));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  $guard=new AphrontWriteGuard($lineage);
  $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$p['session'];
  $viewer=(new PhabricatorAuthSessionEngine())->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$p['session']);
  if(!$viewer||!$viewer->hasSession()||$viewer->getIsDisabled()){throw new RuntimeException('nested_worker_actor_refused');}
  $request=(new AphrontRequest('fixture.invalid','/'))->setUser($viewer);
  $request->setRequestData(array(AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));$request->validateCSRF();
  $project=(new PhabricatorProjectQuery())->setViewer($viewer)->withIDs(array($p['project_id']))->executeOne();
  if(!$project){throw new RuntimeException('nested_worker_project_refused');}
  $conn=$project->establishConnection('w');$identity=queryfx_one($conn,'SELECT CONNECTION_ID() cid,@@server_uuid uuid,CURRENT_USER() principal');
  $publish('ready.json',array('owner'=>$p['owner'],'cid'=>(int)$identity['cid'],'uuid'=>$identity['uuid'],'principal'=>$identity['principal']));
  $wait('execute');$fired=false;$wrote=false;$depth=0;$rollbacks=0;$scope=null;$native_state=null;$generation=null;$config_matches=false;
  $profiler=PhutilServiceProfiler::getInstance();$marker='UPDATE `'.$c['namespace'].'_project`.`'.$project->getTableName().'` SET ';
  $profiler->addListener(function($phase,$id,array$d)use($conn,$project,$marker,&$fired,&$wrote,&$depth,&$rollbacks,&$scope,&$native_state,&$generation,&$config_matches,$p){
    if(($d['type']??null)!=='query'||!is_string($d['query']??null)){return;}
    if($phase==='end'&&str_starts_with($d['query'],'ROLLBACK')){$rollbacks++;}
    if($phase!=='end'||$fired||!str_starts_with($d['query'],$marker)){return;}$fired=true;
    $native_state=(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($conn);
    $depth=$native_state->getDepth();$generation=$native_state->getGeneration();
    $active=(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'active'))->getValue();$scope=($active[spl_object_id($conn)]??null)?->get();
    $config_matches=$scope!==null&&hash_equals($p['execution_digest'],$scope->getSources()['configuration']);
    $wrote=queryfx_one($conn,'SELECT editPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID())['editPolicy']===PhabricatorPolicies::POLICY_USER;
    throw new Error('isolated-native-project-nested-error');});
  $caught=null;try{(new PhabricatorProjectTransactionEditor())->setActor($viewer)->setRequest($request)
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setProjectApprovalID($p['ticket'])
    ->setContinueOnMissingFields(true)->applyTransactions($project,array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)->setNewValue(PhabricatorPolicies::POLICY_USER)));
  }catch(Throwable$error){$caught=$error;}
  $primary=false;for($e=$caught;$e!==null;$e=$e->getPrevious()){if(get_class($e)===Error::class&&$e->getMessage()==='isolated-native-project-nested-error'){$primary=true;}}
  $read=function($key)use(&$scope){return $scope?(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,$key))->getValue($scope):null;};
  $active=(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'active'))->getValue();
  $unknown=$caught instanceof RuntimeException&&$caught->getMessage()==='project_execution_scope_cleanup_failed'&&$primary&&$config_matches&&$fired&&$wrote&&$depth>=2&&$rollbacks===0&&
    $scope!==null&&$read('state')==='quarantined'&&$read('transactionOwnership')==='unknown'&&
    ($active[spl_object_id($conn)]??null)?->get()===$scope&&
    (new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($conn)===$native_state&&
    $native_state->getDepth()===$depth&&$native_state->getGeneration()===$generation;
  $publish('result.json',array('owner'=>$p['owner'],'status'=>$unknown?'expected-quarantine':'protocol-failed',
    'cid'=>(int)$identity['cid'],'configuration_sources_match'=>$config_matches,'real_update_before_error'=>$wrote,'nested_depth'=>$depth,'rollback_count'=>$rollbacks,
    'primary_error_preserved'=>$primary,'unknown_not_acknowledged'=>$unknown,'retry_attempted'=>false));
  $wait('release');
  $publish('terminal-intent.json',array('owner'=>$p['owner'],'exit_intent'=>$unknown?73:74,'quarantined'=>$unknown));
  exit($unknown?73:74);
}catch(Throwable$error){exit(74);}
