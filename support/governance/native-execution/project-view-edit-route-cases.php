<?php

/** Browser's initial textarea LF rule, applied after entity decoding only. */
function governance_project_edit_textarea_value($encoded) {
  $value=html_entity_decode($encoded,ENT_QUOTES,'UTF-8');
  return str_starts_with($value,"\n")?substr($value,1):$value;
}

/** Actual platform route/bootstrap/default edit form; no HTTP/browser claim. */
function governance_project_view_edit_route_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$a=$state['executor'];$b=$state['approver'];
  $server=$_SERVER;$cookies=$_COOKIE;$post=$_POST;$locale=PhabricatorEnv::getLocaleCode();$translator=PhutilTranslator::getInstance();
  $cache=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$saved_cache=$cache->getValue();
  $celerity=new ReflectionProperty(CelerityAPI::class,'response');$saved_celerity=$celerity->getValue();
  $conn=(new PhabricatorProject())->establishConnection('w');
  $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try{$project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic default edit form target')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($a['viewer']->getPHID())
    ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($off);}
  $project->attachParentProject(null)->attachMemberPHIDs(array());
  $uri='/project/edit/'.$project->getID().'/';
  $attributes=function($tag){$result=array();preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:]*)\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27)/',$tag,$matches,PREG_SET_ORDER);
    foreach($matches as$match){$result[strtolower($match[1])]=html_entity_decode($match[2]!==''?$match[2]:($match[3]??''),ENT_QUOTES,'UTF-8');}return$result;};
  $extract=function($html,$expected_uri=null)use($attributes,$uri){
    if($expected_uri===null){$expected_uri=$uri;}
    if(!is_string($html)||strlen($html)>1048576){throw new RuntimeException('project_edit_form_html_budget');}
    preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is',$html,$forms,PREG_SET_ORDER);$found=array();
    foreach($forms as$form){if(strpos($form[2],'name="editEngine"')!==false){$found[]=$form;}}
    if(count($found)!==1){throw new RuntimeException('project_edit_form_exact_form_required');}
    $form=head($found);$form_attributes=$attributes($form[1]);$action=new PhutilURI($form_attributes['action']??'');
    if($action->getPath()!==$expected_uri||strtoupper($form_attributes['method']??'')!=='POST'){
      throw new RuntimeException('project_edit_form_action_refused');}
    $pairs=array();$append=function($name,$value)use(&$pairs){$pairs[]=rawurlencode($name).'='.rawurlencode($value);};
    preg_match_all('/<input\b[^>]*>/is',$form[2],$inputs);
    foreach($inputs[0]as$input){$attr=$attributes($input);$type=strtolower($attr['type']??'text');
      if(!isset($attr['name'])||preg_match('/\bdisabled(?:\s|=|>)/i',$input)||in_array($type,array('button','submit','reset','file'),true)){continue;}
      if(in_array($type,array('checkbox','radio'),true)&&!preg_match('/\bchecked(?:\s|=|>)/i',$input)){continue;}
      $append($attr['name'],$attr['value']??'');}
    preg_match_all('/<textarea\b([^>]*)>(.*?)<\/textarea>/is',$form[2],$areas,PREG_SET_ORDER);
    foreach($areas as$area){$attr=$attributes($area[1]);if(isset($attr['name'])&&!preg_match('/\bdisabled(?:\s|=|$)/i',$area[1])){
      $append($attr['name'],governance_project_edit_textarea_value($area[2]));}}
    preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/is',$form[2],$selects,PREG_SET_ORDER);
    foreach($selects as$select){$attr=$attributes($select[1]);if(!isset($attr['name'])||preg_match('/\bdisabled(?:\s|=|$)/i',$select[1])){continue;}
      preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/is',$select[2],$options,PREG_SET_ORDER);$selected=array();$first=null;
      foreach($options as$option){if(preg_match('/\bdisabled(?:\s|=|$)/i',$option[1])){continue;}$value=$attributes($option[1])['value']??strip_tags($option[2]);
        if($first===null){$first=$value;}if(preg_match('/\bselected(?:\s|=|$)/i',$option[1])){$selected[]=$value;}}
      if(!$selected&&$first!==null){$selected=array($first);}foreach($selected as$value){$append($attr['name'],$value);}}
    return(new PhutilQueryStringParser())->parseQueryString(implode('&',$pairs));
  };
  $call=function($path,$method,array$data,$render=false)use($a,$cache,$celerity){
    $base=new PhutilURI(PhabricatorEnv::getEnvConfig('phabricator.base-uri'));
    if(!$base->getDomain()){throw new RuntimeException('project_edit_form_native_base_required');}
    $_SERVER['REQUEST_METHOD']=$method;$_SERVER['HTTPS']=$base->getProtocol()==='https'?'on':'off';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';unset($_SERVER['HTTP_CONTENT_TYPE'],$_SERVER['HTTP_X_PHABRICATOR_CSRF']);
    $_COOKIE=array(PhabricatorCookies::COOKIE_SESSION=>$a['token']);$_SERVER['HTTP_COOKIE']=PhabricatorCookies::COOKIE_SESSION.'='.$a['token'];
    $_POST=$method==='POST'?$data:array();$_SERVER['QUERY_STRING']=$method==='GET'?http_build_query($data):'';
    $cache->setValue(null,null);$celerity->setValue(null,null);
    $configuration=(new AphrontApplicationConfiguration())->setHost($base->getDomain())->setPath($path);$request=$configuration->buildRequest();
    $configuration->setRequest($request);$site=(new PhabricatorPlatformSite())->newSiteForRequest($request);
    if(!$site){throw new RuntimeException('project_edit_form_platform_site_required');}$matched=null;
    foreach($site->getRoutingMaps()as$map){$matched=$map->routePath($path);if($matched){break;}}
    if(!$matched){throw new RuntimeException('project_edit_form_platform_route_required');}
    $request->setURIMap($matched->getURIData());$controller=$matched->getController()->setRequest($request);
    $expected=$path==='/project/approval/'?PhorgeProjectPolicyApprovalController::class:PhabricatorProjectEditController::class;
    if(get_class($controller)!==$expected){throw new RuntimeException('project_edit_form_exact_controller_required');}
    return governance_native_with_request_write_guard($request,function()use($controller,$request,$render){
      $response=$controller->willBeginExecution();if($response===null){$response=$controller->handleRequest($request);}
      return array($response,$render?(string)hsprintf('%s',$response):null);
    });
  };
  $fingerprint=function()use($conn,$project){return array(
    queryfx_one($conn,'SELECT * FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID()),
    queryfx_all($conn,'SELECT * FROM %R WHERE objectPHID=%s ORDER BY id',new PhabricatorProjectTransaction(),$project->getPHID()),
    queryfx_all($conn,'SELECT approvalID,state,version,bindingDigest,nativeGroupID FROM governance_projectapproval ORDER BY approvalID'),
    queryfx_all($conn,'SELECT id,approvalID,action FROM governance_executionaudit ORDER BY id'));
  };
  $submit=function($policy,$operation='view',$target=null)use($call,$conn,$a,$b,$project){
    if($target===null){$target=$project;}
    $before=ipull(queryfx_all($conn,'SELECT approvalID FROM governance_projectapproval'),'approvalID');
    list($response)=$call('/project/approval/','POST',array('form'=>'policy','form_action'=>'submit','operation'=>$operation,
      'target_phid'=>$target->getPHID(),'new_policy'=>$policy,'reason'=>'Synthetic actual default form transition','ttl_seconds'=>'120',
      AphrontRequest::getCSRFTokenName()=>$a['viewer']->getCSRFToken()));
    if(!($response instanceof AphrontDialogView)){throw new RuntimeException('project_edit_form_real_proposal_failed');}
    $new=array_values(array_diff(ipull(queryfx_all($conn,'SELECT approvalID FROM governance_projectapproval'),'approvalID'),$before));
    if(count($new)!==1){throw new RuntimeException('project_edit_form_exact_ticket_required');}
    PhorgeProjectPolicyApprovalService::call($b['context'],'approve',array('approval_id'=>$new[0]));return$new[0];
  };
  try{
    PhutilTranslator::setInstance(clone $translator);
    list($page,$html)=$call($uri,'GET',array(),true);$body=$extract($html);
    $check($page instanceof PhabricatorStandardPageView&&isset($body['editEngine'],$body[AphrontRequest::TYPE_FORM],$body['governanceApprovalID'])&&
      $body['policy.view']===PhabricatorPolicies::POLICY_USER&&$body['policy.edit']===$a['viewer']->getPHID()&&$body['name']===$project->getName(),
      'project-view-default-edit-controller-real-route-get-renders-native-fields-and-ticket-input');
    $bad_json=$body;$bad_json['payload']='{';$bad_json['action']='history';
    list($bad_response)=$call('/project/approval/','POST',$bad_json);
    $bad_content=$bad_response instanceof AphrontJSONResponse?
      phutil_json_decode($bad_response->setAddJSONShield(false)->buildResponseString()):null;
    $check($bad_response instanceof AphrontJSONResponse&&$bad_response->getHTTPResponseCode()===400&&
      $bad_content['error']==='project_approval_invalid_input',
      'project-policy-errors-real-controller-input-json-is-400');
    $missing_json=$body;$missing_json['payload']=phutil_json_encode(array('approval_id'=>str_repeat('f',64)));
    $missing_json['action']='history';$missing_before=$fingerprint();
    list($missing_response)=$call('/project/approval/','POST',$missing_json);
    $missing_content=$missing_response instanceof AphrontJSONResponse?
      phutil_json_decode($missing_response->setAddJSONShield(false)->buildResponseString()):null;
    $check($missing_response instanceof AphrontJSONResponse&&$missing_response->getHTTPResponseCode()===403&&
      $missing_content['error']==='project_approval_refused'&&$missing_before===$fingerprint(),
      'project-policy-errors-real-controller-missing-ticket-is-generic-403-without-write');
    $ticket=$submit($a['viewer']->getPHID());$before=$fingerprint();
    $saved_binding=queryfx_one($conn,'SELECT bindingJSON FROM governance_projectapproval WHERE approvalID=%s',$ticket)['bindingJSON'];
    queryfx($conn,'UPDATE governance_projectapproval SET bindingJSON=%s WHERE approvalID=%s','{',$ticket);
    $stored_json=$body;$stored_json['payload']=phutil_json_encode(array('approval_id'=>$ticket));$stored_json['action']='history';
    $stored_failed=false;
    try { $call('/project/approval/','POST',$stored_json); }
    catch (JsonException $error) { $stored_failed=get_class($error)===JsonException::class; }
    finally { queryfx($conn,'UPDATE governance_projectapproval SET bindingJSON=%s WHERE approvalID=%s',$saved_binding,$ticket); }
    $check($stored_failed&&$before===$fingerprint(),
      'project-policy-errors-real-controller-stored-json-corruption-propagates-backend-error');
    $missing_form=$body;$missing_form['policy.view']=$a['viewer']->getPHID();unset($missing_form['governanceApprovalID']);
    list($missing_page,$missing_html)=$call($uri,'POST',$missing_form,true);
    $check($missing_page instanceof PhabricatorStandardPageView&&
      strpos(html_entity_decode(strip_tags($missing_html),ENT_QUOTES,'UTF-8'),pht('Enter a valid approval ticket.'))!==false&&
      $before===$fingerprint(),'project-policy-errors-real-native-edit-missing-ticket-renders-validation-without-write');
    $without=$body;unset($without[AphrontRequest::getCSRFTokenName()]);$without['policy.view']=$a['viewer']->getPHID();$without['governanceApprovalID']=$ticket;
    $refused=false;try{$call($uri,'POST',$without);}catch(AphrontMalformedRequestException$error){$refused=true;}
    $check($refused&&$before===$fingerprint(),'project-view-default-edit-controller-real-post-without-csrf-refused-without-write');
    $mixed=$body;$mixed['policy.view']=$a['viewer']->getPHID();$mixed['governanceApprovalID']=$ticket;$mixed['name']='Synthetic second actual mutation';
    list($mixed_page,$mixed_html)=$call($uri,'POST',$mixed,true);
    $check($mixed_page instanceof PhabricatorStandardPageView&&
      strpos(html_entity_decode(strip_tags($mixed_html),ENT_QUOTES,'UTF-8'),
        pht('Save other project changes first, then create a new policy proposal.'))!==false&&
      $before===$fingerprint(),'project-view-default-edit-controller-second-actual-change-refuses-whole-transition');
    $wrong=$submit(PhabricatorPolicies::POLICY_USER,'edit');$before=$fingerprint();
    $wrong_body=$body;$wrong_body['policy.view']=$a['viewer']->getPHID();$wrong_body['governanceApprovalID']=$wrong;
    list($wrong_page,$wrong_html)=$call($uri,'POST',$wrong_body,true);
    $check($wrong_page instanceof PhabricatorStandardPageView&&
      strpos(html_entity_decode(strip_tags($wrong_html),ENT_QUOTES,'UTF-8'),
        pht('A current approved ticket matching this change is required.'))!==false&&
      strpos($wrong_html,'project_execution_ticket_binding_changed')===false&&$before===$fingerprint(),
      'project-view-default-edit-controller-edit-ticket-cannot-authorize-view-form');
    $noone=$submit(PhabricatorPolicies::POLICY_NOONE);$before=$fingerprint();
    $invalid=$body;$invalid['policy.view']=PhabricatorPolicies::POLICY_NOONE;$invalid['governanceApprovalID']=$noone;
    list($invalid_page,$invalid_html)=$call($uri,'POST',$invalid,true);
    $message=pht('The %s policy of this object would no longer allow you to %s the object.','view','view');
    $check($invalid_page instanceof PhabricatorStandardPageView&&strpos(html_entity_decode(strip_tags($invalid_html),ENT_QUOTES,'UTF-8'),$message)!==false&&
      $before===$fingerprint(),'project-view-default-edit-controller-noone-still-renders-native-self-view-validation');
    // Refresh the actual native GET after the rejected validation response.
    list(,$fresh_html)=$call($uri,'GET',array(),true);$good=$extract($fresh_html);
    $good['policy.view']=$a['viewer']->getPHID();$good['governanceApprovalID']=$ticket;
    list($response)=$call($uri,'POST',$good);
    $row=queryfx_one($conn,'SELECT viewPolicy,editPolicy,joinPolicy FROM %R WHERE id=%d',new PhabricatorProject(),$project->getID());
    $stored=queryfx_one($conn,'SELECT state,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$ticket);
    $records=queryfx_all($conn,'SELECT transactionType,metadata FROM %R WHERE objectPHID=%s ORDER BY id',new PhabricatorProjectTransaction(),$project->getPHID());
    $check($response instanceof AphrontRedirectResponse&&$row['viewPolicy']===$a['viewer']->getPHID()&&
      $row['editPolicy']===$a['viewer']->getPHID()&&$row['joinPolicy']===PhabricatorPolicies::POLICY_USER&&
      $stored['state']==='consumed'&&is_string($stored['nativeGroupID'])&&count($records)===1&&$records[0]['transactionType']===PhabricatorTransactions::TYPE_VIEW_POLICY,
      'project-view-default-edit-controller-get-to-post-native-view-record-ticket-commit');
    // Continue through the same actual native GET/POST form, without a ticket,
    // for ordinary content and native no-effect submission.
    list(,$content_html)=$call($uri,'GET',array(),true);$content=$extract($content_html);
    $content_before=$fingerprint();$content['name']='Synthetic native content-only edit';
    list($content_response)=$call($uri,'POST',$content);
    $content_after=$fingerprint();
    $check($content_response instanceof AphrontRedirectResponse&&
      $content_after[0]['name']==='Synthetic native content-only edit'&&
      $content_after[0]['viewPolicy']===$content_before[0]['viewPolicy']&&
      $content_after[0]['editPolicy']===$content_before[0]['editPolicy']&&
      $content_after[0]['joinPolicy']===$content_before[0]['joinPolicy']&&
      $content_after[2]===$content_before[2]&&$content_after[3]===$content_before[3],
      'project-view-default-edit-controller-native-content-only-post-needs-no-policy-ticket');
    list(,$empty_html)=$call($uri,'GET',array(),true);$empty=$extract($empty_html);$empty_before=$fingerprint();
    list($empty_response)=$call($uri,'POST',$empty);
    $check($empty_response instanceof AphrontRedirectResponse&&$fingerprint()===$empty_before,
      'project-view-default-edit-controller-empty-submit-retains-native-no-effect');
    $join=$empty;$join['policy.join']=PhabricatorPolicies::POLICY_ADMIN;
    $join['core.default']='1';$join['governanceNativeForm']='1';$join_refused=false;
    try{$call($uri,'POST',$join);}catch(RuntimeException$error){
      if($error->getMessage()!=='project_execution_native_form_multiple_changes'){throw$error;}$join_refused=true;}
    $check($join_refused&&$fingerprint()===$empty_before,
      'project-view-default-edit-controller-join-change-and-forged-default-flags-refused-before-effects');
    // Exercise an explicit nonempty description change, not a parser-created
    // newline or an assumed NULL-to-empty native transition.
    $setup=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
    try{$raw_project=PhabricatorProject::initializeNewProject($a['viewer'])->setName('Synthetic explicit description form target')
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($a['viewer']->getPHID())
      ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)->save();}finally{unset($setup);}
    $raw_uri='/project/edit/'.$raw_project->getID().'/';
    $read_description=function()use($raw_project,$a){
      $fresh=(new PhabricatorProjectQuery())->setViewer($a['viewer'])->withPHIDs(array($raw_project->getPHID()))->executeOne();
      $fields=PhabricatorCustomField::getObjectFields($fresh,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS);
      $fields->setViewer($a['viewer'])->readFieldsFromStorage($fresh);
      $field=PhabricatorCustomField::getObjectField($fresh,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS,'std:project:internal:description');
      if(!$field){throw new RuntimeException('project_policy_error_description_field_required');}
      return $field->getOldValueForApplicationTransactions();
    };
    $description_before=$read_description();$description_value='Synthetic separately saved description';
    $description_key='std:project:internal:description';
    list(,$raw_html)=$call($raw_uri,'GET',array(),true);$raw_body=$extract($raw_html,$raw_uri);
    if(!array_key_exists($description_key,$raw_body)){throw new RuntimeException('project_policy_error_native_description_input_required');}
    $old_ticket=$submit($a['viewer']->getPHID(),'view',$raw_project);
    $raw_before=queryfx_one($conn,'SELECT * FROM %R WHERE id=%d',new PhabricatorProject(),$raw_project->getID());
    $audits_before=queryfx_all($conn,'SELECT * FROM governance_executionaudit ORDER BY id');
    $raw_policy=$raw_body;$raw_policy['policy.view']=$a['viewer']->getPHID();$raw_policy['governanceApprovalID']=$old_ticket;$raw_policy[$description_key]=$description_value;
    list($raw_error,$raw_error_html)=$call($raw_uri,'POST',$raw_policy,true);
    $old_row=queryfx_one($conn,'SELECT state,nativeGroupID FROM governance_projectapproval WHERE approvalID=%s',$old_ticket);
    $check($raw_error instanceof PhabricatorStandardPageView&&
      strpos(html_entity_decode(strip_tags($raw_error_html),ENT_QUOTES,'UTF-8'),
        pht('Save other project changes first, then create a new policy proposal.'))!==false&&
      $read_description()===$description_before&&$old_row['state']==='approved'&&$old_row['nativeGroupID']===null&&
      $raw_before===queryfx_one($conn,'SELECT * FROM %R WHERE id=%d',new PhabricatorProject(),$raw_project->getID())&&
      $audits_before===queryfx_all($conn,'SELECT * FROM governance_executionaudit ORDER BY id')&&
      queryfx_all($conn,'SELECT id FROM %R WHERE objectPHID=%s',new PhabricatorProjectTransaction(),$raw_project->getPHID())===array(),
      'project-policy-errors-explicit-description-extra-effect-refuses-actionably-without-consumption');
    list(,$raw_fresh_html)=$call($raw_uri,'GET',array(),true);$raw_save=$extract($raw_fresh_html,$raw_uri);$raw_save[$description_key]=$description_value;
    list($raw_saved)=$call($raw_uri,'POST',$raw_save);
    $old_policy=$raw_save;$old_policy['policy.view']=$a['viewer']->getPHID();$old_policy['governanceApprovalID']=$old_ticket;
    list($stale_page,$stale_html)=$call($raw_uri,'POST',$old_policy,true);
    $check($raw_saved instanceof AphrontRedirectResponse&&$read_description()===$description_value&&
      $stale_page instanceof PhabricatorStandardPageView&&
      strpos(html_entity_decode(strip_tags($stale_html),ENT_QUOTES,'UTF-8'),
        pht('A current approved ticket matching this change is required.'))!==false&&
      queryfx_one($conn,'SELECT state FROM governance_projectapproval WHERE approvalID=%s',$old_ticket)['state']==='approved',
      'project-policy-errors-native-separate-content-save-persists-description-and-old-proposal-stays-stale');
    $fresh_ticket=$submit($a['viewer']->getPHID(),'view',$raw_project);
    list(,$ready_html)=$call($raw_uri,'GET',array(),true);$ready=$extract($ready_html,$raw_uri);
    $ready['policy.view']=$a['viewer']->getPHID();$ready['governanceApprovalID']=$fresh_ticket;
    list($ready_result)=$call($raw_uri,'POST',$ready);
    $ready_records=queryfx_all($conn,'SELECT transactionType FROM %R WHERE objectPHID=%s ORDER BY id',new PhabricatorProjectTransaction(),$raw_project->getPHID());
    $check($ready_result instanceof AphrontRedirectResponse&&$read_description()===$description_value&&count($ready_records)===2&&
      $ready_records[0]['transactionType']===PhabricatorTransactions::TYPE_CUSTOMFIELD&&
      $ready_records[1]['transactionType']===PhabricatorTransactions::TYPE_VIEW_POLICY&&
      queryfx_one($conn,'SELECT state FROM governance_projectapproval WHERE approvalID=%s',$fresh_ticket)['state']==='consumed',
      'project-policy-errors-fresh-proposal-after-separate-content-save-executes-only-approved-policy');
    $proofs=(new ReflectionProperty(PhorgeProjectExecutionCatalog::class,'nativeForms'))->getValue();
    $check(($proofs===null||count($proofs)===0)&&!$conn->isInsideTransaction()&&!$conn->isReadLocking()&&!$conn->isWriteLocking(),
      'project-view-default-edit-controller-provenance-and-native-locks-released');
    return array('actual_platform_routing'=>true,'actual_default_edit_controller_get_post'=>true,
      'native_field_generation_and_filtering_retained'=>true,'single_effect_after_native_filter'=>true,
      'caller_default_flag_authority'=>false,'native_content_only_post_retained'=>true,
      'native_no_effect_post_retained'=>true,'unsupported_join_post_refused_early'=>true,
      'actual_http_server'=>false,'browser_proved'=>false);
  }finally{
    try{PhabricatorEnv::setLocaleCode($locale);}finally{PhutilTranslator::setInstance($translator);$_SERVER=$server;$_COOKIE=$cookies;$_POST=$post;
      $cache->setValue(null,$saved_cache);$celerity->setValue(null,$saved_celerity);}
  }
}
