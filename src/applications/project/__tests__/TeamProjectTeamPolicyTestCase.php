<?php

/** Native storage-fixture regressions for independent team/project access. */
final class TeamProjectTeamPolicyTestCase extends PhabricatorTestCase {
  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testShortcutUsesSelectedLiveTeamsAndIndependentDescendantScope() {
    $admin = $this->admin(); $parent_user = $this->generateNewTestUser();
    $child_user = $this->generateNewTestUser(); $outside = $this->generateNewTestUser();
    $parent = $this->team($admin, array($parent_user));
    $this->team($admin, array($child_user), $parent);
    $direct = TeamProjectTeamPolicy::newPolicy($admin, array($parent->getPHID()), 'direct');
    $desc = TeamProjectTeamPolicy::newPolicy($admin, array($parent->getPHID()), 'descendants');
    $this->assertEqual('deny', $direct->getDefaultAction());
    $this->assertEqual(array(array('action' => 'allow',
      'rule' => TeamOrganizationDirectPolicyRule::class, 'value' => array($parent->getPHID()))), $direct->getRules());
    $this->assertTrue($this->matches($parent_user, $direct));
    $this->assertFalse($this->matches($child_user, $direct));
    $this->assertTrue($this->matches($child_user, $desc));
    $this->assertFalse($this->matches($outside, $desc));
    $fresh = id(new TeamOrganizationTeamQuery())->setViewer($admin)->withIDs(array($parent->getID()))->executeOne();
    TeamOrganizationService::setMembers($admin, $fresh, array(), $fresh->getVersion());
    $this->assertFalse($this->matches($parent_user, $direct));
    $this->assertTrue($this->matches($child_user, $desc));
  }

  public function testShortcutRejectsInvalidHiddenArchivedAndDisabledNewSelections() {
    $admin = $this->admin(); $ordinary = $this->generateNewTestUser();
    $visible = $this->team($admin); $hidden = $this->team($admin, array(), null, PhabricatorPolicies::POLICY_ADMIN);
    $archived = $this->team($admin, array(), null, PhabricatorPolicies::POLICY_USER, true);
    foreach (array(
      array(array(), 'direct'), array(array($visible->getPHID()), 'unexpected'),
      array(array($ordinary->getPHID()), 'direct'),
      array(array($hidden->getPHID()), 'direct'), array(array($archived->getPHID()), 'direct'),
      array(array('PHID-ORGT-missing00000000000000'), 'direct'),
      array(array_fill(0, TeamProjectTeamPolicy::MAX_TEAMS + 1, $visible->getPHID()), 'direct'),
    ) as $input) {
      $this->reject(function() use ($ordinary, $input) {
        TeamProjectTeamPolicy::newPolicy($ordinary, $input[0], $input[1]);
      }, InvalidArgumentException::class);
    }
    $stale = clone $ordinary;
    $ordinary->setIsDisabled(1)->save();
    $this->reject(function() use ($stale, $visible) {
      TeamProjectTeamPolicy::newPolicy($stale, array($visible->getPHID()), 'direct');
    }, PhabricatorPolicyException::class);
  }

  public function testReadableLabelsDoNotRevealHiddenNamesOrFlattenAdvancedPolicies() {
    $admin = $this->admin(); $viewer = $this->generateNewTestUser();
    $visible = $this->team($admin); $hidden = $this->team($admin, array(), null, PhabricatorPolicies::POLICY_ADMIN);
    $policy = TeamProjectTeamPolicy::newPolicy($admin,
      array($visible->getPHID(), $hidden->getPHID()), 'descendants');
    $loaded = id(new PhabricatorPolicy())->load($policy->getID());
    $info = TeamProjectTeamPolicy::getPolicyInfo($viewer, $loaded);
    $this->assertTrue(strpos($info['full'], '包含下级') !== false);
    $this->assertTrue(strpos($info['full'], $visible->getName()) !== false);
    $this->assertTrue(strpos($info['full'], '不可见团队') !== false);
    $this->assertFalse(strpos(json_encode($info), $hidden->getName()) !== false);
    $this->assertFalse(strpos(json_encode($info), $hidden->getPHID()) !== false);
    $advanced = id(new PhabricatorPolicy())->setRules(array_merge($policy->getRules(),
      array(array('action' => 'deny', 'rule' => PhabricatorUsersPolicyRule::class,
        'value' => array($viewer->getPHID())))))->setDefaultAction('deny')->save();
    $this->assertEqual(null, TeamProjectTeamPolicy::getPolicyInfo($viewer, $advanced));
    $this->assertEqual(null, TeamProjectTeamPolicy::getPolicyInfo($viewer,
      PhabricatorPolicyQuery::getGlobalPolicy(PhabricatorPolicies::POLICY_USER)));
  }

  public function testCreatingPolicyDoesNotGrantMembershipOrChangeProjectPermissions() {
    $admin = $this->admin(); $owner = $this->generateNewTestUser();
    $participant = $this->generateNewTestUser(); $team = $this->team($admin, array($participant));
    $project = $this->project($owner); $before = $this->state($project);
    $policy = TeamProjectTeamPolicy::newPolicy($owner, array($team->getPHID()), 'direct', $project);
    $this->assertEqual($before, $this->state($this->fresh($owner, $project)));
    $result = id(new ConduitCall('project.edit', array('objectIdentifier' => $project->getPHID(),
      'transactions' => array(array('type' => 'view', 'value' => $policy->getPHID())))))
      ->setUser($owner)->execute();
    $fresh = $this->fresh($owner, $project);
    $this->assertEqual($policy->getPHID(), $fresh->getViewPolicy());
    $this->assertEqual($before['edit'], $fresh->getEditPolicy());
    $this->assertEqual($before['join'], $fresh->getJoinPolicy());
    $this->assertEqual($before['members'], $fresh->getDirectMemberPHIDs());
    $this->assertEqual($before['boundary'], $fresh->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array()));
    $this->assertEqual(array($participant->getPHID()), TeamOrganizationService::getMemberPHIDs($team));
  }

  public function testExistingProjectOnlyAllowsManagersAndRejectsMilestones() {
    $admin = $this->admin(); $owner = $this->generateNewTestUser();
    $editor = $this->generateNewTestUser(); $team = $this->team($admin);
    $edit_policy = id(new PhabricatorPolicy())->setDefaultAction('deny')->setRules(array(
      array('action' => 'allow', 'rule' => PhabricatorUsersPolicyRule::class,
        'value' => array($owner->getPHID(), $editor->getPHID()))))->save();
    $project = $this->project($owner, array(array('type' => 'edit', 'value' => $edit_policy->getPHID()),
      array('type' => 'members.add', 'value' => array($editor->getPHID()))));
    $this->assertTrue(PhabricatorPolicyFilter::hasCapability($editor, $project, 'edit'));
    $this->reject(function() use ($editor, $project, $team) {
      TeamProjectTeamPolicy::newPolicy($editor, array($team->getPHID()), 'direct', $project);
    }, PhabricatorPolicyException::class);
    $this->assertTrue(TeamProjectTeamPolicy::newPolicy($owner,
      array($team->getPHID()), 'direct', $project) instanceof PhabricatorPolicy);
    $milestone = $this->project($owner, array(array('type' => 'milestone', 'value' => $project->getPHID())));
    $this->reject(function() use ($admin, $milestone, $team) {
      TeamProjectTeamPolicy::newPolicy($admin, array($team->getPHID()), 'direct', $milestone);
    }, PhabricatorPolicyException::class);
  }

  public function testTeamShortcutIsAvailableOnAllProjectPoliciesOnly() {
    $admin = $this->admin(); $team = $this->team($admin);
    $policy = TeamProjectTeamPolicy::newPolicy($admin, array($team->getPHID()), 'direct');
    $project = PhabricatorProject::initializeNewProject($admin);
    foreach (array('view', 'edit', 'join') as $capability) {
      $control = id(new AphrontFormPolicyControl())->setViewer($admin)
        ->setPolicyObject($project)->setCapability($capability)
        ->setPolicies(array($policy))->setValue($policy->getPHID());
      $options = id(new ReflectionMethod(AphrontFormPolicyControl::class, 'getOptions'))->invoke($control);
      $this->assertTrue(isset($options['organization-team'][TeamProjectTeamPolicy::SELECT_KEY]));
      $this->assertTrue(strpos($options[PhabricatorPolicyType::TYPE_CUSTOM][$policy->getPHID()]['name'], '团队成员') !== false);
    }
    $task = new ManiphestTask();
    $control = id(new AphrontFormPolicyControl())->setViewer($admin)
      ->setPolicyObject($task)->setCapability('view')->setPolicies(array($policy))->setValue($policy->getPHID());
    $options = id(new ReflectionMethod(AphrontFormPolicyControl::class, 'getOptions'))->invoke($control);
    $this->assertFalse(isset($options['organization-team']));
  }

  private function admin() { return $this->generateNewTestUser()->setIsAdmin(1)->save(); }
  private function team($admin, array $members = array(), $parent = null,
    $visibility = PhabricatorPolicies::POLICY_USER, $archived = false) {
    $team = TeamOrganizationService::saveTeam($admin, null, array(
      'name' => 'Team Shortcut '.Filesystem::readRandomCharacters(12), 'description' => '',
      'parentPHID' => $parent ? $parent->getPHID() : null, 'viewPolicy' => $visibility,
      'membersPolicy' => PhabricatorPolicies::POLICY_ADMIN, 'isArchived' => false));
    if ($members) {
      $team = TeamOrganizationService::setMembers($admin, $team,
        array_values(mpull($members, 'getPHID')), $team->getVersion());
    }
    if ($archived) {
      $team = TeamOrganizationService::saveTeam($admin, $team, array(
        'name' => $team->getName(), 'description' => '', 'parentPHID' => $team->getParentPHID(),
        'viewPolicy' => $visibility, 'membersPolicy' => PhabricatorPolicies::POLICY_ADMIN,
        'isArchived' => true, 'expectedVersion' => $team->getVersion()));
    }
    return $team;
  }
  private function project($actor, array $extra = array()) {
    $result = id(new ConduitCall('project.edit', array('transactions' => array_merge(
      array(array('type' => 'name', 'value' => 'Team Policy '.Filesystem::readRandomCharacters(12))), $extra))))
      ->setUser($actor)->execute();
    return id(new PhabricatorProjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($result['object']['phid']))->needMembers(true)->needAncestorMembers(true)->executeOne();
  }
  private function fresh($viewer, $project) {
    return id(new PhabricatorProjectQuery())->setViewer($viewer)->withIDs(array($project->getID()))
      ->needMembers(true)->needAncestorMembers(true)->executeOne();
  }
  private function state($project) {
    return array('view' => $project->getViewPolicy(), 'edit' => $project->getEditPolicy(),
      'join' => $project->getJoinPolicy(), 'members' => $project->getDirectMemberPHIDs(),
      'boundary' => $project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array()));
  }
  private function matches($viewer, $policy) {
    $spec = head($policy->getRules()); $class = $spec['rule']; $rule = new $class();
    $rule->willApplyRules($viewer, array($spec['value']), array());
    return $rule->applyRule($viewer, $spec['value'], new PhabricatorProject());
  }
  private function reject($call, $class) {
    $caught = null;
    try { $call(); } catch (Throwable $ex) { $caught = $ex; }
    $this->assertTrue($caught instanceof $class,
      $caught ? get_class($caught).': '.$caught->getMessage() : 'Expected rejection.');
  }
}
