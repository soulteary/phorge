<?php

/** Actual producers are invoked only inside the already-owned disposable fixture. */
function governance_recovery_api_cases($fixture,callable $check) {
  $fixture->assertOwned();$environment=governance_recovery_account_fixture_environment($fixture);
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  try {
    $suffix=substr($fixture->getNamespace(),-10);
    $actor=(new PhabricatorUser())->setUsername('recapiadmin'.$suffix)->setRealName('Synthetic API capture admin')->setIsAdmin(1)->setIsApproved(1)->setIsEmailVerified(1)->save();
    $subject=(new PhabricatorUser())->setUsername('recapisubject'.$suffix)->setRealName('Synthetic API capture subject')->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($subject->getPHID())->setAddress('recapi'.$suffix.'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
    $sessions=new PhabricatorAuthSessionEngine();$actual=governance_native_request($actor,$sessions);
    $conn=(new PhabricatorUser())->establishConnection('w');
    $conn->openTransaction();try{PhorgeGovernanceNativeIdentitySource::lockCurrent($actual['context'],$conn);$conn->saveTransaction();}
    catch(Throwable$error){$conn->killTransaction();throw$error;}
    $capture=function()use($actual,$subject){$scope=PhorgeRecoveryNativeCaptureScope::open($actual['context'],$subject->getPHID());
      try{$scope->captureAPICredentials();return$scope->finish();}finally{$scope->close();}};
    $token=PhabricatorConduitToken::initializeNewToken($subject->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
    $authenticate=function($metadata)use($subject){
      $api=new ConduitAPIRequest(array(),true);$controller=(new PhabricatorConduitAPIController())->setRequest(
        (new AphrontRequest('fixture.invalid','/api/user.whoami'))->setUser(new PhabricatorUser()));
      $error=(new ReflectionMethod(PhabricatorConduitAPIController::class,'authenticateUser'))->invoke($controller,$api,$metadata,'user.whoami');
      return array($error,$error===null&&$api->getUser()->getPHID()===$subject->getPHID());};
    $before=$capture();$login=$authenticate(array('token'=>$token->getToken()));
    $check($login[1]&&count($before['api_credentials']['api_credentials']['conduit'])===1,
      'recovery-api-real-standard-token-authentication-and-storage-observation');
    $token->delete();$login=$authenticate(array('token'=>$token->getToken()));$deleted=$capture();
    $check(is_array($login[0])&&$login[0][0]==='ERR-INVALID-AUTH'&&
      count($deleted['api_credentials']['api_credentials']['conduit'])===0&&
      $deleted['api_credentials']['storage_set_digest']!==$before['api_credentials']['storage_set_digest'],
      'recovery-api-real-owned-token-revocation-changes-capture-and-native-authentication');
    $subject->setConduitCertificate(bin2hex(random_bytes(32)))->save();
    $nonce=bin2hex(random_bytes(16));$signature=sha1($nonce.$subject->getConduitCertificate());
    $certificate_login=$authenticate(array('authUser'=>$subject->getUsername(),'authToken'=>$nonce,'authSignature'=>$signature));
    $certificate_before=$capture();$subject->setConduitCertificate(bin2hex(random_bytes(32)))->save();
    $certificate_after=$capture();$old_certificate_login=$authenticate(array('authUser'=>$subject->getUsername(),'authToken'=>$nonce,'authSignature'=>$signature));
    $check($certificate_login[1]&&is_array($old_certificate_login[0])&&$old_certificate_login[0][0]==='ERR-INVALID-AUTH'&&
      $certificate_before['account_facts']['security_fields_digest']!==$certificate_after['account_facts']['security_fields_digest'],
      'recovery-api-real-legacy-certificate-authentication-and-rotation-bound-to-account');
    $legacy=$sessions->establishSession(PhabricatorAuthSession::TYPE_CONDUIT,$subject->getPHID(),false);
    $login=$authenticate(array('sessionKey'=>$legacy));$legacy_capture=$capture();
    $check($login[1]&&count($legacy_capture['api_credentials']['api_credentials']['legacy_sessions'])===1,
      'recovery-api-real-legacy-conduit-session-authentication-is-distinct-from-web');
    $legacy_row=(new PhabricatorAuthSession())->loadOneWhere('userPHID=%s AND sessionKey=%s',$subject->getPHID(),
      PhabricatorAuthSession::newSessionDigest(new PhutilOpaqueEnvelope($legacy)));
    $sessions->logoutSession($subject,$legacy_row);$legacy_rejected=$authenticate(array('sessionKey'=>$legacy));
    $legacy_after=$capture();
    $check(is_array($legacy_rejected[0])&&$legacy_rejected[0][0]==='ERR-INVALID-SESSION'&&
      count($legacy_after['api_credentials']['api_credentials']['legacy_sessions'])===0,
      'recovery-api-real-single-legacy-session-logout-is-observed');
    $mixed_sessions=array();
    $load_session=function($raw)use($subject){
      $row=(new PhabricatorAuthSession())->loadOneWhere('userPHID=%s AND sessionKey=%s',
        $subject->getPHID(),PhabricatorAuthSession::newSessionDigest(new PhutilOpaqueEnvelope($raw)));
      if(!$row){throw new RuntimeException('recovery_fixture_native_session_missing');}return$row;
    };
    try {
      for($i=0;$i<65;$i++){
        $mixed_sessions[]=$load_session($sessions->establishSession(PhabricatorAuthSession::TYPE_WEB,$subject->getPHID(),false));
      }
      $web_only=$capture();$web_refused=false;
      $web_scope=PhorgeRecoveryNativeCaptureScope::open($actual['context'],$subject->getPHID());
      try{$web_scope->captureWebCredentials();}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_web_capture_budget_unknown'){throw$error;}
        $web_refused=true;
      }finally{$web_scope->close();}
      $check(count($mixed_sessions)===65&&$web_refused&&
        count($web_only['api_credentials']['api_credentials']['legacy_sessions'])===0&&!$conn->isInsideTransaction(),
        'recovery-api-unrelated-web-session-budget-does-not-consume-api-budget');
      $mixed_sessions[]=$load_session($sessions->establishSession(PhabricatorAuthSession::TYPE_CONDUIT,$subject->getPHID(),false));
      $mixed=$capture();
      $check(count($mixed['api_credentials']['api_credentials']['legacy_sessions'])===1&&
        $mixed['can_execute']===false&&!$conn->isInsideTransaction(),
        'recovery-api-mixed-session-capture-selects-only-native-conduit-type');
      for($i=0;$i<64;$i++){
        $mixed_sessions[]=$load_session($sessions->establishSession(PhabricatorAuthSession::TYPE_CONDUIT,$subject->getPHID(),false));
      }
      $selected_refused=false;try{$capture();}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_api_capture_budget_unknown'){throw$error;}
        $selected_refused=true;
      }
      $check($selected_refused&&!$conn->isInsideTransaction(),
        'recovery-api-selected-legacy-session-row-budget-remains-enforced');
    }finally{foreach($mixed_sessions as$row){$sessions->logoutSession($subject,$row);}}
    $client=PhabricatorOAuthServerClient::initializeNewClient($subject)->setName('Synthetic Recovery OAuth')->setRedirectURI('https://example.invalid/return')->save();
    $server=(new PhabricatorOAuthServer())->setUser($subject)->setClient($client);
    $authorization=$server->authorizeClient(array());$access=$server->generateAccessToken();
    $code=$server->generateAuthorizationCode(new PhutilURI('https://example.invalid/return'));
    $oauth=$capture();
    $check($server->authorizeToken($access)!==null&&count($oauth['api_credentials']['api_credentials']['oauth_tokens'])===1&&
      count($oauth['api_credentials']['api_credentials']['oauth_codes'])===1&&
      count($oauth['api_credentials']['api_credentials']['oauth_authorizations'])===1&&
      count($oauth['api_credentials']['api_credentials']['oauth_clients'])===1,
      'recovery-api-real-oauth-server-producers-and-associated-client-rows-captured');
    $authorization->delete();$revoked=$capture();
    $check($server->authorizeToken($access)===null&&
      count($revoked['api_credentials']['api_credentials']['oauth_tokens'])===1&&
      count($revoked['api_credentials']['api_credentials']['oauth_authorizations'])===0,
      'recovery-api-real-oauth-authorization-revocation-does-not-erase-retained-token');
    $expired=PhabricatorConduitToken::initializeNewToken($subject->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)
      ->setExpires(time()-1)->save();
    $expired_login=$authenticate(array('token'=>$expired->getToken()));$expired_capture=$capture();
    $check(is_array($expired_login[0])&&$expired_login[0][0]==='ERR-INVALID-AUTH'&&
      $expired_capture['api_credentials']['api_credentials']['conduit'][0]['observation']==='expired',
      'recovery-api-real-expired-standard-token-storage-is-not-native-authority');
    $expired->delete();
    $created=array();try{
      for($i=0;$i<65;$i++){$created[]=PhabricatorConduitToken::initializeNewToken($subject->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();}
      $refused=false;try{$capture();}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_api_capture_budget_unknown'){throw$error;}$refused=true;}
      $check($refused&&!$conn->isInsideTransaction(),'recovery-api-selected-token-row-budget-closes-own-scope-before-payload-fetch');
    }finally{foreach($created as$row){$row->delete();}}
    // Real native OAuth rows cross the former Web-only 64KiB ceiling.
    // Each individual scope remains below the fixed 4096-byte row bound.
    $budget_clients=array();$budget_authorizations=array();
    try {
      for($i=0;$i<40;$i++){
        $budget_client=PhabricatorOAuthServerClient::initializeNewClient($subject)
          ->setName('Synthetic aggregate budget')->setRedirectURI('https://example.invalid/return')->save();
        $budget_clients[]=$budget_client;
        $budget_authorizations[]=(new PhabricatorOAuthServer())->setUser($subject)->setClient($budget_client)
          ->authorizeClient(array(str_repeat('s',3500)=>true));
        if($i===19){
          $stored_bytes=(int)queryfx_one($conn,'SELECT SUM(OCTET_LENGTH(scope)) n FROM %R WHERE userPHID=%s',new PhabricatorOAuthClientAuthorization(),$subject->getPHID())['n'];
          $large_capture=$capture();
          $check($stored_bytes>PhorgeRecoveryNativeWebFacts::MAX_BYTES&&$stored_bytes<PhorgeRecoveryNativeAPIFacts::MAX_BYTES&&count($large_capture['api_credentials']['api_credentials']['oauth_authorizations'])===20&&
            $large_capture['can_execute']===false&&!$conn->isInsideTransaction(),
            'recovery-api-aggregate-above-web-budget-below-api-budget-is-observed');
        }
      }
      $aggregate_refused=false;try{$capture();}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_api_capture_budget_unknown'){throw$error;}
        $aggregate_refused=true;
      }
      $stored_bytes=(int)queryfx_one($conn,'SELECT SUM(OCTET_LENGTH(scope)) n FROM %R WHERE userPHID=%s',new PhabricatorOAuthClientAuthorization(),$subject->getPHID())['n'];
      $check($stored_bytes>PhorgeRecoveryNativeAPIFacts::MAX_BYTES&&$aggregate_refused&&!$conn->isInsideTransaction(),
        'recovery-api-aggregate-above-api-budget-refuses-and-closes-owned-scope');
    }finally{
      foreach($budget_authorizations as$row){$row->delete();}
      foreach($budget_clients as$row){$row->delete();}
    }
    $encoded=json_encode($revoked,JSON_THROW_ON_ERROR);
    $check(strpos($encoded,$access->getToken())===false&&strpos($encoded,$client->getSecret())===false&&
      strpos($encoded,$code->getCode())===false&&strpos($encoded,$legacy)===false&&
      $revoked['can_execute']===false&&$revoked['all_sources_complete']===false&&!$conn->isInsideTransaction(),
      'recovery-api-no-raw-secrets-recovery-permit-or-open-owned-transaction');
    return array('actual_standard_token_authentication'=>true,'actual_single_token_revocation'=>true,
      'actual_legacy_certificate_authentication'=>true,'actual_legacy_session_authentication'=>true,'actual_oauth_server_producers'=>true,
      'api_family_complete'=>false,'all_sources_complete'=>false,'can_execute'=>false,'source_writes_by_capture'=>false);
  } finally {
    try{governance_recovery_account_restore_fixture_environment($environment);}finally{
      $_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}}
  }
}
