<?php

/** Native storage-fixture regressions for independent team/project access. */
final class TeamProjectMembershipTestCase extends PhabricatorTestCase {
  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testCreatorAdminIsIndependentOfInitialMembersAndExplicitACL() {
    $owner = $this->user();
    $project = $this->project($owner, array(), array('view' => 'no-one', 'edit' => 'no-one'));
    $this->assertEqual(array(), $project->getDirectMemberPHIDs());
    $this->assertEqual('admin', TeamProjectMembership::getRole($project, $owner->getPHID()));
    $this->assertTrue($this->can($owner, $project, 'view'));
    $this->assertTrue($this->can($owner, $project, 'edit'));
    $this->assertTrue(TeamProjectMembership::canManage($owner, $project));
  }

  public function testLegacyCreatorAndSiteAdminStillNeedOriginalACL() {
    $owner = $this->user(); $other = $this->user(); $admin = $this->user(true);
    $project = $this->project($owner);
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, null)->setEditPolicy($other->getPHID())->save();
    $project = $this->fresh($project);
    $this->assertFalse($this->can($owner, $project, 'edit'));
    $this->assertFalse(TeamProjectMembership::canManage($owner, $project));
    $this->assertFalse(TeamProjectMembership::canManage($admin, $project));
    $this->assertEqual('admin', TeamProjectMembership::getRole($project, $owner->getPHID()));
  }

  public function testEditorCannotChangeMembersOrPoliciesThroughAPI() {
    $owner = $this->user(); $editor = $this->user(); $guest = $this->user();
    $project = $this->project($owner, array($editor));
    $project = $this->role($owner, $project, $editor, 'editor');
    $this->assertTrue($this->can($editor, $project, 'edit'));
    $this->assertFalse(TeamProjectMembership::canManage($editor, $project));
    foreach (array(array('type' => 'members.add', 'value' => array($guest->getPHID())),
      array('type' => 'members.remove', 'value' => array($owner->getPHID())),
      array('type' => 'view', 'value' => PhabricatorPolicies::POLICY_USER),
      array('type' => 'edit', 'value' => PhabricatorPolicies::POLICY_USER),
      array('type' => 'join', 'value' => PhabricatorPolicies::POLICY_USER)) as $transaction) {
      $this->reject(function() use ($editor, $project, $transaction) {
        $this->edit($editor, $project, array($transaction));
      });
    }
    $this->assertEqual(array($editor->getPHID()), $this->fresh($project)->getDirectMemberPHIDs());
  }

  public function testSelfJoinAndLeaveRemainIdempotentWithoutManagementRole() {
    $owner = $this->user(); $member = $this->user(); $other = $this->user();
    $project = $this->project($owner, array(), array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    $this->assertFalse(TeamProjectMembership::canManage($member, $project));
    foreach (array('members.add', 'members.add') as $type) {
      $project = $this->edit($member, $project,
        array(array('type' => $type, 'value' => array($member->getPHID()))));
    }
    $this->assertEqual(array($member->getPHID()), $project->getDirectMemberPHIDs());
    foreach (array('members.remove', 'members.remove') as $type) {
      $project = $this->edit($member, $project,
        array(array('type' => $type, 'value' => array($member->getPHID()))));
    }
    $this->assertEqual(array(), $project->getDirectMemberPHIDs());
    $this->reject(function() use ($member, $project, $other) {
      $this->edit($member, $project,
        array(array('type' => 'members.add', 'value' => array($other->getPHID()))));
    });
    $this->assertFalse(TeamProjectMembership::canManage($member, $project));
  }

  public function testSelfMembershipCannotHideAdministrativeIntentInNoEffectTargets() {
    $owner = $this->user(); $member = $this->user();
    $existing = $this->user(); $absent = $this->user();
    $project = $this->project($owner, array($existing),
      array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    $me = $member->getPHID(); $there = $existing->getPHID(); $missing = $absent->getPHID();
    foreach (array(
      array('+' => array($there => $there)),
      array('-' => array($missing => $missing)),
      array('=' => array($there => $there)),
      array('+' => array($me => $me, $there => $there)),
      array('+' => array($me => $me), '-' => array($missing => $missing)),
    ) as $spec) {
      $this->reject(function() use ($member, $project, $spec) {
        $this->memberSpec($member, $project, $spec);
      });
      $this->assertEqual(array($there), $this->fresh($project)->getDirectMemberPHIDs());
    }
    $project = $this->memberSpec($member, $project, array('+' => array($me => $me)));
    foreach (array(
      array('-' => array($me => $me, $missing => $missing)),
      array('-' => array($me => $me), '+' => array($there => $there)),
      array('=' => array($me => $me, $there => $there)),
    ) as $spec) {
      $this->reject(function() use ($member, $project, $spec) {
        $this->memberSpec($member, $project, $spec);
      });
      $members = $this->fresh($project)->getDirectMemberPHIDs(); sort($members);
      $expected = array($me, $there); sort($expected);
      $this->assertEqual($expected, $members);
    }
  }

  public function testNativeInverseSelfMembershipKeepsUnchangedOtherMembers() {
    $owner = $this->user(); $member = $this->user(); $other = $this->user();
    $project = $this->project($owner, array($other),
      array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    foreach (array('+', '-') as $operation) {
      $this->inverseMembership($member, $project, $operation);
      $members = $this->fresh($project)->getDirectMemberPHIDs(); sort($members);
      $expected = $operation === '+'
        ? array($other->getPHID(), $member->getPHID()) : array($other->getPHID());
      sort($expected); $this->assertEqual($expected, $members);
    }
    // The complete normalized inverse newValue is empty for the last member.
    $project = $this->project($owner, array(),
      array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    $this->inverseMembership($member, $project, '+');
    $this->inverseMembership($member, $project, '-');
    $this->assertEqual(array(), $this->fresh($project)->getDirectMemberPHIDs());
    $this->assertFalse(TeamProjectMembership::canManage($member, $project));

    $project = $this->project($owner, array($member, $other),
      array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    $project = $this->role($owner, $project, $member, 'editor');
    $this->inverseMembership($member, $project, '-');
    $this->assertEqual('policy', TeamProjectMembership::getRole($this->fresh($project), $member->getPHID()));
    $this->assertEqual(array($other->getPHID()), $this->fresh($project)->getDirectMemberPHIDs());

    $project = $this->project($owner, array($owner),
      array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    $this->reject(function() use ($owner, $project) {
      $this->inverseMembership($owner, $project, '-');
    });
    $this->assertEqual('admin', TeamProjectMembership::getRole($this->fresh($project), $owner->getPHID()));
    $this->assertEqual(array($owner->getPHID()), $this->fresh($project)->getDirectMemberPHIDs());
  }

  public function testViewerCeilingOverridesBroadPolicyAndSurvivesSelfLeaveAndRejoin() {
    $owner = $this->user(); $viewer = $this->user();
    $project = $this->project($owner, array($viewer), array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    $project = $this->role($owner, $project, $viewer, 'viewer');
    $this->assertTrue($this->can($viewer, $project, 'view'));
    $this->assertFalse($this->can($viewer, $project, 'edit'));
    $project = $this->edit($viewer, $project, array(array('type' => 'members.remove', 'value' => array($viewer->getPHID()))));
    $this->assertEqual('viewer', TeamProjectMembership::getRole($project, $viewer->getPHID()));
    $this->assertFalse($this->can($viewer, $project, 'edit'));
    $project = $this->edit($viewer, $project, array(array('type' => 'members.add', 'value' => array($viewer->getPHID()))));
    $this->assertFalse($this->can($viewer, $project, 'edit'));
  }

  public function testForgedSelfLeaveRoleCleanupCannotLiftReadOnlyCeiling() {
    $owner = $this->user(); $viewer = $this->user();
    $project = $this->project($owner, array($viewer), array('view' => 'users', 'edit' => 'users', 'join' => 'users'));
    $project = $this->role($owner, $project, $viewer, 'viewer');
    $roles = TeamProjectMembership::roleMap($project); unset($roles[$viewer->getPHID()]);
    $this->reject(function() use ($viewer, $project, $roles) {
      id(new PhabricatorProjectTransactionEditor())->setActor($viewer)->setContentSource($this->source())
        ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)->applyTransactions($project, array(
          id(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
            ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
            ->setNewValue(array('-' => array($viewer->getPHID() => $viewer->getPHID()))),
          id(new PhabricatorProjectTransaction())->setTransactionType(TeamProjectMemberRoleTransaction::TRANSACTIONTYPE)
            ->setNewValue($roles)));
    });
    $this->assertFalse($this->can($viewer, $project, 'edit'));
  }

  public function testAdminCanAddRemoveMembersAndClearTheirRoleWithAudit() {
    $owner = $this->user(); $admin = $this->user(); $guest = $this->user();
    $project = $this->project($owner, array($admin));
    $project = $this->role($owner, $project, $admin, 'admin');
    $this->assertTrue(TeamProjectMembership::canManage($admin, $project));
    $project = $this->edit($admin, $project, array(array('type' => 'members.add', 'value' => array($guest->getPHID()))));
    $project = $this->role($admin, $project, $guest, 'editor');
    $project = $this->edit($admin, $project, array(array('type' => 'members.remove', 'value' => array($guest->getPHID()))));
    $this->assertFalse(in_array($guest->getPHID(), $project->getDirectMemberPHIDs()));
    $this->assertEqual('policy', TeamProjectMembership::getRole($project, $guest->getPHID()));
    $history = id(new PhabricatorProjectTransactionQuery())->setViewer($owner)
      ->withObjectPHIDs(array($project->getPHID()))
      ->withTransactionTypes(array(TeamProjectMemberRoleTransaction::TRANSACTIONTYPE))->execute();
    $this->assertTrue(count($history) >= 4);
  }

  public function testLastAdminProtectionAndExplicitTransfer() {
    $owner = $this->user(); $successor = $this->user();
    $project = $this->project($owner, array($successor), array('view' => 'no-one', 'edit' => 'no-one'));
    $this->reject(function() use ($owner, $project) { $this->role($owner, $project, $owner, 'viewer'); });
    $project = $this->role($owner, $project, $successor, 'admin');
    $project = $this->role($successor, $project, $owner, 'viewer');
    $this->assertTrue($this->can($owner, $project, 'view'));
    $this->assertFalse(TeamProjectMembership::canManage($owner, $project));
    $this->assertFalse($this->can($owner, $project, 'edit'));
    $this->assertTrue(TeamProjectMembership::canManage($successor, $project));
    $this->reject(function() use ($successor, $project) {
      $this->edit($successor, $project, array(array('type' => 'members.remove', 'value' => array($successor->getPHID()))));
    });
  }

  public function testRoleAndMemberFormsRejectStaleAndRevokedActors() {
    $owner = $this->user(); $admin = $this->user(); $guest = $this->user();
    $project = $this->project($owner, array($admin, $guest));
    $state = TeamProjectMembership::getStateToken($project);
    $project = $this->role($owner, $project, $admin, 'admin');
    $this->reject(function() use ($owner, $project, $guest, $state) {
      TeamProjectMembership::changeRole($owner, $project, $guest->getPHID(), 'editor', $state, $this->source());
    });
    $stale = clone $admin; $project = $this->role($owner, $project, $admin, 'editor');
    $this->reject(function() use ($stale, $project, $guest) { $this->role($stale, $project, $guest, 'viewer'); });
    $admin->setIsDisabled(1)->save();
    $this->reject(function() use ($stale, $project, $guest) { $this->role($stale, $project, $guest, 'admin'); });
  }

  public function testOrdinaryChildrenHaveIndependentManagersAndMilestonesInherit() {
    $owner = $this->user(); $child_owner = $this->user();
    $parent = $this->project($owner, array($child_owner));
    $parent = $this->role($owner, $parent, $child_owner, 'editor');
    $child = $this->project($child_owner, array(), array(), array(array('type' => 'parent', 'value' => $parent->getPHID())));
    $this->assertTrue(TeamProjectMembership::canManage($child_owner, $child));
    $this->assertFalse(TeamProjectMembership::canManage($owner, $child));
    $milestone = $this->project($owner, array(), array(), array(array('type' => 'milestone', 'value' => $parent->getPHID())));
    $this->assertTrue(TeamProjectMembership::canManage($owner, $milestone));
    $this->reject(function() use ($owner, $milestone, $child_owner) { $this->role($owner, $milestone, $child_owner, 'admin'); });
  }

  public function testLegacyRoleMaterializationDoesNotGrantCreatorNewACL() {
    $owner = $this->user(); $admin = $this->user(true); $member = $this->user();
    $project = $this->project($owner, array($member));
    $project->setProperty(TeamProjectMembership::ROLES_PROPERTY, null)
      ->setViewPolicy('users')->setEditPolicy($admin->getPHID())->save();
    $project = $this->fresh($project);
    $this->assertFalse($this->can($owner, $project, 'edit'));
    $project = $this->role($admin, $project, $member, 'editor');
    $this->assertFalse($this->can($owner, $project, 'edit'));
    $this->assertEqual(TeamProjectMembership::LEGACY_ADMIN,
      $project->getProperty(TeamProjectMembership::ROLES_PROPERTY)[$owner->getPHID()]);
  }

  private function memberSpec($actor, $project, array $spec) {
    id(new PhabricatorProjectTransactionEditor())->setActor($actor)->setContentSource($this->source())
      ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)->applyTransactions($this->fresh($project), array(
        id(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
          ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
          ->setNewValue($spec)));
    return $this->fresh($project);
  }
  private function inverseMembership($actor, $project, $operation) {
    // Exercise the receiving protocol of applyInverseEdgeTransactions():
    // write both edges, then supply normalized maps to the inverse editor.
    // The dual-DAO transaction below isolates this fixture; native inverse
    // delivery after the origin commits does not promise cross-DAO rollback.
    // UserTransactionEditor has no core:edge endpoint to expose here.
    $current = $this->fresh($project);
    $old = array_fuse($current->getDirectMemberPHIDs());
    $new = $old;
    if ($operation === '+') { $new[$actor->getPHID()] = $actor->getPHID(); }
    else { unset($new[$actor->getPHID()]); }
    $actor->openTransaction(); $current->openTransaction();
    try {
      $edges = new PhabricatorEdgeEditor();
      $type = PhabricatorProjectMemberOfProjectEdgeType::EDGECONST;
      if ($operation === '+') { $edges->addEdge($actor->getPHID(), $type, $current->getPHID()); }
      else { $edges->removeEdge($actor->getPHID(), $type, $current->getPHID()); }
      $edges->save();
      id(new PhabricatorProjectTransactionEditor())->setActor($actor)->setContentSource($this->source())
        ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)->setIsInverseEdgeEditor(true)
        ->applyTransactions($this->fresh($current), array(
          id(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
            ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
            ->setOldValue($old)->setNewValue($new)));
      $current->saveTransaction(); $actor->saveTransaction();
    } catch (Throwable $ex) {
      $current->killTransaction(); $actor->killTransaction(); throw $ex;
    }
  }
  private function user($admin = false) {
    $user = $this->generateNewTestUser(); return $admin ? $user->setIsAdmin(1)->save() : $user;
  }
  private function project($actor, array $members = array(), array $policies = array(), array $extra = array()) {
    $tx = array(array('type' => 'name', 'value' => 'Membership '.Filesystem::readRandomCharacters(12)));
    if (!$extra || head($extra)['type'] !== 'milestone') {
      $tx[] = array('type' => 'members.set', 'value' => array_values(mpull($members, 'getPHID')));
      $policies += array('view' => $actor->getPHID(), 'edit' => $actor->getPHID(), 'join' => 'no-one');
      foreach ($policies as $type => $value) { $tx[] = array('type' => $type, 'value' => $value); }
    }
    $result = id(new ConduitCall('project.edit', array('transactions' => array_merge($tx, $extra))))->setUser($actor)->execute();
    return $this->fresh($result['object']['phid']);
  }
  private function fresh($project) {
    return id(new PhabricatorProjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array(is_string($project) ? $project : $project->getPHID()))
      ->needMembers(true)->needAncestorMembers(true)->executeOne();
  }
  private function edit($actor, $project, array $tx) {
    $r = id(new ConduitCall('project.edit', array('objectIdentifier' => $project->getPHID(), 'transactions' => $tx)))
      ->setUser($actor)->execute(); return $this->fresh($r['object']['phid']);
  }
  private function role($actor, $project, $target, $role) {
    TeamProjectMembership::changeRole($actor, $project, $target->getPHID(), $role,
      TeamProjectMembership::getStateToken($this->fresh($project)), $this->source());
    return $this->fresh($project);
  }
  private function can($actor, $project, $capability) {
    return PhabricatorPolicyFilter::hasCapability($actor, $this->fresh($project), $capability);
  }
  private function source() { return PhabricatorContentSource::newForSource(PhabricatorUnitTestContentSource::SOURCECONST); }
  private function reject($callback) {
    $error = null; try { $callback(); } catch (Throwable $ex) { $error = $ex; }
    $this->assertTrue($error instanceof PhabricatorPolicyException || $error instanceof InvalidArgumentException ||
      $error instanceof PhabricatorApplicationTransactionValidationException, $error ? get_class($error).': '.$error->getMessage() : 'Expected rejection.');
  }
}
