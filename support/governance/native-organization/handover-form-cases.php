<?php

/** Draft actual native forms/HTML, not wire/browser evidence. */
function governance_native_handover_form_cases($fixture,callable $check,array $state){
  $fixture->assertOwned();$saved_cookie=$_COOKIE;$saved_server=$_SERVER;$conn=(new PhabricatorUser())->establishConnection('w');
  $make=function($suffix,$admin)use($fixture){$u=(new PhabricatorUser())->setUsername('hf'.$suffix.substr($fixture->getNamespace(),-9))
    ->setRealName('Synthetic Handover Form '.$suffix)->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin($admin?1:0)->save();
    (new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();return$u;};
  $route=function($session,$method,array$data,$render=false)use($state){
    $_SERVER['REQUEST_METHOD']=$method;$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$session['token'];
    $viewer=$session['viewer'];$inline=new ReflectionProperty(PhabricatorUser::class,'allowInlineCacheGeneration');$old_inline=$inline->getValue($viewer);
    $celerity=new ReflectionProperty(CelerityAPI::class,'response');$old_celerity=$celerity->getValue();$celerity->setValue(null,null);
    try{
      $state['sessions']->willServeRequestForUser($viewer);
      $configuration=(new AphrontApplicationConfiguration())->setHost('fixture.invalid')->setPath('/people/organization/transfer/');
      $request=$configuration->buildRequest()->setUser($viewer)->setRequestData($data);$configuration->setRequest($request);
      $controller=(new PhorgeOrganizationTransferController())->setRequest($request)->setCurrentApplication(new PhabricatorPeopleApplication());
      return governance_native_with_request_write_guard($request,static function()use($request,$controller,$render){
        $response=$controller->handleRequest($request);return array($response,$render?(string)hsprintf('%s',$response):null);
      });
    }finally{$inline->setValue($viewer,$old_inline);$celerity->setValue(null,$old_celerity);}
  };
  $hidden=function($html){$values=array();preg_match_all('/<input\b([^>]*)>/',$html,$tags);
    foreach($tags[1]as$tag){if(preg_match('/\bname="([^"]*)"/',$tag,$name)&&preg_match('/\bvalue="([^"]*)"/',$tag,$value)){
      $name=html_entity_decode($name[1],ENT_QUOTES,'UTF-8');
      if(in_array($name,array('form_action','lifecycle_action','request','ttl_seconds','deadline_offset'),true)){$values[$name]=html_entity_decode($value[1],ENT_QUOTES,'UTF-8');}}}
    return$values;};
  try{
    $requester_user=$make('requester',true);$requester=governance_native_request($requester_user,$state['sessions']);
    $reviewer=governance_native_request($make('reviewer',true),$state['sessions']);$subject=$make('subject',false);$owner=$make('owner',false);
    $ordinary=governance_native_request($subject,$state['sessions']);PhorgeOrganizationService::newForActor($ordinary['context'])->readActorCapabilities();
    $service=PhorgeOrganizationService::newForActor($requester['context']);
    foreach(array('handover-form-from','handover-form-to')as$id){$service->createUnit(array('operation_id'=>'create-'.$id,'unit_id'=>$id,'kind'=>'department','name'=>$id,'parent_id'=>null));}
    $service->assignPrimaryDepartment(array('operation_id'=>'handover-form-primary','user_phid'=>$subject->getPHID(),'unit_id'=>'handover-form-from','expected_version'=>0));
    $flag=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.organization.enabled',false);
    try{list($off,$unused)=$route($requester,'GET',array());$check($off instanceof Aphront404Response,'native-handover-management-route-default-off-unavailable');}finally{unset($flag);}
    list($denied,$unused)=$route($ordinary,'GET',array());
    $check($denied instanceof AphrontJSONResponse&&$denied->getHTTPResponseCode()===403,'native-handover-management-ordinary-member-cannot-read-admin-forms-or-ticket-identifiers');
    $translator=PhutilTranslator::getInstance();$locale=PhabricatorEnv::getLocaleCode();
    try{PhutilTranslator::setInstance((new PhutilTranslator())->setLocale(new PhabricatorChineseLocale())->setTranslations((new PhabricatorChineseTranslation())->getTranslationMap()));
      list($page,$html)=$route($requester,'GET',array(),true);
      $check($page instanceof PhabricatorStandardPageView&&strpos($html,pht('Organization Transfers'))!==false&&
        pht('Organization Transfers')!=='Organization Transfers'&&strpos($html,'literal-casefold-v1')!==false&&
        strpos(html_entity_decode($html,ENT_QUOTES,'UTF-8'),'kind=department')!==false&&governance_native_route_guard_depth()===0,
        'native-handover-management-real-chinese-html-and-department-tokenizer-config-render');
    }finally{PhutilTranslator::setInstance($translator);PhabricatorEnv::setLocaleCode($locale);}
    $csrf=AphrontRequest::getCSRFTokenName();$input=array('form_action'=>'transfer.preview','subject'=>array($subject->getPHID()),
      'responsible'=>array($owner->getPHID()),'department'=>array('handover-form-to'),'handover_days'=>'14','reason'=>'Actual native forms transfer',
      'ttl_seconds'=>'300','acknowledge_no_project_permission_change'=>'1');
    $before=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R',new PhorgeOrganizationTransfer())['n'];
    list($bad,$unused)=$route($requester,'POST',$input);
    $check($bad instanceof AphrontJSONResponse&&$bad->getHTTPResponseCode()===403&&
      (int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R',new PhorgeOrganizationTransfer())['n']===$before,
      'native-handover-preview-form-without-real-csrf-cannot-create-ticket');
    $input[$csrf]=$requester['viewer']->getCSRFToken();$invalid=$input;$invalid['handover_days']='not-a-number';
    list($bad,$unused)=$route($requester,'POST',$invalid);
    $check($bad instanceof AphrontJSONResponse&&$bad->getHTTPResponseCode()===403,
      'native-handover-form-nonnumeric-calendar-day-cannot-silently-become-immediate-transfer');
    list($preview,$preview_html)=$route($requester,'POST',$input,true);$proposal=$hidden($preview_html);
    $intent=isset($proposal['request'])?phutil_json_decode($proposal['request']):null;
    $check(is_array($intent)&&$intent['subject_phid']===$subject->getPHID()&&$intent['primary_version']===1&&
      $intent['from_department']==='handover-form-from'&&$intent['to_department']==='handover-form-to'&&
      $proposal['form_action']==='transfer.propose'&&!isset($intent['approved'])&&!isset($intent['actor']),
      'native-handover-actual-preview-form-binds-server-derived-primary-and-unit-versions-without-authority');
    $proposal[$csrf]=$requester['viewer']->getCSRFToken();list($redirect,$unused)=$route($requester,'POST',$proposal);
    $check($redirect instanceof AphrontRedirectResponse&&queryfx_one($conn,'SELECT state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id'])['state']==='submitted',
      'native-handover-actual-preview-confirmation-form-persists-pending-ticket');
    list($review_page,$review_html)=$route($reviewer,'GET',array('family'=>'transfer','ticket'=>$intent['transfer_id']),true);
    $detail=$service->readTransfer($intent['transfer_id']);
    $check($review_page instanceof PhabricatorStandardPageView&&strpos($review_html,'ticket.approve')!==false&&
      strpos($review_html,'credentialDigest')===false&&strpos($review_html,'bindingJSON')===false,
      'native-handover-independent-review-page-renders-ticket-details-without-private-source-snapshots');
    $approve=array('form_action'=>'ticket.approve','family'=>'transfer','ticket'=>$intent['transfer_id'],'request_digest'=>$detail['request_digest']);
    list($bad,$unused)=$route($reviewer,'POST',$approve);$approve[$csrf]=$reviewer['viewer']->getCSRFToken();list($ok,$unused)=$route($reviewer,'POST',$approve);
    $check($bad instanceof AphrontJSONResponse&&$bad->getHTTPResponseCode()===403&&$ok instanceof AphrontRedirectResponse&&
      queryfx_one($conn,'SELECT approverPHID,state FROM %R WHERE BINARY transferID=BINARY %s',new PhorgeOrganizationTransfer(),$intent['transfer_id'])['approverPHID']===$reviewer['viewer']->getPHID(),
      'native-handover-independent-approval-form-requires-real-csrf-and-distinct-current-admin');
    $execute=array('form_action'=>'ticket.execute','family'=>'transfer','ticket'=>$intent['transfer_id'],$csrf=>$requester['viewer']->getCSRFToken());
    list($bad,$unused)=$route($requester,'POST',$execute);$execute['acknowledge_no_project_permission_change']='1';list($ok,$unused)=$route($requester,'POST',$execute);
    $check($bad instanceof AphrontJSONResponse&&$bad->getHTTPResponseCode()===403&&$ok instanceof AphrontRedirectResponse&&
      queryfx_one($conn,'SELECT unitID FROM organization_primarydepartment WHERE BINARY userPHID=BINARY %s',$subject->getPHID())['unitID']==='handover-form-to',
      'native-handover-bound-executor-form-requires-explicit-ack-and-switches-primary-once');
    $audits=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_audit WHERE BINARY operationID=BINARY %s',$intent['operation_id'])['n'];
    list($retry,$unused)=$route($requester,'POST',$execute);
    $check($retry instanceof AphrontRedirectResponse&&
      (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_audit WHERE BINARY operationID=BINARY %s',$intent['operation_id'])['n']===$audits,
      'native-handover-consumed-execution-form-retry-uses-ledger-without-reconsumption');
    $disabled=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true);
    (new PhabricatorUserTransactionEditor())->setActor($state['users']['admin'])->setContentSource(PhabricatorContentSource::newForSource('console',array()))->applyTransactions($requester_user,array($disabled));
    list($blocked,$unused)=$route($requester,'POST',$execute);
    $check($blocked instanceof AphrontJSONResponse&&$blocked->getHTTPResponseCode()===403&&
      (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_audit WHERE BINARY operationID=BINARY %s',$intent['operation_id'])['n']===$audits,
      'native-handover-current-disabled-executor-cannot-replay-consumed-form');
    return array('native_html_forms'=>true,'two_current_native_admins'=>true,'actual_request_csrf'=>true,'committed_form_retry'=>true,
      'disabled_current_executor_refused'=>true,'wire_or_browser_proof'=>false,'production_activation'=>false);
  }finally{$_COOKIE=$saved_cookie;$_SERVER=$saved_server;}
}
