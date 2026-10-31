<?php

/** Native storage-fixture regressions for independent team/project access. */
final class TeamSubprojectPermissionsTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testFirstLaterAndNestedSubprojectsRespectOwnPolicy() {
    $creator = $this->newUser('creator');
    $parent_member = $this->newUser('parentmember');
    $invited = $this->newUser('invited');
    $parent = $this->newProject($creator, null, array($parent_member));

    $private = array(
      'view' => $creator->getPHID(),
      'edit' => $creator->getPHID(),
      'join' => PhabricatorPolicies::POLICY_NOONE,
    );
    $first = $this->newProject($creator, $parent, array($invited), $private);
    $this->assertPolicies($private, $first);
    $this->assertMembers(array($invited), $first, true);
    $this->assertMembers(array($invited), $first);
    $this->assertMembers(array($parent_member), $parent, true);
    $this->assertMembers(array($parent_member, $invited), $parent);
    $this->assertDeniedAll($parent_member, $first);
    $this->assertTrue($this->can($creator, $first, 'edit'));
    $this->assertTrue($this->can($invited, $first, 'view'));
    $this->assertFalse($this->can($invited, $first, 'edit'));
    $this->assertFalse($this->can($invited, $first, 'join'));

    $second = $this->newProject($creator, $parent, array(), $private);
    $this->assertPolicies($private, $second);
    $this->assertMembers(array(), $second, true);
    $this->assertMembers(array(), $second);
    $this->assertDeniedAll($parent_member, $second);
    $this->assertDeniedAll($invited, $second);

    $nested = $this->newProject($creator, $first, array(), $private);
    $this->assertPolicies($private, $nested);
    $this->assertMembers(array(), $nested, true);
    $this->assertMembers(array($invited), $first);
    $this->assertMembers(array($parent_member, $invited), $parent);
    $this->assertDeniedAll($invited, $nested);
    $this->assertDeniedAll($parent_member, $nested);

    // Explicit broader selections must also survive; these users may view
    // and join because the creator selected that scope, not via the parent.
    $shared = $private;
    $shared['view'] = PhabricatorPolicies::POLICY_USER;
    $shared['join'] = PhabricatorPolicies::POLICY_USER;
    $third = $this->newProject($creator, $parent, array(), $shared);
    $this->assertPolicies($shared, $third);
    $this->assertMembers(array(), $third, true);
    $this->assertTrue($this->can($parent_member, $third, 'view'));
    $this->assertTrue($this->can($parent_member, $third, 'join'));
    $this->assertFalse($this->can($parent_member, $third, 'edit'));
  }

  public function testParentMembershipCanBeManagedWithoutChangingChildren() {
    $creator = $this->newUser('creator');
    $a = $this->newUser('a');
    $b = $this->newUser('b');
    $c = $this->newUser('c');
    $d = $this->newUser('d');
    $parent = $this->newProject($creator, null, array($a));
    $child = $this->newProject($creator, $parent, array($b));
    $leaf = $this->newProject($creator, $child, array($c));
    $this->assertMembers(array($a, $b, $c), $parent);
    $this->assertMembers(array($b, $c), $child);
    $this->assertMembers(array($c), $leaf);
    $this->assertTrue($this->refresh($parent)->supportsEditMembers());
    $this->assertTrue($this->refresh($child)->supportsEditMembers());

    $this->editMembers($creator, $parent, 'members.add', array($b, $d));
    $this->assertMembers(array($a, $b, $d), $parent, true);
    $this->assertMembers(array($a, $b, $c, $d), $parent);
    $this->assertMembers(array($b), $child, true);
    $this->assertMembers(array($c), $leaf, true);

    // Removing a direct edge must retain any membership coming from a child.
    $this->editMembers($creator, $parent, 'members.remove', array($b, $d));
    $this->assertMembers(array($a), $parent, true);
    $this->assertMembers(array($a, $b, $c), $parent);
    $this->editMembers($creator, $child, 'members.remove', array($b));
    $this->assertMembers(array(), $child, true);
    $this->assertMembers(array($c), $child);
    $this->assertMembers(array($a, $c), $parent);

    $this->editMembers($creator, $parent, 'members.set', array());
    $this->assertMembers(array(), $parent, true);
    $this->assertMembers(array($c), $parent);
    $this->assertMembers(array($c), $leaf, true);
  }

  public function testMilestonesRetainParentPoliciesAndMembers() {
    $creator = $this->newUser('creator');
    $a = $this->newUser('a');
    $b = $this->newUser('b');
    $parent = $this->newProject($creator, null, array($a));
    $this->newProject($creator, $parent, array($b));
    $result = $this->api($creator, array(
      array('type' => 'name', 'value' => 'Milestone '.Filesystem::readRandomCharacters(12)),
      array('type' => 'milestone', 'value' => $parent->getPHID()),
    ));
    $milestone = $this->refresh($result['object']['phid']);
    $parent = $this->refresh($parent);
    $this->assertTrue($milestone->isMilestone());
    $this->assertFalse($milestone->supportsEditMembers());
    $this->assertMembers(array($a, $b), $milestone);
    $this->assertMembers(array(), $milestone, true);
    foreach (array('view', 'edit', 'join') as $capability) {
      $this->assertEqual(
        $parent->getPolicy($capability), $milestone->getPolicy($capability));
    }
  }

  public function testLegacyParentDormantMemberAudit() {
    $creator = $this->newUser('creator');
    $former = $this->newUser('former');
    $current = $this->newUser('current');
    $parent = $this->newProject($creator, null, array($former));
    $this->newProject($creator, $parent, array($current));
    $this->assertTrue(PhabricatorProjectMembershipAudit::inspect()['passed']);

    // Reproduce a pre-upgrade parent: the stale direct edge remains after
    // a member leaves all of its children, so it is absent from the old
    // materialized membership. This writes only to the fixture schema.
    $material_type = PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;
    queryfx(
      $parent->establishConnection('w'),
      'DELETE FROM %T WHERE src = %s AND type = %d AND dst = %s',
      PhabricatorEdgeConfig::TABLE_NAME_EDGE,
      $parent->getPHID(),
      $material_type,
      $former->getPHID());

    $report = PhabricatorProjectMembershipAudit::inspect();
    $this->assertFalse($report['passed']);
    $this->assertTrue($report['readOnly']);
    $this->assertEqual(1, count($report['findings']));
    $this->assertEqual(array(
      'projectID' => (int)$parent->getID(),
      'projectPHID' => $parent->getPHID(),
      'dormantDirectMemberPHIDs' => array($former->getPHID()),
    ), $report['findings'][0]);
    // The audit must not remove the direct edge or rebuild the index.
    $this->assertMembers(array($former), $parent, true);
    $this->assertMembers(array($current), $parent);
  }

  private function newUser($name) {
    // Native Conduit edit fields read the actor's attached preference cache.
    // The fixture helper supplies a complete approved synthetic identity.
    return $this->generateNewTestUser()
      ->setRealName('Subproject Test '.$name)
      ->save();
  }

  private function newProject($creator, $parent, array $members, array $policies = array()) {
    $policies += array(
      'view' => PhabricatorPolicies::POLICY_USER,
      'edit' => PhabricatorPolicies::POLICY_USER,
      'join' => PhabricatorPolicies::POLICY_USER,
    );
    $transactions = array(
      array('type' => 'name', 'value' => 'Subproject '.Filesystem::readRandomCharacters(16)),
      array('type' => 'members.set', 'value' => array_values(mpull($members, 'getPHID'))),
    );
    if ($parent) {
      $transactions[] = array('type' => 'parent', 'value' => $parent->getPHID());
    }
    foreach ($policies as $type => $value) {
      $transactions[] = array('type' => $type, 'value' => $value);
    }
    $result = $this->api($creator, $transactions);
    // Existing ACL regressions exercise legacy projects; new roles have their own suite.
    $project = $this->refresh($result['object']['phid']);
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, null)->save();
    return $this->refresh($project);
  }

  private function api($actor, array $transactions, $project = null) {
    $params = array('transactions' => $transactions);
    if ($project) {
      $params['objectIdentifier'] = $project->getPHID();
    }
    return id(new ConduitCall('project.edit', $params))->setUser($actor)->execute();
  }

  private function editMembers($actor, $project, $type, array $users) {
    $this->api($actor, array(array(
      'type' => $type, 'value' => array_values(mpull($users, 'getPHID')),
    )), $project);
  }

  private function refresh($project) {
    $phid = is_string($project) ? $project : $project->getPHID();
    return id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))
      ->needMembers(true)
      ->needAncestorMembers(true)
      ->executeOne();
  }

  private function assertMembers(array $users, $project, $direct = false) {
    $project = $this->refresh($project);
    $expect = array_values(mpull($users, 'getPHID'));
    $actual = $direct ? $project->getDirectMemberPHIDs() : $project->getMemberPHIDs();
    sort($expect);
    sort($actual);
    $this->assertEqual($expect, $actual);
  }

  private function assertPolicies(array $expect, $project) {
    $project = $this->refresh($project);
    $this->assertEqual($expect['view'], $project->getViewPolicy());
    $this->assertEqual($expect['edit'], $project->getEditPolicy());
    $this->assertEqual($expect['join'], $project->getJoinPolicy());
  }

  private function can($user, $project, $capability) {
    return PhabricatorPolicyFilter::hasCapability(
      $user, $this->refresh($project), $capability);
  }

  private function assertDeniedAll($user, $project) {
    foreach (array('view', 'edit', 'join') as $capability) {
      $this->assertFalse($this->can($user, $project, $capability));
    }
  }
}
