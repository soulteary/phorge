<?php

/** Projection after an actual approved native administrator appointment. */
function governance_native_manager_projection_cases($fixture, callable $check, $subject, $team, $sessions) {
  $cookies=$_COOKIE; $server=$_SERVER;
  try {
    $user=(new PhabricatorUser())->loadOneWhere('phid=%s',$subject);
    $actor=governance_native_request($user,$sessions);
    $query=new PhorgeOrganizationQuery($actor['context']);
    $ids=array_column($query->listUnits(),'unit_id');
    $check($ids===array($team) && $query->countUnits()===1 &&
      array_column($query->selectUnits(),'unit_id')===$ids &&
      array_column($query->exportUnitsPage(),'unit_id')===$ids,
      'native-approved-manager-list-selector-count-export-same-projection');
    $check(count($query->listMembers($team))>=1,
      'native-approved-manager-own-explicit-team-roster-visible');
    governance_native_refused(fn()=>$query->listMembers('native-team'),
      'organization_not_visible',$check,'native-approved-manager-other-team-roster-refused');
  } finally { $_COOKIE=$cookies; $_SERVER=$server; }
}
