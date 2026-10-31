<?php

/** Native storage-fixture regressions for independent team/project access. */
final class TeamOrganizationExtendedPolicyTestObject extends Phobject
  implements PhabricatorPolicyInterface, PhabricatorExtendedPolicyInterface {

  private $phid;
  private $requiredTarget;

  public function __construct($required_target = null) {
    $this->phid = PhabricatorPHID::generateNewPHID('TEST');
    $this->requiredTarget = $required_target;
  }

  public function getPHID() { return $this->phid; }
  public function getCapabilities() {
    return array(PhabricatorPolicyCapability::CAN_VIEW);
  }
  public function getPolicy($capability) {
    return PhabricatorPolicies::POLICY_USER;
  }
  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return false;
  }
  public function getExtendedPolicy($capability, PhabricatorUser $viewer) {
    if ($this->requiredTarget === null) {
      return array();
    }
    return array(array($this->requiredTarget, PhabricatorPolicyCapability::CAN_VIEW));
  }
}

final class TeamOrganizationPermissionsTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testDirectMembershipAndExplicitDescendantsAreDifferent() {
    $admin = $this->newAdmin();
    $parent_user = $this->generateNewTestUser();
    $child_user = $this->generateNewTestUser();
    $leaf_user = $this->generateNewTestUser();
    $outside = $this->generateNewTestUser();
    $parent = $this->newTeam($admin);
    $child = $this->newTeam($admin, array('parentPHID' => $parent->getPHID()));
    $leaf = $this->newTeam($admin, array('parentPHID' => $child->getPHID()));
    $sibling = $this->newTeam($admin, array('parentPHID' => $parent->getPHID()));
    $this->members($admin, $parent, array($parent_user));
    $this->members($admin, $child, array($child_user));
    $this->members($admin, $leaf, array($leaf_user));

    $this->assertScope($parent_user, false, array($parent));
    $this->assertScope($parent_user, true, array($parent));
    $this->assertScope($child_user, false, array($child));
    $this->assertScope($child_user, true, array($parent, $child));
    $this->assertScope($leaf_user, false, array($leaf));
    $this->assertScope($leaf_user, true, array($parent, $child, $leaf));
    $this->assertScope($outside, true, array());
    $this->assertEqual(array(), TeamOrganizationService::getMemberPHIDs($this->freshTeam($sibling)));
    $this->assertEqual(array($parent_user->getPHID()),
      array_values(TeamOrganizationService::getMemberPHIDs($this->freshTeam($parent))));
  }

  public function testTeamVisibilityAndRosterAreIndependentAtEveryLevel() {
    $admin = $this->newAdmin();
    $member = $this->generateNewTestUser();
    $outside = $this->generateNewTestUser();
    $hidden_parent = $this->newTeam($admin, array('viewPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $public_child = $this->newTeam($admin, array(
      'parentPHID' => $hidden_parent->getPHID(),
      'viewPolicy' => PhabricatorPolicies::POLICY_USER,
      'membersPolicy' => PhabricatorPolicies::POLICY_ADMIN,
    ));
    $private_sibling = $this->newTeam($admin, array('parentPHID' => $hidden_parent->getPHID()));
    $this->members($admin, $public_child, array($member));

    $this->assertFalse($this->can($outside, $hidden_parent, 'view'));
    $this->assertTrue($this->can($outside, $public_child, 'view'));
    $this->assertFalse($this->can($outside, $private_sibling, 'view'));
    $this->assertFalse($this->can($outside, $public_child, TeamOrganizationTeam::CAP_MEMBERS));
    $this->assertEqual(array(), TeamOrganizationService::getUserTeams($outside, $member));
    $this->assertSamePHIDs(array($public_child), TeamOrganizationService::getUserTeams($member, $member));
    $this->assertSamePHIDs(array($public_child), TeamOrganizationService::getUserTeams($admin, $member));
    $this->assertRejected(function() use ($outside, $public_child) {
      TeamOrganizationService::loadAudit($outside, $public_child);
    }, PhabricatorPolicyException::class);

    $rule = new TeamOrganizationDirectPolicyRule();
    $labels = $rule->getValueForDisplay($outside, array($hidden_parent->getPHID()));
    $this->assertFalse(strpos(json_encode($labels), $hidden_parent->getName()) !== false);
    $handle = id(new PhabricatorHandleQuery())->setViewer($outside)
      ->withPHIDs(array($public_child->getPHID()))->execute();
    $this->assertFalse(strpos($handle[$public_child->getPHID()]->getFullName(), $hidden_parent->getName()) !== false);
  }

  public function testPeopleFieldHidesPrivateNamesAndCannotAssignTeams() {
    $admin = $this->newAdmin();
    $member = $this->generateNewTestUser();
    $outside = $this->generateNewTestUser();
    $hidden = $this->newTeam($admin);
    $hidden_roster = $this->newTeam($admin, array(
      'viewPolicy' => PhabricatorPolicies::POLICY_USER,
      'membersPolicy' => PhabricatorPolicies::POLICY_ADMIN,
    ));
    $this->members($admin, $hidden, array($member));
    $this->members($admin, $hidden_roster, array($member));
    $field = id(new TeamOrganizationUserField())->setObject($member);
    $this->assertTrue($field->shouldAppearInPropertyView());
    $this->assertFalse($field->shouldAppearInEditView());
    $this->assertFalse($field->shouldAppearInApplicationTransactions());
    $this->assertFalse($field->shouldAppearInConduitDictionary());
    $this->assertFalse($field->shouldAppearInConduitTransactions());

    // Prime the same field object with an authorized viewer before switching.
    $field->setViewer($admin);
    $authorized = (string)phutil_tag('div', array(), $field->renderPropertyViewValue(array()));
    $this->assertTrue(strpos($authorized, $hidden->getName()) !== false);
    $field->setViewer($outside);
    $anonymous_membership = (string)phutil_tag('div', array(), $field->renderPropertyViewValue(array()));
    $this->assertFalse(strpos($anonymous_membership, $hidden->getName()) !== false);
    $this->assertFalse(strpos($anonymous_membership, $hidden_roster->getName()) !== false);
    $field->setViewer($member);
    $own_membership = (string)phutil_tag('div', array(), $field->renderPropertyViewValue(array()));
    $this->assertTrue(strpos($own_membership, $hidden->getName()) !== false);
    $this->assertRejected(function() use ($member, $hidden) {
      TeamOrganizationService::setUserTeams($member, $member, array($hidden->getPHID()));
    }, PhabricatorPolicyException::class);
  }

  public function testInvalidStructureCyclesAndStaleVersionsDoNotWrite() {
    $admin = $this->newAdmin();
    $root_team = $this->newTeam($admin);
    $child = $this->newTeam($admin, array('parentPHID' => $root_team->getPHID()));
    $leaf = $this->newTeam($admin, array('parentPHID' => $child->getPHID()));
    $before = $this->freshTeam($root_team);
    $audit_count = count(TeamOrganizationService::loadAudit($admin, $before));
    foreach (array(
      array('name' => ''),
      array('parentPHID' => $root_team->getPHID()),
      array('parentPHID' => $leaf->getPHID()),
      array('parentPHID' => $admin->getPHID()),
    ) as $invalid) {
      $this->assertRejected(function() use ($admin, $before, $invalid) {
        $this->save($admin, $before, $invalid);
      }, InvalidArgumentException::class);
    }
    $this->assertEqual($before->getVersion(), $this->freshTeam($root_team)->getVersion());
    $this->assertEqual($audit_count, count(TeamOrganizationService::loadAudit($admin, $before)));

    $renamed = $this->save($admin, $before, array('name' => 'Renamed '.Filesystem::readRandomCharacters(12)));
    $this->assertRejected(function() use ($admin, $before) {
      $this->save($admin, $before, array('description' => 'Stale write must fail.'));
    }, InvalidArgumentException::class);
    $this->assertEqual($renamed->getName(), $this->freshTeam($root_team)->getName());
    $this->assertRejected(function() use ($admin, $before) {
      TeamOrganizationService::setMembers($admin, $before, array(), $before->getVersion());
    }, InvalidArgumentException::class);

    // Two editors start with separate roots. The second valid version must
    // still re-read the first move before checking for a newly formed cycle.
    $a = $this->newTeam($admin);
    $b = $this->newTeam($admin);
    $this->save($admin, $a, array('parentPHID' => $b->getPHID()));
    $this->assertRejected(function() use ($admin, $a, $b) {
      $this->save($admin, $b, array('parentPHID' => $a->getPHID()));
    }, InvalidArgumentException::class);
    $this->assertEqual(null, $this->freshTeam($b)->getParentPHID());
  }

  public function testAdminRevocationAndDisableRejectStaleActors() {
    $admin = $this->newAdmin();
    $member = $this->generateNewTestUser();
    $team = $this->newTeam($admin);
    $stale = clone $admin;
    $this->freshUser($admin)->setIsAdmin(0)->save();
    $this->assertTrue((bool)$stale->getIsAdmin());
    $this->assertFalse(TeamOrganizationService::canManage($stale));
    $this->assertAllManagementRejected($stale, $team, $member);

    $this->freshUser($admin)->setIsAdmin(1)->setIsDisabled(1)->save();
    $this->assertFalse((bool)$stale->getIsDisabled());
    $this->assertFalse(TeamOrganizationService::canManage($stale));
    $this->assertAllManagementRejected($stale, $team, $member);
    $this->freshUser($admin)->setIsDisabled(0)->setIsApproved(0)->save();
    $this->assertFalse(TeamOrganizationService::canManage($stale));
    $this->assertAllManagementRejected($stale, $team, $member);
    $this->assertEqual(array(), TeamOrganizationService::getMemberPHIDs($this->freshTeam($team)));
  }

  public function testMemberRemovalDisabledAndUnapprovedUsersLoseGrantsImmediately() {
    $admin = $this->newAdmin();
    $member = $this->generateNewTestUser();
    $system_agent = $this->generateNewTestUser()->setIsSystemAgent(1)->save();
    $team = $this->newTeam($admin);
    $this->members($admin, $team, array($member));
    $this->assertScope($member, false, array($team));
    $stored = $this->freshTeam($team);
    $this->assertRejected(function() use ($admin, $stored, $member, $system_agent) {
      TeamOrganizationService::setMembers($admin, $stored,
        array($member->getPHID(), $system_agent->getPHID()), $stored->getVersion());
    }, InvalidArgumentException::class);
    $this->assertEqual(array($member->getPHID()),
      array_values(TeamOrganizationService::getMemberPHIDs($this->freshTeam($team))));
    $this->freshUser($member)->setIsDisabled(1)->save();
    $this->assertFalse((bool)$member->getIsDisabled());
    $this->assertScope($member, false, array());
    $this->freshUser($member)->setIsDisabled(0)->setIsApproved(0)->save();
    $this->assertScope($member, true, array());
    $this->freshUser($member)->setIsApproved(1)->save();
    $this->assertScope($member, false, array($team));
    $this->members($admin, $team, array());
    $this->assertScope($member, true, array());
  }

  public function testArchiveAndReparentDoNotGrantOtherLevelsAutomatically() {
    $admin = $this->newAdmin();
    $member = $this->generateNewTestUser();
    $root_team = $this->newTeam($admin);
    $middle = $this->newTeam($admin, array('parentPHID' => $root_team->getPHID()));
    $leaf = $this->newTeam($admin, array('parentPHID' => $middle->getPHID()));
    $other = $this->newTeam($admin);
    $this->members($admin, $leaf, array($member));
    $this->save($admin, $this->freshTeam($middle), array('isArchived' => true));
    $this->assertScope($member, false, array($leaf));
    $this->assertScope($member, true, array($root_team, $leaf));
    $this->save($admin, $this->freshTeam($leaf), array('parentPHID' => $other->getPHID()));
    $this->assertScope($member, true, array($other, $leaf));
    $this->save($admin, $this->freshTeam($leaf), array('isArchived' => true));
    $this->assertScope($member, true, array());
    $this->assertEqual(array($member->getPHID()),
      array_values(TeamOrganizationService::getMemberPHIDs($this->freshTeam($leaf))));
  }

  public function testUserAssignmentsDetectConcurrentChangesAndReplaceAtomically() {
    $admin = $this->newAdmin();
    $member = $this->generateNewTestUser();
    $a = $this->newTeam($admin);
    $b = $this->newTeam($admin);
    $c = $this->newTeam($admin);
    TeamOrganizationService::setUserTeams($admin, $member, array($a->getPHID()), array());
    $this->members($admin, $b, array($member));
    $this->assertRejected(function() use ($admin, $member, $a, $c) {
      TeamOrganizationService::setUserTeams($admin, $member,
        array($c->getPHID()), array($a->getPHID()));
    }, InvalidArgumentException::class);
    $this->assertScope($member, false, array($a, $b));
    TeamOrganizationService::setUserTeams($admin, $member,
      array($c->getPHID()), array($a->getPHID(), $b->getPHID()));
    $this->assertScope($member, false, array($c));
    $this->assertEqual(array(), TeamOrganizationService::getMemberPHIDs($this->freshTeam($a)));
    $this->assertEqual(array(), TeamOrganizationService::getMemberPHIDs($this->freshTeam($b)));
  }

  public function testNativeProjectViewEditJoinUseSeparateTeamRulesWithoutMemberCopies() {
    $owner = $this->newAdmin();
    $reader = $this->generateNewTestUser();
    $editor = $this->generateNewTestUser();
    $joiner = $this->generateNewTestUser();
    $outside = $this->generateNewTestUser();
    $read_team = $this->newTeam($owner);
    $edit_team = $this->newTeam($owner);
    $join_team = $this->newTeam($owner);
    $this->members($owner, $read_team, array($reader, $editor, $joiner));
    $this->members($owner, $edit_team, array($editor));
    $this->members($owner, $join_team, array($joiner));
    $project = $this->newProject($owner, array(
      'view' => $this->teamPolicy($owner, $read_team),
      'edit' => $this->teamPolicy($owner, $edit_team),
      'join' => $this->teamPolicy($owner, $join_team),
    ));
    foreach (array(
      array($reader, array(true, false, false)),
      // Native project editors can always join, regardless of join policy.
      array($editor, array(true, true, true)),
      array($joiner, array(true, false, true)),
      array($outside, array(false, false, false)),
    ) as $case) {
      foreach (array('view', 'edit', 'join') as $index => $capability) {
        $this->assertEqual($case[1][$index], $this->can($case[0], $project, $capability),
          $case[0]->getUsername().' '.$capability);
      }
    }
    $this->assertEqual(array(), $this->freshProject($project)->getMemberPHIDs());
    $this->assertTrue($this->can($editor, $project, 'edit'));
    $this->assertFalse($this->can($reader, $project, 'edit'));
    $this->members($owner, $edit_team, array());
    $this->assertFalse($this->can($editor, $project, 'edit'));
    $this->assertTrue($this->can($editor, $project, 'view'));
    $this->assertEqual(array(), $this->freshProject($project)->getMemberPHIDs());
  }

  public function testStrictBoundaryConstrainsExistingProjectMembersAndStaleObjects() {
    $owner = $this->newAdmin();
    $member = $this->generateNewTestUser();
    $outside = $this->generateNewTestUser();
    $team = $this->newTeam($owner, array('viewPolicy' => PhabricatorPolicies::POLICY_USER));
    $this->members($owner, $team, array($member));
    $project = $this->newProject($owner, array(
      'view' => PhabricatorPolicies::POLICY_USER,
      'edit' => PhabricatorPolicies::POLICY_USER,
      'join' => PhabricatorPolicies::POLICY_USER,
    ), array($member), array(
      array('type' => 'organization.teams', 'value' => array($team->getPHID())),
      array('type' => 'organization.scope', 'value' => 'direct'),
    ));
    foreach (array('view', 'edit', 'join') as $capability) {
      $this->assertTrue($this->can($member, $project, $capability));
      $this->assertFalse($this->can($outside, $project, $capability));
    }
    $this->members($owner, $team, array());
    foreach (array('view', 'edit', 'join') as $capability) {
      $this->assertFalse($this->can($member, $project, $capability));
    }
    $this->assertFalse($this->can($member, $project, 'edit'));
    $this->assertEqual(array($member->getPHID()),
      array_values($this->freshProject($project)->getMemberPHIDs()));

    $this->members($owner, $team, array($member));
    $this->assertTrue($this->can($member, $project, 'view'));
    $this->freshUser($member)->setIsDisabled(1)->save();
    foreach (array('view', 'edit', 'join') as $capability) {
      $this->assertFalse($this->can($member, $project, $capability));
    }
    $this->freshUser($member)->setIsDisabled(0)->save();

    $this->editProject($owner, $project, array(
      array('type' => 'organization.teams', 'value' => array()),
    ));
    $without_boundary = $this->freshProject($project);
    foreach (array('view', 'edit', 'join') as $capability) {
      $this->assertTrue($this->can($outside, $without_boundary, $capability));
    }
    // A delegated project manager must still pass the existing boundary
    // self-lock checks. The legacy marker adds no automatic ACL grant.
    $roles = TeamProjectMembership::roleMap($without_boundary);
    $roles[$outside->getPHID()] = TeamProjectMembership::LEGACY_ADMIN;
    $without_boundary->setProperty(TeamProjectMembership::ROLES_PROPERTY, $roles)->save();
    $project = $this->freshProject($without_boundary);
    $this->assertTrue(TeamProjectMembership::canManage($outside, $project));
    $this->assertRejected(function() use ($outside, $project, $team) {
      $this->editProject($outside, $project, array(
        array('type' => 'organization.teams', 'value' => array($team->getPHID())),
      ));
    }, PhabricatorApplicationTransactionValidationException::class);
    $this->assertRejected(function() use ($outside, $project, $team) {
      $transaction = id(new PhabricatorProjectTransaction())
        ->setTransactionType('team:organization-boundary-teams')
        ->setNewValue(array($team->getPHID()));
      id(new PhabricatorProjectTransactionEditor())->setActor($outside)
        ->setContentSource(PhabricatorContentSource::newForSource(
          PhabricatorUnitTestContentSource::SOURCECONST))
        ->applyTransactions($this->freshProject($project), array($transaction));
    }, PhabricatorApplicationTransactionValidationException::class);
    $this->assertEqual(array(), $this->freshProject($project)->getProperty(
      TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array()));
  }

  public function testStrictDescendantsAreExplicitAndDoNotBypassOriginalProjectACL() {
    $admin = $this->newAdmin();
    $owner = $this->generateNewTestUser();
    $child_member = $this->generateNewTestUser();
    $root_team = $this->newTeam($admin);
    $child = $this->newTeam($admin, array('parentPHID' => $root_team->getPHID()));
    $this->members($admin, $root_team, array($owner));
    $this->members($admin, $child, array($child_member));
    $project = $this->newProject($owner, array(
      'view' => PhabricatorPolicies::POLICY_USER,
    ), array(), array(
      array('type' => 'organization.teams', 'value' => array($root_team->getPHID())),
    ));
    $this->assertFalse($this->can($child_member, $project, 'view'));
    $this->editProject($owner, $project, array(
      array('type' => 'organization.scope', 'value' => 'descendants'),
    ));
    $this->assertTrue($this->can($child_member, $this->freshProject($project), 'view'));
    $this->save($admin, $this->freshTeam($child), array('parentPHID' => null));
    $this->assertFalse($this->can($child_member, $this->freshProject($project), 'view'));
    $this->editProject($owner, $project, array(
      array('type' => 'view', 'value' => $owner->getPHID()),
    ));
    $this->assertFalse($this->can($admin, $this->freshProject($project), 'view'));
    $this->assertTrue(TeamOrganizationService::canManage($admin));
  }

  public function testExistingHiddenAndArchivedBoundaryTokensCanBeRetained() {
    $admin = $this->newAdmin();
    $editor = $this->generateNewTestUser();
    $hidden = $this->newTeam($admin, array('viewPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $active = $this->newTeam($admin, array('viewPolicy' => PhabricatorPolicies::POLICY_USER));
    $new_hidden = $this->newTeam($admin, array('viewPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $this->members($admin, $hidden, array($editor));
    $this->members($admin, $active, array($editor));
    $selected = array($hidden->getPHID(), $active->getPHID());
    $project = $this->newProject($admin, array(
      'view' => PhabricatorPolicies::POLICY_USER,
      'edit' => PhabricatorPolicies::POLICY_USER,
    ), array(), array(array('type' => 'organization.teams', 'value' => $selected)));
    $roles = TeamProjectMembership::roleMap($project);
    $roles[$editor->getPHID()] = TeamProjectMembership::LEGACY_ADMIN;
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, $roles)->save();
    $project = $this->freshProject($project);
    $this->assertFalse($this->can($editor, $hidden, 'view'));
    $this->assertTrue($this->can($editor, $project, 'edit'));
    $this->editProject($editor, $project, array(
      array('type' => 'name', 'value' => 'Retained hidden reference '.Filesystem::readRandomCharacters(10)),
      array('type' => 'organization.teams', 'value' => $selected),
    ));
    $this->save($admin, $this->freshTeam($hidden), array('isArchived' => true));
    $retained_name = 'Retained archived reference '.Filesystem::readRandomCharacters(10);
    $this->editProject($editor, $project, array(
      array('type' => 'name', 'value' => $retained_name),
      array('type' => 'organization.teams', 'value' => $selected),
    ));
    $this->assertEqual($selected, $this->freshProject($project)->getProperty(
      TeamOrganizationProjectBoundary::TEAMS_PROPERTY));
    $this->assertRejected(function() use ($editor, $project, $selected, $new_hidden) {
      $this->editProject($editor, $project, array(
        array('type' => 'name', 'value' => 'Rejected update'),
        array('type' => 'organization.teams',
          'value' => array_merge($selected, array($new_hidden->getPHID()))),
      ));
    }, PhabricatorApplicationTransactionValidationException::class);
    $this->assertEqual($retained_name, $this->freshProject($project)->getName());
  }

  public function testBoundaryHandlesAreGenericAndPreserveParentProjectHandles() {
    $admin = $this->newAdmin();
    $outside = $this->generateNewTestUser();
    $hidden_team = $this->newTeam($admin, array(
      'viewPolicy' => PhabricatorPolicies::POLICY_ADMIN,
    ));
    $parent = $this->newProject($admin, array(
      'view' => PhabricatorPolicies::POLICY_USER,
    ));
    $child = $this->newProject($admin, array(
      'view' => PhabricatorPolicies::POLICY_USER,
    ), array(), array(
      array('type' => 'parent', 'value' => $parent->getPHID()),
      array('type' => 'organization.teams', 'value' => array($hidden_team->getPHID())),
    ));
    $guard = TeamOrganizationProjectBoundary::newGuard($child);
    $guard_phid = $guard->getPHID();
    $this->assertFalse($guard_phid === $parent->getPHID());
    $this->assertFalse($this->can($outside, $hidden_team, 'view'));
    $this->assertFalse($this->can($outside, $child, 'view'));

    // These are the actual targets consumed by PolicyExplainController.
    $extended = $child->getExtendedPolicy(PhabricatorPolicyCapability::CAN_VIEW, $admin);
    $target_phids = array();
    foreach ($extended as $rule) {
      $target_phids[] = is_object($rule[0]) ? $rule[0]->getPHID() : $rule[0];
    }
    $this->assertTrue(in_array($parent->getPHID(), $target_phids));
    $this->assertTrue(in_array($guard_phid, $target_phids));
    foreach (array($admin, $outside) as $viewer) {
      $handles = id(new PhabricatorHandleQuery())->setViewer($viewer)
        ->withPHIDs($target_phids)->execute();
      $label = $handles[$guard_phid];
      $this->assertTrue($label->isComplete());
      $this->assertEqual('团队访问边界', $label->getName());
      $this->assertEqual(null, $label->getURI());
      $html = (string)phutil_tag('div', array(), $label->renderTag());
      foreach (array('Unknown', $guard_phid, $hidden_team->getName(),
          $hidden_team->getPHID(), $child->getName(), 'href=') as $private_text) {
        $this->assertFalse(strpos($html, $private_text) !== false);
      }
      $this->assertTrue($handles[$parent->getPHID()]->isComplete());
      $this->assertEqual($parent->getName(), $handles[$parent->getPHID()]->getName());
      $this->assertEqual('/tag/'.$parent->getPrimarySlug().'/',
        $handles[$parent->getPHID()]->getURI());
    }
  }

  public function testBoundaryLabelsNeverAuthorizeObjectsOrExtendedPolicies() {
    $admin = $this->newAdmin();
    $viewer = $this->generateNewTestUser();
    $guard_phid = 'PHID-ORGB-'.str_repeat('a', 20);
    foreach (array($admin, $viewer) as $actor) {
      // Use the real handle loader and its workspace object, not a mock label.
      $handle_query = id(new PhabricatorHandleQuery())->setViewer($actor)
        ->withPHIDs(array($guard_phid));
      $handles = $handle_query->execute();
      $this->assertTrue($handles[$guard_phid]->isComplete());
      $workspace = $handle_query->getObjectsFromWorkspace(array($guard_phid));
      $label = $workspace[$guard_phid];
      $this->assertTrue($label instanceof TeamOrganizationBoundaryLabel);
      $this->assertFalse($this->can($actor, $label, 'view'));
      $this->assertRejected(function() use ($actor, $label) {
        $this->can($actor, $label, 'edit');
      }, Exception::class);
      $this->assertTrue($this->can($actor, new TeamOrganizationExtendedPolicyTestObject(), 'view'));
      foreach (array($label, $guard_phid) as $target) {
        $this->assertFalse($this->can($actor,
          new TeamOrganizationExtendedPolicyTestObject($target), 'view'));
      }
      // Merely rendering a label must not make it a loadable native entity.
      $this->assertEqual(array(), id(new PhabricatorObjectQuery())->setViewer($actor)
        ->withPHIDs(array($guard_phid))->execute());
      $this->assertRejected(function() use ($actor, $guard_phid) {
        id(new PhabricatorHandleQuery())->setViewer($actor)
          ->requireObjectCapabilities(array(PhabricatorPolicyCapability::CAN_EDIT))
          ->withPHIDs(array($guard_phid))->execute();
      }, Exception::class);
    }
    // The native extended checker loads bare PHIDs as the omnipotent viewer.
    $this->assertEqual(array(), id(new PhabricatorObjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($guard_phid))->execute());
  }

  private function newAdmin() {
    return $this->generateNewTestUser()->setIsAdmin(1)->save();
  }

  private function newTeam($admin, array $input = array()) {
    return TeamOrganizationService::saveTeam($admin, null, $input + array(
      'name' => 'Organization '.Filesystem::readRandomCharacters(16),
      'description' => '', 'parentPHID' => null,
      'viewPolicy' => 'obj.team.organization.members',
      'membersPolicy' => 'obj.team.organization.members',
      'isArchived' => false,
    ));
  }

  private function save($admin, $team, array $changes) {
    return TeamOrganizationService::saveTeam($admin, $team, $changes + array(
      'name' => $team->getName(), 'description' => $team->getDescription(),
      'parentPHID' => $team->getParentPHID(),
      'viewPolicy' => $team->getViewPolicy(), 'membersPolicy' => $team->getMembersPolicy(),
      'isArchived' => (bool)$team->getIsArchived(),
      'expectedVersion' => $team->getVersion(),
    ));
  }

  private function members($admin, $team, array $users) {
    $team = $this->freshTeam($team);
    return TeamOrganizationService::setMembers($admin, $team,
      array_values(mpull($users, 'getPHID')), $team->getVersion());
  }

  private function freshTeam($team) {
    return id(new TeamOrganizationTeam())->load($team->getID());
  }

  private function freshUser($user) {
    return id(new PhabricatorUser())->load($user->getID());
  }

  private function assertSamePHIDs(array $expected, array $actual) {
    $expected = array_values(mpull($expected, 'getPHID'));
    $actual = array_values(mpull($actual, 'getPHID'));
    sort($expected); sort($actual);
    $this->assertEqual($expected, $actual);
  }

  private function assertScope($viewer, $descendants, array $teams) {
    $actual = array_keys(TeamOrganizationService::getMembershipScope($viewer, $descendants));
    $expected = array_values(mpull($teams, 'getPHID'));
    sort($actual); sort($expected);
    $this->assertEqual($expected, $actual);
  }

  private function can($viewer, $object, $capability) {
    return PhabricatorPolicyFilter::hasCapability($viewer, $object, $capability);
  }

  private function assertAllManagementRejected($actor, $team, $target) {
    $this->assertRejected(function() use ($actor) {
      $this->newTeam($actor);
    }, PhabricatorPolicyException::class);
    $this->assertRejected(function() use ($actor, $team) {
      $this->save($actor, $team, array('description' => 'Forbidden edit'));
    }, PhabricatorPolicyException::class);
    $this->assertRejected(function() use ($actor, $team, $target) {
      TeamOrganizationService::setMembers($actor, $team, array($target->getPHID()), $team->getVersion());
    }, PhabricatorPolicyException::class);
    $this->assertRejected(function() use ($actor, $team, $target) {
      TeamOrganizationService::setUserTeams($actor, $target, array($team->getPHID()));
    }, PhabricatorPolicyException::class);
  }

  private function teamPolicy($owner, $team, $descendants = false) {
    return id(new PhabricatorPolicy())->setRules(array(
      array('action' => 'allow', 'rule' => PhabricatorUsersPolicyRule::class,
        'value' => array($owner->getPHID())),
      array('action' => 'allow',
        'rule' => $descendants ? TeamOrganizationDescendantsPolicyRule::class : TeamOrganizationDirectPolicyRule::class,
        'value' => array($team->getPHID())),
    ))->setDefaultAction('deny')->save()->getPHID();
  }

  private function newProject($owner, array $policies = array(), array $members = array(), array $extra = array()) {
    $policies += array('view' => $owner->getPHID(), 'edit' => $owner->getPHID(),
      'join' => PhabricatorPolicies::POLICY_NOONE);
    $transactions = array(
      array('type' => 'name', 'value' => 'Organization Project '.Filesystem::readRandomCharacters(16)),
      array('type' => 'members.set', 'value' => array_values(mpull($members, 'getPHID'))),
    );
    foreach ($policies as $key => $value) {
      $transactions[] = array('type' => $key, 'value' => $value);
    }
    $result = id(new ConduitCall('project.edit', array(
      'transactions' => array_merge($transactions, $extra))))->setUser($owner)->execute();
    // Existing ACL regressions exercise legacy projects; new roles have their own suite.
    $project = $this->freshProject($result['object']['phid']);
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, null)->save();
    return $this->freshProject($project);
  }

  private function freshProject($project) {
    $phid = is_string($project) ? $project : $project->getPHID();
    return id(new PhabricatorProjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))->needMembers(true)->needAncestorMembers(true)->executeOne();
  }

  private function editProject($owner, $project, array $transactions) {
    return id(new ConduitCall('project.edit', array(
      'objectIdentifier' => $project->getPHID(), 'transactions' => $transactions,
    )))->setUser($owner)->execute();
  }

  private function assertRejected($callback, $class) {
    $caught = null;
    try { $callback(); } catch (Throwable $error) { $caught = $error; }
    $this->assertTrue($caught instanceof $class,
      $caught ? get_class($caught).': '.$caught->getMessage() : 'Expected rejection.');
  }
}
