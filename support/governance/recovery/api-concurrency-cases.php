<?php

/**
 * Experimental, owned native credential concurrency profile.
 * No production query changes and no assumed deadlock outcome.
 */
function governance_recovery_api_concurrency_cases($fixture,callable $check) {
  global $control;
  $fixture->assertOwned();
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){
    throw new RuntimeException('recovery_api_source_revision_required');}
  if(!($control instanceof mysqli)){throw new RuntimeException('recovery_api_observer_required');}
  require_once dirname(__DIR__).'/native-organization/workers.php';
  $suffix=substr($fixture->getNamespace(),-12);
  $user=(new PhabricatorUser())->setUsername('apistress'.$suffix)
    ->setRealName('Synthetic Credential Concurrency Identity')->setIsApproved(1)->save();
  $api=(new PhabricatorConduitToken())->initializeNewToken($user->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
  $cli=(new PhabricatorConduitToken())->initializeNewToken($user->getPHID(),PhabricatorConduitToken::TYPE_COMMANDLINE)->save();
  $client=PhabricatorOAuthServerClient::initializeNewClient($user)->setName('Synthetic concurrency client')
    ->setRedirectURI('https://example.invalid/oauth/return')->save();
  $server=(new PhabricatorOAuthServer())->setUser($user)->setClient($client);
  $scope=ConduitAPIMethod::getConduitMethod('user.whoami')->getRequiredScope();
  $server->authorizeClient(array($scope=>true));
  $oauth=$server->generateAccessToken();
  $user_conn=$user->establishConnection('w');
  // Preinitialize the real epoch in a separate committed native transaction.
  // Otherwise initializer contention would masquerade as a token-read fence.
  foreach(array($api,$cli)as$credential){
    $context=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken(
      $user,new PhutilOpaqueEnvelope($credential->getToken()));
    $user_conn->openTransaction();
    try{PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$user_conn);$user_conn->saveTransaction();}
    catch(Throwable$error){$user_conn->killTransaction();throw$error;}
  }
  $observations=array();$handles=array();$active=null;
  try{
    foreach(array('api','cli','oauth')as$profile){
      foreach(array('insert','revoke')as$operation){
        foreach(array('commit','rollback')as$boundary){
          $fixture->assertOwned();
          $is_oauth=$profile==='oauth';
          $dao=$is_oauth?($operation==='revoke'?new PhabricatorOAuthClientAuthorization():
            new PhabricatorOAuthServerAccessToken()):new PhabricatorConduitToken();
          $conn=$dao->establishConnection('w');
          if($conn->isInsideTransaction()||$user_conn->isInsideTransaction()){
            throw new RuntimeException('recovery_api_inherited_transaction_refused');}
          // Fresh baseline per attempt: committed revocation must not poison
          // later rollback cases or the original credential under observation.
          if($is_oauth){
            list($authorized,$authorization)=$server->userHasAuthorizedClient(array($scope=>true));
            if(!$authorized){$authorization=$server->authorizeClient(array($scope=>true));}
            $target=$server->generateAccessToken();
          }
          else{$target=(new PhabricatorConduitToken())->initializeNewToken($user->getPHID(),
            $profile==='api'?PhabricatorConduitToken::TYPE_STANDARD:PhabricatorConduitToken::TYPE_COMMANDLINE)->save();}
          $secret=$target->getToken();
          $check(governance_recovery_api_native_auth($secret,$is_oauth,$user->getPHID())==='allowed',
            'recovery-api-concurrency-'.$profile.'-'.$operation.'-'.$boundary.'-native-auth-baseline');
          $old_expiry=$is_oauth?null:queryfx_one($conn,'SELECT expires FROM %R WHERE id=%d',$dao,(int)$target->getID())['expires'];
          $conn->openTransaction();$active=$conn;
          if($operation==='insert'){
            // Ordinary DAO writer, not forced onto the User connection.
            if($is_oauth){$new=$server->generateAccessToken();}
            else{$new=(new PhabricatorConduitToken())->initializeNewToken($user->getPHID(),
              $profile==='api'?PhabricatorConduitToken::TYPE_STANDARD:PhabricatorConduitToken::TYPE_COMMANDLINE)->save();}
            $changed_id=(int)$new->getID();
          }else if($is_oauth){
            // Actual OAuth authorization settings revocation leaves access
            // tokens in place and deletes the user/client authorization.
            $changed_id=(int)$authorization->getID();$authorization->delete();
          }else{
            // Match PhabricatorConduitTokenTerminateController exactly.
            $changed_id=(int)$target->getID();
            $target->setExpires(PhabricatorTime::getNow()-60)->save();
          }
          $blocker=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
          $parent_user_thread=(int)queryfx_one($user_conn,'SELECT CONNECTION_ID() n')['n'];
          $parent_user_tracked=$user_conn->isInsideTransaction();
          $handle=governance_native_start_worker($fixture,array('action'=>$is_oauth?
            'recovery.oauth.authenticate':'recovery.api.current','token'=>$secret,'user'=>$user->getPHID()));
          $handles[]=$handle;
          $observation=governance_recovery_api_observe($fixture,$control,$handle,$blocker,
            $is_oauth?'oauth_server':'conduit',$dao->getTableName(),$parent_user_thread,$parent_user_tracked,$conn,$user_conn);
          // Both completed and exact owned blocking are honest observations.
          // Neither means source-family completeness or successful restoration.
          $check(in_array($observation['state'],array('completed-before-release','blocked-by-owned-writer'),true),
            'recovery-api-concurrency-'.$profile.'-'.$operation.'-'.$boundary.'-bounded-native-observation');
          if($boundary==='commit'){$conn->saveTransaction();}else{$conn->killTransaction();}
          $active=null;
          $result=governance_native_finish_worker($handle);
          $allowed=$is_oauth?($result['outcome']==='allowed'):($result['status']==='committed');
          // Nonlocking native OAuth authentication may legitimately finish on
          // the committed old version before writer release. We record that,
          // then demand a fresh native authentication of the committed outcome.
          $expected_after=!($operation==='revoke'&&$boundary==='commit');
          $fresh=governance_recovery_api_native_auth($secret,$is_oauth,$user->getPHID());
          $current_ok=$is_oauth||$allowed===$expected_after;
          if(!$is_oauth&&$operation==='revoke'){
            $stored=queryfx_one($conn,'SELECT id,expires FROM %R WHERE id=%d',$dao,$changed_id);
            $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
            $stored_ok=$stored!==null&&($boundary==='commit'?
              ($stored['expires']!==null&&(int)$stored['expires']<=$now):$stored['expires']===$old_expiry);
          }else{
            $changed=queryfx_one($conn,'SELECT id FROM %R WHERE id=%d',$dao,$changed_id)!==null;
            $stored_ok=$changed===($operation==='insert'?($boundary==='commit'):($boundary!=='commit'));
          }
          $check($current_ok&&($fresh==='allowed')===$expected_after&&$stored_ok&&
            !$conn->isInsideTransaction()&&!$user_conn->isInsideTransaction(),
            'recovery-api-concurrency-'.$profile.'-'.$operation.'-'.$boundary.'-fresh-native-postcondition');
          $observations[]=array('profile'=>$profile,'operation'=>$operation,'boundary'=>$boundary,
            'observation'=>$observation,'reader_allowed'=>$allowed,'fresh_auth_allowed'=>$fresh==='allowed');
        }
      }
    }
  }finally{
    if($active!==null&&$active->isInsideTransaction()){$active->killTransaction();}
    // Top-level fixture owns and reaps all registered exec workers. Never hide
    // an earlier observation/SQL failure by interpreting it as denial.
  }
  return array('profile_version'=>1,'source_revision'=>$revision,'observations'=>$observations,'attempts'=>12,
    'actual_current_reader_profiles'=>array('api','cli'),'oauth_native_authentication_only'=>true,
    'api_token_scan_deadlock_proven'=>false,'production_query_changed'=>false,
    'all_sources_complete'=>false,'can_execute'=>false);
}

function governance_recovery_api_native_auth($secret,$oauth,$expected_user) {
  $api=new ConduitAPIRequest(array(),true);
  $controller=(new PhabricatorConduitAPIController())->setRequest(
    (new AphrontRequest('fixture.invalid','/api/user.whoami'))->setUser(new PhabricatorUser()));
  $metadata=array($oauth?'access_token':'token'=>$secret);
  $error=(new ReflectionMethod(PhabricatorConduitAPIController::class,'authenticateUser'))
    ->invoke($controller,$api,$metadata,'user.whoami');
  if($error===null&&$api->getUser()->getPHID()===$expected_user){return 'allowed';}
  if(is_array($error)&&array_keys($error)===array(0,1)&&$error[0]==='ERR-INVALID-AUTH'){return 'denied';}
  throw new RuntimeException('recovery_api_native_auth_unknown');
}

function governance_recovery_api_observe($fixture,mysqli $observer,array $handle,$blocker,$app,$table,$parent_user_thread,$parent_user_tracked,$producer_conn,$actor_conn) {
  $fixture->assertOwned();
  $worker=$GLOBALS['governance_native_workers'][$handle['index']]??null;
  if(!is_array($worker)||!in_array($worker['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)||
    !in_array($app,array('conduit','oauth_server'),true)||
    !in_array($table,$app==='conduit'?array('conduit_token'):
      array('oauth_server_oauthserveraccesstoken','oauth_server_oauthclientauthorization'),true)){
    throw new RuntimeException('recovery_api_observer_scope_refused');}
  $deadline=hrtime(true)+5000000000;
  do{
    $stat=lstat($worker['private']);$owner=$worker['private'].'/owner';
    if(!$stat||is_link($worker['private'])||$stat['dev']!==$worker['dev']||$stat['ino']!==$worker['ino']||
      ($stat['mode']&0777)!==0700||!is_file($owner)||is_link($owner)||(fileperms($owner)&0777)!==0600||
      filesize($owner)!==64||!hash_equals($worker['nonce'],file_get_contents($owner))){
      throw new RuntimeException('recovery_api_observer_owner_refused');}
    $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
      'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
      'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
      'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
      'WHERE r.PROCESSLIST_ID='.(int)$handle['mysql_thread'].' AND b.PROCESSLIST_ID='.(int)$blocker.
      " AND l.OBJECT_SCHEMA='".$observer->real_escape_string($fixture->getNamespace().'_'.$app).
      "' AND l.OBJECT_NAME='".$observer->real_escape_string($table)."' AND l.LOCK_STATUS='WAITING'";
    $query=$observer->query($sql);if(!$query){throw new RuntimeException('recovery_api_lock_observation_failed');}
    $count=(int)$query->fetch_assoc()['n'];$query->free();
    if($count>0){return array('state'=>'blocked-by-owned-writer','exact_requester_and_blocker'=>true);}
    if(is_file($worker['private'].'/result.json')){
      return array('state'=>'completed-before-release','exact_requester_and_blocker'=>false);}
    if(!is_resource($worker['process'])||!proc_get_status($worker['process'])['running']){
      throw new RuntimeException('recovery_api_reader_exited_without_result');}
    usleep(20000);
  }while(hrtime(true)<$deadline);
  try{$GLOBALS['governance_api_concurrency_child_failure']=governance_recovery_api_wait_diagnostic(
    $fixture,$observer,$worker,$handle,$blocker,$parent_user_thread,$parent_user_tracked,$producer_conn,$actor_conn);}
  catch(Throwable$diagnostic_error){$GLOBALS['governance_api_concurrency_child_failure']=array(
    'diagnostic_unavailable'=>true,'error_type'=>get_class($diagnostic_error));}
  throw new RuntimeException('recovery_api_observation_timeout');
}

/** Actual requester/blocker relations, only on failure; never successful evidence. */
function governance_recovery_api_wait_diagnostic($fixture,mysqli$observer,array$worker,array$handle,
  $blocker,$parent_user_thread,$parent_user_tracked,$producer_conn,$actor_conn) {
  $fixture->assertOwned();governance_native_api_concurrency_owner($handle);
  $out=governance_native_api_startup_diagnostic($worker,$fixture);
  $path=$worker['private'].'/api-phase.json';clearstatcache(true,$path);
  $phase=governance_native_api_startup_phase(json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR),$worker['nonce']);
  $roles=array();foreach(array('proof_thread','writer_thread','reader_thread','credential_thread')as$key){
    if($phase[$key]!==null){$roles[$phase[$key]][]=$key;}}
  if(!$roles||!is_int($blocker)||$blocker<1||!is_int($parent_user_thread)||$parent_user_thread<1||!is_bool($parent_user_tracked)){
    throw new RuntimeException('recovery_api_wait_diagnostic_binding_refused');}
  // Capture only already-owned live connection objects after failure. No new app reader.
  $registry=governance_recovery_api_connection_registry($fixture,$observer,$producer_conn,$actor_conn,$roles);
  $ids=implode(',',array_map('intval',array_keys($roles)));
  $sql='SELECT r.PROCESSLIST_ID requester,b.PROCESSLIST_ID blocker,l.OBJECT_SCHEMA schema_name,'.
    'l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_MODE mode_name,l.LOCK_TYPE type_name '.
    'FROM performance_schema.data_lock_waits w '.
    'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
    'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
    'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
    'WHERE r.PROCESSLIST_ID IN ('.$ids.') LIMIT 17';
  $query=$observer->query($sql);if(!$query){throw new RuntimeException('recovery_api_wait_diagnostic_query_failed');}
  $rows=$query->fetch_all(MYSQLI_ASSOC);$query->free();
  $tables=array();foreach(array('user'=>new PhabricatorUser(),'user-transaction'=>new PhabricatorUserTransaction(),
    'account-epoch'=>new PhorgeGovernanceIdentityEpoch(),'conduit-token'=>new PhabricatorConduitToken(),
    'oauth-token'=>new PhabricatorOAuthServerAccessToken(),'oauth-authorization'=>new PhabricatorOAuthClientAuthorization())as$label=>$dao){
    $tables[$label]=array('schema'=>$fixture->getNamespace().'_'.$dao->getApplicationName(),'table'=>$dao->getTableName());}
  $out['actual_waits']=governance_recovery_api_wait_projection($rows,$roles,$blocker,$parent_user_thread,$fixture->getNamespace(),$tables,$registry);
  $out['connection_registry_roles']=governance_recovery_api_registry_role_names($registry);
  $query=$observer->query('SELECT t.PROCESSLIST_ID requester,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name '.
    'FROM performance_schema.metadata_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.OWNER_THREAD_ID '.
    "WHERE l.LOCK_STATUS='PENDING' AND t.PROCESSLIST_ID IN (".$ids.') LIMIT 9');
  if(!$query){throw new RuntimeException('recovery_api_wait_diagnostic_query_failed');}
  $metadata=$query->fetch_all(MYSQLI_ASSOC);$query->free();
  if(count($metadata)>8){throw new RuntimeException('recovery_api_wait_diagnostic_budget_refused');}
  $out['metadata_waits']=array();foreach($metadata as$row){$id=(int)$row['requester'];
    if(!isset($roles[$id])){throw new RuntimeException('recovery_api_wait_diagnostic_requester_refused');}
    $out['metadata_waits'][]=array('requester_roles'=>$roles[$id],
      'object_class'=>governance_recovery_api_object_class($row,$fixture->getNamespace(),$tables),
      'pending_observed'=>true,'blocking_relation_proven'=>false);}
  $out['parent_actor_tracked_transaction_open']=$parent_user_tracked;
  $query=$observer->query('SELECT COUNT(*) n FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.(int)$parent_user_thread);
  if(!$query){throw new RuntimeException('recovery_api_wait_diagnostic_query_failed');}
  $out['parent_actor_actual_transaction_present']=(int)$query->fetch_assoc()['n']>0;$query->free();
  return$out;
}

function governance_recovery_api_wait_projection(array$rows,array$roles,$blocker,$parent_user_thread,$namespace,array$tables,array$registry=array()) {
  if(count($rows)>16||!is_string($namespace)||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    throw new RuntimeException('recovery_api_wait_diagnostic_budget_refused');}
  $out=array();
  foreach($rows as$row){
    $requester=(int)$row['requester'];if(!isset($roles[$requester])){throw new RuntimeException('recovery_api_wait_diagnostic_requester_refused');}
    $kind=governance_recovery_api_object_class($row,$namespace,$tables);
    $mode=$row['mode_name'];
    $out[]=array('requester_roles'=>$roles[$requester],'requester_matches_observed_thread'=>true,
      'blocker_matches_producer_connection'=>(int)$row['blocker']===$blocker,
      'blocker_matches_parent_actor_connection'=>(int)$row['blocker']===$parent_user_thread,
      'blocker_registry_roles'=>governance_recovery_api_registry_matches($registry,(int)$row['blocker']),
      'object_class'=>$kind,'index_class'=>$row['index_name']===null?'none':($row['index_name']==='PRIMARY'?'primary':'other'),
      'lock_type'=>$row['type_name']==='RECORD'?'record':($row['type_name']==='TABLE'?'table':'other'),
      'lock_mode'=>is_string($mode)&&str_starts_with($mode,'S')?'shared':(is_string($mode)&&str_starts_with($mode,'X')?'exclusive':'other'));
  }
  return$out;
}

function governance_recovery_api_object_class(array$row,$namespace,array$tables) {
  $schema=$row['schema_name'];$kind=is_string($schema)&&str_starts_with($schema,$namespace.'_')?'namespace-other':'foreign-other';
  foreach($tables as$label=>$binding){
    if($schema===$binding['schema']&&$row['table_name']===$binding['table']){return$label;}}
  return$kind;
}

/** Failure-only registry. Raw IDs stay in this owned process, never in receipt. */
function governance_recovery_api_connection_registry($fixture,mysqli$observer,$producer,$actor,array$child_roles) {
  $fixture->assertOwned();
  $setup=$fixture->getAdmin();
  if(!($setup instanceof mysqli)||!($producer instanceof AphrontDatabaseConnection)||
    !($actor instanceof AphrontDatabaseConnection)){
    throw new RuntimeException('recovery_api_registry_connection_refused');}
  $registry=array();
  foreach(array('parent-producer'=>$producer,'parent-actor'=>$actor)as$role=>$conn){
    governance_recovery_api_registry_add($registry,$role,(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n']);}
  governance_recovery_api_registry_add($registry,'parent-control',(int)$observer->thread_id);
  governance_recovery_api_registry_add($registry,'parent-setup',(int)$setup->thread_id);
  // Reflection reads the native cache only; establishConnection('r') could create a new connection.
  $cached=new ReflectionMethod(LiskDAO::class,'getEstablishedConnection');
  foreach(array('conduit'=>new PhabricatorConduitToken(),'user'=>new PhabricatorUser())as$app=>$dao){
    foreach(array('r','w')as$mode){
      $conn=$cached->invoke($dao,$mode);if($conn===null){continue;}
      if(!($conn instanceof AphrontDatabaseConnection)){throw new RuntimeException('recovery_api_registry_connection_refused');}
      governance_recovery_api_registry_add($registry,'parent-cached-'.$app.'-'.$mode,
        (int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n']);
    }
  }
  foreach($child_roles as$id=>$names){foreach($names as$name){
    governance_recovery_api_registry_add($registry,'child-'.$name,(int)$id);}}
  return$registry;
}
function governance_recovery_api_registry_add(array&$registry,$role,$id) {
  $allowed=array('parent-producer','parent-actor','parent-control','parent-setup',
    'parent-cached-conduit-r','parent-cached-conduit-w','parent-cached-user-r','parent-cached-user-w',
    'child-proof_thread','child-writer_thread','child-reader_thread','child-credential_thread');
  if(!is_string($role)||!in_array($role,$allowed,true)||!is_int($id)||$id<1||count($registry)>12){
    throw new RuntimeException('recovery_api_registry_shape_refused');}
  if(isset($registry[$role])&&$registry[$role]!==$id){throw new RuntimeException('recovery_api_registry_identity_changed');}
  $registry[$role]=$id;
}
function governance_recovery_api_registry_matches(array$registry,$id) {
  if(!is_int($id)||$id<1||count($registry)>12){throw new RuntimeException('recovery_api_registry_shape_refused');}
  $checked=array();foreach($registry as$role=>$value){governance_recovery_api_registry_add($checked,$role,$value);}
  $out=array();foreach($checked as$role=>$value){if($value===$id){$out[]=$role;}}
  sort($out,SORT_STRING);return$out;
}
function governance_recovery_api_registry_role_names(array$registry) {
  $checked=array();foreach($registry as$role=>$value){governance_recovery_api_registry_add($checked,$role,$value);}
  $names=array_keys($checked);sort($names,SORT_STRING);return$names;
}
