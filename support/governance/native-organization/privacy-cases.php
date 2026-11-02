<?php

/** Existing appointment seeds are fixture setup, not a grant/approval endpoint. */
function governance_native_privacy_seed($fixture, $unit, $user) {
  $fixture->assertOwned();
  $sql='INSERT INTO '.$fixture->table('user','organization_appointment').
    ' (unitID,userPHID,role,state,validFrom,validUntil,version) VALUES (?,?,\'team_admin\',\'active\',0,NULL,1)';
  $mode=(new mysqli_driver())->report_mode;
  $statement=null;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $statement=$fixture->getAdmin()->prepare($sql);
    if (!$statement || !$statement->bind_param('ss',$unit,$user) ||
        !$statement->execute() || $statement->affected_rows!==1) {
      throw new RuntimeException('native_privacy_fixture_seed_failed');
    }
  } finally {
    if ($statement) { $statement->close(); }
    mysqli_report($mode);
  }
}
function governance_native_privacy_add(PhorgeOrganizationService $service, $operation,
  $unit, $user, $version) {
  return $service->execute(array('operation_id'=>$operation,'mutations'=>array(array(
    'action'=>'member.add','approval_id'=>null,'expected_appointment_version'=>0,
    'expected_member_version'=>0,'expected_team_version'=>$version,
    'target_user'=>$user,'team'=>$unit,'valid_from'=>0,'valid_until'=>null))));
}
function governance_native_privacy_context(PhabricatorUser $user, $sessions) {
  $cookies=$_COOKIE; $had_method=array_key_exists('REQUEST_METHOD',$_SERVER);
  $method=$_SERVER['REQUEST_METHOD']??null;
  try { return governance_native_request($user,$sessions)['context']; }
  finally {
    $_COOKIE=$cookies;
    if ($had_method) { $_SERVER['REQUEST_METHOD']=$method; }
    else { unset($_SERVER['REQUEST_METHOD']); }
  }
}
function governance_native_privacy_cases($fixture, callable $check, array $state) {
  $fixture->assertOwned(); $service=$state['service'];
  $service->createUnit(array('operation_id'=>'privacy-parent','unit_id'=>'privacy-parent',
    'kind'=>'team','name'=>'Synthetic Private Parent','parent_id'=>null));
  $service->createUnit(array('operation_id'=>'privacy-child','unit_id'=>'privacy-child',
    'kind'=>'team','name'=>'Synthetic Child','parent_id'=>'privacy-parent'));
  $users=array(); $contexts=array();
  foreach (array('child','parent','subject') as $role) {
    $user=(new PhabricatorUser())->setUsername('gnp'.$role.substr($fixture->getNamespace(),-12))
      ->setRealName('Synthetic Privacy '.$role)->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())
      ->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
    $users[$role]=$user;
    $contexts[$role]=governance_native_privacy_context($user,$state['sessions']);
  }
  governance_native_privacy_add($service,'privacy-child-member','privacy-child',$users['child']->getPHID(),1);
  governance_native_privacy_add($service,'privacy-parent-member','privacy-parent',$users['parent']->getPHID(),1);
  governance_native_privacy_add($service,'privacy-subject-child','privacy-child',$users['subject']->getPHID(),2);
  governance_native_privacy_add($service,'privacy-subject-parent','privacy-parent',$users['subject']->getPHID(),2);
  $ordinary=new PhorgeOrganizationQuery($contexts['child']);
  $check($ordinary->listUnits()===array() && $ordinary->countUnits()===0 &&
    $ordinary->listUserTeams($users['child']->getPHID())===array(),
    'native-directory-ordinary-member-self-has-no-roster-exception');
  governance_native_privacy_seed($fixture,'privacy-child',$users['child']->getPHID());
  governance_native_privacy_seed($fixture,'privacy-parent',$users['parent']->getPHID());
  $child=new PhorgeOrganizationQuery($contexts['child']);
  $parent=new PhorgeOrganizationQuery($contexts['parent']);
  $child_rows=$child->listUnits();
  $check(count($child_rows)===1 && $child_rows[0]['unit_id']==='privacy-child' &&
    $child_rows[0]['parent_id']===null,
    'native-directory-child-manager-hidden-parent-id-redacted');
  $check($child->selectUnits()===$child_rows && $child->exportUnitsPage()===$child_rows &&
    $child->countUnits()===count($child_rows),
    'native-directory-child-manager-list-selector-count-export-share-projection');
  $members=$child->listMembers('privacy-child');
  $visible_phids=array_column($members,'user_phid'); sort($visible_phids);
  $expected=array($users['child']->getPHID(),$users['subject']->getPHID());sort($expected);
  $check($visible_phids===$expected,'native-directory-child-manager-members-current-team-only');
  $teams=$child->listUserTeams($users['subject']->getPHID());
  $check(count($teams)===1 && $teams[0]['unit_id']==='privacy-child' && $teams[0]['parent_id']===null,
    'native-directory-other-user-hidden-parent-membership-not-disclosed');
  governance_native_refused(static fn()=>$child->listMembers('privacy-parent'),
    'organization_not_visible',$check,'native-directory-child-manager-cannot-read-parent-roster');
  $parent_rows=$parent->listUnits();
  $check(count($parent_rows)===1 && $parent_rows[0]['unit_id']==='privacy-parent' &&
    $parent->selectUnits()===$parent_rows && $parent->exportUnitsPage()===$parent_rows &&
    $parent->countUnits()===1,'native-directory-parent-manager-no-automatic-child-list');
  governance_native_refused(static fn()=>$parent->listMembers('privacy-child'),
    'organization_not_visible',$check,'native-directory-parent-manager-cannot-read-child-roster');
  $parent_teams=$parent->listUserTeams($users['subject']->getPHID());
  $check(count($parent_teams)===1 && $parent_teams[0]['unit_id']==='privacy-parent',
    'native-directory-parent-manager-other-user-relations-filtered');
  // Explicit independent appointment is required even where hierarchy exists.
  governance_native_privacy_add($service,'privacy-explicit-parent-member','privacy-parent',
    $users['child']->getPHID(),3);
  governance_native_privacy_seed($fixture,'privacy-parent',$users['child']->getPHID());
  $explicit=$child->listUnits(); $byid=array();foreach($explicit as $row){$byid[$row['unit_id']]=$row;}
  $check(count($explicit)===2 && $byid['privacy-child']['parent_id']==='privacy-parent' &&
    $child->selectUnits()===$explicit && $child->exportUnitsPage()===$explicit &&
    $child->countUnits()===2,'native-directory-parent-reference-only-after-explicit-parent-appointment');
  return array('actual_people_accounts'=>true,'actual_native_sessions'=>true,
    'actual_directory_queries'=>true,'existing_appointments_fixture_seed_only'=>true,
    'appointment_grant_workflow_proof'=>false,'native_permission_axes_changed'=>false);
}
