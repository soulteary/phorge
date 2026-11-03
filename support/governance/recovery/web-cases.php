<?php

/** Actual owned native source fixtures; no revocation/recovery production API. */
function governance_recovery_web_cases($fixture,callable $check) {
  $fixture->assertOwned();$suffix=substr($fixture->getNamespace(),-10);
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  try {
    $new_user=function($name,$admin=false)use($suffix){return(new PhabricatorUser())
      ->setUsername('recweb'.$name.$suffix)->setRealName('Synthetic Recovery Web '.$name)
      ->setIsApproved(1)->setIsAdmin($admin?1:0)->save();};
    $actor=$new_user('admin',true);$subject=$new_user('subject');$other=$new_user('other');
    $sessions=new PhabricatorAuthSessionEngine();$admin=governance_native_request($actor,$sessions);
    $conn=(new PhabricatorUser())->establishConnection('w');$target=$subject->getPHID();
    $conn->openTransaction();
    try{PhorgeGovernanceNativeIdentitySource::lockCurrent($admin['context'],$conn);$conn->saveTransaction();}
    catch(Throwable$error){$conn->killTransaction();throw$error;}
    $capture=function()use($admin,$target){$scope=PhorgeRecoveryNativeCaptureScope::open($admin['context'],$target);
      try{$scope->captureWebCredentials();return$scope->finish();}finally{$scope->close();}};
    $refuse=function($callable,$reason,$label)use($check){$denied=false;
      try{$callable();}catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!==$reason){throw$error;}$denied=true;}
      $check($denied,$label);};
    $load_session=function($token)use($target){return(new PhabricatorAuthSession())->loadOneWhere(
      'userPHID=%s AND sessionKey=%s',$target,
      PhabricatorAuthSession::newSessionDigest(new PhutilOpaqueEnvelope($token)));};
    $full_token=$sessions->establishSession(PhabricatorAuthSession::TYPE_WEB,$target,false);
    $full=$load_session($full_token);
    $partial_token=$sessions->establishSession(PhabricatorAuthSession::TYPE_WEB,$target,true);
    $expired_token=$sessions->establishSession(PhabricatorAuthSession::TYPE_WEB,$target,false);
    $expired=$load_session($expired_token);$expired->setSessionExpires(time()-1)->save();
    $foreign_token=$sessions->establishSession(PhabricatorAuthSession::TYPE_WEB,$other->getPHID(),false);
    $first=$capture();$web=$first['web_credentials'];
    $states=array_count_values(array_column($web['sessions'],'observation'));
    $check(count($web['sessions'])===3&&($states['unexpired-stored-session']??0)===1&&
      ($states['partial-stored-session']??0)===1&&($states['expired']??0)===1&&
      $web['consistency']==='two-matching-current-samples-not-atomic'&&$web['web_family_complete']===false,
      'recovery-web-real-full-partial-expired-and-foreign-session-observations');
    $serialized=json_encode($first,JSON_THROW_ON_ERROR);
    $check(strpos($serialized,$full_token)===false&&strpos($serialized,$partial_token)===false&&
      strpos($serialized,$full->getSessionKey())===false&&strpos($serialized,$foreign_token)===false,
      'recovery-web-native-token-and-session-keys-are-not-returned');
    $before_digest=$web['storage_set_digest'];$sessions->logoutSession($subject,$full);
    $logged_out=$capture();
    $check(count($logged_out['web_credentials']['sessions'])===2&&
      $logged_out['web_credentials']['storage_set_digest']!==$before_digest&&
      (new PhabricatorAuthSession())->loadOneWhere('sessionKey=%s',PhabricatorAuthSession::newSessionDigest(new PhutilOpaqueEnvelope($foreign_token)))!==null,
      'recovery-web-real-single-session-logout-is-observed-without-global-revocation');
    $sessions->establishSession(PhabricatorAuthSession::TYPE_WEB,$target,false);
    $reissued=$capture();
    $check(count($reissued['web_credentials']['sessions'])===3&&
      $reissued['web_credentials']['storage_set_digest']!==$before_digest,
      'recovery-web-real-logout-and-reissue-changes-credential-set-binding');
    // The actual native one-time login producer writes the Auth schema. Its
    // private URI is used only in memory and never included in any receipt.
    $uri=$sessions->getOneTimeLoginURI($subject);
    $token=(new PhabricatorAuthTemporaryToken())->loadOneWhere('tokenResource=%s AND tokenType=%s',
      $target,PhabricatorAuthOneTimeLoginTemporaryTokenType::TOKENTYPE);
    if(!$token){throw new RuntimeException('recovery_fixture_native_token_missing');}
    $with_token=$capture();$temporary=$with_token['web_credentials']['temporary_tokens'];
    $check(count($temporary)===1&&$temporary[0]['kind']==='login:onetime'&&
      $temporary[0]['association']==='direct-subject'&&$temporary[0]['credential_permission']===false&&
      strpos(json_encode($with_token),$token->getTokenCode())===false&&strpos(json_encode($with_token),$uri)===false,
      'recovery-web-real-native-one-time-token-is-captured-without-code-or-uri');
    $token->revokeToken();$revoked=$capture();
    $check($revoked['web_credentials']['temporary_tokens'][0]['observation']==='expired'&&
      $revoked['web_credentials']['storage_set_digest']!==$with_token['web_credentials']['storage_set_digest']&&
      count($revoked['web_credentials']['sessions'])===3,
      'recovery-web-real-single-temporary-token-revocation-is-observed');
    $new_token=function($type,$resource,$owner=null,$properties=array())use($conn){
      return(new PhabricatorAuthTemporaryToken())->setTokenResource($resource)->setTokenType($type)
        ->setTokenCode(hash('sha256',random_bytes(32)))->setTokenExpires(time()+600)
        ->setUserPHID($owner)->setProperties($properties)->save();};
    $new_token('unknown.synthetic',$target);
    $new_token('login:onetime','synthetic-private@example.invalid',$target);
    $new_token('login:onetime',$target,$other->getPHID());
    $ambiguous=$capture();$unknown=$ambiguous['web_credentials']['unknown_reasons'];
    $check(in_array('unknown-token-kind',$unknown,true)&&in_array('ambiguous-resource',$unknown,true)&&
      in_array('foreign-owner-conflict',$unknown,true)&&strpos(json_encode($ambiguous),'synthetic-private@')===false&&
      $ambiguous['all_sources_complete']===false&&$ambiguous['can_execute']===false,
      'recovery-web-unknown-kind-email-resource-and-foreign-owner-remain-partial');
    $baseline=$capture();
    foreach(array(true,false)as$disabled){$current=(new PhabricatorUser())->loadOneWhere('phid=%s',$target);
      (new PhabricatorUserTransactionEditor())->setActor($actor)
        ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
        ->applyTransactions($current,array((new PhabricatorUserTransaction())
          ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled)));}
    $aba=$capture();
    $check($aba['account_facts']['native_transaction_id']!==$baseline['account_facts']['native_transaction_id']&&
      $aba['web_credentials']['storage_set_digest']===$baseline['web_credentials']['storage_set_digest']&&
      $aba['web_credentials']['can_execute']===false,
      'recovery-web-native-account-aba-is-distinct-from-retained-credential-rows');
    $created=array();
    try {
      for($i=0;$i<65;$i++){$created[]=(new PhabricatorAuthSession())->setUserPHID($target)->setType('web')
        ->setSessionKey(hash('sha256',random_bytes(32)))->setSessionStart(time())->setSessionExpires(time()+600)
        ->setIsPartial(0)->setSignedLegalpadDocuments(0)->save();}
      $refuse($capture,'recovery_web_capture_budget_unknown','recovery-web-selected-session-row-budget-refuses-before-payload-fetch');
    }finally{foreach($created as$row){$row->delete();}}
    $large=$new_token('unknown.synthetic',$target,null,array('synthetic'=>base64_encode(random_bytes(6000))));
    try {
      $auth=(new PhabricatorAuthTemporaryToken())->establishConnection('w');
      $size=queryfx_one($auth,'SELECT OCTET_LENGTH(properties) n FROM %R WHERE id=%d',new PhabricatorAuthTemporaryToken(),$large->getID());
      $check((int)$size['n']>4096,'recovery-web-oversized-native-property-fixture-is-really-oversized');
      $refuse($capture,'recovery_web_storage_budget_unknown','recovery-web-temporary-property-byte-budget-refuses-before-payload-fetch');
    }finally{$large->delete();}
    $created=array();
    try {
      for($i=0;$i<34;$i++){$created[]=$new_token('unknown.synthetic',$target,null,
        array('synthetic'=>base64_encode(random_bytes(2300))));}
      $refuse($capture,'recovery_web_capture_budget_unknown','recovery-web-aggregate-selected-property-byte-budget-refuses-before-payload-fetch');
    }finally{foreach($created as$row){$row->delete();}}
    $check(!$conn->isInsideTransaction()&&$capture()['can_execute']===false,
      'recovery-web-budget-refusals-release-owned-scope-without-source-poisoning');
    return array('actual_native_session_producer'=>true,'actual_native_logout'=>true,
      'actual_native_one_time_token_producer'=>true,'actual_native_single_token_revoke'=>true,
      'actual_password_controller_or_oauth_complete'=>false,'web_family_complete'=>false,
      'all_sources_complete'=>false,'can_execute'=>false,'source_writes_by_capture'=>false);
  } finally {
    $_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}
  }
}
