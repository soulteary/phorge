<?php

/** Real native authentication, durable approval and current-source negatives. */
function governance_native_auth_cases($fixture, callable $check, array $state) {
  $fixture->assertOwned();
  $conn=(new PhabricatorUser())->establishConnection('w');
  $users=$state['users']; $sessions=$state['sessions'];
  $first=$state['admin'];
  $service=PhorgeOrganizationService::newForActor($first['context']);
  $reject=function($callable,$name) use ($check) {
    $rejected=false;
    try { $callable(); } catch (Throwable $error) {
      if (get_class($error)!==RuntimeException::class ||
          !preg_match('/\A(?:organization|governance|media)_[a-z_]+\z/',$error->getMessage())) {
        throw $error;
      }
      $rejected=true;
    }
    $check($rejected,$name);
  };
  $new_user=function($role,$admin=false) use ($fixture) {
    return (new PhabricatorUser())->setUsername('ga'.$role.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Auth '.$role)->setIsApproved(1)->setIsAdmin($admin?1:0)
      ->setIsEmailVerified(1)->save();
  };
  $second_user=$new_user('second',true);
  $second=governance_native_request($second_user,$sessions);
  $second_service=PhorgeOrganizationService::newForActor($second['context']);
  $subject=$users['member']->getPHID();
  $team='native-auth-team';
  $service->createUnit(array('operation_id'=>'auth-create-team','unit_id'=>$team,
    'kind'=>'team','name'=>'Synthetic Auth Team','parent_id'=>null));
  $mutation=function($action,$target,$from,$until,$approval=null) use ($conn,$team) {
    $unit=queryfx_one($conn,'SELECT version FROM organization_unit WHERE unitID=%s',$team);
    $member=queryfx_one($conn,'SELECT version FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$target);
    $appointment=queryfx_one($conn,'SELECT version FROM organization_appointment WHERE unitID=%s AND userPHID=%s',$team,$target);
    return array('action'=>$action,'team'=>$team,'target_user'=>$target,
      'valid_from'=>$from,'valid_until'=>$until,'approval_id'=>$approval,
      'expected_team_version'=>(int)$unit['version'],
      'expected_member_version'=>$member?(int)$member['version']:0,
      'expected_appointment_version'=>$appointment?(int)$appointment['version']:0);
  };
  $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];
  $service->execute(array('operation_id'=>'auth-add-member','mutations'=>array(
    $mutation('member.add',$subject,$now-60,null))));
  $proposal=array('approval_id'=>'native-auth-approval','operation_id'=>'auth-grant-admin',
    'team'=>$team,'action'=>'appointment.grant','target_user'=>$subject,
    'valid_from'=>$now-10,'valid_until'=>null,'ttl_seconds'=>120);
  $submitted=$service->proposeApproval($proposal);
  $check($submitted['state']==='submitted','native-approval-real-request-submitted');
  $reject(function() use ($service) { $service->approveApproval('native-auth-approval'); },
    'native-approval-requester-cannot-self-approve');
  $approved=$second_service->approveApproval('native-auth-approval');
  $check($approved['state']==='approved','native-approval-independent-current-admin-approved');
  $approved_again=$second_service->approveApproval('native-auth-approval');
  $check($approved_again['replayed']===true,'native-approval-same-approver-idempotent-no-new-approval');
  $grant=$mutation('appointment.grant',$subject,$now-10,null,'native-auth-approval');
  $reject(function() use ($service,$grant) {
    $service->execute(array('operation_id'=>'auth-wrong-operation','mutations'=>array($grant)));
  },'native-approval-cannot-authorize-another-operation');
  $ticket=queryfx_one($conn,'SELECT state FROM organization_approval WHERE approvalID=%s','native-auth-approval');
  $check($ticket['state']==='approved','native-approval-wrong-operation-keeps-approved-ticket');
  $applied=$service->execute(array('operation_id'=>'auth-grant-admin','mutations'=>array($grant)));
  $ticket=queryfx_one($conn,'SELECT state,consumedByOperation FROM organization_approval WHERE approvalID=%s','native-auth-approval');
  $appointment=queryfx_one($conn,'SELECT state,role FROM organization_appointment WHERE unitID=%s AND userPHID=%s',$team,$subject);
  $check($ticket['state']==='consumed' && $ticket['consumedByOperation']==='auth-grant-admin' &&
    $appointment['state']==='active' && $appointment['role']==='team_admin',
    'native-approval-consume-and-appointment-commit-together');
  governance_native_manager_projection_cases($fixture,$check,$subject,$team,$sessions);
  $replay=$service->execute(array('operation_id'=>'auth-grant-admin','mutations'=>array($grant)));
  $check($replay['replayed']===true,'native-approved-business-operation-replays-with-current-authority');
  $reject(function() use ($service,$proposal) {
    $copy=$proposal; $copy['operation_id']='auth-rebind-consumed'; $service->proposeApproval($copy);
  },'native-consumed-approval-cannot-be-rebound');

  // A durable approval must retain its approver's concrete live credential.
  $revoke=$proposal; $revoke['approval_id']='auth-revoke-logout';
  $revoke['operation_id']='auth-revoke-logout-op'; $revoke['action']='appointment.revoke';
  $revoke['valid_from']=null; $revoke['valid_until']=null;
  $service->proposeApproval($revoke); $second_service->approveApproval($revoke['approval_id']);
  $sessions->logoutSession($second['viewer'],$second['viewer']->getSession());
  $revoke_mutation=$mutation('appointment.revoke',$subject,null,null,$revoke['approval_id']);
  $reject(function() use ($service,$revoke,$revoke_mutation) {
    $service->execute(array('operation_id'=>$revoke['operation_id'],'mutations'=>array($revoke_mutation)));
  },'native-approved-ticket-invalidated-by-approver-actual-logout');
  $ticket=queryfx_one($conn,'SELECT state FROM organization_approval WHERE approvalID=%s',$revoke['approval_id']);
  $appointment=queryfx_one($conn,'SELECT state FROM organization_appointment WHERE unitID=%s AND userPHID=%s',$team,$subject);
  $check($ticket['state']==='approved' && $appointment['state']==='active',
    'native-approver-logout-rejection-preserves-ticket-and-appointment');
  $second=governance_native_request($second_user,$sessions);
  $second_service=PhorgeOrganizationService::newForActor($second['context']);
  $revoke['approval_id']='auth-revoke-stale'; $revoke['operation_id']='auth-revoke-stale-op';
  $service->proposeApproval($revoke); $second_service->approveApproval($revoke['approval_id']);
  $service->execute(array('operation_id'=>'auth-touch-membership','mutations'=>array(
    $mutation('member.update',$subject,$now-60,null))));
  $revoke_mutation=$mutation('appointment.revoke',$subject,null,null,$revoke['approval_id']);
  $reject(function() use ($service,$revoke,$revoke_mutation) {
    $service->execute(array('operation_id'=>$revoke['operation_id'],'mutations'=>array($revoke_mutation)));
  },'native-approval-detects-membership-version-and-team-source-change');
  $revoke['approval_id']='auth-revoke-expired'; $revoke['operation_id']='auth-revoke-expired-op';
  $revoke['ttl_seconds']=2;
  $service->proposeApproval($revoke); $second_service->approveApproval($revoke['approval_id']);
  $ticket=queryfx_one($conn,'SELECT bindingJSON FROM organization_approval WHERE approvalID=%s',$revoke['approval_id']);
  $expires=json_decode($ticket['bindingJSON'],true)['expiresAt'];
  $deadline=microtime(true)+5;
  while ((int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n']<$expires) {
    if (microtime(true)>$deadline) { throw new RuntimeException('native_auth_expiry_wait_timeout'); }
    usleep(50000);
  }
  $revoke_mutation=$mutation('appointment.revoke',$subject,null,null,$revoke['approval_id']);
  $reject(function() use ($service,$revoke,$revoke_mutation) {
    $service->execute(array('operation_id'=>$revoke['operation_id'],'mutations'=>array($revoke_mutation)));
  },'native-approval-real-database-clock-expiry-rejected');

  // Exercise the actual private native authentication branch with a real token,
  // not ConduitAPIRequest::setUser as an authentication substitute.
  $api_token=PhabricatorConduitToken::initializeNewToken($users['admin']->getPHID(),
    PhabricatorConduitToken::TYPE_STANDARD)->save();
  $api_request=new ConduitAPIRequest(array(),true);
  $http=(new AphrontRequest('fixture.invalid','/api/user.whoami'))->setUser(new PhabricatorUser());
  $controller=(new PhabricatorConduitAPIController())->setRequest($http);
  $authenticate=new ReflectionMethod(PhabricatorConduitAPIController::class,'authenticateUser');
  $auth_error=$authenticate->invoke($controller,$api_request,array('token'=>$api_token->getToken()),'user.whoami');
  $check($auth_error===null && $api_request->getGovernanceActorContext()->getCredentialType()==='api-token',
    'native-conduit-authentication-branch-attaches-real-api-context');
  $api_service=PhorgeOrganizationService::newForActor($api_request->getGovernanceActorContext());
  $check($api_service->countUnits()>0,'native-api-token-locks-real-token-owner-and-primary');
  $bad_request=new ConduitAPIRequest(array(),true);
  $bad=$authenticate->invoke($controller,$bad_request,array('token'=>'api-'.str_repeat('x',28)),'user.whoami');
  $check(is_array($bad),'native-conduit-rejects-nonexistent-api-token');
  $reject(function() use ($bad_request) { $bad_request->getGovernanceActorContext(); },
    'native-rejected-token-does-not-create-governance-context');
  $api_token->setExpires($now-1)->save();
  $reject(function() use ($api_service) { $api_service->countUnits(); },
    'native-current-api-token-expiry-invalidates-prior-context');
  $api_token->delete();
  $reject(function() use ($api_service) { $api_service->countUnits(); },
    'native-current-api-token-deletion-invalidates-prior-context');

  // No credential means no first-issuer metadata may remain after failure.
  $unissued=$new_user('unissued');
  $fake=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($unissued,
    new PhutilOpaqueEnvelope('api-'.str_repeat('z',28)));
  $fake_service=PhorgeOrganizationService::newForActor($fake);
  $reject(function() use ($fake_service) { $fake_service->countUnits(); },
    'native-invented-credential-cannot-initialize-actor');
  $epoch=queryfx_one($conn,'SELECT epoch FROM organization_identityepoch WHERE userPHID=%s',$unissued->getPHID());
  $check($epoch===null,'native-invalid-credential-leaves-no-initialization-metadata');

  // Native user transactions must invalidate a preexisting full web context
  // through disable -> enable even without any source read in between.
  $fresh=governance_native_request($second_user,$sessions);
  $fresh_service=PhorgeOrganizationService::newForActor($fresh['context']);
  $fresh_service->countUnits();
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$fresh['token'];
  $media=$state['media']; $browser=governance_native_prepare_media($media);
  $issued=$media->issue($fresh['request'],$browser['transaction_id'],'phorge-files','private-media','/file/data/key/PHID-FILE-ssssssssssssssssssss/name', governance_native_media_nonce($browser['transaction_id']));
  $media_binding=$media->redeem($browser['transaction_id'],$issued['exchange_code']->openEnvelope(),
    $browser['browser_secret']->openEnvelope(),'phorge-files','private-media','/file/data/key/PHID-FILE-ssssssssssssssssssss/name', governance_native_media_nonce($browser['transaction_id']));
  $media->authenticate($media_binding['credential']->openEnvelope());
  $conn->openTransaction();
  try {
    $old_binding=PhorgeGovernanceNativeIdentitySource::lockCurrent($fresh['context'],$conn)
      ->getSource()->getSnapshotData();
    $conn->saveTransaction();
  } catch (Throwable $error) { $conn->killTransaction(); throw $error; }
  foreach (array(true,false) as $disabled) {
    $target=(new PhabricatorUser())->loadOneWhere('phid=%s',$second_user->getPHID());
    $transaction=(new PhabricatorUserTransaction())
      ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled);
    (new PhabricatorUserTransactionEditor())->setActor($users['admin'])
      ->setContentSource(PhabricatorContentSource::newForSource('console', array()))
      ->applyTransactions($target,array($transaction));
  }
  $reject(function() use ($old_binding,$conn) {
    $conn->openTransaction();
    try {
      PhorgeGovernanceNativeIdentitySource::lockCredentialBinding($old_binding,$conn);
      $conn->saveTransaction();
    } catch (Throwable $error) { $conn->killTransaction(); throw $error; }
  },'native-disable-enable-without-intermediate-read-does-not-revive-issued-binding');
  $reject(function() use ($media,$media_binding) {
    $media->authenticate($media_binding['credential']->openEnvelope());
  },'native-actual-media-credential-does-not-revive-after-account-disable-enable');
  $new_second=governance_native_request($second_user,$sessions);
  $check(PhorgeOrganizationService::newForActor($new_second['context'])->countUnits()>0,
    'native-fresh-session-after-enable-remains-usable');

  $logout=governance_native_request($users['admin'],$sessions);
  $logout_service=PhorgeOrganizationService::newForActor($logout['context']);
  $logout_service->countUnits();
  $sessions->logoutSession($logout['viewer'],$logout['viewer']->getSession());
  $reject(function() use ($logout_service) { $logout_service->countUnits(); },
    'native-current-web-session-deletion-invalidates-prior-context');
  $check(!$conn->isInsideTransaction(),'native-auth-negative-paths-release-owned-transactions');
  return array('native_api_authentication_branch_exercised'=>true,
    'native_durable_two_person_approval'=>true,'native_web_and_api_revocation_checked'=>true,
    'daemon_or_external_delivery_executed'=>false);
}
