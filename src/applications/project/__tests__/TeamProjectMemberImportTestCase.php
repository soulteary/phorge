<?php

/** Native storage-fixture regressions for independent team/project access. */
final class TeamProjectMemberImportTestCase extends PhabricatorTestCase {
  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testMixedSourcesDeduplicateAndImportIsASnapshot() {
    $admin = $this->admin(); $actor = $this->generateNewTestUser();
    $a = $this->generateNewTestUser(); $b = $this->generateNewTestUser(); $c = $this->generateNewTestUser();
    $team = $this->team($admin, array($a, $b));
    $project = $this->project($admin, array($b, $c));
    $before = array($project->getViewPolicy(), $project->getEditPolicy(), $project->getJoinPolicy(),
      $project->getDirectMemberPHIDs());
    $tokens = array($a->getUsername(), '@'.$a->getUsername(), $a->getPHID(),
      'teamMembers('.$team->getPHID().')', 'members('.$project->getPHID().')');
    $this->same(array($a, $b, $c), TeamProjectMemberImport::resolve($actor, $tokens));
    $snapshot = TeamProjectMemberImport::resolve($actor, array('teamMembers('.$team->getPHID().')'));
    TeamOrganizationService::setMembers($admin, $team, array($c->getPHID()), $team->getVersion());
    $this->same(array($a, $b), $snapshot);
    $this->same(array($c), TeamProjectMemberImport::resolve($actor, array('teamMembers('.$team->getPHID().')')));
    $project = $this->fresh($project);
    $this->assertEqual($before, array($project->getViewPolicy(), $project->getEditPolicy(),
      $project->getJoinPolicy(), $project->getDirectMemberPHIDs()));
    $this->assertFalse(PhabricatorPolicyFilter::hasCapability($actor, $project, 'edit'));
  }

  public function testOnlyDirectTeamAndProjectMembersAreCopied() {
    $admin = $this->admin(); $actor = $this->generateNewTestUser();
    $a = $this->generateNewTestUser(); $b = $this->generateNewTestUser();
    $parent = $this->team($admin, array($a));
    $this->team($admin, array($b), array('parentPHID' => $parent->getPHID()));
    $project = $this->project($admin, array($a));
    $this->project($admin, array($b), array(array('type' => 'parent', 'value' => $project->getPHID())));
    $project = $this->fresh($project);
    $this->assertTrue(in_array($b->getPHID(), $project->getMemberPHIDs()));
    $this->same(array($a), TeamProjectMemberImport::resolve($actor,
      array('teamMembers('.$parent->getPHID().')', 'members('.$project->getPHID().')')));
  }

  public function testHiddenUnreadableArchivedAndMilestoneSourcesRejectWholeImport() {
    $admin = $this->admin(); $actor = $this->generateNewTestUser(); $a = $this->generateNewTestUser();
    $unreadable = $this->team($admin, array($a), array('membersPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $hidden = $this->team($admin, array($a), array('viewPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $archived = $this->team($admin, array($a));
    TeamOrganizationService::saveTeam($admin, $archived, array('name' => $archived->getName(),
      'description' => '', 'parentPHID' => null, 'viewPolicy' => PhabricatorPolicies::POLICY_USER,
      'membersPolicy' => PhabricatorPolicies::POLICY_USER, 'isArchived' => true,
      'expectedVersion' => $archived->getVersion()));
    $private = $this->project($admin, array($a), array(array('type' => 'view', 'value' => $admin->getPHID())));
    $closed = $this->project($admin, array($a)); $closed->setStatus(PhabricatorProjectStatus::STATUS_ARCHIVED)->save();
    $parent = $this->project($admin, array($a));
    $milestone = $this->project($admin, array(), array(array('type' => 'milestone', 'value' => $parent->getPHID())));
    $sources = array('teamMembers('.$unreadable->getPHID().')', 'teamMembers('.$hidden->getPHID().')',
      'teamMembers('.$archived->getPHID().')', 'members('.$private->getPHID().')',
      'members('.$closed->getPHID().')', 'members('.$milestone->getPHID().')',
      'members(PHID-PROJ-00000000000000000000)');
    foreach ($sources as $source) {
      $this->rejected(function() use ($actor, $source, $a) {
        TeamProjectMemberImport::resolve($actor, array($a->getPHID(), $source));
      });
    }
  }

  public function testSearchRequiresTeamRosterAndProjectVisibility() {
    $admin = $this->admin(); $actor = $this->generateNewTestUser();
    $open = $this->team($admin, array());
    $this->team($admin, array(), array('membersPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $this->team($admin, array(), array('viewPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $source = id(new TeamProjectMemberTeamDatasource())->setViewer($actor)->setRawQuery('')->setLimit(100);
    $this->assertEqual(array('teamMembers('.$open->getPHID().')'), mpull($source->loadResults(), 'getPHID'));
    $public = $this->project($admin, array());
    $this->project($admin, array(), array(array('type' => 'view', 'value' => $admin->getPHID())));
    $this->project($admin, array(), array(array('type' => 'milestone', 'value' => $public->getPHID())));
    $source = id(new TeamProjectMemberProjectDatasource())->setViewer($actor)->setRawQuery('')->setLimit(100);
    $this->assertEqual(array('members('.$public->getPHID().')'), mpull($source->loadResults(), 'getPHID'));
  }

  public function testUnusableUsersAreFilteredAndCachedActorsAreRefreshed() {
    $admin = $this->admin(); $a = $this->generateNewTestUser();
    $disabled = $this->generateNewTestUser()->setIsDisabled(1)->save();
    $pending = $this->generateNewTestUser()->setIsApproved(0)->save();
    $bot = $this->generateNewTestUser()->setIsSystemAgent(1)->save();
    $mail = $this->generateNewTestUser()->setIsMailingList(1)->save();
    $this->same(array($a), TeamProjectMemberImport::resolve($admin,
      array($a->getPHID(), $disabled->getPHID(), $pending->getPHID(), $bot->getPHID(),
        $mail->getPHID(), 'missing_username', 'PHID-USER-00000000000000000000')));
    $private = $this->team($admin, array($a), array('membersPolicy' => PhabricatorPolicies::POLICY_ADMIN));
    $cached = clone $admin; $admin->setIsAdmin(0)->save();
    $this->rejected(function() use ($cached, $private) {
      TeamProjectMemberImport::resolve($cached, array('teamMembers('.$private->getPHID().')'));
    });
    $a->setIsDisabled(1)->save();
    $this->rejected(function() use ($a) { TeamProjectMemberImport::resolve($a, array()); });
  }

  public function testMalformedTokensAndInputLimitAreRejected() {
    $actor = $this->generateNewTestUser();
    foreach (array(array(array()), array(5), array('teamMembers(PHID-PROJ-00000000000000000000)'),
      array('members(PHID-ORGT-00000000000000000000)'), array('members(me())'),
      array('PHID-PROJ-00000000000000000000'), array_fill(0, 501, $actor->getPHID())) as $tokens) {
      $this->rejected(function() use ($actor, $tokens) { TeamProjectMemberImport::resolve($actor, $tokens); });
    }
  }

  public function testExpandedLimitAndMoreThanOnePageOfUsers() {
    // This deliberately exercises a real 501-person fixture, rather than a
    // mock. The framework's four-second per-case CPU limit is for small tests.
    set_time_limit(0);
    $admin = $this->admin(); $users = array();
    for ($ii = 0; $ii < 501; $ii++) { $users[] = $this->generateNewTestUser(); }
    $project = $this->project($admin, array_slice($users, 0, 500));
    $this->assertEqual(500, count(TeamProjectMemberImport::resolve($admin, array('members('.$project->getPHID().')'))));
    id(new ConduitCall('project.edit', array('objectIdentifier' => $project->getPHID(),
      'transactions' => array(array('type' => 'members.add', 'value' => array($users[500]->getPHID()))))))
      ->setUser($admin)->execute();
    $this->rejected(function() use ($admin, $project) {
      TeamProjectMemberImport::resolve($admin, array('members('.$project->getPHID().')'));
    });
  }

  private function admin() { return $this->generateNewTestUser()->setIsAdmin(1)->save(); }
  private function team($admin, array $users, array $extra = array()) {
    $team = TeamOrganizationService::saveTeam($admin, null, $extra + array(
      'name' => 'Import Team '.Filesystem::readRandomCharacters(16), 'description' => '',
      'parentPHID' => null, 'viewPolicy' => PhabricatorPolicies::POLICY_USER,
      'membersPolicy' => PhabricatorPolicies::POLICY_USER, 'isArchived' => false));
    TeamOrganizationService::setMembers($admin, $team, array_values(mpull($users, 'getPHID')), $team->getVersion());
    return id(new TeamOrganizationTeam())->load($team->getID());
  }
  private function project($admin, array $members, array $extra = array()) {
    $transactions = array(array('type' => 'name', 'value' => 'Import Project '.Filesystem::readRandomCharacters(16)),
      array('type' => 'view', 'value' => PhabricatorPolicies::POLICY_USER),
      array('type' => 'edit', 'value' => $admin->getPHID()));
    $milestone = false;
    foreach ($extra as $entry) { $milestone = $milestone || $entry['type'] === 'milestone'; }
    if ($milestone) { $transactions = array(head($transactions)); }
    else { $transactions[] = array('type' => 'members.set', 'value' => array_values(mpull($members, 'getPHID'))); }
    $result = id(new ConduitCall('project.edit', array('transactions' => array_merge($transactions, $extra))))
      ->setUser($admin)->execute();
    return $this->fresh($result['object']['phid']);
  }
  private function fresh($project) {
    $phid = is_string($project) ? $project : $project->getPHID();
    return id(new PhabricatorProjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))->needMembers(true)->needAncestorMembers(true)->executeOne();
  }
  private function same(array $expected, array $actual) {
    $expected = array_values(mpull($expected, 'getPHID')); sort($expected); sort($actual);
    $this->assertEqual($expected, $actual);
  }
  private function rejected($callback) {
    $caught = null;
    try { $callback(); } catch (Throwable $ex) { $caught = $ex; }
    $this->assertTrue($caught instanceof InvalidArgumentException,
      $caught ? get_class($caught).': '.$caught->getMessage() : 'Expected rejection.');
  }
}
