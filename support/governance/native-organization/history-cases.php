<?php

/** Real native audit projection. Every request uses an actual native credential. */
function governance_native_history_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$service=$state['service'];$conn=(new PhabricatorUser())->establishConnection('w');
  $saved_cookie=$_COOKIE;$saved_server=$_SERVER;
  $new_user=function($suffix,$admin)use($fixture){$user=(new PhabricatorUser())
    ->setUsername('history'.$suffix.substr($fixture->getNamespace(),-10))->setRealName('Synthetic History '.$suffix)
    ->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin($admin?1:0)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();return$user;};
  $route=function($session,$method,array$data){
    $_SERVER['REQUEST_METHOD']=$method;$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];
    $request=(new AphrontRequest('fixture.invalid','/people/organization/history/'))->setUser($session['viewer'])->setRequestData($data);
    $controller=(new PhorgeOrganizationHistoryController())->setRequest($request)->setCurrentApplication(new PhabricatorPeopleApplication());
    $response=governance_native_with_request_write_guard($request,static function()use($controller,$request){return$controller->handleRequest($request);});
    $body=null;if($response instanceof AphrontJSONResponse){$body=phutil_json_decode($response->setAddJSONShield(false)->buildResponseString());}
    return array($response,$body);
  };
  try{
    $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];$cutoff=$now-365*86400;
    $audit=$fixture->table('user','organization_audit');$admin=$state['admin']['viewer']->getPHID();
    for($i=0;$i<103;$i++){
      $created=$i<2?$cutoff-86400:($i===2?$cutoff+86400:$now);
      governance_native_concurrency_sql($fixture,'INSERT INTO '.$audit.
        ' (operationID,actorPHID,action,unitID,subjectPHID,beforeJSON,afterJSON,createdAt) VALUES (?,?,?,?,?,?,?,?)',
        array('history-fixture-'.$i,$admin,'member.update','history-test',null,
          '{"private_fixture_marker":"must-not-be-returned"}','{"credential_fixture_marker":"must-not-be-returned"}',$created));
    }
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
    $page=$service->readAuditPage(100);$next=$service->readAuditPage(100,$page['next_cursor']);
    $ids=array_merge(array_column($page['rows'],'id'),array_column($next['rows'],'id'));
    $check(count($page['rows'])===100 && $page['next_cursor']!==null && count($ids)===count(array_unique($ids)),
      'native-history-real-keyset-pages-have-no-duplicate-records');
    $keys=array_keys(head($page['rows']));sort($keys);
    $expected=array('action','actor_phid','created_at','id','operation_id','retention_candidate','subject_phid','unit_id');sort($expected);
    $encoded=phutil_json_encode(array($page,$next));
    $check($keys===$expected && strpos($encoded,'must-not-be-returned')===false &&
      $page['deletion_enabled']===false && $page['full_snapshot_included']===false,
      'native-history-never-loads-or-returns-private-snapshots-or-credential-binding');
    $before=$fixture->countRows('user','organization_audit');$old=$service->readAuditPage(100,null,true);
    $old_ops=array_column($old['rows'],'operation_id');
    $check(in_array('history-fixture-0',$old_ops,true) && in_array('history-fixture-1',$old_ops,true) &&
      !in_array('history-fixture-2',$old_ops,true) && $old['retention_days']===365 &&
      $fixture->countRows('user','organization_audit')===$before,
      'native-history-365-day-candidate-report-retains-all-records');
    list($response,$body)=$route($state['admin'],'GET',array('json'=>'true','limit'=>10));
    $check($response->getHTTPResponseCode()===200 && count($body['result']['rows'])===10 && governance_native_route_guard_depth()===0,
      'native-history-actual-get-current-admin-without-csrf-read-scope-restored');
    list($post,$postbody)=$route($state['admin'],'POST',array('json'=>'true',AphrontRequest::getCSRFTokenName()=>$state['admin']['viewer']->getCSRFToken()));
    $check($post->getHTTPResponseCode()===403 && !isset($postbody['result']) && $fixture->countRows('user','organization_audit')===$before,
      'native-history-has-no-post-delete-or-mutation-surface');
    $ordinary=$new_user('ordinary',false);$ordinary_session=governance_native_request($ordinary,$state['sessions']);
    list($forbidden,$body)=$route($ordinary_session,'GET',array('json'=>'true'));
    $check($forbidden->getHTTPResponseCode()===403 && !isset($body['result']),
      'native-history-ordinary-user-cannot-read-audit-or-retention-count');
    $team='history-team';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
    $service->createUnit(array('operation_id'=>'history-team-create','unit_id'=>$team,'kind'=>'team','name'=>'Synthetic History Team','parent_id'=>null));
    governance_native_privacy_add($service,'history-manager-add',$team,$ordinary->getPHID(),1);
    governance_native_privacy_seed($fixture,$team,$ordinary->getPHID());
    list($forbidden,$body)=$route($ordinary_session,'GET',array('json'=>'true','retention_only'=>'true'));
    $check($forbidden->getHTTPResponseCode()===403 && !isset($body['result']),
      'native-history-current-team-administrator-still-cannot-read-global-audit');
    $admin_user=$new_user('temporaryadmin',true);$temporary=governance_native_request($admin_user,$state['sessions']);
    list($first,$body)=$route($temporary,'GET',array('json'=>'true','limit'=>1));
    $cursor=$body['result']['next_cursor'];
    $actor=$state['admin']['viewer'];
    $factors=(new PhabricatorAuthFactorConfigQuery())->setViewer($state['admin']['viewer'])->withUserPHIDs(array($state['admin']['viewer']->getPHID()))
      ->withFactorProviderStatuses(array(PhabricatorAuthFactorProviderStatus::STATUS_ACTIVE,PhabricatorAuthFactorProviderStatus::STATUS_DEPRECATED))->execute();
    $check(!$factors && !$state['admin']['viewer']->getSession()->isHighSecuritySession(),
      'native-history-reader-revocation-uses-actual-no-factor-normal-session');
    $transaction=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(false);
    (new PhabricatorUserTransactionEditor())->setActor($actor)
      ->setContentSourceFromRequest($state['admin']['request'])->setCancelURI('/people/manage/'.$admin_user->getID().'/')
      ->applyTransactions($admin_user,array($transaction));
    list($revoked,$body)=$route($temporary,'GET',array('json'=>'true','after'=>$cursor));
    $check($first->getHTTPResponseCode()===200 && $revoked->getHTTPResponseCode()===403 && !isset($body['result']),
      'native-history-administrator-revoked-between-pages-cannot-use-old-cursor');
    return array('current_system_administrator_only'=>true,'snapshot_fields_returned'=>false,
      'retention_days'=>365,'history_deleted'=>false,'full_browser_acceptance'=>false,'production_activation'=>false);
  }finally{$_COOKIE=$saved_cookie;$_SERVER=$saved_server;}
}
