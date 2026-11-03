<?php

/** Explicit native member/appointment compound; real independent credentials. */
function governance_native_compound_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();$service=$state['service'];$conn=(new PhabricatorUser())->establishConnection('w');
  $saved_cookie=$_COOKIE;$saved_server=$_SERVER;
  try{
    $fingerprint=function()use($conn){$all=array();foreach(array('unit','member','appointment','approval','operation','audit')as$table){
      $rows=queryfx_all($conn,'SELECT * FROM %T','organization_'.$table);
      usort($rows,static function($a,$b){return strcmp(phutil_json_encode($a),phutil_json_encode($b));});$all[$table]=$rows;}
      return hash('sha256',phutil_json_encode($all));};
    $team='compound-native-team';$service->createUnit(array('operation_id'=>'compound-create','unit_id'=>$team,
      'kind'=>'team','name'=>'Explicit Compound Team','parent_id'=>null));
    $make=function($suffix,$admin)use($fixture){$user=(new PhabricatorUser())->setUsername('compound'.$suffix.substr($fixture->getNamespace(),-10))
      ->setRealName('Synthetic Compound '.$suffix)->setIsApproved(1)->setIsEmailVerified(1)->setIsAdmin($admin?1:0)->save();
      (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
        ->setIsPrimary(1)->setIsVerified(1)->save();return$user;};
    $subject=$make('subject',false);$approver=governance_native_request($make('approver',true),$state['sessions']);
    $input=array('action'=>'member.add-and-appoint','approval_id'=>'compound-native-grant',
      'operation_id'=>'compound-native-grant-op','team'=>$team,'target_user'=>$subject->getPHID(),
      'expected_team_version'=>1,'expected_member_version'=>0,'expected_appointment_version'=>0,
      'impact_acknowledged'=>true,'member_from'=>0,'member_until'=>null,'appointment_from'=>0,'appointment_until'=>null);
    $post=function($session,$action,$payload) {
      return governance_native_route_response($session,'POST',array('json'=>'true','action'=>$action,
        'payload'=>phutil_json_encode($payload),AphrontRequest::getCSRFTokenName()=>$session['viewer']->getCSRFToken()));};
    list($response,$body)=$post($state['admin'],'compound.preview',$input);
    $check($response->getHTTPResponseCode()===200 && $body['result']['independent_approval_required']===true &&
      $body['result']['native_project_permissions_changed']===false && is_string($body['result']['impact_message']),
      'native-compound-preview-declares-explicit-membership-impact-and-independent-approval');
    $proposal=$input;$proposal['ttl_seconds']=300;
    $proposal_form=array('json'=>'true','form_action'=>'compound.propose','compound_action'=>$input['action'],
      'approval_id'=>$input['approval_id'],'operation_id'=>$input['operation_id'],'unit_id'=>$team,'unit_version'=>1,
      'member_version'=>0,'appointment_version'=>0,'compound_subject'=>array($subject->getPHID()),'impact_acknowledged'=>1,
      AphrontRequest::getCSRFTokenName()=>$state['admin']['viewer']->getCSRFToken());
    list($response,$body)=governance_native_route_response($state['admin'],'POST',$proposal_form);
    $check($response->getHTTPResponseCode()===200 && $body['result']['state']==='submitted' &&
      !queryfx_one($conn,'SELECT id FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$subject->getPHID()),
      'native-compound-proposal-keeps-real-before-member-absent');
    list($self,$unused)=$post($state['admin'],'approval.approve',array('approval_id'=>$input['approval_id']));
    $check($self->getHTTPResponseCode()===403,'native-compound-requester-cannot-self-approve');
    list($review,$unused)=governance_native_route_response($approver,'POST',array('form_action'=>'compound.approve',
      'approval_id'=>$input['approval_id'],AphrontRequest::getCSRFTokenName()=>$approver['viewer']->getCSRFToken()));
    $check($review instanceof AphrontDialogView,'native-compound-administrator-form-reviews-fixed-membership-impact-before-approval');
    list($response,$body)=governance_native_route_response($approver,'POST',array('action'=>'approval.approve',
      'payload'=>phutil_json_encode(array('approval_id'=>$input['approval_id'])),'confirm_compound_approval'=>1,
      AphrontRequest::getCSRFTokenName()=>$approver['viewer']->getCSRFToken()));
    $approved_row=queryfx_one($conn,'SELECT state FROM organization_approval WHERE approvalID=%s',$input['approval_id']);
    $check($response instanceof AphrontRedirectResponse && $approved_row['state']==='approved',
      'native-compound-independent-live-administrator-approves-exact-before-sources');
    $tampered=$input;$tampered['member_until']=PHP_INT_MAX-1;
    list($bad,$unused)=$post($state['admin'],'compound.execute',$tampered);
    $check($bad->getHTTPResponseCode()===403 && !queryfx_one($conn,
      'SELECT id FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$subject->getPHID()),
      'native-compound-parameter-tamper-cannot-insert-member');
    $grant_trigger='`'.$fixture->getNamespace().'_user`.`compound_grant_audit_fault`';$grant_installed=false;
    try{
      governance_native_setup_query($fixture,'CREATE TRIGGER '.$grant_trigger.' BEFORE INSERT ON '.$fixture->table('user','organization_audit').
        " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-compound-grant-fault'");$grant_installed=true;
      $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
        $fixture->getNamespace()."_user' AND TRIGGER_NAME='compound_grant_audit_fault'")->fetch_assoc();
      $check((int)$proof['n']===1,'native-compound-grant-audit-trigger-actually-installed');
      $before=$fingerprint();$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];$failed=false;
      try{$service->executeCompound($input);}catch(AphrontQueryException$error){
        if($error->getCode()!==1644||strpos($error->getMessage(),'owned-compound-grant-fault')===false){throw$error;}$failed=true;}
      $check($failed && $fingerprint()===$before,
        'native-compound-grant-audit-fault-rolls-back-new-member-appointment-consumption-and-ledger');
    }finally{if($grant_installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$grant_trigger);}}
    list($response,$body)=$post($state['admin'],'compound.execute',$input);
    $member=queryfx_one($conn,'SELECT state,version FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$subject->getPHID());
    $appointment=queryfx_one($conn,'SELECT state,role,version FROM organization_appointment WHERE unitID=%s AND userPHID=%s',$team,$subject->getPHID());
    $ticket=queryfx_one($conn,'SELECT state,consumedByOperation FROM organization_approval WHERE approvalID=%s',$input['approval_id']);
    $check($response->getHTTPResponseCode()===200 && $member['state']==='active' && (int)$member['version']===1 &&
      $appointment['state']==='active' && $appointment['role']==='team_admin' && (int)$appointment['version']===1 &&
      $ticket['state']==='consumed' && $ticket['consumedByOperation']===$input['operation_id'],
      'native-compound-member-appointment-and-approved-consumption-commit-together');
    list($replay,$body)=$post($state['admin'],'compound.execute',$input);
    $check($replay->getHTTPResponseCode()===200 && $body['result']['replayed']===true,
      'native-compound-operation-replay-does-not-reconsume-approved-ticket');
    $before_retry=$fingerprint();
    list($form_retry,$retry_body)=governance_native_route_response($state['admin'],'POST',array('json'=>'true',
      'form_action'=>'compound.execute','approval_id'=>$input['approval_id'],
      AphrontRequest::getCSRFTokenName()=>$state['admin']['viewer']->getCSRFToken()));
    $check($form_retry->getHTTPResponseCode()===200 && $retry_body['result']['replayed']===true && $fingerprint()===$before_retry,
      'native-compound-consumed-approval-id-form-retries-committed-ledger-without-writes');
    global $control;
    if(!($control instanceof mysqli)){throw new RuntimeException('native_concurrency_observer_required');}
    $setup=$fixture->getAdmin();$setup->begin_transaction();$held=true;$workers=array();
    try{
      governance_native_setup_query($fixture,'SELECT id FROM '.$fixture->table('user','organization_guard').' WHERE id=1 FOR UPDATE');
      for($i=0;$i<2;$i++){$workers[]=governance_native_start_worker($fixture,array('action'=>'org.compound.execute-approved',
        'token'=>$state['admin']['token'],'payload'=>array('approval_id'=>$input['approval_id'])));}
      foreach($workers as$worker){governance_native_concurrency_wait($fixture,$control,$worker,'organization_guard');}
      $check(true,'native-compound-two-approval-id-retries-really-wait-on-organization-mutex');
      $setup->commit();$held=false;$retry_results=array();foreach($workers as&$worker){$retry_results[]=governance_native_finish_worker($worker);}unset($worker);
      $check(array_column($retry_results,'status')===array('committed','committed') &&
        array_column($retry_results,'replayed')===array(true,true) && $fingerprint()===$before_retry,
        'native-compound-concurrent-consumed-approval-id-retries-never-reconsume-or-duplicate-ledger');
    }finally{if($held){$setup->rollback();}}
    list($wrong_executor,$unused)=$post($approver,'compound.execute-approved',array('approval_id'=>$input['approval_id']));
    $check($wrong_executor->getHTTPResponseCode()===403 && $fingerprint()===$before_retry,
      'native-compound-consumed-approval-id-still-requires-bound-current-executor');
    $factors=(new PhabricatorAuthFactorConfigQuery())->setViewer($approver['viewer'])->withUserPHIDs(array($approver['viewer']->getPHID()))
      ->withFactorProviderStatuses(array(PhabricatorAuthFactorProviderStatus::STATUS_ACTIVE,PhabricatorAuthFactorProviderStatus::STATUS_DEPRECATED))->execute();
    $check(!$factors && !$approver['viewer']->getSession()->isHighSecuritySession(),
      'native-compound-executor-revocation-uses-real-no-factor-normal-session');
    $actor_user=(new PhabricatorUser())->loadOneWhere('phid=%s',$state['admin']['viewer']->getPHID());
    $role_change=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(false);
    (new PhabricatorUserTransactionEditor())->setActor($approver['viewer'])
      ->setContentSourceFromRequest($approver['request'])->setCancelURI('/people/manage/'.$actor_user->getID().'/')
      ->applyTransactions($actor_user,array($role_change));
    try{
      list($revoked_executor,$unused)=$post($state['admin'],'compound.execute-approved',array('approval_id'=>$input['approval_id']));
      $check($revoked_executor->getHTTPResponseCode()===403 && $fingerprint()===$before_retry,
        'native-compound-consumed-approval-id-refuses-executor-whose-native-admin-role-was-revoked');
    }finally{
      $actor_user=(new PhabricatorUser())->loadOneWhere('phid=%s',$state['admin']['viewer']->getPHID());
      $restore=(new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(true);
      (new PhabricatorUserTransactionEditor())->setActor($approver['viewer'])
        ->setContentSourceFromRequest($approver['request'])->setCancelURI('/people/manage/'.$actor_user->getID().'/')
        ->applyTransactions($actor_user,array($restore));
    }
    $manager=governance_native_request($subject,$state['sessions']);
    $query=new PhorgeOrganizationQuery($manager['context']);
    $check($query->getUnit($team)!==null,'native-compound-explicit-member-and-appointment-enable-only-own-team-directory');
    $ordinary=$make('ordinary',false);$normal=array('action'=>'member.add','team'=>$team,'target_user'=>$ordinary->getPHID(),
      'valid_from'=>0,'valid_until'=>null,'approval_id'=>null,'expected_team_version'=>2,'expected_member_version'=>0,'expected_appointment_version'=>0);
    list($added,$unused)=$post($manager,'members.edit',array('operation_id'=>'compound-manager-add-ordinary','mutations'=>array($normal)));
    $check($added->getHTTPResponseCode()===200,'native-team-manager-direct-ordinary-membership-needs-no-per-change-approval');
    $self_end=$normal;$self_end['action']='member.end';$self_end['target_user']=$subject->getPHID();
    $self_end['expected_team_version']=3;$self_end['expected_member_version']=1;$self_end['expected_appointment_version']=1;$self_end['valid_from']=null;
    list($refused,$unused)=$post($manager,'members.edit',array('operation_id'=>'compound-manager-cannot-end-admin','mutations'=>array($self_end)));
    $check($refused->getHTTPResponseCode()===403,'native-team-manager-cannot-remove-own-administrator-membership-prerequisite');
    $before_member=queryfx_one($conn,'SELECT version FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$ordinary->getPHID());
    $form=array('json'=>'true','form_action'=>'member.suspend','operation_id'=>'compound-manager-form-suspend','unit_id'=>$team,
      'user_phid'=>$ordinary->getPHID(),'unit_version'=>3,'member_version'=>$before_member['version'],'appointment_version'=>0);
    list($no_csrf,$unused)=governance_native_route_response($manager,'POST',$form);
    $check($no_csrf->getHTTPResponseCode()===403,'native-team-manager-ordinary-suspend-form-missing-csrf-refused');
    $form[AphrontRequest::getCSRFTokenName()]=$manager['viewer']->getCSRFToken();
    list($suspended,$unused)=governance_native_route_response($manager,'POST',$form);
    $current_ordinary=queryfx_one($conn,'SELECT state,version FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$ordinary->getPHID());
    $check($suspended->getHTTPResponseCode()===200 && $current_ordinary['state']==='suspended' && (int)$current_ordinary['version']===2,
      'native-team-manager-ordinary-suspend-form-real-csrf-current-role-commits');
    $revoke=$input;$revoke['action']='appointment.revoke-and-end';$revoke['approval_id']='compound-native-revoke';
    $revoke['operation_id']='compound-native-revoke-op';$revoke['expected_team_version']=4;
    $revoke['expected_member_version']=1;$revoke['expected_appointment_version']=1;
    foreach(array('member_from','member_until','appointment_from','appointment_until')as$key){$revoke[$key]=null;}
    $old=array('action'=>'appointment.revoke','approval_id'=>'compound-old-standalone','operation_id'=>'compound-old-standalone-op',
      'team'=>$team,'target_user'=>$subject->getPHID(),'valid_from'=>null,'valid_until'=>null,'ttl_seconds'=>300);
    $post($state['admin'],'approval.propose',$old);$post($approver,'approval.approve',array('approval_id'=>$old['approval_id']));
    $wrong_ticket=$revoke;$wrong_ticket['approval_id']=$old['approval_id'];$wrong_ticket['operation_id']=$old['operation_id'];
    list($wrong_response,$unused)=$post($state['admin'],'compound.execute',$wrong_ticket);
    $old_ticket=queryfx_one($conn,'SELECT state FROM organization_approval WHERE approvalID=%s',$old['approval_id']);
    $check($wrong_response->getHTTPResponseCode()===403 && $old_ticket['state']==='approved',
      'native-standalone-appointment-approval-cannot-authorize-compound-member-end');
    $proposal=$revoke;$proposal['ttl_seconds']=300;
    $post($state['admin'],'compound.propose',$proposal);$post($approver,'approval.approve',array('approval_id'=>$revoke['approval_id']));
    $trigger='`'.$fixture->getNamespace().'_user`.`compound_audit_fault`';$installed=false;
    try{
      governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$fixture->table('user','organization_audit').
        " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-compound-audit-fault'");$installed=true;
      $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
        $fixture->getNamespace()."_user' AND TRIGGER_NAME='compound_audit_fault'")->fetch_assoc();
      $check((int)$proof['n']===1,'native-compound-owned-sql-fault-trigger-actually-installed');
      $before=$fingerprint();$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$state['admin']['token'];$failed=false;
      try{$service->executeCompound($revoke);}catch(AphrontQueryException$error){
        if($error->getCode()!==1644 || strpos($error->getMessage(),'owned-compound-audit-fault')===false){throw$error;}$failed=true;}
      $check($failed && $fingerprint()===$before,
        'native-compound-real-audit-fault-rolls-back-both-relationships-consumption-unit-and-ledger');
    }finally{if($installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}}
    list($response,$body)=governance_native_route_response($state['admin'],'POST',array('json'=>'true',
      'form_action'=>'compound.execute','approval_id'=>$revoke['approval_id'],
      AphrontRequest::getCSRFTokenName()=>$state['admin']['viewer']->getCSRFToken()));

    $member=queryfx_one($conn,'SELECT state,version FROM organization_member WHERE unitID=%s AND userPHID=%s',$team,$subject->getPHID());
    $appointment=queryfx_one($conn,'SELECT state,version FROM organization_appointment WHERE unitID=%s AND userPHID=%s',$team,$subject->getPHID());
    $check($response->getHTTPResponseCode()===200 && $member['state']==='ended' && (int)$member['version']===2 &&
      $appointment['state']==='ended' && (int)$appointment['version']===2 && $query->getUnit($team)===null,
      'native-compound-approved-revoke-and-end-retains-history-and-removes-new-directory-access');
    list($revoked_replay,$unused)=$post($manager,'members.edit',array('operation_id'=>'compound-manager-add-ordinary','mutations'=>array($normal)));
    $check($revoked_replay->getHTTPResponseCode()===403,'native-revoked-team-manager-cannot-replay-old-successful-operation');
    return array('explicit_compound_native_transaction'=>true,'independent_approval'=>true,
      'before_source_membership_not_fabricated'=>true,'native_project_permissions_changed'=>false,
      'full_compound_failure_matrix_complete'=>false,'production_activation'=>false);
  }finally{$_COOKIE=$saved_cookie;$_SERVER=$saved_server;}
}
