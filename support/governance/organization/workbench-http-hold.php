<?php
require_once __DIR__.'/workbench-http-common.php';
require_once __DIR__.'/approval-workbench-cases.php';
function governance_workbench_http_observe($fixture,array $roles) {
  $fixture->assertOwned();$db=$fixture->getAdmin();$probe=$db->query('SELECT @@autocommit a,@@server_uuid u,CONNECTION_ID() t')->fetch_assoc();governance_workbench_http_need((string)$probe['a']==='1');
  $sessions=array();$users=array();foreach($roles as$name=>$role){
    $stmt=$db->prepare('SELECT COUNT(*) n FROM '.$fixture->table('user','phabricator_session')." WHERE userPHID=? AND type='web'");$phid=$role['phid'];$stmt->bind_param('s',$phid);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();governance_workbench_http_need((int)$row['n']<=4);$sessions[$name]=(int)$row['n'];
    $stmt=$db->prepare('SELECT isAdmin,isApproved,isDisabled FROM '.$fixture->table('user','user').' WHERE phid=?');$stmt->bind_param('s',$phid);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();governance_workbench_http_need(count($rows)===1);$users[$name]=$rows[0];
  }
  $rows=array();foreach(array('approval'=>'approvalID,state,approverPHID,sourceRequesterPHID,sourceExecutorPHID,sourceSubjectPHID,consumedByOperation,version','member'=>'unitID,userPHID,state,version','appointment'=>'unitID,userPHID,role,state,version')as$table=>$columns){
    $result=$db->query('SELECT '.$columns.' FROM '.$fixture->table('user','organization_'.$table).' ORDER BY '.($table==='approval'?'approvalID':'id').' LIMIT 65');$values=$result->fetch_all(MYSQLI_ASSOC);governance_workbench_http_need(count($values)<=64);$rows[$table]=$values;
  }
  return array('fingerprint'=>governance_approval_workbench_fingerprint($fixture),'web_session_counts'=>$sessions,'native_user_flags'=>$users,'organization'=>$rows,'observer_autocommit'=>true,'observer_thread_private'=>(string)$probe['t'],'server_uuid_private'=>$probe['u']);
}
function governance_workbench_http_hold($fixture,$control,callable $check,array $file_service) {
  $fixture->assertOwned();$owner=getenv('PHORGE_GOVERNANCE_WORKBENCH_HTTP_OWNER');$directory=getenv('PHORGE_GOVERNANCE_WORKBENCH_HTTP_DIR');$journal=governance_workbench_http_journal($directory,$owner);
  try {
    $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');$prerequisite=getenv('PHORGE_GOVERNANCE_WORKBENCH_NATIVE26_PROOF_SHA256');
    governance_workbench_http_need(is_string($revision)&&preg_match('/\A[a-f0-9]{40}\z/D',$revision)===1&&is_string($prerequisite)&&preg_match('/\A[a-f0-9]{64}\z/D',$prerequisite)===1);
    $origin=governance_workbench_http_origin(getenv('PHORGE_GOVERNANCE_WORKBENCH_HTTP_PORT'));$seconds=getenv('PHORGE_GOVERNANCE_WORKBENCH_HOLD_SECONDS');$seconds=$seconds===false?'900':$seconds;governance_workbench_http_need(is_string($seconds)&&preg_match('/\A[1-9][0-9]{2,3}\z/D',$seconds)===1&&(int)$seconds>=120&&(int)$seconds<=1800);
    governance_workbench_http_need(PhabricatorEnv::getEnvConfig('governance.organization.enabled')===true);
    foreach(array('governance.media-exchange.enabled','governance.media-exchange.http-enabled','governance.account-recovery.enabled','governance.notifications.enabled')as$key){governance_workbench_http_need(PhabricatorEnv::getEnvConfig($key)===false);}
    $file_config=governance_workbench_http_file_config(array('uri'=>$file_service['uri'],'token'=>$file_service['token'],'binary_sha256'=>$file_service['binary_sha256']));
    governance_workbench_http_need($file_service['kind']==='file'&&PhabricatorEnv::getEnvConfig('gorge.file.uri')===$file_config['uri']&&PhabricatorEnv::getEnvConfig('gorge.file.token')===$file_config['token']);
    $roles=array();foreach(array('requester'=>1,'reviewer'=>1,'ordinary'=>0)as$name=>$admin){
      $user=(new PhabricatorUser())->setUsername('wbhttp'.$name.substr($fixture->getNamespace(),-10))->setRealName('Synthetic workbench '.$name)->setIsApproved(1)->setIsAdmin($admin)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      $password=bin2hex(random_bytes(24)).'!Synthetic';PhabricatorAuthPassword::initializeNewPassword($user,PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)->setPassword(new PhutilOpaqueEnvelope($password),$user)->save();
      $engine=(new PhabricatorAuthPasswordEngine())->setViewer($user)->setObject($user)->setPasswordType(PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT)->setContentSource(PhabricatorContentSource::newForSource('console',array()));
      $check($engine->isValidPassword(new PhutilOpaqueEnvelope($password)),'workbench-http-'.$name.'-actual-native-password-hash');$roles[$name]=array('username'=>$user->getUsername(),'password'=>$password,'phid'=>$user->getPHID(),'native_admin'=>$admin===1);
    }
    $provider=new PhabricatorPasswordAuthProvider();$provider_config=(new PhabricatorAuthProviderConfig())->setProviderClass(get_class($provider))->setProviderType($provider->getProviderType())->setProviderDomain($provider->getProviderDomain())->setIsEnabled(1)->setShouldAllowLogin(1)->setShouldAllowRegistration(0)->save();$provider->attachProviderConfig($provider_config);
    $native=(new PhabricatorUser())->establishConnection('w');$physical=queryfx_one($native,'SELECT @@server_uuid u,CONNECTION_ID() t');$state=governance_workbench_http_observe($fixture,$roles);
    $check($physical['u']===$state['server_uuid_private']&&(string)$physical['t']!==$state['observer_thread_private'],'workbench-http-independent-same-primary-observer');
    $check($state['web_session_counts']===array('requester'=>0,'reviewer'=>0,'ordinary'=>0),'workbench-http-no-preseeded-native-web-sessions');
    $check($state['organization']['approval']===array()&&$state['organization']['member']===array()&&$state['organization']['appointment']===array(),'workbench-http-ui-will-create-original-requests');
    $nonce=(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,'ownerNonce'))->getValue($fixture);$config=array('owner'=>$owner,'fixture_nonce'=>$nonce,'quickstart_sha256'=>hash_file('sha256',dirname(__DIR__,3).'/resources/sql/quickstart.sql'),'source_revision'=>$revision,'native26_prerequisite_sha256'=>$prerequisite,'origin'=>$origin,'writer'=>$fixture->getWriterConfig(),'file_service'=>$file_config);
    $raw=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);governance_workbench_http_config($raw,hash('sha256',$raw),$config['quickstart_sha256']);
    LiskDAO::closeAllConnections();$GLOBALS['governance_workbench_http_cleanup_permitted']=false;
    $writer_closed=$fixture->closeOwnedWriterConnectionForHTTP();
    governance_workbench_http_record($journal,array('phase'=>'owned-fixture-writer-closed','proof'=>$writer_closed));
    $check($writer_closed['owned_fixture_writer_connection_closed']===true&&$writer_closed['exact_original_thread_absent']===true,'workbench-http-owned-independent-writer-closed-before-ready');
    governance_workbench_http_record($journal,array('phase'=>'ready','actual_namespace_private'=>$fixture->getNamespace(),'router_config'=>$config,'router_config_sha256'=>hash('sha256',$raw),'router_config_raw'=>$raw,'origin'=>$origin,'login_uri'=>$origin.$provider->getLoginURI(),'workbench_uri'=>$origin.'/people/organization/approvals/','organization_uri'=>$origin.'/people/organization/','roles'=>$roles,'browser_session_preseeded'=>false,'initial'=>$state,'native26_proof_pointer_only_not_verified_by_keeper'=>true,'HTTP_started_by_keeper'=>false));
    stream_set_blocking(STDIN,false);$deadline=microtime(true)+(int)$seconds;$buffer='';$observations=0;$released=false;
    while(microtime(true)<$deadline){
      $fixture->assertOwned();$part=fread(STDIN,4097);governance_workbench_http_need(is_string($part)&&strlen($buffer)+strlen($part)<=4096);$buffer.=$part;
      if(strpos($buffer,"\n")!==false){$line=substr($buffer,0,strpos($buffer,"\n"));$buffer=substr($buffer,strpos($buffer,"\n")+1);$command=governance_workbench_http_command($line,$owner);
        if($command['action']==='release'){
          $writer=$fixture->getWriterConfig();$stmt=$control->prepare('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?');$name=$writer['user'];$stmt->bind_param('s',$name);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();governance_workbench_http_need((string)$row['n']==='0','workbench_http_writer_sessions_still_live');
          governance_workbench_http_record($journal,array('phase'=>'released','writer_sessions_zero'=>true,'HTTP_process_exit_independently_proved_by_keeper'=>false));$released=true;$GLOBALS['governance_workbench_http_cleanup_permitted']=true;break;
        }
        governance_workbench_http_need(++$observations<=20);governance_workbench_http_record($journal,array('phase'=>$command['phase'],'observed'=>governance_workbench_http_observe($fixture,$roles)));
      }
      if(feof(STDIN)){throw new RuntimeException('workbench_http_parent_channel_closed');}usleep(200000);
    }
    governance_workbench_http_need($released,'workbench_http_owner_lease_expired');$check(true,'workbench-http-private-owner-release-and-no-live-writer-sessions');
    return array('synthetic_roles'=>3,'native_password_provider'=>true,'browser_session_preseeded'=>false,'independent_primary_observer'=>true,'organization_only_isolated_flag'=>true,'parent_release_observed'=>true,'private_namespace_journal'=>true,'HTTP_started_by_keeper'=>false,'browser_executed_by_keeper'=>false,'N09_executed'=>false,'HTTP_process_cleanup_proved_by_keeper'=>false);
  }finally{fclose($journal['stream']);}
}
