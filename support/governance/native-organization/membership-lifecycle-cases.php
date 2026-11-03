<?php

/** Actual People SQL/Service batches; no synthetic organization-store proof. */
function governance_native_membership_lifecycle_cases($fixture, callable $check, array $state) {
  $fixture->assertOwned(); $service=$state['service'];
  $conn=(new PhabricatorUser())->establishConnection('w');
  $team='lifecycle-batch-team';
  $service->createUnit(array('operation_id'=>'lifecycle-create','unit_id'=>$team,
    'kind'=>'team','name'=>'Lifecycle Batch Team','parent_id'=>null));
  $users=array();
  foreach(array('one','two','three') as $suffix){
    $users[]=(new PhabricatorUser())->setUsername('lifecycle'.$suffix.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Lifecycle '.$suffix)->setIsApproved(1)->save();
  }
  $mutation=function($target,$action='member.add')use($conn,$team){
    $unit=queryfx_one($conn,'SELECT version FROM organization_unit WHERE unitID=%s',$team);
    $member=queryfx_one($conn,'SELECT version FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$target);
    return array('action'=>$action,'team'=>$team,'target_user'=>$target,'approval_id'=>null,
      'valid_from'=>$action==='member.add'?0:null,'valid_until'=>null,
      'expected_team_version'=>(int)$unit['version'],'expected_member_version'=>$member?(int)$member['version']:0,
      'expected_appointment_version'=>0);
  };
  $fingerprint=function()use($conn){
    $rows=array();foreach(array('unit','member','appointment','approval','operation','audit')as$name){
      $rows[$name]=queryfx_all($conn,'SELECT * FROM %T ORDER BY %C', 'organization_'.$name,
        $name==='approval'?'approvalID':($name==='operation'?'operationID':($name==='audit'?'id':'unitID')));
    }
    foreach($rows as &$table_rows){usort($table_rows,function($a,$b){return strcmp(phutil_json_encode($a),phutil_json_encode($b));});}unset($table_rows);
    return hash('sha256',phutil_json_encode($rows));
  };
  $batch=array($mutation($users[0]->getPHID()),$mutation($users[1]->getPHID()));
  $bad=$batch;$bad[1]['expected_member_version']=1;$before=$fingerprint();
  governance_native_refused(function()use($service,$bad){$service->execute(array('operation_id'=>'lifecycle-last-stale','mutations'=>$bad));},
    'organization_stale_version',$check,'membership-lifecycle-last-item-stale-refused');
  $check($fingerprint()===$before,'membership-lifecycle-last-item-rolls-back-earlier-member-audit-and-ledger');
  $result=$service->execute(array('operation_id'=>'lifecycle-batch-success','mutations'=>$batch));
  $members=queryfx_all($conn,'SELECT state,version FROM organization_member WHERE unitID=%s',$team);
  $check(count($members)===2 && (int)$result['versions'][$team]===2,
    'membership-lifecycle-real-two-member-batch-commits-one-unit-version');
  $before=$fingerprint();$replay=$service->execute(array('operation_id'=>'lifecycle-batch-success','mutations'=>$batch));
  $check($replay['replayed']===true && $fingerprint()===$before,
    'membership-lifecycle-successful-batch-replay-has-no-additional-writes');
  // DDL is held only by the fixture setup identity; the runtime role gets no DDL.
  $namespace=$fixture->getNamespace();
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){throw new RuntimeException('lifecycle_fixture_scope_refused');}
  $trigger='`'.$namespace.'_user`.`lifecycle_audit_fault`';
  $audit=$fixture->table('user','organization_audit');
  $installed=false;
  try{
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$audit.
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-lifecycle-audit-fault'");
    $installed=true;
    $proof=governance_native_setup_query($fixture,
      "SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
      $namespace."_user' AND TRIGGER_NAME='lifecycle_audit_fault'")->fetch_assoc();
    $check((int)$proof['n']===1,'membership-lifecycle-real-audit-fault-trigger-installed');
    $before=$fingerprint();$failed=false;
    try{$service->execute(array('operation_id'=>'lifecycle-audit-fault','mutations'=>array($mutation($users[2]->getPHID()))));}
    catch(Throwable$error){
      // Require the actual owned SQL fault, not any unrelated precondition error.
      if($error->getCode()!==1644 || strpos($error->getMessage(),'owned-lifecycle-audit-fault')===false){throw$error;}
      $failed=true;
    }
    $check($failed,'membership-lifecycle-real-sql-audit-fault-observed-after-member-write');
    $check($fingerprint()===$before,'membership-lifecycle-audit-fault-rolls-back-member-unit-audit-ledger');
  }finally{if($installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}}
  $saved_cookie=$_COOKIE;$saved_server=$_SERVER;$depth=governance_native_route_guard_depth();
  try{
    foreach(array('member.end','member.suspend')as$index=>$action){
      $m=$mutation($users[$index]->getPHID(),$action);
      $form=array('json'=>'true','form_action'=>$action,'operation_id'=>'lifecycle-form-'.$index,
        'unit_id'=>$team,'user_phid'=>$m['target_user'],'unit_version'=>$m['expected_team_version'],
        'member_version'=>$m['expected_member_version'],'appointment_version'=>0);
      $before=$fingerprint();
      list($response,$body)=governance_native_route_response($state['admin'],'POST',$form);
      $check($response->getHTTPResponseCode()===403 && $fingerprint()===$before &&
        governance_native_route_guard_depth()===$depth,
        'membership-lifecycle-'.$action.'-actual-form-without-csrf-no-write');
      $form[AphrontRequest::getCSRFTokenName()]=$state['admin']['viewer']->getCSRFToken();
      list($response,$body)=governance_native_route_response($state['admin'],'POST',$form);
      $check($response->getHTTPResponseCode()===200 && isset($body['result']) &&
        governance_native_route_guard_depth()===$depth,
        'membership-lifecycle-'.$action.'-actual-csrf-form-commits');
    }
  }finally{$_COOKIE=$saved_cookie;$_SERVER=$saved_server;}
  $rows=queryfx_all($conn,'SELECT state,version FROM organization_member WHERE unitID=%s ORDER BY userPHID',$team);
  $states=array_column($rows,'state');sort($states);
  $check($states===array('ended','suspended') && min(array_map('intval',array_column($rows,'version')))===2,
    'membership-lifecycle-end-and-suspend-retain-versioned-native-history');
  global $control;
  if(!($control instanceof mysqli)){throw new RuntimeException('native_concurrency_observer_required');}
  $fresh=governance_native_request($users[2],$state['sessions']);
  PhorgeOrganizationService::newForActor($fresh['context'])->readUnits();
  $race=array('operation_id'=>'lifecycle-concurrent-operation','mutations'=>array($mutation($users[2]->getPHID())));
  $setup=$fixture->getAdmin();$setup->begin_transaction();$locked=true;$workers=array();
  try{
    governance_native_setup_query($fixture,'SELECT id FROM '.$fixture->table('user','organization_guard').' WHERE id=1 FOR UPDATE');
    for($i=0;$i<2;$i++){$workers[]=governance_native_start_worker($fixture,array('action'=>'org.members.edit',
      'token'=>$state['admin']['token'],'payload'=>$race));}
    foreach($workers as$worker){governance_native_concurrency_wait($fixture,$control,$worker,'organization_guard');}
    $check(true,'membership-lifecycle-two-real-writers-wait-on-native-organization-mutex');
    $setup->commit();$locked=false;$results=array();
    foreach($workers as&$worker){$results[]=governance_native_finish_worker($worker);}unset($worker);
    $flags=array_column($results,'replayed');sort($flags);
    $check(array_column($results,'status')===array('committed','committed')&&$flags===array(false,true),
      'membership-lifecycle-concurrent-identical-operation-applies-once-and-replays-once');
    $count=queryfx_one($conn,'SELECT COUNT(*) n FROM organization_audit WHERE operationID=%s',$race['operation_id']);
    $current=queryfx_one($conn,'SELECT version FROM organization_unit WHERE unitID=%s',$team);
    $check((int)$count['n']===1 && (int)$current['version']===5,
      'membership-lifecycle-concurrent-operation-writes-one-audit-and-one-version');
  }finally{if($locked){$setup->rollback();}}
  return array('native_batch_atomicity'=>true,'native_sql_fault_rollback'=>true,
    'retained_relationship_history'=>true,'compound_approval_implemented'=>false,
    'public_csrf_lifecycle_complete'=>false,'production_activation'=>false);
}
