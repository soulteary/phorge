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
          $dao=$is_oauth?new PhabricatorOAuthServerAccessToken():new PhabricatorConduitToken();
          $conn=$dao->establishConnection('w');
          if($conn->isInsideTransaction()||$user_conn->isInsideTransaction()){
            throw new RuntimeException('recovery_api_inherited_transaction_refused');}
          // Fresh baseline per attempt: committed revocation must not poison
          // later rollback cases or the original credential under observation.
          if($is_oauth){$target=$server->generateAccessToken();}
          else{$target=(new PhabricatorConduitToken())->initializeNewToken($user->getPHID(),
            $profile==='api'?PhabricatorConduitToken::TYPE_STANDARD:PhabricatorConduitToken::TYPE_COMMANDLINE)->save();}
          $secret=$target->getToken();
          $check(governance_recovery_api_native_auth($secret,$is_oauth,$user->getPHID())==='allowed',
            'recovery-api-concurrency-'.$profile.'-'.$operation.'-'.$boundary.'-native-auth-baseline');
          $conn->openTransaction();$active=$conn;
          if($operation==='insert'){
            // Ordinary DAO writer, not forced onto the User connection.
            if($is_oauth){$new=$server->generateAccessToken();}
            else{$new=(new PhabricatorConduitToken())->initializeNewToken($user->getPHID(),
              $profile==='api'?PhabricatorConduitToken::TYPE_STANDARD:PhabricatorConduitToken::TYPE_COMMANDLINE)->save();}
            $changed_id=(int)$new->getID();
          }else{$changed_id=(int)$target->getID();$target->delete();}
          $blocker=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
          $handle=governance_native_start_worker($fixture,array('action'=>$is_oauth?
            'recovery.oauth.authenticate':'recovery.api.current','token'=>$secret,'user'=>$user->getPHID()));
          $handles[]=$handle;
          $observation=governance_recovery_api_observe($fixture,$control,$handle,$blocker,
            $is_oauth?'oauth_server':'conduit',$dao->getTableName());
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
          $changed=queryfx_one($conn,'SELECT id FROM %R WHERE id=%d',$dao,$changed_id)!==null;
          $expected_changed=$operation==='insert'?($boundary==='commit'):($boundary!=='commit');
          $check($current_ok&&($fresh==='allowed')===$expected_after&&$changed===$expected_changed&&
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

function governance_recovery_api_observe($fixture,mysqli $observer,array $handle,$blocker,$app,$table) {
  $fixture->assertOwned();
  $worker=$GLOBALS['governance_native_workers'][$handle['index']]??null;
  if(!is_array($worker)||!in_array($worker['action'],array('recovery.api.current','recovery.oauth.authenticate'),true)||
    !in_array($app,array('conduit','oauth_server'),true)||
    !in_array($table,array('conduit_token','oauth_server_oauthserveraccesstoken'),true)){
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
  throw new RuntimeException('recovery_api_observation_timeout');
}
