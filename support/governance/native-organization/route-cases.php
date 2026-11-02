<?php

/** Calls real native controllers; this is not an HTTP server/browser receipt. */
function governance_native_route_response(array $session, $method, array $data) {
  $_SERVER['REQUEST_METHOD']=$method;
  $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];
  $request=(new AphrontRequest('fixture.invalid','/people/organization/'))
    ->setUser($session['viewer'])->setRequestData($data);
  $controller=(new PhorgeOrganizationController())->setRequest($request)
    ->setCurrentApplication(new PhabricatorPeopleApplication());
  $response=$controller->handleRequest($request);
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
      'browser_executed'=>false,'csrf_and_cookie_checked'=>true);
  } finally {
    PhabricatorEnv::overrideConfig('governance.organization.enabled',$initial);
    $_COOKIE=$cookie; $_SERVER=$server;
  }
}
