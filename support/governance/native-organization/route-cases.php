<?php

/** Fixture-only: preserve owned-DB checks and add the actual web CSRF guard. */
function governance_native_with_request_write_guard(AphrontRequest $request, callable $action) {
  $guard=(new ReflectionProperty(AphrontWriteGuard::class,'instance'))->getValue();
  if (!($guard instanceof AphrontWriteGuard)) {
    throw new RuntimeException('native_route_active_write_guard_required');
  }
  $property=new ReflectionProperty(AphrontWriteGuard::class,'callback');
  $callback=$property->getValue($guard);
  $property->setValue($guard,static function() use ($callback,$request) {
    call_user_func($callback);
    $request->validateCSRF();
  });
  try { return $action(); }
  finally { $property->setValue($guard,$callback); }
}
function governance_native_route_guard_depth() {
  $guard=(new ReflectionProperty(AphrontWriteGuard::class,'instance'))->getValue();
  return (new ReflectionProperty(AphrontWriteGuard::class,'allowDepth'))->getValue($guard);
}
function governance_native_route_epoch_count($fixture,$phid) {
  $fixture->assertOwned();$mode=(new mysqli_driver())->report_mode;$statement=null;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $statement=$fixture->getAdmin()->prepare('SELECT COUNT(*) n FROM '.
      $fixture->table('user','organization_identityepoch').' WHERE BINARY userPHID=BINARY ?');
    $statement->execute(array($phid));return (int)$statement->get_result()->fetch_assoc()['n'];
  } finally { if($statement){$statement->close();}mysqli_report($mode); }
}

/** Calls real native controllers; this is not an HTTP server/browser receipt. */
function governance_native_route_response(array $session, $method, array $data) {
  $_SERVER['REQUEST_METHOD']=$method;
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];
  $request=(new AphrontRequest('fixture.invalid','/people/organization/'))
    ->setUser($session['viewer'])->setRequestData($data);
  $controller=(new PhorgeOrganizationController())->setRequest($request)
    ->setCurrentApplication(new PhabricatorPeopleApplication());
  $response=governance_native_with_request_write_guard($request,
    static fn()=>$controller->handleRequest($request));
  $body=null;
  if ($response instanceof AphrontJSONResponse) {
    $body=phutil_json_decode($response->setAddJSONShield(false)->buildResponseString());
  }
  return array($response,$body);
}

function governance_native_route_cases($fixture, callable $check, array $state) {
  $fixture->assertOwned();
  $admin=$state['admin'];
  $cookie=$_COOKIE; $server=$_SERVER;
  $initial=PhabricatorEnv::getEnvConfig('governance.organization.enabled');
  try {
    PhabricatorEnv::overrideConfig('governance.organization.enabled',false);
    list($response,$body)=governance_native_route_response($admin,'GET',array('json'=>'true'));
    $check($response instanceof Aphront404Response,
      'native-controller-feature-off-route-unavailable');
    PhabricatorEnv::overrideConfig('governance.organization.enabled',true);
    list($response,$body)=governance_native_route_response($admin,'GET',
      array('json'=>'true','mode'=>'list'));
    $check($response instanceof AphrontJSONResponse && $response->getHTTPResponseCode()===200 &&
      isset($body['result']) && is_array($body['result']) && count($body['result'])===2,
      'native-controller-current-session-list-real-service');
    $user=(new PhabricatorUser())->setUsername('gnget'.substr($fixture->getNamespace(),-12))
      ->setRealName('Synthetic First GET')->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())
      ->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
    $fresh=governance_native_request($user,$state['sessions']);
    $depth=governance_native_route_guard_depth();
    $check($depth===0 && governance_native_route_epoch_count($fixture,$user->getPHID())===0,
      'native-controller-tokenless-get-first-issuer-metadata-initially-absent');
    $invalid=$fresh;$invalid['token']=str_repeat('f',64);
    list($bad,$badbody)=governance_native_route_response($invalid,'GET',array('json'=>'true','mode'=>'count'));
    $check($bad->getHTTPResponseCode()===403 && !isset($badbody['result']) &&
      governance_native_route_epoch_count($fixture,$user->getPHID())===0 &&
      governance_native_route_guard_depth()===$depth,
      'native-controller-invalid-cookie-get-no-issuer-metadata-or-unguarded-leak');
    list($first,$firstbody)=governance_native_route_response($fresh,'GET',array('json'=>'true','mode'=>'count'));
    $check($first->getHTTPResponseCode()===200 && $firstbody['result']===0 &&
      governance_native_route_epoch_count($fixture,$user->getPHID())===1 &&
      governance_native_route_guard_depth()===$depth,
      'native-controller-tokenless-get-real-csrf-guard-first-issuer-initialized');
    $_SERVER['REQUEST_METHOD']='GET';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$admin['token'];
    $get_request=(new AphrontRequest('fixture.invalid','/people/organization/'))
      ->setUser($admin['viewer'])->setRequestData(array());
    $guarded=false;$guard_before=$fixture->countRows('user','organization_unit');
    try { governance_native_with_request_write_guard($get_request,
      static fn()=>$state['service']->createUnit(array('operation_id'=>'native-route-guard-leak-probe',
        'unit_id'=>'native-guard-leak-unit','kind'=>'team','name'=>'Guard Leak Probe','parent_id'=>null))); }
    catch (AphrontMalformedRequestException $error) { $guarded=true; }
    $check($guarded && governance_native_route_guard_depth()===$depth &&
      $fixture->countRows('user','organization_unit')===$guard_before,
      'native-controller-after-get-normal-writes-remain-csrf-guarded');
    $before=$fixture->countRows('user','organization_unit');
    $payload=array('operation_id'=>'native-route-create','unit_id'=>'native-route-unit',
      'kind'=>'team','name'=>'Synthetic Route Team','parent_id'=>null);
    $data=array('json'=>'true','action'=>'unit.create','payload'=>json_encode($payload));
    list($response,$body)=governance_native_route_response($admin,'POST',$data);
    $check($response->getHTTPResponseCode()===403 && isset($body['error']) &&
      $fixture->countRows('user','organization_unit')===$before,
      'native-controller-json-post-without-csrf-refused-no-write');
    $data[AphrontRequest::getCSRFTokenName()]=$admin['viewer']->getCSRFToken();
    $missing=$data; unset($missing['payload']);
    list($response,$body)=governance_native_route_response($admin,'POST',$missing);
    $check($response->getHTTPResponseCode()===403 && isset($body['error']) &&
      $fixture->countRows('user','organization_unit')===$before,
      'native-controller-missing-json-payload-refused-without-native-warning');
    list($response,$body)=governance_native_route_response($admin,'POST',$data);
    $check($response->getHTTPResponseCode()===200 && isset($body['result']) &&
      $fixture->countRows('user','organization_unit')===$before+1,
      'native-controller-json-post-without-form-action-real-commit');
    list($response,$body)=governance_native_route_response($admin,'POST',$data);
    $check($response->getHTTPResponseCode()===200 &&
      $fixture->countRows('user','organization_unit')===$before+1,
      'native-controller-json-operation-replay-no-extra-row');
    $forged=$admin; $forged['token']=str_repeat('f',64);
    list($response,$body)=governance_native_route_response($forged,'GET',
      array('json'=>'true','mode'=>'count'));
    $check($response->getHTTPResponseCode()===403 && !isset($body['result']) && isset($body['error']),
      'native-controller-viewer-alone-wrong-cookie-no-count-leak');
    return array('actual_controller_called'=>true,'http_server_executed'=>false,
      'browser_executed'=>false,'csrf_and_cookie_checked'=>true,'actual_request_write_guard_callback'=>true,
      'readonly_unguarded_scope_restored'=>governance_native_route_guard_depth()===0);
  } finally {
    PhabricatorEnv::overrideConfig('governance.organization.enabled',$initial);
    $_COOKIE=$cookie; $_SERVER=$server;
  }
}
