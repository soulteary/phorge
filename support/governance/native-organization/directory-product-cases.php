<?php

require_once __DIR__.'/native-cases.php';
require_once __DIR__.'/privacy-cases.php';
require_once __DIR__.'/route-cases.php';

/** Real controller/render calls; deliberately not called browser evidence. */
function governance_directory_product_response($session,array $data,$method='GET',$profile=null) {
  $resources=new ReflectionProperty(CelerityAPI::class,'response');$previous=$resources->getValue();$resources->setValue(null,null);
  try{
  $_SERVER['REQUEST_METHOD']=$method;$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];
  (new PhabricatorAuthSessionEngine())->willServeRequestForUser($session['viewer']);
  $config=(new AphrontApplicationConfiguration())->setHost('fixture.invalid')->setPath('/people/organization/');
  $request=$config->buildRequest()->setUser($session['viewer'])->setRequestData($data);$config->setRequest($request);
  if($profile!==null){
    $controller=(new PhabricatorPeopleProfileViewController())->setRequest($request)->setCurrentApplication(new PhabricatorPeopleApplication());
    $method=new ReflectionMethod($controller,'buildPropertyView');$response=$method->invoke($controller,$profile);
  }else{
    $controller=(new PhorgeOrganizationController())->setRequest($request)->setCurrentApplication(new PhabricatorPeopleApplication());
    $response=governance_native_with_request_write_guard($request,static fn()=>$controller->handleRequest($request));

  }
  return array($response,$response===null||$response instanceof AphrontResponse?'':(string)hsprintf('%s',$response));
  }finally{$resources->setValue(null,$previous);}
}

/** Product additions against real owned schemas and real native credentials. */
function governance_directory_product_cases($fixture,callable $check) {
  $fixture->assertOwned();$users=array();$sessions=new PhabricatorAuthSessionEngine();
  foreach(array('admin','manager','subject','disabled')as$role){
    $u=(new PhabricatorUser())->setUsername('dp'.$role.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Directory '.$role)->setIsApproved(1)->setIsEmailVerified(1)
      ->setIsAdmin($role==='admin'?1:0)->setIsDisabled($role==='disabled'?1:0)->save();
    (new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();$users[$role]=$u;
  }
  $admin=governance_native_request($users['admin'],$sessions);
  $manager=governance_native_privacy_context($users['manager'],$sessions);
  $service=PhorgeOrganizationService::newForActor($admin['context']);
  foreach(array(array('dp-parent','team',null),array('dp-a','team','dp-parent'),array('dp-b','team',null),array('dp-dept','department',null))as$row){
    $service->createUnit(array('operation_id'=>'create-'.$row[0],'unit_id'=>$row[0],'kind'=>$row[1],
      'parent_id'=>$row[2],'name'=>'Directory '.$row[0]));
  }
  governance_native_privacy_add($service,'manager-add','dp-a',$users['manager']->getPHID(),1);
  governance_native_privacy_seed($fixture,'dp-a',$users['manager']->getPHID());
  $restricted=PhorgeOrganizationService::newForActor($manager);
  $subject=$users['subject']->getPHID();
  $intent=array('mode'=>'import','operation_id'=>'bulk-first','unit_id'=>'dp-a','user_phid'=>null,
    'selection'=>array($subject,$subject),'valid_from'=>0,'valid_until'=>null);
  $before=$fixture->countRows('user','organization_member');
  $plan=$restricted->bulkMembers($intent);
  $check($fixture->countRows('user','organization_member')===$before&&count($plan['changes'])===1&&
    $plan['changes'][0]['action']==='member.add','bulk-preview-deduplicates-without-member-write');
  governance_native_refused(fn()=>$restricted->bulkMembers($intent,$plan['digest'],false),
    'organization_bulk_confirmation_required',$check,'bulk-impact-acknowledgement-required');
  $committed=$restricted->bulkMembers($intent,$plan['digest'],true);
  $count=$fixture->countRows('user','organization_member');
  $replay=$restricted->bulkMembers($intent,$plan['digest'],true);
  $check($committed['status']==='committed'&&$replay['replayed']===true&&
    $fixture->countRows('user','organization_member')===$count,'bulk-import-commits-once-and-replays');
  $intent['operation_id']='bulk-keep';$keep=$restricted->bulkMembers($intent);
  $check($keep['changes'][0]['action']==='keep'&&!$keep['mutations'],'bulk-retains-existing-period');
  $intent['operation_id']='bulk-stale';$stale=$restricted->bulkMembers($intent);
  $unit=$service->readUnit('dp-a');
  $service->updateUnit(array('operation_id'=>'rename-a','unit_id'=>'dp-a','expected_version'=>$unit['version'],
    'name'=>'Renamed A','parent_id'=>'dp-parent','state'=>'active'));
  governance_native_refused(fn()=>$restricted->bulkMembers($intent,$stale['digest'],true),
    'organization_stale_bulk_preview',$check,'bulk-stale-unit-preview-refused');
  $replace=array('mode'=>'replace','operation_id'=>'replace-first','unit_id'=>null,'user_phid'=>$subject,
    'selection'=>array('dp-b'),'valid_from'=>0,'valid_until'=>null);
  governance_native_refused(fn()=>$restricted->bulkMembers($replace),'organization_system_admin_required',
    $check,'bulk-replacement-system-admin-only');
  $service->assignPrimaryDepartment(array('operation_id'=>'assign-subject','unit_id'=>'dp-dept','user_phid'=>$subject,'expected_version'=>0));
  $plan=$service->bulkMembers($replace);$service->bulkMembers($replace,$plan['digest'],true);
  $query=new PhorgeOrganizationQuery($admin['context']);
  $check(array_column($query->listUserTeams($subject),'unit_id')===array('dp-b')&&
    $query->getUserDepartment($subject)['unit_id']==='dp-dept','bulk-replacement-preserves-primary-department');
  $rows=governance_native_setup_query($fixture,'SELECT state FROM '.$fixture->table('user','organization_member').
    " WHERE userPHID='".$subject."' ORDER BY unitID")->fetch_all(MYSQLI_ASSOC);
  $check(array_column($rows,'state')===array('ended','active'),'bulk-replacement-retains-ended-history');
  $replace['operation_id']='replace-empty';$replace['selection']=array();$plan=$service->bulkMembers($replace);
  $service->bulkMembers($replace,$plan['digest'],true);
  $check($query->listUserTeams($subject)===array(),'bulk-explicit-empty-selection-ends-memberships');
  $replace['operation_id']='replace-admin-prerequisite';$replace['user_phid']=$users['manager']->getPHID();
  $before=$fixture->countRows('user','organization_audit');
  governance_native_refused(fn()=>$service->bulkMembers($replace),'organization_appointment_prerequisite',
    $check,'bulk-replacement-cannot-end-administrator-prerequisite');
  $check($fixture->countRows('user','organization_audit')===$before,'bulk-refused-preview-does-not-write-audit');
  $intent['operation_id']='bulk-disabled';$intent['selection']=array($users['disabled']->getPHID());
  governance_native_refused(fn()=>$restricted->bulkMembers($intent),'organization_subject_ineligible',
    $check,'bulk-disabled-user-refused');
  $intent['operation_id']='bulk-reactivate';$intent['selection']=array($subject);$plan=$restricted->bulkMembers($intent);
  $check($plan['changes'][0]['action']==='member.update','bulk-ended-relation-reactivation-explicit');
  $restricted->bulkMembers($intent,$plan['digest'],true);
  $mquery=new PhorgeOrganizationQuery($manager);
  $check($mquery->getUserDepartment($subject)===null&&
    array_column($mquery->listUserTeams($subject),'unit_id')===array('dp-a'),
    'profile-projection-hides-unmanaged-department-and-teams');
  $roots=$mquery->treePage();
  $check(count($roots)===1&&$roots[0]['unit_id']==='dp-a'&&$roots[0]['parent_id']===null,
    'tree-hidden-parent-promotes-visible-child-without-reference');
  governance_native_refused(fn()=>$mquery->treePage('dp-parent'),'organization_not_visible',
    $check,'tree-hidden-parent-cannot-be-expanded');
  $check(array_column($query->treePage('dp-parent'),'unit_id')===array('dp-a'),
    'tree-explicit-visible-parent-expands-direct-children');
  $page1=$query->treePage(null,1);$page2=$query->treePage(null,1,$page1[0]['unit_id']);
  $check(count($page1)===1&&count($page2)===1&&$page1[0]['unit_id']!==$page2[0]['unit_id'],
    'tree-visible-branch-keyset-pagination');
  $check($query->treePage(null,100,null,'%')===array(),'tree-search-percent-is-literal');
  // A failure in the second 32-pair chunk must roll back the first chunk,
  // its audit records, unit versions and both inner/outer operation receipts.
  $batch=array();for($i=0;$i<33;$i++){
    $person=(new PhabricatorUser())->setUsername('dpbatch'.$i.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Batch Member')->setIsApproved(1)->save();$batch[]=$person->getPHID();
  }
  sort($batch,SORT_STRING);$intent['operation_id']='bulk-multi-chunk';$intent['selection']=$batch;
  $plan=$service->bulkMembers($intent);$before=array();
  foreach(array('organization_member','organization_audit','organization_operation')as$table){$before[$table]=$fixture->countRows('user',$table);}
  $unitBefore=$service->readUnit('dp-a');$trigger='`'.$fixture->getNamespace().'_user`.`directory_product_fault`';
  governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.
    $fixture->table('user','organization_audit')." FOR EACH ROW BEGIN IF NEW.subjectPHID='".last($batch).
    "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='directory_product_test_fault'; END IF; END");
  $fault=false;
  try{$service->bulkMembers($intent,$plan['digest'],true);}
  catch(Throwable $error){$fault=strpos($error->getMessage(),'directory_product_test_fault')!==false;}
  finally{governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}
  $check($fault,'bulk-second-chunk-real-audit-fault-observed');
  $after=array();foreach(array_keys($before)as$table){$after[$table]=$fixture->countRows('user',$table);}
  $check($before===$after&&$service->readUnit('dp-a')['version']===$unitBefore['version'],
    'bulk-second-chunk-failure-rolls-back-entire-transaction');
  $result=$service->bulkMembers($intent,$plan['digest'],true);
  $check($result['status']==='committed'&&$fixture->countRows('user','organization_member')===$before['organization_member']+33,
    'bulk-multiple-chunks-commit-all-members');
  $replace['operation_id']='replace-concurrent-edge';$replace['user_phid']=$subject;$replace['selection']=array('dp-a');
  $plan=$service->bulkMembers($replace);$extra=$intent;$extra['operation_id']='bulk-new-edge';$extra['unit_id']='dp-b';$extra['selection']=array($subject);
  $preview=$service->bulkMembers($extra);$service->bulkMembers($extra,$preview['digest'],true);
  governance_native_refused(fn()=>$service->bulkMembers($replace,$plan['digest'],true),
    'organization_stale_bulk_preview',$check,'bulk-replacement-detects-concurrently-added-team');
  $check(array_column($query->listUserTeams($subject,100,'dp-a'),'unit_id')===array('dp-b'),
    'profile-team-keyset-does-not-repeat-previous-page');
  $service->createUnit(array('operation_id'=>'zero-parent','unit_id'=>'0','kind'=>'team','parent_id'=>null,'name'=>'Numeric Zero'));
  $service->createUnit(array('operation_id'=>'zero-child','unit_id'=>'dp-zero-child','kind'=>'team','parent_id'=>'0','name'=>'Child of Zero'));
  $check(array_column(PhorgeOrganizationEndpoint::read($admin['context'],'tree','0',null,100,null),'unit_id')===array('dp-zero-child'),
    'tree-valid-zero-parent-is-not-normalized-to-root');
  governance_native_refused(fn()=>PhorgeOrganizationEndpoint::write($admin['context'],'members.commit',array(
    'intent'=>$intent,'expected_digest'=>null,'acknowledge_impact'=>true)),
    'organization_bulk_confirmation_required',$check,'bulk-api-commit-cannot-fall-back-to-preview');
  $service->clearPrimaryDepartment(array('operation_id'=>'clear-subject-department','user_phid'=>$subject,'expected_version'=>1));
  $check($query->getUserDepartment($subject)===null&&count($query->listUserTeams($subject))===2,
    'profile-cleared-primary-department-retains-visible-teams');
  $service->assignPrimaryDepartment(array('operation_id'=>'restore-subject-department','unit_id'=>'dp-dept',
    'user_phid'=>$subject,'expected_version'=>2));
  $managerSession=governance_native_request($users['manager'],$sessions);
  list($page,$html)=governance_directory_product_response($managerSession,array('view'=>'tree'));
  $GLOBALS['directory_product_diagnostic']=array('response_class'=>get_class($page),'html_bytes'=>strlen($html),'name_visible'=>strpos($html,'Renamed A')!==false,'parent_visible'=>strpos($html,'Directory dp-parent')!==false,'link_present'=>strpos($html,'tree_parent=dp-a')!==false);
  $check($page instanceof PhabricatorStandardPageView&&strpos($html,'Renamed A')!==false&&
    strpos($html,'Directory dp-parent')===false&&strpos($html,'tree_parent=dp-a')!==false,
    'tree-real-controller-render-redacts-hidden-parent');
  list($page,$html)=governance_directory_product_response($admin,array('bulk'=>'import','unit_id'=>'dp-a'));
  $check($page instanceof PhabricatorStandardPageView&&strpos($html,'usernames')!==false&&
    strpos($html,'bulk.preview')!==false,'bulk-real-import-form-offers-paste-and-selection');
  list($page,$html)=governance_directory_product_response($managerSession,array('bulk'=>'replace'));
  $check(!($page instanceof PhabricatorStandardPageView),'bulk-real-controller-denies-manager-replacement-form');
  list($properties,$html)=governance_directory_product_response($managerSession,array(),'GET',$users['subject']);
  $check(strpos($html,'Renamed A')!==false&&strpos($html,'Directory dp-b')===false&&
    strpos($html,'Directory dp-dept')===false,'profile-real-properties-hide-other-team-and-department');
  list($properties,$html)=governance_directory_product_response($admin,array(),'GET',$users['subject']);
  $check(strpos($html,'Directory dp-dept')!==false&&strpos($html,'Directory dp-b')!==false,
    'profile-real-admin-properties-show-authorized-relations');
  $before=$fixture->countRows('user','organization_member');
  list($page,$html)=governance_directory_product_response($admin,array('bulk'=>'import','unit_id'=>'dp-b',
    'form_action'=>'bulk.preview','selection'=>array($users['manager']->getPHID())),'POST');
  $check(!($page instanceof PhabricatorStandardPageView)&&$fixture->countRows('user','organization_member')===$before,
    'bulk-real-preview-form-requires-csrf');
  $data=array('bulk'=>'import','unit_id'=>'dp-b','form_action'=>'bulk.preview','selection'=>array(),
    'usernames'=>$users['manager']->getUsername().','.$users['manager']->getUsername(),
    AphrontRequest::getCSRFTokenName()=>$admin['viewer']->getCSRFToken());
  list($page,$html)=governance_directory_product_response($admin,$data,'POST');
  $check($page instanceof PhabricatorStandardPageView&&strpos($html,'confirmed_intent')!==false&&
    $fixture->countRows('user','organization_member')===$before,'bulk-real-pasted-preview-deduplicates-without-write');
  $hidden=array();preg_match_all('/<input[^>]*>/',$html,$inputs);
  foreach($inputs[0]as$input){
    if(preg_match('/name="([^"]+)"/',$input,$name)&&preg_match('/value="([^"]*)"/',$input,$value)){
      $hidden[html_entity_decode($name[1],ENT_QUOTES,'UTF-8')]=html_entity_decode($value[1],ENT_QUOTES,'UTF-8');}}
  $hidden['acknowledge_bulk']='1';
  list($response,$body)=governance_directory_product_response($admin,$hidden,'POST');
  $check($response instanceof AphrontRedirectResponse&&$fixture->countRows('user','organization_member')===$before+1,
    'bulk-real-confirmed-csrf-form-commits-import');
  if(getenv('PHORGE_DIRECTORY_BROWSER_ORIGIN')!==false){
    require_once __DIR__.'/directory-product-browser-fixture.php';
    governance_directory_product_browser($fixture,$service,$sessions,$admin,$users,$check);
  }
  return array('real_native_credentials'=>true,'real_owned_mysql'=>true,'browser_acceptance'=>false,
    'production_changed'=>false,'native_permission_migration'=>false);
}
