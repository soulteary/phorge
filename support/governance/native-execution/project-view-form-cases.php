<?php

/** Native request/controller/forms and editor POST. No network/browser proof. */
function governance_project_view_form_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$a=$state['executor'];$b=$state['approver'];
  $saved_server=$_SERVER;$saved_cookie=$_COOKIE;$saved_post=$_POST;
  $saved_translator=PhutilTranslator::getInstance();$saved_locale=PhabricatorEnv::getLocaleCode();
  $preferences=PhabricatorUserPreferences::loadUserPreferences($a['viewer']);
  $prior_translation=$preferences->getPreference(PhabricatorTranslationSetting::SETTINGKEY);
  $cache_property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$saved_cache=$cache_property->getValue();
  $celerity_property=new ReflectionProperty(CelerityAPI::class,'response');$old_celerity=$celerity_property->getValue();
  $conn=(new PhabricatorProject())->establishConnection('w');
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try{$project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic form policy target')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($a['viewer']->getPHID())
    ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($off);}
  $project->attachParentProject(null)->attachMemberPHIDs(array());
  $call=function(array $session,$method,array $data,$render=false,$after_bootstrap=null)use($cache_property,$celerity_property){
    $_SERVER['REQUEST_METHOD']=$method;$_SERVER['HTTPS']='on';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];
    $_SERVER['HTTP_COOKIE']=PhabricatorCookies::COOKIE_SESSION.'='.$session['token'];
    unset($_SERVER['HTTP_X_PHABRICATOR_CSRF'],$_SERVER['HTTP_CONTENT_TYPE']);
    $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';$_POST=$method==='POST'?$data:array();
    $_SERVER['QUERY_STRING']=$method==='GET'?http_build_query($data):'';
    $cache_property->setValue(null,null);$celerity_property->setValue(null,null);
    $configuration=(new AphrontApplicationConfiguration())->setHost('fixture.invalid')->setPath('/project/approval/');
    $request=$configuration->buildRequest()->setRequestData($data);
    $configuration->setRequest($request);
    $controller=(new PhorgeProjectPolicyApprovalController())->setRequest($request)->setCurrentApplication(new PhabricatorProjectApplication());
    return governance_native_with_request_write_guard($request,function()use($controller,$request,$render,$after_bootstrap){
      $response=$controller->willBeginExecution();
      if($response===null){if($after_bootstrap!==null){$after_bootstrap($request);}$response=$controller->handleRequest($request);}
      $html=null;if($render){$html=(string)hsprintf('%s',$response);}
      return array($response,$request,$html);
    });
  };
  $input_value=function($html,$name){$values=array();preg_match_all('/<input\b[^>]*>/',$html,$tags);
    foreach($tags[0]as$tag){if(strpos($tag,'name="'.$name.'"')!==false&&preg_match('/\bvalue="([^"]*)"/',$tag,$match)){
      $values[]=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');}}
    if(count($values)!==1){throw new RuntimeException('project_form_exact_input_required');}return$values[0];};
  $fingerprint=function()use($conn,$project){return array(
    queryfx_one($conn,'SELECT viewPolicy,editPolicy,joinPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID()),
    queryfx_all($conn,'SELECT approvalID,state,bindingDigest FROM governance_projectapproval ORDER BY approvalID'),
    queryfx_all($conn,'SELECT id,approvalID,action FROM governance_executionaudit ORDER BY id'));
  };
  $form=function($operation,$policy)use($project,$a){return array('form'=>'policy','form_action'=>'submit',
    'operation'=>$operation,'target_phid'=>$project->getPHID(),'new_policy'=>$policy,'reason'=>'Synthetic native form proposal',
    'ttl_seconds'=>'120','__csrf__'=>$a['viewer']->getCSRFToken());};
  $propose=function(array$data)use($call,$a,$conn){
    $before=queryfx_all($conn,'SELECT approvalID FROM governance_projectapproval');
    list($response)=$call($a,'POST',$data);
    if(!($response instanceof AphrontDialogView)){throw new RuntimeException('project_form_proposal_response_required');}
    $after=queryfx_all($conn,'SELECT approvalID FROM governance_projectapproval');
    $new=array_values(array_diff(ipull($after,'approvalID'),ipull($before,'approvalID')));
    if(count($new)!==1){throw new RuntimeException('project_form_exact_new_proposal_required');}
    return$new[0];
  };
  $editor=function($ticket,$policy)use($a,$project){
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$a['token'];
    $_SERVER['HTTP_COOKIE']=PhabricatorCookies::COOKIE_SESSION.'='.$a['token'];
    $request=(new AphrontRequest('fixture.invalid','/project/edit/'.$project->getID().'/'))->setUser($a['viewer'])
      ->setRequestData(array('__csrf__'=>$a['viewer']->getCSRFToken(),'governanceApprovalID'=>$ticket));
    return governance_native_with_request_write_guard($request,function()use($request,$a,$project,$policy){
      return(new PhabricatorProjectTransactionEditor())->setActor($a['viewer'])->setRequest($request)
        ->setContentSource($request->newContentSource())->setContinueOnMissingFields(true)
        ->applyTransactions($project,array((new PhabricatorProjectTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue($policy)));
    });
  };
  $set_admin=function($admin)use($fixture,$a){
    $statement=$fixture->getAdmin()->prepare('UPDATE '.$fixture->table('user','user').' SET isAdmin=? WHERE BINARY phid=BINARY ?');
    if(!$statement){throw new RuntimeException('project_form_fixture_role_prepare_failed');}
    try{if($statement->execute(array($admin,$a['viewer']->getPHID()))!==true||$statement->affected_rows!==1){
      throw new RuntimeException('project_form_fixture_role_update_failed');}}
    finally{$statement->close();}
  };
  try{
    // Seed a real owned native language preference. The actual session
    // bootstrap must load it and the native module translation map.
    $preferences->setPreference(PhabricatorTranslationSetting::SETTINGKEY,'zh_CN')->save();
    PhabricatorUserCache::clearCache(PhabricatorUserPreferencesCacheType::KEY_PREFERENCES,$a['viewer']->getPHID());
    PhutilTranslator::setInstance(new PhutilTranslator());
    // Ensure a real locale transition even when an earlier fixture request
    // happened to leave the ambient locale at zh_CN.
    PhabricatorEnv::setLocaleCode('en_US');
    $before=$fingerprint();$depth=governance_native_route_guard_depth();
    $disabled=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
    try{list($disabled_response)=$call($a,'GET',array('form'=>'policy'));}finally{unset($disabled);}
    list($blank,,$html)=$call($a,'GET',array('form'=>'policy'),true);
    $check($disabled_response instanceof Aphront404Response&&$blank instanceof AphrontDialogView&&strpos($html,'name="operation"')!==false&&
      strpos($html,'name="projects')!==false&&strpos($html,'要修改的策略')!==false&&strpos($html,$project->getName())===false&&
      $fingerprint()===$before&&governance_native_route_guard_depth()===$depth,
      'project-view-form-blank-native-controller-has-project-selector-and-no-business-write');
    list($view,,$view_html)=$call($a,'POST',array('form'=>'policy','form_action'=>'preview','operation'=>'view',
      'projects'=>array($project->getPHID()),'__csrf__'=>$a['viewer']->getCSRFToken()),true);
    list($edit,,$edit_html)=$call($a,'POST',array('form'=>'policy','form_action'=>'preview','operation'=>'edit',
      'projects'=>array($project->getPHID()),'__csrf__'=>$a['viewer']->getCSRFToken()),true);
    list($read_only)=$call($b,'POST',array('form'=>'policy','form_action'=>'preview','operation'=>'view',
      'projects'=>array($project->getPHID()),'__csrf__'=>$b['viewer']->getCSRFToken()));
    $check($read_only instanceof AphrontJSONResponse&&$read_only->getHTTPResponseCode()===403&&
      $view instanceof AphrontDialogView&&$edit instanceof AphrontDialogView&&
      strpos($view_html,'value="view"')!==false&&strpos($edit_html,'value="edit"')!==false&&
      $input_value($view_html,'new_policy')===PhabricatorPolicies::POLICY_USER&&
      $input_value($edit_html,'new_policy')===$a['viewer']->getPHID()&&$fingerprint()===$before,
      'project-view-form-preview-selects-corresponding-current-policy-without-persisting');
    $private_name='Synthetic admin-only form option';
    $named=(new PhorgeNamedPolicy())->setName($private_name)->setEffectivePolicy(PhabricatorPolicies::POLICY_USER)
      ->setViewPolicy(PhabricatorPolicies::POLICY_ADMIN)->setEditPolicy(PhabricatorPolicies::POLICY_ADMIN)->save();
    // A real DB source change after native request bootstrap creates the stale
    // admin viewer. This is a fixture seam, not a native role-writer proof.
    $set_admin(1);$loaded_admin=false;$stale_options_would_include=false;
    try{
      list($stale_response,,$stale_html)=$call($a,'POST',array('form'=>'policy','form_action'=>'preview','operation'=>'view',
        'projects'=>array($project->getPHID()),'__csrf__'=>$a['viewer']->getCSRFToken()),true,
        function($request)use($set_admin,&$loaded_admin,&$stale_options_would_include,$project,$named,$private_name){
          $loaded_admin=(bool)$request->getUser()->getIsAdmin();
          $options=(new PhabricatorPolicyQuery())->setViewer($request->getUser())->setObject($project)->execute();
          foreach($options as$option){if($option->getPHID()===$named->getPHID()&&$option->getName()===$private_name){$stale_options_would_include=true;}}
          $set_admin(0);});
    }finally{
      $row=queryfx_one((new PhabricatorUser())->establishConnection('w'),'SELECT isAdmin FROM %R WHERE phid=%s',new PhabricatorUser(),$a['viewer']->getPHID());
      if((string)$row['isAdmin']==='1'){$set_admin(0);}
    }
    $check($loaded_admin&&$stale_options_would_include&&$stale_response instanceof AphrontDialogView&&strpos($stale_html,$private_name)===false&&$fingerprint()===$before,
      'project-view-form-policy-options-use-current-scope-actor-after-real-role-source-change');
    $json_call=function(array$session,$action,array$payload)use($call){
      list($response)=$call($session,'POST',array('action'=>$action,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),
        '__csrf__'=>$session['viewer']->getCSRFToken()));
      if(!($response instanceof AphrontJSONResponse)||$response->getHTTPResponseCode()!==200){throw new RuntimeException('project_form_json_compatibility_failed');}
      $body=json_decode($response->setAddJSONShield(false)->buildResponseString(),true,16,JSON_THROW_ON_ERROR);
      if(!is_array($body['result']??null)){throw new RuntimeException('project_form_json_result_required');}return$body['result'];
    };
    $json_before=$fingerprint();
    list($json_without_csrf)=$call($a,'POST',array('action'=>'submit','payload'=>json_encode(array(
      'target_phid'=>$project->getPHID(),'new_policy'=>PhabricatorPolicies::POLICY_USER,
      'reason'=>'Synthetic missing native JSON CSRF','ttl_seconds'=>120),JSON_THROW_ON_ERROR)));
    $json_csrf_refused=$json_without_csrf instanceof AphrontJSONResponse&&$json_without_csrf->getHTTPResponseCode()===403&&$fingerprint()===$json_before;
    $json_edit=$json_call($a,'submit',array('target_phid'=>$project->getPHID(),'new_policy'=>PhabricatorPolicies::POLICY_USER,
      'reason'=>'Synthetic existing JSON edit proposal','ttl_seconds'=>120));
    $json_call($b,'approve',array('approval_id'=>$json_edit['approval_id']));
    $json_edit_history=$json_call($b,'history',array('approval_id'=>$json_edit['approval_id']));
    $check($json_csrf_refused&&$json_edit_history['operation']==='project.edit-policy'&&$json_edit_history['state']==='approved'&&
      $json_edit_history['new_policy']===PhabricatorPolicies::POLICY_USER,
      'project-view-form-existing-json-edit-proposal-approve-and-admin-history-compatible');
    $json_view=$json_call($a,'submit',array('target_phid'=>$project->getPHID(),
      'intent'=>array('version'=>3,'operation'=>'project.view-policy','policy'=>$a['viewer']->getPHID()),
      'reason'=>'Synthetic existing JSON view proposal','ttl_seconds'=>120));
    $json_call($b,'approve',array('approval_id'=>$json_view['approval_id']));
    $json_view_history=$json_call($b,'history',array('approval_id'=>$json_view['approval_id']));
    $check($json_view_history['operation']==='project.view-policy'&&$json_view_history['state']==='approved'&&
      $json_view_history['new_policy']===$a['viewer']->getPHID(),
      'project-view-form-existing-json-view-proposal-approve-and-admin-history-compatible');
    $before=$fingerprint();
    list($denied_history)=$call($a,'POST',array('action'=>'history','payload'=>json_encode(array('approval_id'=>$json_view['approval_id']),JSON_THROW_ON_ERROR),
      '__csrf__'=>$a['viewer']->getCSRFToken()));
    $check($denied_history instanceof AphrontJSONResponse&&$denied_history->getHTTPResponseCode()===403&&$fingerprint()===$before,
      'project-view-form-existing-json-history-still-denies-nonadmin-current-editor');
    $data=$form('view',$a['viewer']->getPHID());unset($data['__csrf__']);
    list($response)=$call($a,'POST',$data);
    $check($response instanceof AphrontJSONResponse&&$response->getHTTPResponseCode()===403&&$fingerprint()===$before,
      'project-view-form-native-post-without-csrf-refused-without-write');
    $data=$form('join',$a['viewer']->getPHID());list($response)=$call($a,'POST',$data);
    $check($response instanceof AphrontJSONResponse&&$response->getHTTPResponseCode()===403&&$fingerprint()===$before,
      'project-view-form-unknown-operation-refused-without-write');
    $data=$form('view',$a['viewer']->getPHID());$data['payload']='{}';list($response)=$call($a,'POST',$data);
    $check($response instanceof AphrontJSONResponse&&$response->getHTTPResponseCode()===403&&$fingerprint()===$before,
      'project-view-form-mixed-json-and-form-payload-refused-without-write');
    $data=$form('edit',PhabricatorPolicies::POLICY_USER);unset($data['operation']);$legacy=$propose($data);
    $intent=PhorgeProjectPolicyApprovalStore::intent($legacy);
    $check($intent['intent']->getOperation()==='project.edit-policy',
      'project-view-form-absent-operation-keeps-legacy-edit-policy-meaning');
    PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$legacy));
    $before=$fingerprint();$refused=false;
    try{$editor($legacy,$a['viewer']->getPHID());}catch(RuntimeException$error){
      if($error->getMessage()!=='project_execution_ticket_binding_changed'){throw$error;}$refused=true;}
    $check($refused&&$fingerprint()===$before,'project-view-form-edit-ticket-cannot-authorize-native-view-post');
    $noone=$propose($form('view',PhabricatorPolicies::POLICY_NOONE));
    PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$noone));
    $before=$fingerprint();$refused=false;
    try{$editor($noone,PhabricatorPolicies::POLICY_NOONE);}catch(PhabricatorApplicationTransactionValidationException$error){
      $types=array_values(array_unique(mpull($error->getErrors(),'getType')));
      if($types!==array(PhabricatorTransactions::TYPE_VIEW_POLICY)){throw$error;}$refused=true;}
    $check($refused&&$fingerprint()===$before,'project-view-form-approved-noone-still-refused-by-native-self-view-validator');
    $ticket=$propose($form('view',$a['viewer']->getPHID()));$intent=PhorgeProjectPolicyApprovalStore::intent($ticket);
    $check($intent['intent']->isViewPolicy()&&$intent['intent']->getOperation()==='project.view-policy',
      'project-view-form-submits-server-typed-view-intent');
    PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$ticket));
    $applied=$editor($ticket,$a['viewer']->getPHID());
    $row=queryfx_one($conn,'SELECT viewPolicy,editPolicy,joinPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
    $stored=queryfx_one($conn,'SELECT state,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$ticket);
    $check(count($applied)===1&&$row['viewPolicy']===$a['viewer']->getPHID()&&$row['editPolicy']===$a['viewer']->getPHID()&&
      $row['joinPolicy']===PhabricatorPolicies::POLICY_USER&&$stored['state']==='consumed'&&
      $stored['nativeGroupID']===$applied[0]->getTransactionGroupID()&&!$conn->isInsideTransaction(),
      'project-view-form-approved-native-web-post-commits-view-ticket-and-native-record');
    return array('actual_native_controller_form'=>true,'actual_chinese_form_render'=>true,'policy_options_use_current_actor'=>true,'actual_native_post_csrf'=>true,'server_typed_view_intent'=>true,
      'legacy_edit_meaning_retained'=>true,'existing_json_controller_compatible'=>true,'admin_history_gate_retained'=>true,'native_self_view_validator_retained'=>true,'actual_http_server'=>false,'browser_proved'=>false);
  }finally{
    try{
      if($prior_translation===null){$preferences->unsetPreference(PhabricatorTranslationSetting::SETTINGKEY);}
      else{$preferences->setPreference(PhabricatorTranslationSetting::SETTINGKEY,$prior_translation);}
      $preferences->save();
      PhabricatorUserCache::clearCache(PhabricatorUserPreferencesCacheType::KEY_PREFERENCES,$a['viewer']->getPHID());
    }finally{
      try{PhabricatorEnv::setLocaleCode($saved_locale);}finally{PhutilTranslator::setInstance($saved_translator);
        $_SERVER=$saved_server;$_COOKIE=$saved_cookie;$_POST=$saved_post;
        $cache_property->setValue(null,$saved_cache);$celerity_property->setValue(null,$old_celerity);}
    }
  }
}
