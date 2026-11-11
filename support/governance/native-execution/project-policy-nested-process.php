<?php
/** Fixture-only exec/observer protocol. Never repairs a quarantined DAO pool. */
function governance_policy_nested_result(array $ready,array $result,$owner) {
  $keys=array_keys($ready);sort($keys);$rk=array_keys($result);sort($rk);
  if($keys!==array('cid','owner','principal','uuid')||
    $rk!==array('cid','configuration_sources_match','nested_depth','owner','primary_error_preserved','real_update_before_error','retry_attempted','rollback_count','status','unknown_not_acknowledged')||
    !is_int($ready['cid'])||$ready['cid']<1||$result['cid']!==$ready['cid']||
    !hash_equals($owner,$ready['owner'])||!hash_equals($owner,$result['owner'])||
    $result['status']!=='expected-quarantine'||$result['configuration_sources_match']!==true||$result['real_update_before_error']!==true||
    !is_int($result['nested_depth'])||$result['nested_depth']<2||$result['rollback_count']!==0||
    $result['primary_error_preserved']!==true||$result['unknown_not_acknowledged']!==true||$result['retry_attempted']!==false){
    throw new RuntimeException('nested_fixture_protocol_refused');}
}
function governance_project_policy_nested_process($fixture,callable $check,array $actor,$project,$ticket) {
  $fixture->assertOwned();$ns=$fixture->getNamespace();$config=$fixture->getWriterConfig();
  $control=(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'control'))->getValue($fixture);
  $observer=$fixture->getAdmin();$parent_identity=$control->query('SELECT CONNECTION_ID() cid,@@server_uuid uuid,@@autocommit a')->fetch_assoc();
  $data_identity=$observer->query('SELECT CONNECTION_ID() cid,@@server_uuid uuid,@@autocommit a')->fetch_assoc();
  if(!$parent_identity||!$data_identity||(string)$data_identity['a']!=='1'||$data_identity['uuid']!==$parent_identity['uuid']||
    (string)$data_identity['cid']===(string)$parent_identity['cid']){throw new RuntimeException('nested_fixture_independent_observer_refused');}
  if(!$parent_identity||(string)$parent_identity['a']!=='1'||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$ns)||
    !is_string($ticket)||!preg_match('/\A[a-f0-9]{64}\z/D',$ticket)||$config['namespace']!==$ns){throw new RuntimeException('nested_fixture_owner_refused');}
  $snapshot=function()use($fixture,$observer,$ns,$project,$ticket,$actor){$fixture->assertOwned();
    $project_table=$project->getTableName();$native_table=(new PhabricatorProjectTransaction())->getTableName();$queue_table=(new PhabricatorWorkerActiveTask())->getTableName();
    $phid=$observer->real_escape_string($project->getPHID());$user=$observer->real_escape_string($actor['viewer']->getPHID());$rows=array();
    $queries=array(
      'ticket'=>"SELECT * FROM `{$ns}_project`.governance_projectapproval WHERE approvalID='{$ticket}'",
      'project'=>"SELECT * FROM `{$ns}_project`.`{$project_table}` WHERE id=".(int)$project->getID(),
      'native'=>"SELECT * FROM `{$ns}_project`.`{$native_table}` WHERE objectPHID='{$phid}'",
      'audit'=>"SELECT * FROM `{$ns}_project`.governance_executionaudit WHERE approvalID='{$ticket}'",
      'queue'=>"SELECT * FROM `{$ns}_worker`.`{$queue_table}` WHERE objectPHID='{$phid}'",
      'actor'=>"SELECT * FROM `{$ns}_user`.user WHERE phid='{$user}'",
      'sessions'=>"SELECT * FROM `{$ns}_user`.phabricator_session WHERE userPHID='{$user}'");
    foreach($queries as$key=>$sql){$data=$observer->query($sql)->fetch_all(MYSQLI_ASSOC);usort($data,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$rows[$key]=$data;}
    return $rows;};
  $before=$snapshot();$path=sys_get_temp_dir().'/governance-policy-nested-'.bin2hex(random_bytes(12));
  if(!mkdir($path,0700)){throw new RuntimeException('nested_fixture_directory_refused');}$stat=lstat($path);$owner=bin2hex(random_bytes(32));
  $owned=function()use($path,$stat,$owner){$s=lstat($path);if(!$s||is_link($path)||$s['dev']!==$stat['dev']||$s['ino']!==$stat['ino']||
    ($s['mode']&0777)!==0700){throw new RuntimeException('nested_fixture_owner_refused');}
    if(file_exists($path.'/owner')&&(is_link($path.'/owner')||(fileperms($path.'/owner')&0777)!==0600||
      !hash_equals($owner,file_get_contents($path.'/owner')))){throw new RuntimeException('nested_fixture_owner_refused');}};
  $write=function($name,$value)use($path,$owned){$owned();if(!in_array($name,array('owner','payload.json','execute','release','stdout.log','stderr.log'),true)){
    throw new RuntimeException('nested_fixture_file_refused');}$h=fopen($path.'/'.$name,'x');if(!$h){throw new RuntimeException('nested_fixture_file_refused');}
    try{chmod($path.'/'.$name,0600);$raw=is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR);
      if(fwrite($h,$raw)!==strlen($raw)||!fflush($h)){throw new RuntimeException('nested_fixture_file_refused');}}finally{fclose($h);}};
  $execution_config=array();foreach(PhorgeProjectExecutionCatalog::configurationKeys()as$key){$execution_config[$key]=PhabricatorEnv::getEnvConfig($key);}
  PhorgeProjectExecutionConfigSource::validateControlValues($execution_config);
  $binding=json_decode($before['ticket'][0]['bindingJSON']??'',true,32,JSON_THROW_ON_ERROR);
  $execution_digest=$binding['sources']['configuration']??null;
  if(!is_string($execution_digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$execution_digest)){throw new RuntimeException('nested_fixture_ticket_configuration_missing');}
  $write('owner',$owner);$write('payload.json',array('owner'=>$owner,'dev'=>$stat['dev'],'ino'=>$stat['ino'],'config'=>$config,
    'fixture_nonce'=>(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture),
    'source'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'project_id'=>(int)$project->getID(),
    'session'=>$actor['token'],'ticket'=>$ticket,'execution_config'=>$execution_config,'execution_digest'=>$execution_digest));
  // Register no kill-all/pool cleanup. A deadline or unknown owner remains a failure.
  $write('stdout.log','');$write('stderr.log','');
  $GLOBALS['governance_policy_nested_cleanup_permitted']=false;
  $process=proc_open(array(PHP_BINARY,__DIR__.'/project-policy-nested-worker.php',$path),
    array(0=>array('file','/dev/null','r'),1=>array('file',$path.'/stdout.log','a'),2=>array('file',$path.'/stderr.log','a')),
    $pipes,null,array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1'));
  if(!is_resource($process)){throw new RuntimeException('nested_fixture_exec_refused');}
  foreach(array('stdout.log','stderr.log')as$name){if(is_file($path.'/'.$name)){chmod($path.'/'.$name,0600);}}
  $read=function($name)use($path,$owned,$process){$until=microtime(true)+8;do{$owned();$f=$path.'/'.$name;
    if(is_file($f)&&!is_link($f)&&(fileperms($f)&0777)===0600&&filesize($f)<=4096){return json_decode(file_get_contents($f),true,8,JSON_THROW_ON_ERROR);}
    if(!proc_get_status($process)['running']){break;}usleep(20000);}while(microtime(true)<$until);
    throw new RuntimeException('nested_fixture_child_protocol_missing');};
  $ready=$read('ready.json');$cid=$ready['cid']??null;
  if(!is_int($cid)||$cid<1||$cid===(int)$parent_identity['cid']||$cid===(int)$data_identity['cid']||($ready['owner']??null)!==$owner||
    ($ready['uuid']??null)!==$parent_identity['uuid']||($ready['principal']??null)!==$config['user'].'@%'){
    throw new RuntimeException('nested_fixture_physical_identity_refused');}
  $observe=function()use($fixture,$control,$cid,$config){$fixture->assertOwned();$user=$control->real_escape_string($config['user']);
    $row=$control->query('SELECT COUNT(*) n,MIN(USER) principal FROM information_schema.PROCESSLIST WHERE ID='.$cid)->fetch_assoc();
    if($row&&(string)$row['n']==='1'&&$row['principal']!==$config['user']){throw new RuntimeException('nested_fixture_cid_owner_changed');}
    $tx=$control->query('SELECT COUNT(*) n FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.$cid)->fetch_assoc();
    if(!$row||!$tx||!in_array((string)$row['n'],array('0','1'),true)||!in_array((string)$tx['n'],array('0','1'),true)){
      throw new RuntimeException('nested_fixture_observer_unknown');}return array((int)$row['n'],(int)$tx['n']);};
  if($observe()!==array(1,0)){throw new RuntimeException('nested_fixture_prestate_refused');}
  $write('execute',$owner);$result=$read('result.json');governance_policy_nested_result($ready,$result,$owner);
  $until=microtime(true)+3;$live_transaction=false;
  do{if($observe()===array(1,1)){$live_transaction=true;break;}usleep(20000);}while(microtime(true)<$until);
  if(!$live_transaction){throw new RuntimeException('nested_fixture_quarantine_transaction_not_observed');}
  $check($snapshot()===$before,'project-approval-native-nested-error-isolated-uncommitted-while-child-quarantined');
  $write('release',$owner);$until=microtime(true)+8;$terminal=null;
  do{$owned();$status=proc_get_status($process);if(!$status['running']){$terminal=$status;break;}usleep(20000);}while(microtime(true)<$until);
  if($terminal===null||$terminal['signaled']||!in_array($terminal['exitcode'],array(73,255),true)){
    throw new RuntimeException('nested_fixture_terminal_refused');}
  proc_close($process);$intent_file=$path.'/terminal-intent.json';$owned();
  if(!is_file($intent_file)||is_link($intent_file)||(fileperms($intent_file)&0777)!==0600||filesize($intent_file)>4096){throw new RuntimeException('nested_fixture_exit_intent_missing');}
  $intent=json_decode(file_get_contents($intent_file),true,8,JSON_THROW_ON_ERROR);$ik=array_keys($intent);sort($ik);
  if($ik!==array('exit_intent','owner','quarantined')||$intent['owner']!==$owner||$intent['exit_intent']!==73||$intent['quarantined']!==true){throw new RuntimeException('nested_fixture_exit_intent_refused');}
  if($terminal['exitcode']===255){
    $err=$path.'/stderr.log';if(!is_file($err)||is_link($err)||(fileperms($err)&0777)!==0600||filesize($err)>1048576){throw new RuntimeException('nested_fixture_destructor_diagnostic_missing');}
    $diagnostic=file_get_contents($err);
    if(!str_contains($diagnostic,'Process exited with an open transaction!')||!str_contains($diagnostic,'AphrontDatabaseTransactionState')){throw new RuntimeException('nested_fixture_destructor_error_unexpected');}
  }
  $until=microtime(true)+3;$absent=false;
  do{if($observe()===array(0,0)){$absent=true;break;}usleep(20000);}while(microtime(true)<$until);
  if(!$absent||$snapshot()!==$before){throw new RuntimeException('nested_fixture_no_commit_proof_failed');}
  $GLOBALS['governance_policy_nested_cleanup_permitted']=true;
  $check(true,'project-approval-actual-native-nested-update-error-quarantines-without-savepoint-guess');
  $check(true,'project-approval-native-nested-error-child-terminal-transaction-gone-and-no-commit');
  // Only the proven terminal child directory is reclaimed. No native state reset.
  $owned();$allowed=array('owner','payload.json','execute','release','ready.json','result.json','terminal-intent.json','stdout.log','stderr.log');
  foreach(scandir($path)as$name){if($name==='.'||$name==='..'){continue;}if(!in_array($name,$allowed,true)||is_link($path.'/'.$name)||
    !is_file($path.'/'.$name)||(fileperms($path.'/'.$name)&0777)!==0600){throw new RuntimeException('nested_fixture_cleanup_owner_refused');}}
  foreach($allowed as$name){if(file_exists($path.'/'.$name)&&!unlink($path.'/'.$name)){throw new RuntimeException('nested_fixture_cleanup_failed');}}
  if(!rmdir($path)){throw new RuntimeException('nested_fixture_cleanup_failed');}
  return array('nested_error_quarantined'=>true,'owned_child_terminal'=>true,'independent_same_server_no_commit'=>true,
    'nested_rollback_ack_claimed'=>false,'nested_case_not_skipped'=>true,
    'queue_snapshot_scope'=>'worker_activetask_only','worker_task_data_not_measured'=>true);
}
