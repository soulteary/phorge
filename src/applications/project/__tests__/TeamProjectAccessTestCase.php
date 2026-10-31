<?php

/** Native storage-fixture regressions for independent team/project access. */
final class TeamProjectAccessTestCase extends PhabricatorTestCase {
  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testAddUnionsTeamsPeopleAndExistingMembersWithoutChangingOtherCapabilities() {
    $owner = $this->admin(); $team_user = $this->generateNewTestUser();
    $guest = $this->generateNewTestUser(); $member = $this->generateNewTestUser();
    $team = $this->team($owner, array($team_user));
    $project = $this->project($owner, array(), array($member));
    $old = array($project->getEditPolicy(), $project->getJoinPolicy());
    $project = $this->change($owner, $project, array('teams' => array($team->getPHID()),
      'users' => array($guest->getPHID())));
    foreach (array($owner, $team_user, $guest, $member) as $viewer) {
      $this->assertTrue($this->can($viewer, $project, 'view'));
    }
    $this->assertEqual($old, array($project->getEditPolicy(), $project->getJoinPolicy()));
    $this->assertFalse($this->can($guest, $project, 'edit'));
    $before = $project->getViewPolicy();
    $project = $this->change($owner, $project, array('teams' => array($team->getPHID()),
      'users' => array($guest->getPHID())));
    $this->assertEqual($before, $project->getViewPolicy());
    $tx = id(new PhabricatorProjectTransactionQuery())->setViewer($owner)
      ->withObjectPHIDs(array($project->getPHID()))->withTransactionTypes(
        array(PhabricatorTransactions::TYPE_VIEW_POLICY))->execute();
    $found = false;
    foreach ($tx as $entry) {
      $found = $found || $entry->getMetadataValue('team.project.access.operation') === 'add';
    }
    $this->assertTrue($found);
  }

  public function testReplaceOnlySelectedCapabilityAndRetainsNativeAutomaticGrants() {
    $owner = $this->admin(); $old_user = $this->generateNewTestUser();
    $new_user = $this->generateNewTestUser(); $member = $this->generateNewTestUser();
    $project = $this->project($owner, array(), array($member));
    $project = $this->change($owner, $project, array('users' => array($old_user->getPHID())));
    $old = array($project->getEditPolicy(), $project->getJoinPolicy());
    $project = $this->change($owner, $project, array('mode' => 'replace',
      'users' => array($owner->getPHID(), $new_user->getPHID())));
    $this->assertFalse($this->can($old_user, $project, 'view'));
    $this->assertTrue($this->can($new_user, $project, 'view'));
    $this->assertTrue($this->can($member, $project, 'view'));
    $this->assertTrue($this->can($owner, $project, 'join'));
    $this->assertEqual($old, array($project->getEditPolicy(), $project->getJoinPolicy()));
  }

  public function testRevokeRemovesOnlyChosenSourcesAndDoesNotChangeMemberships() {
    $owner = $this->admin(); $viewer = $this->generateNewTestUser();
    $team = $this->team($owner, array($viewer));
    $child = $this->team($owner, array($viewer), $team);
    $project = $this->project($owner);
    $project = $this->change($owner, $project, array('users' => array($viewer->getPHID()),
      'teams' => array($team->getPHID()), 'descendantTeams' => array($team->getPHID())));
    $project = $this->change($owner, $project, array('mode' => 'revoke',
      'teams' => array($team->getPHID())));
    $this->assertTrue($this->can($viewer, $project, 'view'));
    $project = $this->change($owner, $project, array('mode' => 'revoke',
      'users' => array($viewer->getPHID())));
    $this->assertTrue($this->can($viewer, $project, 'view'));
    $project = $this->change($owner, $project, array('mode' => 'revoke',
      'descendantTeams' => array($team->getPHID())));
    $this->assertFalse($this->can($viewer, $project, 'view'));
    $this->assertEqual(array($viewer->getPHID()), TeamOrganizationService::getMemberPHIDs($team));
    $this->assertEqual(array($viewer->getPHID()), TeamOrganizationService::getMemberPHIDs($child));
    $this->assertEqual(array(), $project->getDirectMemberPHIDs());
    $before = $project->getViewPolicy();
    $project = $this->change($owner, $project, array('mode' => 'revoke',
      'users' => array($viewer->getPHID())));
    $this->assertEqual($before, $project->getViewPolicy());
  }

  public function testLegacyDenyOrderAndDefaultActionSurviveAddAndRevoke() {
    $owner = $this->admin(); $blocked = $this->generateNewTestUser();
    $outside = $this->generateNewTestUser();
    $legacy = $this->policy(array($this->userRule('deny', array($blocked))), 'allow');
    $project = $this->project($owner, array('view' => $legacy->getPHID()));
    $this->assertFalse($this->can($blocked, $project, 'view'));
    $this->assertTrue($this->can($outside, $project, 'view'));
    $project = $this->change($owner, $project, array('users' => array($blocked->getPHID())));
    $this->assertTrue($this->can($blocked, $project, 'view'));
    $policy = TeamProjectAccessService::getPolicy($owner, $project, 'view');
    $this->assertEqual('allow', $policy->getDefaultAction());
    $this->assertEqual('deny', $policy->getRules()[1]['action']);
    $this->reject(function() use ($owner, $project) {
      $this->change($owner, $project, array('mode' => 'remove-rule', 'ruleIndex' => 1));
    }, InvalidArgumentException::class);
    $project = $this->change($owner, $project, array('mode' => 'remove-rule', 'ruleIndex' => 0));
    $this->assertFalse($this->can($blocked, $project, 'view'));
    $this->assertTrue($this->can($outside, $project, 'view'));
    $this->assertEqual($legacy->getRules(),
      id(new PhabricatorPolicy())->load($legacy->getID())->getRules());
  }

  public function testSharedPolicyAndOtherProjectAreNeverMutated() {
    $owner = $this->admin(); $old_user = $this->generateNewTestUser();
    $added = $this->generateNewTestUser();
    $shared = $this->policy(array($this->userRule('allow', array($owner, $old_user))));
    $first = $this->project($owner, array('view' => $shared->getPHID()));
    $second = $this->project($owner, array('view' => $shared->getPHID()));
    $first = $this->change($owner, $first, array('users' => array($added->getPHID())));
    $first = $this->change($owner, $first, array('mode' => 'revoke',
      'users' => array($old_user->getPHID())));
    $this->assertFalse($this->can($old_user, $first, 'view'));
    $this->assertTrue($this->can($old_user, $second, 'view'));
    $this->assertFalse($this->can($added, $second, 'view'));
    $this->assertEqual($shared->getRules(), id(new PhabricatorPolicy())->load($shared->getID())->getRules());
    $this->assertEqual($shared->getPHID(), $this->fresh($second)->getViewPolicy());
  }

  public function testNamedPoliciesRequireExplicitReplacement() {
    $owner = $this->admin(); $viewer = $this->generateNewTestUser();
    $named = id(new PhorgeNamedPolicy())->setName('Access named policy')
      ->setTargetObjectType('PROJ')->setEffectivePolicy(PhabricatorPolicies::POLICY_USER)->save();
    $project = $this->project($owner, array('view' => $named->getPHID()));
    foreach (array('add', 'revoke', 'remove-rule') as $mode) {
      $this->reject(function() use ($owner, $project, $viewer, $mode) {
        $this->change($owner, $project, array('mode' => $mode,
          'users' => array($viewer->getPHID()), 'ruleIndex' => 0));
      }, InvalidArgumentException::class);
      $this->assertEqual($named->getPHID(), $this->fresh($project)->getViewPolicy());
    }
    $project = $this->change($owner, $project, array('mode' => 'replace',
      'users' => array($owner->getPHID())));
    $this->assertFalse($this->can($viewer, $project, 'view'));
    $this->assertEqual(PhabricatorPolicies::POLICY_USER,
      id(new PhorgeNamedPolicy())->load($named->getID())->getEffectivePolicy());
  }

  public function testStaleFormsInvalidNewSubjectsAndRuleLimitAreRejected() {
    $owner = $this->admin(); $viewer = $this->generateNewTestUser();
    $project = $this->project($owner); $old_state = TeamProjectAccessService::getStateToken($project);
    $project = $this->change($owner, $project, array('users' => array($viewer->getPHID())));
    $this->reject(function() use ($owner, $project, $viewer, $old_state) {
      $this->change($owner, $project, array('mode' => 'revoke',
        'users' => array($viewer->getPHID()), 'expectedState' => $old_state));
    }, InvalidArgumentException::class);
    $this->reject(function() use ($owner, $project) {
      $this->change($owner, $project, array());
    }, InvalidArgumentException::class);
    $viewer->setIsDisabled(1)->save();
    $this->reject(function() use ($owner, $project, $viewer) {
      $this->change($owner, $project, array('users' => array($viewer->getPHID())));
    }, InvalidArgumentException::class);
    $rules = array_fill(0, TeamProjectAccessService::MAX_RULES,
      $this->userRule('allow', array($owner)));
    $full = $this->policy($rules); $project = $this->project($owner, array('view' => $full->getPHID()));
    $new_user = $this->generateNewTestUser();
    $this->reject(function() use ($owner, $project, $new_user) {
      $this->change($owner, $project, array('users' => array($new_user->getPHID())));
    }, InvalidArgumentException::class);
    $oversized = $this->policy(array_fill(0, TeamProjectAccessService::MAX_RULES + 2,
      $this->userRule('allow', array($owner))));
    $project = $this->project($owner, array('view' => $oversized->getPHID()));
    $project = $this->change($owner, $project, array('mode' => 'remove-rule', 'ruleIndex' => 0));
    $this->assertEqual(TeamProjectAccessService::MAX_RULES + 1,
      count(TeamProjectAccessService::getPolicy($owner, $project, 'view')->getRules()));
  }

  public function testCurrentActorAndCurrentEditPermissionAreRequired() {
    $owner = $this->admin(); $reader = $this->generateNewTestUser();
    $project = $this->project($owner, array('view' => PhabricatorPolicies::POLICY_USER));
    $this->assertFalse(TeamProjectAccessService::canManage($reader, $project));
    $this->reject(function() use ($reader, $project) {
      $this->change($reader, $project, array('users' => array($reader->getPHID())));
    }, PhabricatorPolicyException::class);
    $stale = clone $owner; $owner->setIsDisabled(1)->save();
    $this->assertFalse(TeamProjectAccessService::canManage($stale, $project));
    $this->reject(function() use ($stale, $project, $reader) {
      $this->change($stale, $project, array('users' => array($reader->getPHID())));
    }, PhabricatorPolicyException::class);
    $owner->setIsDisabled(0)->setIsApproved(0)->save();
    $this->assertFalse(TeamProjectAccessService::canManage($stale, $project));
    $owner->setIsApproved(1)->save();
    $project = $this->project($owner, array('view' => PhabricatorPolicies::POLICY_USER,
      'edit' => PhabricatorPolicies::POLICY_ADMIN));
    $owner->setIsAdmin(0)->save();
    $this->assertFalse(TeamProjectAccessService::canManage($stale, $project));
    $this->reject(function() use ($stale, $project, $reader) {
      $this->change($stale, $project, array('users' => array($reader->getPHID())));
    }, PhabricatorPolicyException::class);
  }

  public function testNativeSelfLockoutProtectionIsPreserved() {
    $owner = $this->admin(); $other = $this->generateNewTestUser();
    $project = $this->project($owner); $state = TeamProjectAccessService::getStateToken($project);
    foreach (array('view', 'edit') as $capability) {
      $this->reject(function() use ($owner, $project, $other, $capability) {
        $this->change($owner, $project, array('capability' => $capability,
          'mode' => 'replace', 'users' => array($other->getPHID())));
      }, PhabricatorApplicationTransactionValidationException::class);
      $this->assertEqual($state, TeamProjectAccessService::getStateToken($this->fresh($project)));
    }
  }

  public function testStrictBoundaryNeedsExplicitClearAndMilestonesUseParent() {
    $owner = $this->admin(); $guest = $this->generateNewTestUser();
    $team = $this->team($owner, array($owner));
    $project = $this->project($owner, array(), array(), array(
      array('type' => 'organization.teams', 'value' => array($team->getPHID()))));
    $project = $this->change($owner, $project, array('users' => array($guest->getPHID())));
    $this->assertFalse($this->can($guest, $project, 'view'));
    $this->assertTrue((bool)TeamOrganizationProjectBoundary::newGuard($project));
    $project = $this->change($owner, $project, array('clearBoundary' => true));
    $this->assertTrue($this->can($guest, $project, 'view'));
    $this->assertEqual(null, TeamOrganizationProjectBoundary::newGuard($project));
    $result = id(new ConduitCall('project.edit', array('transactions' => array(
      array('type' => 'name', 'value' => 'Access Milestone'),
      array('type' => 'milestone', 'value' => $project->getPHID())))))
      ->setUser($owner)->execute();
    $milestone = $this->fresh($result['object']['phid']);
    $this->reject(function() use ($owner, $milestone, $guest) {
      $this->change($owner, $milestone, array('users' => array($guest->getPHID())));
    }, InvalidArgumentException::class);
  }

  public function testGlobalPublicCapabilityLimitAndHistoricalHiddenReferences() {
    $owner = $this->admin(); $editor = $this->generateNewTestUser();
    $guest = $this->generateNewTestUser();
    $project = $this->project($owner, array('view' => PhabricatorPolicies::POLICY_USER));
    // Native setters forbid public edit in the UI. Exercise a stored legacy
    // value to ensure composing it does not turn anonymous edit into an allow.
    $project->setEditPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
    $project = $this->change($owner, $project, array('capability' => 'edit',
      'users' => array($guest->getPHID())));
    $rules = TeamProjectAccessService::getPolicy($owner, $project, 'edit')->getRules();
    $this->assertEqual(PhabricatorPolicies::POLICY_USER, last($rules)['value']);
    $this->assertEqual(PhorgeGlobalPolicyRule::class, last($rules)['rule']);
    $team = $this->team($owner, array($guest));
    $project = $this->change($owner, $project, array('teams' => array($team->getPHID())));
    $team = TeamOrganizationService::saveTeam($owner, $team,
      array('viewPolicy' => PhabricatorPolicies::POLICY_ADMIN,
        'isArchived' => true, 'expectedVersion' => $team->getVersion()));
    // Delegation is explicit: an ordinary editor can no longer manage grants.
    $this->assertFalse(TeamProjectAccessService::canManage($editor, $project));
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, array(
      $owner->getPHID() => 'legacy-admin', $editor->getPHID() => 'admin'))->save();
    $project = $this->fresh($project);
    $this->assertTrue(TeamProjectAccessService::canManage($editor, $project));
    $project = $this->change($editor, $project, array('mode' => 'revoke',
      'teams' => array($team->getPHID())));
    $rules = TeamProjectAccessService::getPolicy($editor, $project, 'view')->getRules();
    $this->assertFalse(strpos(json_encode($rules), $team->getPHID()) !== false);
    $this->reject(function() use ($editor, $project, $team) {
      $this->change($editor, $project, array('teams' => array($team->getPHID())));
    }, InvalidArgumentException::class);
  }

  public function testFormTeamScopesAndMixedReplacementPreserveDistinctSources() {
    $owner = $this->admin(); $direct = $this->generateNewTestUser();
    $direct_child = $this->generateNewTestUser(); $branch = $this->generateNewTestUser();
    $branch_child = $this->generateNewTestUser(); $guest = $this->generateNewTestUser();
    $a = $this->team($owner, array($direct));
    $this->team($owner, array($direct_child), $a);
    $b = $this->team($owner, array($branch));
    $this->team($owner, array($branch_child), $b);
    $project = $this->project($owner);
    $unchanged = array($project->getEditPolicy(), $project->getJoinPolicy());
    $project = $this->form($owner, $project, array('teamScope' => 'direct',
      'teams' => array($a->getPHID())));
    $this->assertTrue($this->can($direct, $project, 'view'));
    $this->assertFalse($this->can($direct_child, $project, 'view'));
    $project = $this->form($owner, $project, array('teamScope' => 'descendants',
      'descendantTeams' => array($b->getPHID())));
    $this->assertTrue($this->can($branch_child, $project, 'view'));
    $project = $this->form($owner, $project, array('mode' => 'replace',
      'teamScope' => 'mixed', 'users' => array($owner->getPHID(), $guest->getPHID()),
      'teams' => array($a->getPHID()), 'descendantTeams' => array($b->getPHID())));
    foreach (array($owner, $direct, $branch, $branch_child, $guest) as $user) {
      $this->assertTrue($this->can($user, $project, 'view'));
    }
    $this->assertFalse($this->can($direct_child, $project, 'view'));
    $project = $this->form($owner, $project, array('mode' => 'revoke',
      'teamScope' => 'direct', 'teams' => array($a->getPHID())));
    $this->assertFalse($this->can($direct, $project, 'view'));
    $this->assertTrue($this->can($branch_child, $project, 'view'));
    $this->assertTrue($this->can($guest, $project, 'view'));
    $this->assertEqual($unchanged, array($project->getEditPolicy(), $project->getJoinPolicy()));
    $this->assertEqual(array(), $project->getDirectMemberPHIDs());
  }

  public function testFormRejectsInvalidDropdownsAndHiddenSelectionResidue() {
    $owner = $this->admin(); $user = $this->generateNewTestUser();
    $team = $this->team($owner, array($owner)); $project = $this->project($owner);
    $teams = array($team->getPHID()); $users = array($user->getPHID());
    $state = TeamProjectAccessService::getStateToken($project);
    $history = $this->historyCount($owner, $project);
    $inputs = array(
      array('mode' => 'invalid'), array('teamScope' => 'invalid'),
      array('boundaryMode' => 'invalid'), array('capability' => 'publish'),
      array('mode' => array('add')), array('teamScope' => array('direct')),
      array('teamScope' => 'none', 'teams' => $teams),
      array('teamScope' => 'none', 'descendantTeams' => $teams),
      array('teamScope' => 'direct', 'descendantTeams' => $teams),
      array('teamScope' => 'descendants', 'teams' => $teams),
      array('mode' => 'keep', 'users' => $users),
      array('mode' => 'all-users', 'users' => $users),
      array('mode' => 'administrators', 'teamScope' => 'direct', 'teams' => $teams),
      array('mode' => 'automatic', 'teamScope' => 'descendants', 'descendantTeams' => $teams),
      array('mode' => 'keep', 'boundaryMode' => 'keep', 'boundaryTeams' => $teams),
      array('mode' => 'keep', 'boundaryMode' => 'none', 'boundaryTeams' => $teams),
      array('mode' => 'keep', 'boundaryMode' => 'direct'),
      array('mode' => 'keep', 'boundaryMode' => 'descendants'),
      array('mode' => 'keep', 'clearBoundary' => true),
      array('mode' => 'keep', 'clearBoundary' => false),
    );
    foreach ($inputs as $input) {
      $this->reject(function() use ($owner, $project, $input) {
        $this->form($owner, $project, $input);
      }, InvalidArgumentException::class);
      $this->assertEqual($state, TeamProjectAccessService::getStateToken($this->fresh($project)));
      $this->assertEqual($history, $this->historyCount($owner, $project));
    }
  }

  public function testFormGlobalModesUseNativePoliciesAndKeepAutomaticGrants() {
    $owner = $this->admin(); $other_admin = $this->admin();
    $member = $this->generateNewTestUser(); $outside = $this->generateNewTestUser();
    $project = $this->project($owner, array(), array($owner, $member));
    $other = array($project->getEditPolicy(), $project->getJoinPolicy());
    foreach (array('all-users' => PhabricatorPolicies::POLICY_USER,
      'administrators' => PhabricatorPolicies::POLICY_ADMIN,
      'automatic' => PhabricatorPolicies::POLICY_NOONE) as $mode => $policy) {
      $project = $this->form($owner, $project, array('mode' => $mode));
      $this->assertEqual($policy, $project->getViewPolicy());
      $this->assertTrue($this->can($member, $project, 'view'));
      $this->assertEqual($mode === 'all-users', $this->can($outside, $project, 'view'));
      $this->assertEqual($mode !== 'automatic', $this->can($other_admin, $project, 'view'));
      $this->assertEqual($other, array($project->getEditPolicy(), $project->getJoinPolicy()));
    }
    foreach (array('all-users' => PhabricatorPolicies::POLICY_USER,
      'administrators' => PhabricatorPolicies::POLICY_ADMIN,
      'automatic' => PhabricatorPolicies::POLICY_NOONE) as $mode => $policy) {
      $project = $this->form($owner, $project, array('mode' => $mode, 'capability' => 'join'));
      $this->assertEqual($policy, $project->getJoinPolicy());
      $this->assertTrue($this->can($owner, $project, 'join'));
      $this->assertEqual($mode === 'all-users', $this->can($member, $project, 'join'));
    }
    foreach (array('all-users' => PhabricatorPolicies::POLICY_USER,
      'administrators' => PhabricatorPolicies::POLICY_ADMIN) as $mode => $policy) {
      $project = $this->form($owner, $project, array('mode' => $mode, 'capability' => 'edit'));
      $this->assertEqual($policy, $project->getEditPolicy());
      $this->assertTrue($this->can($owner, $project, 'edit'));
      $this->assertEqual(PhabricatorPolicies::POLICY_NOONE, $project->getViewPolicy());
    }
  }

  public function testFormKeepPreservesLegacyNamedPublicAndOrderedPoliciesWithoutHistory() {
    $owner = $this->admin(); $blocked = $this->generateNewTestUser();
    $ordered = $this->policy(array($this->userRule('deny', array($blocked))), 'allow');
    $named = id(new PhorgeNamedPolicy())->setName('Preserved named scope')
      ->setTargetObjectType('PROJ')->setEffectivePolicy(PhabricatorPolicies::POLICY_USER)->save();
    foreach (array($ordered->getPHID(), $named->getPHID(), PhabricatorPolicies::POLICY_PUBLIC) as $policy) {
      $project = $this->project($owner, array('view' => $policy));
      // Keep must not normalize even this historical unsupported edit value.
      $project->setEditPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
      $project = $this->fresh($project);
      $state = TeamProjectAccessService::getStateToken($project);
      $history = $this->historyCount($owner, $project);
      foreach (array('view', 'edit', 'join') as $capability) {
        $project = $this->form($owner, $project, array('mode' => 'keep', 'capability' => $capability));
        $this->assertEqual($state, TeamProjectAccessService::getStateToken($project));
        $this->assertEqual($history, $this->historyCount($owner, $project));
      }
    }
    $this->assertEqual('allow', id(new PhabricatorPolicy())->load($ordered->getID())->getDefaultAction());
    $this->assertEqual($ordered->getRules(), id(new PhabricatorPolicy())->load($ordered->getID())->getRules());
    $this->assertEqual(PhabricatorPolicies::POLICY_USER,
      id(new PhorgeNamedPolicy())->load($named->getID())->getEffectivePolicy());
  }

  public function testFormNamedPolicyAllowsBoundaryOnlyChangesOrExplicitReplacement() {
    $owner = $this->admin(); $guest = $this->generateNewTestUser();
    $team = $this->team($owner, array($owner));
    $named = id(new PhorgeNamedPolicy())->setName('Named form policy')
      ->setTargetObjectType('PROJ')->setEffectivePolicy(PhabricatorPolicies::POLICY_USER)->save();
    $project = $this->project($owner, array('view' => $named->getPHID()));
    foreach (array('add', 'revoke') as $mode) {
      $this->reject(function() use ($owner, $project, $guest, $mode) {
        $this->form($owner, $project, array('mode' => $mode, 'users' => array($guest->getPHID())));
      }, InvalidArgumentException::class);
      $this->reject(function() use ($owner, $project, $team, $mode) {
        $this->form($owner, $project, array('mode' => $mode, 'boundaryMode' => 'direct',
          'boundaryTeams' => array($team->getPHID())));
      }, InvalidArgumentException::class);
      $this->assertEqual(TeamProjectAccessService::getStateToken($project),
        TeamProjectAccessService::getStateToken($this->fresh($project)));
    }
    $project = $this->form($owner, $project, array('mode' => 'keep', 'boundaryMode' => 'direct',
      'boundaryTeams' => array($team->getPHID())));
    $this->assertEqual($named->getPHID(), $project->getViewPolicy());
    $this->assertFalse($this->can($guest, $project, 'view'));
    $project = $this->form($owner, $project, array('mode' => 'all-users', 'boundaryMode' => 'none'));
    $this->assertEqual(PhabricatorPolicies::POLICY_USER, $project->getViewPolicy());
    $this->assertTrue($this->can($guest, $project, 'view'));
    $project = $this->project($owner, array('view' => $named->getPHID()));
    $project = $this->form($owner, $project, array('mode' => 'replace', 'users' => array($owner->getPHID())));
    $this->assertFalse($this->can($guest, $project, 'view'));
    $this->assertEqual(PhabricatorPolicies::POLICY_USER,
      id(new PhorgeNamedPolicy())->load($named->getID())->getEffectivePolicy());
  }

  public function testFormBoundaryModesAffectAllCapabilitiesWithoutChangingTheirPolicies() {
    $owner = $this->admin(); $direct = $this->generateNewTestUser();
    $child = $this->generateNewTestUser(); $outside = $this->generateNewTestUser();
    $team = $this->team($owner, array($direct));
    $this->team($owner, array($child), $team);
    $policies = array_fill_keys(array('view', 'edit', 'join'), PhabricatorPolicies::POLICY_USER);
    $project = $this->project($owner, $policies, array($outside));
    foreach (array('direct', 'descendants', 'none') as $mode) {
      $project = $this->form($owner, $project, array('mode' => 'keep', 'boundaryMode' => $mode,
        'boundaryTeams' => $mode === 'none' ? array() : array($team->getPHID())));
      foreach (array('view', 'edit', 'join') as $capability) {
        $this->assertEqual($policies[$capability], $project->getPolicy($capability));
        $this->assertTrue($this->can($direct, $project, $capability));
        $this->assertEqual($mode !== 'direct', $this->can($child, $project, $capability));
        $this->assertEqual($mode === 'none', $this->can($outside, $project, $capability));
      }
    }
    $this->assertEqual(array($outside->getPHID()), $project->getDirectMemberPHIDs());
    $this->assertEqual(array($direct->getPHID()), TeamOrganizationService::getMemberPHIDs($team));
  }

  public function testFormPolicyAndBoundaryChangesRollBackTogetherOnSelfLockout() {
    $admin = $this->admin(); $owner = $this->generateNewTestUser();
    $outsider = $this->generateNewTestUser();
    $own_team = $this->team($admin, array($owner));
    $other_team = $this->team($admin, array($outsider));
    $project = $this->project($owner, array(), array(), array(
      array('type' => 'organization.teams', 'value' => array($own_team->getPHID()))));
    $state = TeamProjectAccessService::getStateToken($project);
    $history = $this->historyCount($owner, $project);
    foreach (array(
      array('mode' => 'all-users', 'capability' => 'edit', 'boundaryMode' => 'direct',
        'boundaryTeams' => array($other_team->getPHID())),
      array('mode' => 'automatic', 'capability' => 'edit', 'boundaryMode' => 'none'),
      array('mode' => 'administrators', 'capability' => 'view', 'boundaryMode' => 'none'),
    ) as $input) {
      $this->reject(function() use ($owner, $project, $input) {
        $this->form($owner, $project, $input);
      }, PhabricatorApplicationTransactionValidationException::class);
      $this->assertEqual($state, TeamProjectAccessService::getStateToken($this->fresh($project)));
      $this->assertEqual($history, $this->historyCount($owner, $project));
    }
    $project = $this->form($owner, $project, array('mode' => 'all-users', 'capability' => 'edit',
      'boundaryMode' => 'descendants', 'boundaryTeams' => array($own_team->getPHID())));
    $this->assertEqual(PhabricatorPolicies::POLICY_USER, $project->getEditPolicy());
    $this->assertEqual('descendants', $project->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY));
    $this->assertFalse($this->can($outsider, $project, 'edit'));
  }

  public function testFormEmptyOperationsRequireAnActualBoundaryChange() {
    $owner = $this->admin(); $team = $this->team($owner, array($owner));
    $project = $this->project($owner); $original = $project->getViewPolicy();
    foreach (array(array(), array('mode' => 'revoke', 'boundaryMode' => 'none')) as $input) {
      $this->reject(function() use ($owner, $project, $input) {
        $this->form($owner, $project, $input);
      }, InvalidArgumentException::class);
    }
    $project = $this->form($owner, $project, array('boundaryMode' => 'direct',
      'boundaryTeams' => array($team->getPHID())));
    $this->assertEqual($original, $project->getViewPolicy());
    $this->reject(function() use ($owner, $project, $team) {
      $this->form($owner, $project, array('boundaryMode' => 'direct',
        'boundaryTeams' => array($team->getPHID())));
    }, InvalidArgumentException::class);
    $project = $this->form($owner, $project, array('mode' => 'revoke', 'boundaryMode' => 'none'));
    $this->assertEqual(null, TeamOrganizationProjectBoundary::newGuard($project));
    $this->assertEqual($original, $project->getViewPolicy());
    $history = $this->historyCount($owner, $project);
    $project = $this->form($owner, $project, array('mode' => 'keep'));
    $this->assertEqual($history, $this->historyCount($owner, $project));
    $project = $this->form($owner, $project, array('mode' => 'replace', 'capability' => 'join'));
    $this->assertTrue($this->can($owner, $project, 'join'));
    $this->assertEqual(array(), TeamProjectAccessService::getPolicy($owner, $project, 'join')->getRules());
  }

  public function testFormRetainsExistingHiddenArchivedBoundaryReferencesWithoutAddingNewOnes() {
    $owner = $this->admin(); $editor = $this->generateNewTestUser();
    $hidden = $this->team($owner, array($editor));
    $active = $this->team($owner, array($editor));
    $new_hidden = $this->team($owner, array($editor));
    foreach (array($hidden, $new_hidden) as $team) {
      TeamOrganizationService::saveTeam($owner, $team, array(
        'viewPolicy' => PhabricatorPolicies::POLICY_ADMIN, 'expectedVersion' => $team->getVersion()));
    }
    $project = $this->project($owner, array('view' => PhabricatorPolicies::POLICY_USER,
      'edit' => PhabricatorPolicies::POLICY_USER), array(), array(
        array('type' => 'organization.teams', 'value' => array($hidden->getPHID(), $active->getPHID()))));
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, array(
      $owner->getPHID() => 'legacy-admin', $editor->getPHID() => 'legacy-admin'))->save();
    $project = $this->fresh($project);
    $hidden = id(new TeamOrganizationTeam())->load($hidden->getID());
    TeamOrganizationService::saveTeam($owner, $hidden,
      array('isArchived' => true, 'expectedVersion' => $hidden->getVersion()));
    $project = $this->form($editor, $project, array('mode' => 'keep', 'boundaryMode' => 'descendants',
      'boundaryTeams' => array($hidden->getPHID(), $active->getPHID())));
    $this->assertTrue(in_array($hidden->getPHID(), $project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY)));
    $state = TeamProjectAccessService::getStateToken($project);
    $this->reject(function() use ($editor, $project, $hidden, $active, $new_hidden) {
      $this->form($editor, $project, array('mode' => 'all-users', 'boundaryMode' => 'direct',
        'boundaryTeams' => array($hidden->getPHID(), $active->getPHID(), $new_hidden->getPHID())));
    }, PhabricatorApplicationTransactionValidationException::class);
    $this->assertEqual($state, TeamProjectAccessService::getStateToken($this->fresh($project)));
    $project = $this->form($editor, $project, array('mode' => 'keep', 'boundaryMode' => 'direct',
      'boundaryTeams' => array($active->getPHID())));
    $this->assertEqual(array($active->getPHID()), $project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY));
  }

  public function testFormKeepStillChecksVersionCurrentActorAndMilestoneOwnership() {
    $owner = $this->admin(); $guest = $this->generateNewTestUser();
    $project = $this->project($owner); $state = TeamProjectAccessService::getStateToken($project);
    $project = $this->change($owner, $project, array('users' => array($guest->getPHID())));
    $this->reject(function() use ($owner, $project, $state) {
      $this->form($owner, $project, array('mode' => 'keep', 'expectedState' => $state));
    }, InvalidArgumentException::class);
    $this->reject(function() use ($guest, $project) {
      $this->form($guest, $project, array('mode' => 'keep'));
    }, PhabricatorPolicyException::class);
    $stale = clone $owner; $owner->setIsDisabled(1)->save();
    $this->reject(function() use ($stale, $project) {
      $this->form($stale, $project, array('mode' => 'keep'));
    }, PhabricatorPolicyException::class);
    $owner->setIsDisabled(0)->save();
    $result = id(new ConduitCall('project.edit', array('transactions' => array(
      array('type' => 'name', 'value' => 'Form Milestone'),
      array('type' => 'milestone', 'value' => $project->getPHID())))))
      ->setUser($owner)->execute();
    $milestone = $this->fresh($result['object']['phid']);
    $this->reject(function() use ($owner, $milestone) {
      $this->form($owner, $milestone, array('mode' => 'keep'));
    }, InvalidArgumentException::class);
  }

  private function admin() {
    $user = $this->generateNewTestUser(); return $user->setIsAdmin(1)->save();
  }
  private function team($owner, array $members, $parent = null) {
    $team = TeamOrganizationService::saveTeam($owner, null, array(
      'name' => 'Access Team '.Filesystem::readRandomCharacters(12),
      'viewPolicy' => PhabricatorPolicies::POLICY_USER,
      'parentPHID' => $parent ? $parent->getPHID() : null));
    return TeamOrganizationService::setMembers($owner, $team,
      array_values(mpull($members, 'getPHID')), $team->getVersion());
  }
  private function project($owner, array $policies = array(), array $members = array(), array $extra = array()) {
    $policies += array('view' => $owner->getPHID(), 'edit' => $owner->getPHID(), 'join' => PhabricatorPolicies::POLICY_NOONE);
    $tx = array(array('type' => 'name', 'value' => 'Access Project '.Filesystem::readRandomCharacters(12)),
      array('type' => 'members.set', 'value' => array_values(mpull($members, 'getPHID'))));
    foreach ($policies as $key => $value) { $tx[] = array('type' => $key, 'value' => $value); }
    $result = id(new ConduitCall('project.edit', array('transactions' => array_merge($tx, $extra))))
      ->setUser($owner)->execute();
    // Existing ACL regressions exercise legacy projects; new roles have their own suite.
    $project = $this->fresh($result['object']['phid']);
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, null)->save();
    return $this->fresh($project);
  }
  private function fresh($project) {
    return id(new PhabricatorProjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array(is_string($project) ? $project : $project->getPHID()))
      ->needMembers(true)->needAncestorMembers(true)->executeOne();
  }
  private function change($actor, $project, array $input) {
    $input += array('expectedState' => TeamProjectAccessService::getStateToken($project));
    return TeamProjectAccessService::apply($actor, $project, $input, $this->source());
  }
  private function form($actor, $project, array $input) {
    $input += array('expectedState' => TeamProjectAccessService::getStateToken($project));
    return TeamProjectAccessService::applyForm($actor, $project, $input, $this->source());
  }
  private function historyCount($actor, $project) {
    return count(id(new PhabricatorProjectTransactionQuery())->setViewer($actor)
      ->withObjectPHIDs(array($project->getPHID()))->execute());
  }
  private function source() {
    return PhabricatorContentSource::newForSource(PhabricatorUnitTestContentSource::SOURCECONST);
  }
  private function can($viewer, $project, $capability) {
    return PhabricatorPolicyFilter::hasCapability($viewer, $this->fresh($project), $capability);
  }
  private function userRule($action, array $users) {
    return array('action' => $action, 'rule' => PhabricatorUsersPolicyRule::class,
      'value' => array_values(mpull($users, 'getPHID')));
  }
  private function policy(array $rules, $default = 'deny') {
    return id(new PhabricatorPolicy())->setRules($rules)->setDefaultAction($default)->save();
  }
  private function reject($callback, $class) {
    $caught = null;
    try { $callback(); } catch (Throwable $ex) { $caught = $ex; }
    $this->assertTrue($caught instanceof $class,
      $caught ? get_class($caught).': '.$caught->getMessage() : 'Expected rejection.');
  }
}
