<?php

require_once __DIR__.'/privacy-cases.php';
require_once __DIR__.'/concurrency-cases.php';

/** Real native source/SQL; existing manager seeds never prove appointment approval. */
function governance_native_directory_completion_cases($fixture, callable $check, array $state) {
  $fixture->assertOwned();$service=$state['service'];$saved_cookie=$_COOKIE;$saved_server=$_SERVER;
  try{
    $service->createUnit(array('operation_id'=>'dc-dept','unit_id'=>'dc-dept','kind'=>'department','name'=>'Completion Department','parent_id'=>null));
    $parent=null;
    for($depth=1;$depth<=8;$depth++){
      $id='dc-depth-'.$depth;$service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,
        'kind'=>'team','name'=>'Depth '.$depth,'parent_id'=>$parent));$parent=$id;
    }
    $at8=$service->previewUnitChange(array('unit_id'=>'dc-preview','kind'=>'team','parent_id'=>'dc-depth-7','expected_version'=>0));
    $at9=$service->previewUnitChange(array('unit_id'=>'dc-preview','kind'=>'team','parent_id'=>'dc-depth-8','expected_version'=>0));
    $check($at8['depth']===8 && $at8['warnings']===array() && $at9['depth']===9 &&
      $at9['warnings']===array('organization_depth_soft_limit') && $at9['blocking']===false,
      'directory-completion-depth-eight-nine-real-hierarchy-soft-warning');
    $service->createUnit(array('operation_id'=>'dc-depth-nine','unit_id'=>'dc-depth-9','kind'=>'team','name'=>'Depth 9','parent_id'=>'dc-depth-8'));
    $check((new PhorgeOrganizationQuery($state['admin']['context']))->getUnit('dc-depth-9')!==null,
      'directory-completion-depth-nine-is-allowed-not-hard-limit');
    governance_native_refused(fn()=>$service->previewUnitChange(array('unit_id'=>'dc-depth-1','kind'=>'team',
      'parent_id'=>'dc-depth-9','expected_version'=>1)),'organization_parent_cycle_or_budget',$check,
      'directory-completion-preview-cycle-refused');
    $user=(new PhabricatorUser())->setUsername('dcmanager'.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Directory Manager')->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $manager=governance_native_request($user,$state['sessions']);
    $service->updateUnitMetadata(array('operation_id'=>'dc-metadata','unit_id'=>'dc-depth-9','expected_version'=>1,
      'associated_department_id'=>'dc-dept','contact_user_phid'=>$state['users']['member']->getPHID()));
    governance_native_privacy_add($service,'dc-member','dc-depth-9',$user->getPHID(),2);
    governance_native_privacy_seed($fixture,'dc-depth-9',$user->getPHID());
    $query=new PhorgeOrganizationQuery($manager['context']);$row=$query->getUnit('dc-depth-9');
    $check($row['parent_id']===null && $row['department_id']===null && $row['contact_user_phid']===null,
      'directory-completion-hidden-parent-department-nonmember-contact-redacted');
    governance_native_privacy_add($service,'dc-contact-member','dc-depth-9',$state['users']['member']->getPHID(),3);
    $check($query->getUnit('dc-depth-9')['contact_user_phid']===$state['users']['member']->getPHID(),
      'directory-completion-contact-visible-only-in-permitted-current-roster');
    $service->proposeApproval(array('approval_id'=>'dc-model-good','operation_id'=>'dc-model-good-op','team'=>'dc-depth-9',
      'action'=>'appointment.grant','target_user'=>$user->getPHID(),'valid_from'=>0,'valid_until'=>null,'ttl_seconds'=>180));
    $approver_user=(new PhabricatorUser())->setUsername('dcapprove'.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Directory Approver')->setIsApproved(1)->setIsAdmin(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($approver_user->getPHID())->setAddress($approver_user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $approver=governance_native_request($approver_user,$state['sessions']);
    $approver_service=PhorgeOrganizationService::newForActor($approver['context']);
    $table=$fixture->table('user','organization_unit');
    governance_native_setup_query($fixture,'UPDATE '.$table." SET visibilityModel='unknown-v2' WHERE unitID='dc-depth-9'");
    try{
      $check($query->getUnit('dc-depth-9')===null && $query->countUnits()===0,
        'directory-completion-unknown-visibility-model-fails-closed');
      governance_native_refused(fn()=>$service->proposeApproval(array('approval_id'=>'dc-model-bad','operation_id'=>'dc-model-bad-op',
        'team'=>'dc-depth-9','action'=>'appointment.grant','target_user'=>$user->getPHID(),
        'valid_from'=>0,'valid_until'=>null,'ttl_seconds'=>180)),
        'organization_approval_source_refused',$check,'directory-completion-unknown-model-approval-proposal-refused');
      governance_native_refused(fn()=>$approver_service->approveApproval('dc-model-good'),
        'organization_approval_source_refused',$check,'directory-completion-unknown-model-independent-approval-refused');
      $tickets=governance_native_concurrency_sql($fixture,'SELECT approvalID,state FROM '.
        $fixture->table('user','organization_approval').' WHERE approvalID IN (?,?)',array('dc-model-good','dc-model-bad'));
      $check(count($tickets)===1 && $tickets[0]['approvalID']==='dc-model-good' && $tickets[0]['state']==='submitted',
        'directory-completion-unknown-model-no-new-or-approved-ticket');
    }
    finally{governance_native_setup_query($fixture,'UPDATE '.$table." SET visibilityModel='restricted-admin-v1' WHERE unitID='dc-depth-9'");}
    $service->assignPrimaryDepartment(array('operation_id'=>'dc-assign','user_phid'=>$user->getPHID(),'unit_id'=>'dc-dept','expected_version'=>0));
    $service->clearPrimaryDepartment(array('operation_id'=>'dc-clear','user_phid'=>$user->getPHID(),'expected_version'=>1));
    $primary=governance_native_concurrency_sql($fixture,'SELECT unitID,version FROM '.$fixture->table('user','organization_primarydepartment').' WHERE userPHID=?',array($user->getPHID()));
    $check(count($primary)===1 && $primary[0]['unitID']===null && (int)$primary[0]['version']===2,
      'directory-completion-clear-main-department-retains-monotonic-row-fence');
    governance_native_refused(fn()=>$service->clearPrimaryDepartment(array('operation_id'=>'dc-stale-clear',
      'user_phid'=>$user->getPHID(),'expected_version'=>1)),'organization_stale_version',$check,
      'directory-completion-stale-clear-refused');
    $unassigned=$service->readUnassignedUsers(100);
    $check(in_array($user->getPHID(),array_column($unassigned,'user_phid'),true),
      'directory-completion-unassigned-admin-warning-real-query');
    governance_native_refused(fn()=>(new PhorgeOrganizationQuery($manager['context']))->listUnassignedUsers(),
      'organization_system_admin_required',$check,'directory-completion-unassigned-query-denies-team-admin');
    for($i=0;$i<101;$i++){$id=sprintf('dc-page-%03d',$i);$service->createUnit(array('operation_id'=>$id,'unit_id'=>$id,
      'kind'=>'team','name'=>sprintf('Paging_%03d',$i),'parent_id'=>null));}
    $service->createUnit(array('operation_id'=>'dc-page-deceptive','unit_id'=>'dc-deceptive','kind'=>'team','name'=>'PagingXdeceptive','parent_id'=>null));
    $admin=new PhorgeOrganizationQuery($state['admin']['context']);
    $page1=$admin->searchUnits('Paging_',100);$page2=$admin->searchUnits('Paging_',100,last($page1)['unit_id']);
    $check(count($page1)===100 && count($page2)===1 && $admin->countUnits('Paging_')===101 &&
      count(array_unique(array_column(array_merge($page1,$page2),'unit_id')))===101,
      'directory-completion-search-literal-wildcard-keyset-count-native-sql');
    $member_phids=array();
    for($i=0;$i<101;$i++){$person=(new PhabricatorUser())->setUsername('dcp'.sprintf('%03d',$i).substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Paged Member')->setIsApproved(1)->save();$member_phids[]=$person->getPHID();}
    $unit_version=1;
    foreach(array_chunk($member_phids,32) as $batch_index=>$phids){$mutations=array();foreach($phids as $phid){$mutations[]=array(
      'action'=>'member.add','approval_id'=>null,'expected_appointment_version'=>0,'expected_member_version'=>0,
      'expected_team_version'=>$unit_version,'target_user'=>$phid,'team'=>'dc-page-000','valid_from'=>0,'valid_until'=>null);}
      $service->execute(array('operation_id'=>'dc-member-batch-'.$batch_index,'mutations'=>$mutations));$unit_version++;}
    $members1=$admin->listMembers('dc-page-000',100);$members2=$admin->listMembers('dc-page-000',100,last($members1)['user_phid']);
    $check(count($members1)===100 && count($members2)===1 && $admin->countMembers('dc-page-000')===101 &&
      count(array_unique(array_column(array_merge($members1,$members2),'user_phid')))===101,
      'directory-completion-member-keyset-and-count-over-first-hundred-native-rows');
    $phid_shaped_id=$state['users']['member']->getPHID();
    $service->createUnit(array('operation_id'=>'dc-phid-shape','unit_id'=>$phid_shaped_id,'kind'=>'team',
      'name'=>'Organization Domain Label','parent_id'=>null));
    $admin_source=(new PhorgeOrganizationDatasource())->setActorContext($state['admin']['context']);
    $admin_tokens=$admin_source->renderTokens(array($phid_shaped_id));
    $manager_source=(new PhorgeOrganizationDatasource())->setActorContext($manager['context']);
    $hidden_tokens=$manager_source->renderTokens(array($phid_shaped_id));
    $check($admin_tokens[0]->getKey()===$phid_shaped_id && $admin_tokens[0]->getValue()==='Organization Domain Label',
      'directory-completion-phid-shaped-unit-token-resolves-organization-domain');
    $check($hidden_tokens[0]->getValue()===pht('Unavailable Organization Unit') &&
      $hidden_tokens[0]->getValue()!==$state['users']['member']->getFullName(),
      'directory-completion-hidden-phid-shaped-unit-token-never-falls-back-to-native-handle');
    $source=(new PhorgeOrganizationDatasource())->setActorContext($manager['context'])->setRawQuery('Depth');
    $check(array_map(static fn($r)=>$r->getPHID(),$source->loadResults())===array('dc-depth-9'),
      'directory-completion-native-datasource-shares-privacy-projection');
    $_SERVER['REQUEST_METHOD']='GET';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$manager['token'];
    $request=(new AphrontRequest('fixture.invalid','/people/organization/typeahead/'))
      ->setUser($manager['viewer'])->setRequestData(array('q'=>'Depth'));
    $controller=(new PhorgeOrganizationDatasourceController())->setRequest($request)->setCurrentApplication(new PhabricatorPeopleApplication());
    $response=governance_native_with_request_write_guard($request,static fn()=>$controller->handleRequest($request));
    $check($response instanceof AphrontAjaxResponse && $response->getHTTPResponseCode()===200 && governance_native_route_guard_depth()===0,
      'directory-completion-tokenless-native-datasource-real-csrf-guarded-get');
    $preview=$service->previewUnitArchive('dc-depth-9');
    $check($preview['effects']['member']===2 && $preview['effects']['appointment']===1 && !$preview['history_deleted'] &&
      !$preview['native_project_permissions_changed'] && !$preview['restore_supported'],
      'directory-completion-archive-preview-reports-effects-without-revocation-claim');
    $service->updateUnit(array('operation_id'=>'dc-archive','unit_id'=>'dc-depth-9','expected_version'=>4,
      'name'=>'Depth 9','state'=>'archived','parent_id'=>'dc-depth-8'));
    $check($query->getUnit('dc-depth-9')===null,'directory-completion-archive-removes-new-directory-visibility');
    governance_native_refused(fn()=>$service->updateUnit(array('operation_id'=>'dc-restore','unit_id'=>'dc-depth-9',
      'expected_version'=>5,'name'=>'Depth 9','state'=>'active','parent_id'=>'dc-depth-8')),
      'organization_restore_workflow_required',$check,'directory-completion-archive-restore-stays-review-gated');
    $unassigned_candidates=$service->readUnassignedUsers(100);$render_subject=null;
    foreach($unassigned_candidates as $candidate){if($candidate['reason']==='unassigned'){$render_subject=$candidate;break;}}
    if($render_subject===null){throw new RuntimeException('directory_render_unassigned_fixture_missing');}
    $service->createUnit(array('operation_id'=>'dc-inactive-render-create','unit_id'=>'dc-inactive-render','kind'=>'department',
      'name'=>'Synthetic Inactive Render Department','parent_id'=>null));
    $service->assignPrimaryDepartment(array('operation_id'=>'dc-inactive-render-assign','unit_id'=>'dc-inactive-render',
      'user_phid'=>$render_subject['user_phid'],'expected_version'=>$render_subject['primary_department_version']));
    $service->updateUnit(array('operation_id'=>'dc-inactive-render-archive','unit_id'=>'dc-inactive-render','expected_version'=>1,
      'name'=>'Synthetic Inactive Render Department','state'=>'archived','parent_id'=>null));
    foreach(array('dc-preserve-parent'=>'team','dc-preserve-dept'=>'department') as $id=>$kind){
      $service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,'kind'=>$kind,'name'=>$id,'parent_id'=>null));}
    $service->createUnit(array('operation_id'=>'create-dc-preserve-child','unit_id'=>'dc-preserve-child','kind'=>'team',
      'name'=>'Preserve Child','parent_id'=>'dc-preserve-parent'));
    $service->updateUnitMetadata(array('operation_id'=>'dc-preserve-metadata','unit_id'=>'dc-preserve-child','expected_version'=>1,
      'associated_department_id'=>'dc-preserve-dept','contact_user_phid'=>null));
    governance_native_privacy_add($service,'dc-preserve-manager','dc-preserve-child',$user->getPHID(),2);
    governance_native_privacy_seed($fixture,'dc-preserve-child',$user->getPHID());
    foreach(array('dc-preserve-parent','dc-preserve-dept') as $id){$service->updateUnit(array('operation_id'=>'archive-'.$id,
      'unit_id'=>$id,'expected_version'=>1,'name'=>$id,'state'=>'archived','parent_id'=>null));}
    $admin_child=$admin->getUnitForManagement('dc-preserve-child');$manager_child=$query->getUnit('dc-preserve-child');
    $manager_management_denied=false;try{$query->getUnitForManagement('dc-preserve-child');}
    catch(RuntimeException $error){if($error->getMessage()!=='organization_system_admin_required'){throw $error;}$manager_management_denied=true;}
    $check($admin_child['management_references']['parent_id']==='dc-preserve-parent' &&
      $admin_child['management_references']['department_id']==='dc-preserve-dept' &&
      $manager_child['parent_id']===null && $manager_child['department_id']===null && !isset($manager_child['management_references']) &&
      !isset($admin->getUnit('dc-preserve-child')['management_references']) && $manager_management_denied,
      'directory-completion-unavailable-current-references-admin-only-not-manager');
    $csrf=AphrontRequest::getCSRFTokenName();$token=$state['admin']['viewer']->getCSRFToken();
    list($rename_response,$unused)=governance_native_route_response($state['admin'],'POST',array($csrf=>$token,
      'form_action'=>'unit.update','operation_id'=>'dc-preserve-rename','unit_id'=>'dc-preserve-child','unit_version'=>'3',
      'name'=>'Renamed Child','state'=>'active','parent_action'=>'keep'));
    $after_rename=governance_native_concurrency_sql($fixture,'SELECT parentID,version FROM '.$table.' WHERE unitID=?',array('dc-preserve-child'))[0];
    $check($rename_response instanceof AphrontRedirectResponse && $after_rename['parentID']==='dc-preserve-parent' && (int)$after_rename['version']===4,
      'directory-completion-real-form-rename-retains-unavailable-parent-reference');
    list($metadata_response,$unused)=governance_native_route_response($state['admin'],'POST',array($csrf=>$token,
      'form_action'=>'unit.metadata','operation_id'=>'dc-preserve-contact','unit_id'=>'dc-preserve-child','unit_version'=>'4',
      'department_action'=>'keep','contact_action'=>'replace','contact'=>array($state['users']['member']->getPHID())));
    $after_metadata=governance_native_concurrency_sql($fixture,'SELECT associatedDepartmentID,contactUserPHID,version FROM '.$table.' WHERE unitID=?',array('dc-preserve-child'))[0];
    $check($metadata_response instanceof AphrontRedirectResponse && $after_metadata['associatedDepartmentID']==='dc-preserve-dept' &&
      $after_metadata['contactUserPHID']===$state['users']['member']->getPHID() && (int)$after_metadata['version']===5,
      'directory-completion-real-form-contact-edit-retains-unavailable-department-reference');
    list($clear_parent_response,$unused)=governance_native_route_response($state['admin'],'POST',array($csrf=>$token,
      'form_action'=>'unit.update','operation_id'=>'dc-explicit-parent-clear','unit_id'=>'dc-preserve-child','unit_version'=>'5',
      'name'=>'Renamed Child','state'=>'active','parent_action'=>'replace','parent'=>array()));
    list($clear_department_response,$unused)=governance_native_route_response($state['admin'],'POST',array($csrf=>$token,
      'form_action'=>'unit.metadata','operation_id'=>'dc-explicit-department-clear','unit_id'=>'dc-preserve-child','unit_version'=>'6',
      'department_action'=>'replace','department'=>array(),'contact_action'=>'keep'));
    $cleared=governance_native_concurrency_sql($fixture,'SELECT parentID,associatedDepartmentID,version FROM '.$table.' WHERE unitID=?',array('dc-preserve-child'))[0];
    $contact_members=governance_native_concurrency_sql($fixture,'SELECT COUNT(*) n FROM '.$fixture->table('user','organization_member').
      ' WHERE unitID=? AND userPHID=?',array('dc-preserve-child',$state['users']['member']->getPHID()))[0];
    $check($clear_parent_response instanceof AphrontRedirectResponse && $clear_department_response instanceof AphrontRedirectResponse &&
      $cleared['parentID']===null && $cleared['associatedDepartmentID']===null && (int)$cleared['version']===7 && (int)$contact_members['n']===0,
      'directory-completion-real-form-explicit-clear-does-not-add-contact-membership');
    $old_inline=(new ReflectionProperty(PhabricatorUser::class,'allowInlineCacheGeneration'))->getValue($state['admin']['viewer']);
    $old_locale=PhabricatorEnv::getLocaleCode();
    $old_translator=PhutilTranslator::getInstance();
    // Exact native HTTP bootstrap hook (PhabricatorController::willBeginExecution):
    // allow real cache reads/generation, never attach synthetic cache values.
    $state['sessions']->willServeRequestForUser($state['admin']['viewer']);
    $translator=(new PhutilTranslator())->setLocale(new PhabricatorChineseLocale())
      ->setTranslations((new PhabricatorChineseTranslation())->getTranslationMap());
    try{
      PhutilTranslator::setInstance($translator);$_SERVER['REQUEST_METHOD']='GET';
      $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];
      $application_configuration=(new AphrontApplicationConfiguration())->setHost('fixture.invalid')->setPath('/people/organization/');
      $page_request=$application_configuration->buildRequest()->setUser($state['admin']['viewer'])->setRequestData(array('unit_id'=>'dc-preserve-child'));
      $application_configuration->setRequest($page_request);
      $page_controller=(new PhorgeOrganizationController())->setRequest($page_request)->setCurrentApplication(new PhabricatorPeopleApplication());
      $html=governance_native_with_request_write_guard($page_request,static function() use ($page_controller,$page_request) {
        $page=$page_controller->handleRequest($page_request);
        if(!($page instanceof PhabricatorStandardPageView)){throw new RuntimeException('directory_render_page_unavailable');}
        return (string)hsprintf('%s',$page);
      });
      // Native Celerity serializes behavior JSON in HTML-escaped data
      // attributes. Decode the attribute as DOM.getAttribute() does in the
      // real Javelin initializer, then inspect the actual tokenizer configs.
      preg_match_all('/data-javelin-init-data="([^"]*)"/',$html,$behavior_attributes);
      $directory_mode=false;$people_mode=false;$safe_modes=array();
      foreach($behavior_attributes[1]as$attribute){
        $behavior_data=phutil_json_decode(html_entity_decode($attribute,ENT_QUOTES,'UTF-8'));
        foreach(idx($behavior_data,'aphront-basic-tokenizer',array())as$config){
          $src=idx($config,'src');$mode=idx($config,'normalizerMode');
          if($src==='/people/organization/typeahead/'){$directory_mode=$directory_mode||$mode==='literal-casefold-v1';
            $safe_modes[]=array('source'=>'organization','mode'=>$mode);}
          if(is_string($src)&&strpos($src,'/typeahead/class/PhabricatorPeopleDatasource/')===0){
            $people_mode=$people_mode||$mode==='default';$safe_modes[]=array('source'=>'people','mode'=>$mode);}
        }
      }
      // Only names and modes: never persist HTML, CSRF/session data or URLs.
      $metadata_file=tempnam(sys_get_temp_dir(),'governance-directory-tokenizer-');
      if($metadata_file===false){throw new RuntimeException('directory_metadata_capture_failed');}
      chmod($metadata_file,0600);file_put_contents($metadata_file,phutil_json_encode($safe_modes));
      $check($directory_mode && $people_mode &&
        (new PhorgeOrganizationDatasource())->getTokenizerNormalizerMode()==='literal-casefold-v1' &&
        (new PhabricatorPeopleDatasource())->getTokenizerNormalizerMode()==='default',
        'directory-completion-real-html-sends-directory-literal-normalizer-with-people-default');
      $check(strpos($html,pht('Unassigned'))!==false && pht('Unassigned')!=='Unassigned' && strpos($html,'>unassigned<')===false,
        'directory-completion-real-chinese-page-localizes-unassigned-status');
      $check(strpos($html,pht('Inactive Department'))!==false && pht('Inactive Department')!=='Inactive Department' &&
        strpos($html,'>inactive_department<')===false && governance_native_route_guard_depth()===0,
        'directory-completion-real-chinese-page-localizes-inactive-department-status');
    }finally{$state['admin']['viewer']->setAllowInlineCacheGeneration($old_inline);
      PhabricatorEnv::setLocaleCode($old_locale);PhutilTranslator::setInstance($old_translator);}
    $member=queryfx_one((new PhabricatorUser())->establishConnection('w'),
      'SELECT m.*,u.version unitVersion FROM organization_member m JOIN organization_unit u ON u.unitID=m.unitID WHERE m.unitID=%s ORDER BY m.userPHID LIMIT 1','dc-page-000');
    $zone=new DateTimeZone($state['admin']['viewer']->getTimezoneIdentifier());
    $date=new DateTime('+10 days',$zone);$date->setTime(14,30,0);$expected=(int)$date->format('U');
    list($date_response,$unused)=governance_native_route_response($state['admin'],'POST',array($csrf=>$token,
      'form_action'=>'member.update','operation_id'=>'dc-form-finite-period','unit_id'=>'dc-page-000',
      'user_phid'=>$member['userPHID'],'unit_version'=>$member['unitVersion'],'member_version'=>$member['version'],
      'appointment_version'=>0,'valid_from'=>$member['validFrom'],'until_e'=>'1','until_d'=>$date->format('m/d/Y'),'until_t'=>'14:30'));
    $period=queryfx_one((new PhabricatorUser())->establishConnection('w'),
      'SELECT validUntil,version FROM organization_member WHERE unitID=%s AND userPHID=%s','dc-page-000',$member['userPHID']);
    $check($date_response instanceof AphrontRedirectResponse && (int)$period['validUntil']===$expected &&
      (int)$period['version']===(int)$member['version']+1,
      'directory-completion-real-csrf-finite-date-form-persists-native-timestamp');
    $primary=queryfx_one((new PhabricatorUser())->establishConnection('w'),
      'SELECT version FROM organization_primarydepartment WHERE userPHID=%s',$render_subject['user_phid']);
    list($clear_inactive,$unused)=governance_native_route_response($state['admin'],'POST',array($csrf=>$token,
      'form_action'=>'department.clear','operation_id'=>'dc-form-inactive-clear','user_phid'=>$render_subject['user_phid'],
      'primary_version'=>$primary['version']));
    $cleared_primary=queryfx_one((new PhabricatorUser())->establishConnection('w'),
      'SELECT unitID,version FROM organization_primarydepartment WHERE userPHID=%s',$render_subject['user_phid']);
    $check(strpos($html,'value="department.clear"')!==false && $clear_inactive instanceof AphrontRedirectResponse &&
      $cleared_primary['unitID']===null && (int)$cleared_primary['version']===(int)$primary['version']+1,
      'directory-completion-inactive-attention-has-real-audited-clear-form');
    list($reassign,$unused)=governance_native_route_response($state['admin'],'POST',array($csrf=>$token,
      'form_action'=>'department.assign','operation_id'=>'dc-form-inactive-reassign','user_phid'=>$render_subject['user_phid'],
      'primary_version'=>$cleared_primary['version'],'department'=>array('dc-dept')));
    $assigned=queryfx_one((new PhabricatorUser())->establishConnection('w'),
      'SELECT unitID,version FROM organization_primarydepartment WHERE userPHID=%s',$render_subject['user_phid']);
    $check($reassign instanceof AphrontRedirectResponse && $assigned['unitID']==='dc-dept' &&
      (int)$assigned['version']===(int)$cleared_primary['version']+1,
      'directory-completion-cleared-inactive-department-can-explicitly-reassign');
    foreach(array('dc-selector-literal'=>'Engineering-Team_%','dc-selector-decoy'=>'EngineeringXTeamXA')as$id=>$name){
      $service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,'kind'=>'team','name'=>$name,'parent_id'=>null));}
    $selector=function($session,$q,$raw) {
      $_SERVER['REQUEST_METHOD']='GET';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];
      $request=(new AphrontRequest('fixture.invalid','/people/organization/typeahead/'))
        ->setUser($session['viewer'])->setRequestData(array('q'=>$q,'raw'=>$raw));
      $controller=(new PhorgeOrganizationDatasourceController())->setRequest($request)->setCurrentApplication(new PhabricatorPeopleApplication());
      $response=governance_native_with_request_write_guard($request,static function()use($controller,$request){return $controller->handleRequest($request);});
      if(!($response instanceof AphrontAjaxResponse)||$response->getHTTPResponseCode()!==200){throw new RuntimeException('directory_selector_response_refused');}
      return array_column((new ReflectionProperty(AphrontAjaxResponse::class,'content'))->getValue($response),2);
    };
    $check($selector($state['admin'],'engineering team','Engineering-Team_%')===array('dc-selector-literal'),
      'directory-completion-selector-native-normalized-q-preserves-raw-uppercase-punctuation');
    $matches=$selector($state['admin'],'engineer','engineer');sort($matches);
    $check($matches===array('dc-selector-decoy','dc-selector-literal'),
      'directory-completion-selector-lowercase-partial-matches-uppercase-native-name');
    $check($selector($state['admin'],'engineering team','engineering-Team_%')===array('dc-selector-literal'),
      'directory-completion-selector-percent-underscore-remain-literal-not-wildcards');
    $check($selector($manager,'engineering team','Engineering-Team_%')===array() &&
      governance_native_route_guard_depth()===0,
      'directory-completion-selector-case-folding-does-not-disclose-hidden-units');
    return array('native_hierarchy_and_metadata'=>true,'native_datasource'=>true,'schema_migration_test_environment_only'=>true,
      'native_project_permissions_changed'=>false,'audit_cleanup_enabled'=>false,'handover_implemented'=>false);
  }finally{$_COOKIE=$saved_cookie;$_SERVER=$saved_server;}
}
