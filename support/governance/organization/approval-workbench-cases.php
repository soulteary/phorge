<?php

/** Owned native session/controller cases; not an HTTP server/browser receipt. */
function governance_approval_workbench_route(array $auth,$method,array $data) {
  $_SERVER['REQUEST_METHOD']=$method;$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$auth['token'];
  $request=(new AphrontRequest('fixture.invalid','/people/organization/approvals/'))
    ->setUser($auth['viewer'])->setRequestData($data);
  $controller=(new PhorgeOrganizationApprovalController())->setRequest($request)
    ->setCurrentApplication(new PhabricatorPeopleApplication());
  // Native willBeginExecution performs this lifecycle before serving a viewer.
  (new PhabricatorAuthSessionEngine())->willServeRequestForUser($auth['viewer']);
  return governance_native_with_request_write_guard($request,static fn()=>$controller->handleRequest($request));
}

/** Failure-only bounded projection, never messages/arguments/identities/HTML. */
function governance_approval_workbench_page_kind($value) {
  if($value instanceof PhabricatorStandardPageView){return 'standard-page';}
  if($value instanceof AphrontDialogView){return 'dialog';}
  if($value instanceof AphrontRedirectResponse){return 'redirect';}
  return is_object($value)?'other-object':'non-object';
}
function governance_approval_workbench_probe(callable $call) {
  try{$call();return array('completed'=>true,'error_class'=>'none','source_file'=>'none','source_line'=>0);}
  catch(Throwable$error){
    $class=get_class($error);if(!in_array($class,array('Error','TypeError','RuntimeException','InvalidArgumentException','Exception','PhutilInvalidStateException'),true)){$class='other';}
    $file=basename($error->getFile());if(!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,95}\.php\z/D',$file)){$file='other';}
    $line=$error->getLine();if(!is_int($line)||$line<1||$line>10000000){$line=0;}
    return array('completed'=>false,'error_class'=>$class,'source_file'=>$file,'source_line'=>$line);
  }
}
function governance_approval_workbench_get_projection(array $value) {
  $keys=array_keys($value);sort($keys);$expected=array('detail_kind','detail_standard','fingerprint_equal','list_kind','list_standard','may_approve_false','may_execute_false','probes');sort($expected);
  if($keys!==$expected){throw new RuntimeException('workbench_GET_projection_refused');}
  foreach(array('detail_standard','fingerprint_equal','list_standard','may_approve_false','may_execute_false')as$key){if(!is_bool($value[$key])){throw new RuntimeException('workbench_GET_projection_refused');}}
  foreach(array('list_kind','detail_kind')as$key){if(!in_array($value[$key],array('standard-page','dialog','redirect','other-object','non-object'),true)){throw new RuntimeException('workbench_GET_projection_refused');}}
  if(!is_array($value['probes'])||array_keys($value['probes'])!==array('people-labels','detail-render')){throw new RuntimeException('workbench_GET_projection_refused');}
  foreach($value['probes']as$probe){if(!is_array($probe)||array_keys($probe)!==array('completed','error_class','source_file','source_line')||!is_bool($probe['completed'])||!in_array($probe['error_class'],array('none','Error','TypeError','RuntimeException','InvalidArgumentException','Exception','PhutilInvalidStateException','other'),true)||(!in_array($probe['source_file'],array('none','other'),true)&&(!is_string($probe['source_file'])||!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,95}\.php\z/D',$probe['source_file'])))||!is_int($probe['source_line'])||$probe['source_line']<0||$probe['source_line']>10000000){throw new RuntimeException('workbench_GET_projection_refused');}}
  return $value;
}

/** Restore every fixture global even if another restoration fails. */
function governance_approval_workbench_restore(array $actions,?Throwable $primary) {
  $errors=array();foreach($actions as$name=>$action){try{$action();}catch(Throwable$error){$errors[$name]=$error;}}
  if($errors){if($primary!==null){$errors=array('primary'=>$primary)+$errors;}throw new PhutilAggregateException('workbench_fixture_restoration_failed',$errors);}
}

/** Independent owned setup reads, complete fixed-schema rows, private hash only. */
function governance_approval_workbench_fingerprint($fixture) {
  $fixture->assertOwned();$db=$fixture->getAdmin();
  $spec=array(
    'unit'=>array('id',array('id','unitID','kind','state','name','parentID','version','visibilityModel','visibilityVersion','associatedDepartmentID','contactUserPHID'),array()),
    'member'=>array('id',array('id','unitID','userPHID','state','validFrom','validUntil','version'),array()),
    'appointment'=>array('id',array('id','unitID','userPHID','role','state','validFrom','validUntil','version'),array()),
    'operation'=>array('operationID',array('operationID','actorPHID','requestDigest','resultJSON','createdAt'),array('resultJSON'=>false)),
    'audit'=>array('id',array('id','operationID','actorPHID','action','unitID','subjectPHID','beforeJSON','afterJSON','createdAt'),array('beforeJSON'=>false,'afterJSON'=>false)),
    'approval'=>array('approvalID',array('approvalID','operationID','requestDigest','bindingJSON','state','approverPHID','approverVersion','approverDigest','approverBindingJSON','approvedAt','consumedAt','consumedByOperation','version','sourceParticipantVersion','sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID'),array('bindingJSON'=>false,'approverBindingJSON'=>true)));
  $plans=array();$total=0;
  foreach($spec as$table=>$definition){list($key,$columns,$payloads)=$definition;$names=array('`'.$key.'` rowKey');
    foreach($payloads as$column=>$nullable){$names[]='OCTET_LENGTH(`'.$column.'`) `'.$column.'Bytes`';}
    $result=$db->query('SELECT '.implode(',',$names).' FROM '.$fixture->table('user','organization_'.$table).' FORCE INDEX (PRIMARY) ORDER BY `'.$key.'` LIMIT 65');
    if($result===false){throw new RuntimeException('approval_workbench_fingerprint_header_unknown');}
    $rows=$result->fetch_all(MYSQLI_ASSOC);if(count($rows)>64){throw new RuntimeException('approval_workbench_fingerprint_rows_exceeded');}
    $seen=array();$plan=array();
    foreach($rows as$row){
      $headerKeys=array_keys($row);sort($headerKeys);$expectedHeaders=array('rowKey');foreach($payloads as$column=>$nullable){$expectedHeaders[]=$column.'Bytes';}sort($expectedHeaders);
      if($headerKeys!==$expectedHeaders){throw new RuntimeException('approval_workbench_fingerprint_header_shape_unknown');}
      if(!array_key_exists('rowKey',$row)||(!is_string($row['rowKey'])&&!is_int($row['rowKey']))||isset($seen[(string)$row['rowKey']])){throw new RuntimeException('approval_workbench_fingerprint_identity_unknown');}
      $id=(string)$row['rowKey'];
      if($key==='id'){if(!preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)){throw new RuntimeException('approval_workbench_fingerprint_identity_unknown');}}
      elseif(!PhorgeGovernanceOrganizationRequest::identifier($id)){throw new RuntimeException('approval_workbench_fingerprint_identity_unknown');}
      $seen[$id]=true;$lengths=array();$bytes=4096; // Fixed non-LONGTEXT columns, independently schema-pinned.
      foreach($payloads as$column=>$nullable){$n=$row[$column.'Bytes']??null;
        if($n===null&&$nullable){$lengths[$column]=null;continue;}
        if((!is_int($n)&&!is_string($n))||!preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D',(string)$n)||(int)$n>32768){throw new RuntimeException('approval_workbench_fingerprint_payload_exceeded');}
        $lengths[$column]=(int)$n;$bytes+=(int)$n;
      }
      $total+=$bytes;if($total>2097152){throw new RuntimeException('approval_workbench_fingerprint_budget_exceeded');}
      $plan[]=array('id'=>$id,'lengths'=>$lengths);
    }
    $plans[$table]=$plan;
  }
  // No full row is read until all six tables' payload plans fit the budget.
  $snapshot=array();$actualBytes=0;
  foreach($spec as$table=>$definition){list($key,$columns,$payloads)=$definition;$rows=array();
    foreach($plans[$table]as$item){
      $selected=implode(',',array_map(static fn($name)=>'`'.$name.'`',$columns));
      $statement=$db->prepare('SELECT '.$selected.' FROM '.$fixture->table('user','organization_'.$table).' FORCE INDEX (PRIMARY) WHERE `'.$key.'`=? LIMIT 2');
      if($statement===false){throw new RuntimeException('approval_workbench_fingerprint_read_unknown');}
      try{$id=$item['id'];$statement->bind_param('s',$id);$statement->execute();$result=$statement->get_result();$values=$result->fetch_all(MYSQLI_ASSOC);}
      finally{$statement->close();}
      if(count($values)!==1){throw new RuntimeException('approval_workbench_fingerprint_row_changed');}$row=$values[0];$keys=array_keys($row);sort($keys);$expected=$columns;sort($expected);
      if($keys!==$expected||(!is_string($row[$key])&&!is_int($row[$key]))||(string)$row[$key]!==$item['id']){throw new RuntimeException('approval_workbench_fingerprint_row_changed');}
      $scalarBytes=0;foreach($row as$name=>$value){
        if($value!==null&&!is_string($value)&&!is_int($value)){throw new RuntimeException('approval_workbench_fingerprint_value_unknown');}
        if(isset($payloads[$name])||array_key_exists($name,$payloads)){
          if(($value===null?null:strlen((string)$value))!==$item['lengths'][$name]){throw new RuntimeException('approval_workbench_fingerprint_payload_changed');}
        }else{$scalarBytes+=strlen((string)$value);}
      }
      if($scalarBytes>4096){throw new RuntimeException('approval_workbench_fingerprint_scalar_budget_exceeded');}
      ksort($row,SORT_STRING);$rows[]=$row;$actualBytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));
      if($actualBytes>2097152){throw new RuntimeException('approval_workbench_fingerprint_budget_exceeded');}
    }
    // Canonical full-row order does not depend on numeric-vs-string client IDs.
    usort($rows,static fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
    $snapshot[$table]=$rows;
  }
  return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
}

/** Distinguish a real original-impact review from the generic error dialog. */
function governance_approval_workbench_review_matches($response,array $intent,PhabricatorUser $viewer) {
  if(get_class($response)!==AphrontDialogView::class||$response->getTitle()!==pht('Review Compound Membership Change')){return false;}
  $hidden=(new ReflectionProperty(AphrontDialogView::class,'hidden'))->getValue($response);
  if($hidden!==array(array('approval',$intent['approval_id']),array('form_action','approve'))||
      (new ReflectionProperty(AphrontDialogView::class,'submitButton'))->getValue($response)!==pht('Approve Original Request')){return false;}
  $action=match($intent['action']){'member.add-and-appoint'=>pht('Add Membership and Administrator Appointment'),'appointment.revoke-and-end'=>pht('Revoke Appointment and End Membership'),default=>throw new RuntimeException('approval_workbench_review_action_unknown')};
  $date=static fn($value)=>$value===null?pht('Unchanged / No End Date'):phabricator_datetime($value,$viewer);
  $text=array(pht('Team: %s; user: %s; change: %s',$intent['team'],$intent['target_user'],$action),
    pht('Membership: %s to %s; appointment: %s to %s',$date($intent['member_from']),$date($intent['member_until']),$date($intent['appointment_from']),$date($intent['appointment_until'])));
  $children=(new ReflectionProperty(AphrontView::class,'children'))->getValue($response);
  if(count($children)!==3){return false;}
  foreach($text as$i=>$value){if(get_class($children[$i])!==PhutilSafeHTML::class||
      $children[$i]->getHTMLContent()!==phutil_tag('p',array('class'=>'aphront-dialog-view-paragraph'),$value)->getHTMLContent()){return false;}}
  return true;
}

function governance_approval_workbench_approved_setup($response,array $detail,$id,$approver) {
  if(get_class($response)!==AphrontRedirectResponse::class||$detail['approval_id']!==$id||$detail['state']!=='approved'||$detail['approver']!==$approver){
    throw new RuntimeException('approval_workbench_stale_case_approval_not_proved');
  }
}

function governance_approval_workbench_focused($fixture,callable $check) {
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)){throw new RuntimeException('approval_workbench_source_unknown');}
  $fixture->assertOwned();require_once dirname(__DIR__).'/native-organization/native-cases.php';
  require_once dirname(__DIR__).'/native-organization/route-cases.php';
  require_once dirname(__DIR__).'/native-organization/workers.php';
  require_once dirname(__DIR__).'/recovery/project-source-focused.php';
  $conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()||$conn->isReadLocking()||$conn->isWriteLocking()){
    throw new RuntimeException('approval_workbench_outer_state_refused');
  }
  $stack=PhabricatorEnv::getConfigSourceStack();
  if(get_class($stack)!==PhabricatorConfigStackSource::class||count($stack->getStack())!==2||
      get_class($stack->getStack()[0])!==PhabricatorConfigDictionarySource::class){
    throw new RuntimeException('approval_workbench_config_scope_refused');
  }
  $source=$stack->getStack()[0];$values=(new ReflectionProperty(PhabricatorConfigDictionarySource::class,'dictionary'))->getValue($source);
  $cache=(new ReflectionProperty(PhabricatorEnv::class,'cache'))->getValue();$cookies=$_COOKIE;$server=$_SERVER;$primary=null;
  $locale=PhabricatorEnv::getLocaleCode();$translator=PhutilTranslator::getInstance();
  $switch=static function($auth){$_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$auth['token'];};
  $fingerprint=static fn()=>governance_approval_workbench_fingerprint($fixture);
  try {
    // Keep native lifecycle locale mutations off the caller's translator object.
    PhutilTranslator::setInstance(clone $translator);
    $sessions=new PhabricatorAuthSessionEngine();$auth=array();$users=array();
    foreach(array('requester'=>true,'reviewer'=>true,'disabled'=>true,'subject'=>false,'ordinary'=>false)as$name=>$admin){
      $u=(new PhabricatorUser())->setUsername('awb'.$name.substr($fixture->getNamespace(),-10))
        ->setRealName('Synthetic approval '.$name)->setIsApproved(1)->setIsAdmin($admin?1:0)->setIsEmailVerified(1)->save();
      (new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
      $users[$name]=$u;$auth[$name]=governance_native_request($u,$sessions);
      // Actual native lifecycle generates/loads persisted preference cache, not a synthetic attachment.
      $viewer=$auth[$name]['viewer'];$sessions->willServeRequestForUser($viewer);
      $viewer->getTimezoneIdentifier();$viewer->getUserSetting(PhabricatorTimeFormatSetting::SETTINGKEY);
      $type=new PhabricatorUserPreferencesCacheType();$key=PhabricatorUserPreferencesCacheType::KEY_PREFERENCES;
      $stored=PhabricatorUserCache::readCaches($type,$key,array($viewer->getPHID()));
      if(!array_key_exists($viewer->getPHID(),$stored)||!is_array($type->getValueFromStorage($stored[$viewer->getPHID()]))){throw new RuntimeException('workbench_native_preferences_cache_unproved');}
    }
    $switch($auth['requester']);$service=PhorgeOrganizationService::newForActor($auth['requester']['context']);
    $team='approval-workbench-team';$service->createUnit(array('operation_id'=>'awb-create','unit_id'=>$team,'kind'=>'team','name'=>'Synthetic approval team','parent_id'=>null));
    // An actual noncompound request makes an empty displayed page with progress.
    $service->proposeApproval(array('approval_id'=>'00-noncompound','operation_id'=>'awb-single','team'=>$team,
      'action'=>'appointment.revoke','target_user'=>$users['subject']->getPHID(),'valid_from'=>null,'valid_until'=>null,'ttl_seconds'=>300));
    $make=static function($id,$team,$version=1)use($users){return array('action'=>'member.add-and-appoint','approval_id'=>$id,
      'operation_id'=>'op-'.$id,'team'=>$team,'target_user'=>$users['subject']->getPHID(),
      'expected_team_version'=>$version,'expected_member_version'=>0,'expected_appointment_version'=>0,
      'impact_acknowledged'=>true,'member_from'=>0,'member_until'=>null,'appointment_from'=>0,'appointment_until'=>null,'ttl_seconds'=>300);};
    $service->proposeCompoundApproval($make('10-grant',$team));
    $page=$service->readApprovalWorkbenchPage(1,null);
    $check($page['rows']===array()&&$page['next_cursor']==='00-noncompound'&&$page['bounded_scanned_rows']===2&&$page['bounded_payload_rows']===1,
      'approval-workbench-empty-compound-page-retains-progress-cursor');
    $next=$service->readApprovalWorkbenchPage(1,$page['next_cursor']);
    $check(count($next['rows'])===1&&$next['rows'][0]['approval_id']==='10-grant'&&$next['next_cursor']===null,
      'approval-workbench-next-page-finds-original-native-compound');
    $before=$fingerprint();$response=governance_approval_workbench_route($auth['requester'],'GET',array());
    $detail_response=governance_approval_workbench_route($auth['requester'],'GET',array('approval'=>'10-grant'));
    $detail=$service->readApprovalWorkbenchDetail('10-grant');
    $after=$fingerprint();$get_ok=$response instanceof PhabricatorStandardPageView&&$detail_response instanceof PhabricatorStandardPageView&&$detail['may_approve']===false&&$detail['may_execute']===false&&$before===$after;
    if(!$get_ok){
      $request=(new AphrontRequest('fixture.invalid','/people/organization/approvals/'))->setUser($auth['requester']['viewer']);
      $observer=(new PhorgeOrganizationApprovalController())->setRequest($request)->setCurrentApplication(new PhabricatorPeopleApplication());
      $GLOBALS['governance_approval_workbench_GET_failure']=array('list_kind'=>governance_approval_workbench_page_kind($response),'detail_kind'=>governance_approval_workbench_page_kind($detail_response),'list_standard'=>$response instanceof PhabricatorStandardPageView,'detail_standard'=>$detail_response instanceof PhabricatorStandardPageView,'may_approve_false'=>$detail['may_approve']===false,'may_execute_false'=>$detail['may_execute']===false,'fingerprint_equal'=>$before===$after,'probes'=>array());
      foreach(array('people-labels'=>array('peopleLabels',$next['rows']),'detail-render'=>array('detail',$detail))as$name=>$probe){
        $GLOBALS['governance_approval_workbench_GET_failure']['probes'][$name]=governance_approval_workbench_probe(static fn()=>governance_native_with_request_write_guard($request,static fn()=>(new ReflectionMethod($observer,$probe[0]))->invoke($observer,$probe[1])));
      }
    }
    $check($get_ok,'approval-workbench-GET-list-and-detail-never-approve-or-consume');
    $post=static function($who,$action,$id,$csrf=true)use($auth){$data=array('approval'=>$id,'form_action'=>$action);
      if($csrf){$data[AphrontRequest::getCSRFTokenName()]=$auth[$who]['viewer']->getCSRFToken();}
      return governance_approval_workbench_route($auth[$who],'POST',$data);};
    $response=$post('reviewer','approve','10-grant',false);
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-missing-CSRF-has-no-approval-side-effect');
    $response=$post('requester','approve','10-grant');
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-native-requester-self-approval-refused');
    $response=governance_approval_workbench_route($auth['ordinary'],'GET',array());
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-ordinary-account-cannot-read-admin-records');
    $disabled=(new PhabricatorUser())->load($users['disabled']->getID());$disabled->setIsDisabled(1)->save();
    $response=governance_approval_workbench_route($auth['disabled'],'GET',array());
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-disabled-real-native-admin-session-refused');
    $response=$post('reviewer','review','10-grant');
    $check(governance_approval_workbench_review_matches($response,$make('10-grant',$team),$auth['reviewer']['viewer'])&&$before===$fingerprint(),'approval-workbench-original-impact-review-is-read-only');
    $response=$post('reviewer','approve','10-grant');
    $switch($auth['reviewer']);$reviewer=PhorgeOrganizationService::newForActor($auth['reviewer']['context']);$detail=$reviewer->readApprovalWorkbenchDetail('10-grant');
    $check($response instanceof AphrontRedirectResponse&&$detail['state']==='approved'&&$detail['approver']===$users['reviewer']->getPHID()&&$detail['may_execute']===false,
      'approval-workbench-independent-current-native-admin-approves-original-binding');
    $before=$fingerprint();$response=$post('reviewer','execute','10-grant');
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-nonexecutor-cannot-consume-approved-request');
    $fault='`'.$fixture->getNamespace().'_user`.`approval_workbench_audit_fault`';
    $fault_attempted=false;$fault_primary=null;$before=$fingerprint();
    try {
      $fixture->assertOwned();$fault_attempted=true;
      governance_native_setup_query($fixture,'CREATE TRIGGER '.$fault.' BEFORE INSERT ON '.
        $fixture->table('user','organization_audit')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO=1644,MESSAGE_TEXT='owned workbench rollback'");
      $response=$post('requester','execute','10-grant');
      $check($response instanceof AphrontDialogView&&$before===$fingerprint()&&!$conn->isInsideTransaction(),
        'approval-workbench-execution-fault-rolls-back-binding-membership-appointment-and-ledger');
    }catch(Throwable$error){$fault_primary=$error;throw$error;}
    finally{
      if($fault_attempted){try{$fixture->assertOwned();governance_native_setup_query($fixture,'DROP TRIGGER IF EXISTS '.$fault);}
        catch(Throwable$error){if($fault_primary){throw new PhutilAggregateException('approval_workbench_fault_cleanup_failed',array('primary'=>$fault_primary,'cleanup'=>$error));}throw$error;}}
    }
    $response=$post('requester','execute','10-grant');
    $switch($auth['requester']);$detail=$service->readApprovalWorkbenchDetail('10-grant');
    $member=queryfx_one($conn,'SELECT state FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$users['subject']->getPHID());
    $appointment=queryfx_one($conn,'SELECT state FROM organization_appointment WHERE unitID=%s AND userPHID=%s',$team,$users['subject']->getPHID());
    $check($response instanceof AphrontRedirectResponse&&$detail['state']==='consumed'&&$member['state']==='active'&&$appointment['state']==='active'&&$detail['may_approve']===false&&$detail['may_execute']===false,
      'approval-workbench-bound-executor-atomically-creates-member-and-appointment');
    $before=$fingerprint();$response=$post('requester','execute','10-grant');
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-consumed-page-command-refused-without-new-mutation');
    $switch($auth['requester']);$retry=$service->executeApprovedCompound('10-grant');
    $check($retry['replayed']===true&&$before===$fingerprint(),'approval-workbench-existing-native-ledger-replay-semantics-retained');
    $service->createUnit(array('operation_id'=>'awb-stale-create','unit_id'=>'awb-stale','kind'=>'team','name'=>'Synthetic stale team','parent_id'=>null));
    $service->proposeCompoundApproval($make('20-stale','awb-stale'));
    $stale_approved=$post('reviewer','approve','20-stale');$switch($auth['reviewer']);
    governance_approval_workbench_approved_setup($stale_approved,$reviewer->readApprovalWorkbenchDetail('20-stale'),'20-stale',$users['reviewer']->getPHID());
    $switch($auth['requester']);$service->updateUnit(array('operation_id'=>'awb-source-change','unit_id'=>'awb-stale','name'=>'Changed synthetic team','parent_id'=>null,'state'=>'active','expected_version'=>1));
    $before=$fingerprint();$response=$post('requester','execute','20-stale');
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-current-source-drift-refuses-execution');
    $switch($auth['requester']);$service->createUnit(array('operation_id'=>'awb-expired-create','unit_id'=>'awb-expired','kind'=>'team','name'=>'Synthetic expired team','parent_id'=>null));
    $service->proposeCompoundApproval($make('30-expired','awb-expired'));
    // Explicit owned-storage expiry fault, not a wall-clock expiration proof.
    $row=queryfx_one($conn,'SELECT bindingJSON FROM organization_approval WHERE approvalID=%s','30-expired');$binding=json_decode($row['bindingJSON'],true,16,JSON_THROW_ON_ERROR);
    $now=(int)queryfx_one($conn,'SELECT UNIX_TIMESTAMP() n')['n'];$binding['issuedAt']=$now-300;$binding['expiresAt']=$now-1;
    $json=PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($binding);
    $statement=$fixture->getAdmin()->prepare('UPDATE '.$fixture->table('user','organization_approval').' SET bindingJSON=?,requestDigest=? WHERE approvalID=?');
    try{$digest=hash('sha256',$json);$id='30-expired';$statement->bind_param('sss',$json,$digest,$id);$statement->execute();if($statement->affected_rows!==1){throw new RuntimeException('approval_workbench_expiry_fault_unknown');}}finally{$statement->close();}
    $before=$fingerprint();$response=$post('reviewer','approve','30-expired');
    $check($response instanceof AphrontDialogView&&$before===$fingerprint(),'approval-workbench-owned-expired-binding-refuses-approval');
    $source->setKeys(array('governance.organization.enabled'=>false));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
    $response=governance_approval_workbench_route($auth['requester'],'GET',array());
    $check($response instanceof Aphront404Response,'approval-workbench-default-feature-off-route-unavailable');
  } catch(Throwable $error) {$primary=$error;throw $error;}
  finally {
    $_COOKIE=$cookies;$_SERVER=$server;
    governance_approval_workbench_restore(array(
      'locale'=>static fn()=>(new ReflectionProperty(PhabricatorEnv::class,'localeCode'))->setValue(null,$locale),
      'translator'=>static fn()=>PhutilTranslator::setInstance($translator),
      'configuration'=>static fn()=>governance_recovery_project_fixture_cleanup(null,$source,$values,$cache,$primary)), $primary);
  }
  return array('source_revision'=>$revision,'native_sessions_and_current_sysadmin_service'=>true,'native_controller_objects_exercised'=>true,
    'HTTP_server_or_browser_executed'=>false,'wall_clock_expiry_wait_proved'=>false,
    'real_owned_expired_binding_fault_tested'=>true,'execution_rollback_fault_tested'=>true,'full_approval_family_complete'=>false,
    'all_sources_complete'=>false,'restoration_authorized'=>false);
}
