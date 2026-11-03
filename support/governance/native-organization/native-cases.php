<?php

/** This fixture factory authenticates through the actual native session engine. */
function governance_native_request(PhabricatorUser $user, PhabricatorAuthSessionEngine $sessions) {
  $token=$sessions->establishSession(PhabricatorAuthSession::TYPE_WEB,$user->getPHID(),false);
  $viewer=$sessions->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$token);
  if (!$viewer || !$viewer->hasSession()) { throw new RuntimeException('native_fixture_session_load_failed'); }
  $_SERVER['REQUEST_METHOD']='POST';
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$token;
  $request=(new AphrontRequest('fixture.invalid','/'))->setUser($viewer);
  $request->setRequestData(array(AphrontRequest::getCSRFTokenName()=>$viewer->getCSRFToken()));
  $request->validateCSRF();
  return array('request'=>$request,'viewer'=>$viewer,'token'=>$token,
    'context'=>PhorgeGovernanceNativeActorContext::newFromWebRequest($request));
}
/** Real transport address from the request; private fixture map is not runtime API. */
function governance_native_prepare_media($media,$secret=null,$path='/file/data/key/PHID-FILE-ssssssssssssssssssss/name') {
  $_SERVER['REMOTE_ADDR']='127.0.0.1';
  $nonce=bin2hex(random_bytes(32));
  $request=(new AphrontRequest('fixture.invalid','/media/session/prepare/'))->setRequestData(array());
  $target=PhorgeGovernanceMediaTarget::fromPath($path,PhabricatorEnv::getEnvConfig('cluster.instance') ?: null);
  $prepared=$media->beginBrowserTransaction($secret,$target,$request,$nonce);
  $GLOBALS['governance_media_nonces'][$prepared['transaction_id']]=$nonce;
  return $prepared;
}
function governance_native_media_nonce($transaction) {
  return $GLOBALS['governance_media_nonces'][$transaction]??str_repeat('0',64);
}
function governance_native_refused(callable $action, $reason, callable $check, $name) {
  $refused=false;
  try { $action(); }
  catch (Throwable $error) { if ($error->getMessage()!==$reason) { throw $error; } $refused=true; }
  $check($refused,$name);
}
function governance_native_setup_query($fixture, $sql) {
  $mode=(new mysqli_driver())->report_mode;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $fixture->assertOwned();
    $result=$fixture->getAdmin()->query($sql);
    if ($result===false) { throw new RuntimeException('native_fixture_setup_query_refused'); }
    return $result;
  } finally { mysqli_report($mode); }
}
function governance_native_media_fingerprint($fixture) {
  $fixture->assertOwned(); $rows=array();
  foreach (array('governance_mediabrowser','governance_mediaexchange','governance_mediacredential','governance_mediaquota') as $table) {
    $data=governance_native_setup_query($fixture,'SELECT * FROM '.$fixture->table('user',$table))->fetch_all(MYSQLI_ASSOC);
    usort($data,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b))); $rows[$table]=$data;
  }
  return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
}

/** Actual native models and credentials; all data and effects are fixture-owned. */
function governance_native_organization_cases($fixture, callable $check) {
  $fixture->assertOwned();
  $namespace=$fixture->getNamespace();
  require_once __DIR__.'/schema-cases.php';
  $schema_summary=governance_native_schema_cases($fixture,$check);
  require_once __DIR__.'/workers.php';

  if ((new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue() !== false ||
      PhabricatorGorgeTaskQueueClient::isConfigured()) {
    throw new RuntimeException('native_fixture_background_delivery_refused');
  }
  $check(true,'native-fixture-background-execution-disabled');
  $users=array();
  foreach (array('admin','member') as $role) {
    $user=(new PhabricatorUser())->setUsername('gn'.$role.substr($namespace,-12))
      ->setRealName('Synthetic Native '.$role)->setIsApproved(1)
      ->setIsAdmin($role==='admin'?1:0)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())
      ->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
    $users[$role]=$user;
  }
  $check(true,'native-people-dao-synthetic-accounts-created');
  $sessions=new PhabricatorAuthSessionEngine();
  $admin=governance_native_request($users['admin'],$sessions);
  $viewer=$admin['viewer']; $request=$admin['request']; $context=$admin['context'];
  $check($viewer instanceof PhabricatorUser && $viewer->hasSession(),
    'native-session-engine-issued-and-loaded-actual-web-session');
  $check($context->getUserPHID()===$viewer->getPHID(),
    'native-cookie-and-csrf-request-created-sealed-actor');
  $service=PhorgeOrganizationService::newForActor($context);
  $service->createUnit(array('operation_id'=>'native-create-dept','unit_id'=>'native-dept',
    'kind'=>'department','name'=>'Synthetic Department','parent_id'=>null));
  $service->createUnit(array('operation_id'=>'native-create-team','unit_id'=>'native-team',
    'kind'=>'team','name'=>'Synthetic Team','parent_id'=>null));
  $check($service->countUnits()===2,'native-organization-unit-create-and-protected-count');
  $service->assignPrimaryDepartment(array('operation_id'=>'native-primary-dept','user_phid'=>$users['member']->getPHID(),
    'unit_id'=>'native-dept','expected_version'=>0));
  $check($fixture->countRows('user','organization_primarydepartment')===1,
    'native-primary-department-real-people-table-commit');
  require_once __DIR__.'/media-issuer-cases.php';
  $issuer_upgrade_summary=governance_native_media_legacy_upgrade_cases($fixture,$check);
  $media=new PhorgeGovernanceMediaExchangeService();
  $prepared=governance_native_prepare_media($media);
  $issued=$media->issue($request,$prepared['transaction_id'],'phorge-files','private-media','/file/data/key/PHID-FILE-ssssssssssssssssssss/name', governance_native_media_nonce($prepared['transaction_id']));
  $redeemed=$media->redeem($prepared['transaction_id'],$issued['exchange_code']->openEnvelope(),
    $prepared['browser_secret']->openEnvelope(),'phorge-files','private-media','/file/data/key/PHID-FILE-ssssssssssssssssssss/name', governance_native_media_nonce($prepared['transaction_id']));
  $snapshot=$media->authenticate($redeemed['credential']->openEnvelope());
  $check($snapshot->getUserPHID()===$viewer->getPHID(),
    'native-media-session-bound-issue-redeem-authenticate');
  $state=array('users'=>$users,'sessions'=>$sessions,'admin'=>$admin,'service'=>$service,
    'media'=>$media,'media_credential'=>$redeemed['credential'],
    'media_browser'=>$prepared['browser_secret']);
  require_once __DIR__.'/media-issuer-cases.php';
  $issuer_summary=governance_native_media_issuer_cases($fixture,$check,$state);
  require_once __DIR__.'/media-target-cases.php';
  $target_summary=governance_native_media_target_cases($fixture,$check,$state);
  require_once __DIR__.'/route-cases.php';
  $route_summary=governance_native_route_cases($fixture,$check,$state);
  require_once __DIR__.'/privacy-cases.php';
  $privacy_summary=governance_native_privacy_cases($fixture,$check,$state);
  require_once __DIR__.'/concurrency-cases.php';
  $concurrency_summary=governance_native_concurrency_cases($fixture,$check,$state);
  require_once __DIR__.'/manager-projection-cases.php';
  require_once __DIR__.'/media-cases.php';
  $media_summary=governance_native_media_cases($fixture,$check,$state);
  require_once __DIR__.'/auth-cases.php';
  $auth_summary=governance_native_auth_cases($fixture,$check,$state);
  require_once __DIR__.'/legacy-role-cases.php';
  $legacy_role_summary=governance_native_legacy_role_cases($fixture,$check,$state);
  require_once __DIR__.'/media-http-cases.php';
  $http_summary=governance_native_media_http_cases($fixture,$check,$state);
  require_once __DIR__.'/directory-completion-cases.php';
  $directory_completion_summary=governance_native_directory_completion_cases($fixture,$check,$state);
  require_once __DIR__.'/membership-lifecycle-cases.php';
  $lifecycle_summary=governance_native_membership_lifecycle_cases($fixture,$check,$state);
  require_once __DIR__.'/compound-cases.php';
  $compound_summary=governance_native_compound_cases($fixture,$check,$state);
  require_once dirname(__DIR__).'/notification/native-cases.php';
  $private_mail_summary=governance_private_mail_native_cases($fixture,$check);
  require_once __DIR__.'/history-cases.php';
  $history_summary=governance_native_history_cases($fixture,$check,$state);
  require_once dirname(__DIR__).'/native-execution/producer-fence-cases.php';
  $producer_summary=governance_project_producer_fence_cases($fixture,$check,$users['admin']);
  require_once dirname(__DIR__).'/native-execution/project-policy-cases.php';
  $execution_summary=governance_project_policy_execution_cases($fixture,$check);
  require_once __DIR__.'/transfer-foundation-cases.php';
  $transfer_summary=governance_native_transfer_foundation_cases($fixture,$check,$state);
  require_once __DIR__.'/transfer-execution-cases.php';
  $transfer_execution_summary=governance_native_transfer_execution_cases($fixture,$check,$state);
  require_once __DIR__.'/handover-lifecycle-cases.php';
  $handover_summary=governance_native_handover_lifecycle_cases($fixture,$check,$state);
  require_once __DIR__.'/handover-form-cases.php';
  $handover_form_summary=governance_native_handover_form_cases($fixture,$check,$state);
  require_once __DIR__.'/noncredential-source-v2-cases.php';
  $source_v2_summary=governance_native_noncredential_source_v2_cases($fixture,$check,$state);
  require_once __DIR__.'/revocation-measurement-cases.php';
  $measurement_summary=governance_native_revocation_measurement_cases($fixture,$check,$state);
  require_once __DIR__.'/revocation-api-token-cases.php';
  $api_measurement_summary=governance_native_revocation_api_token_cases($fixture,$check,$state);
  return array('private_mail_tests'=>$private_mail_summary,'api_token_revocation_measurement_tests'=>$api_measurement_summary,'revocation_measurement_tests'=>$measurement_summary,'http_tests'=>$http_summary,'noncredential_source_v2_tests'=>$source_v2_summary,'handover_form_tests'=>$handover_form_summary,'handover_lifecycle_tests'=>$handover_summary,'transfer_execution_tests'=>$transfer_execution_summary,'transfer_foundation_tests'=>$transfer_summary,'history_tests'=>$history_summary,'compound_tests'=>$compound_summary,'membership_lifecycle_tests'=>$lifecycle_summary,'directory_completion_tests'=>$directory_completion_summary,'producer_fence_tests'=>$producer_summary,'project_execution_tests'=>$execution_summary,'actual_native_account_source'=>true,'actual_session_engine'=>true,

    'actual_csrf_validation'=>true,'isolated_features_enabled'=>true,
    'production_features_enabled'=>false,'media_backend_only'=>true,
    'daemon_or_external_delivery_executed'=>false,'issuer_upgrade_tests'=>$issuer_upgrade_summary,'target_tests'=>$target_summary,'issuer_tests'=>$issuer_summary,'schema_tests'=>$schema_summary,'route_tests'=>$route_summary,'privacy_tests'=>$privacy_summary,'concurrency_tests'=>$concurrency_summary,
    'media_tests'=>$media_summary,'auth_tests'=>$auth_summary,'legacy_role_tests'=>$legacy_role_summary,
    'native_approval_implemented'=>$auth_summary['native_durable_two_person_approval']);
}
