<?php

/** Regression coverage for project history and the native task workboard. */
final class TeamProjectProgressTestCase extends PhabricatorTestCase {

  private $historyEnvironment;
  private $recordLocale;

  protected function willRunTests() {
    parent::willRunTests();
    $this->recordLocale = PhabricatorEnv::beginScopedLocale('en_US');
    $this->historyEnvironment = PhabricatorEnv::beginScopedEnv();
    $this->historyEnvironment->overrideEnvConfig('project.history-state', 'active');
  }

  protected function didRunTests() {
    unset($this->historyEnvironment);
    unset($this->recordLocale);
    parent::didRunTests();
  }

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  protected function willRunOneTest($test) {
    parent::willRunOneTest($test);
    // These are real storage/Feed integrations, including native publication
    // and paired file/image calls. Keep a bounded integration budget instead
    // of the pure-unit default. The parent teardown restores the prior limit.
    set_time_limit(30);
  }

  public function testHistoryModesStopAllNewWritesAndRetainOldReaders() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $record = $this->publish($owner, $project);
    $before = $record->getNewValue();
    $env = PhabricatorEnv::beginScopedEnv();
    foreach (array('off', 'read-only', 'invalid-state') as $state) {
      $env->overrideEnvConfig('project.history-state', $state);
      $this->assertFalse(TeamProjectProgress::canPublish($owner, $project));
      $this->assertCannotPublishAnyRecord($owner, $project);
      $stored = TeamProjectProgress::newQuery($owner, $project)
        ->withIDs(array($record->getID()))->executeOne();
      $this->assertEqual($before, $stored->getNewValue());
    }
    unset($env);
  }

  public function testDisabledAndUnapprovedStaleActorsCannotPublishForOtherParticipants() {
    $owner = $this->generateNewTestUser();
    $participant = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $stale_actor = clone $owner;
    foreach (array('disabled', 'unapproved') as $condition) {
      $owner->setIsDisabled((int)($condition === 'disabled'))
        ->setIsApproved((int)($condition !== 'unapproved'))->save();
      $this->assertFalse(TeamProjectProgress::canPublish($stale_actor, $project));
      $this->assertRejected(function() use ($stale_actor, $participant, $project) {
        $this->publish($stale_actor, $project, array(
          'participants' => array($participant->getPHID()),
        ));
      }, PhabricatorPolicyException::class);
      $this->assertRejected(function() use ($stale_actor, $participant, $project) {
        $xaction = id(new PhabricatorProjectTransaction())
          ->setTransactionType(TeamProjectProgressNodeTransaction::TRANSACTIONTYPE)
          ->setNewValue($this->record(array(
            'participants' => array($participant->getPHID()),
          )));
        $this->editor($stale_actor)->applyTransactions($project, array($xaction));
      }, PhabricatorApplicationTransactionValidationException::class);
      $owner->setIsDisabled(0)->setIsApproved(1)->save();
    }
    $this->assertEqual(array(), TeamProjectProgress::newQuery($owner, $project)->execute());
  }

  public function testIdenticalContentAppendsDistinctEvents() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $first = $this->publish($owner, $project);
    $second = $this->publish($owner, $project);
    $this->assertTrue($first->getID() !== $second->getID());
    $this->assertEqual($first->getNewValue(), $second->getNewValue());
    $this->assertEqual(2, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
  }

  public function testParticipantsNeedVisibilityButNotDirectMembership() {
    $owner = $this->generateNewTestUser();
    $participant = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $record = $this->publish($owner, $project, array(
      'participants' => array($participant->getPHID(), $participant->getPHID()),
    ));
    $this->assertEqual(array($participant->getPHID()), $record->getNewValue()['participants']);
    $this->assertFalse($this->refresh($project)->isUserMember($participant->getPHID()));
    $this->assertRejected(function() use ($owner, $project) {
      $this->publish($owner, $project, array(
        'participants' => array_fill(0, 51, $owner->getPHID()),
      ));
    }, PhabricatorApplicationTransactionValidationException::class);
    $this->assertEqual(1, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
  }

  public function testParticipantSummaryCountsOnlyUnshownPeopleInBothLocales() {
    $owner = $this->generateNewTestUser();
    $participants = array();
    for ($index = 0; $index < TeamProjectProgress::MAX_PARTICIPANTS; $index++) {
      $participants[] = $this->generateNewTestUser()->getPHID();
    }
    $project = $this->newProject($owner, array());
    foreach (array(5, 6, 50) as $count) {
      $this->publish($owner, $project, array(
        'participants' => array_slice($participants, 0, $count),
      ));
      foreach (array('en_US', 'zh_CN') as $locale) {
        $scope = PhabricatorEnv::beginScopedLocale($locale);
        try {
          $view = id(new TeamProjectProgressSummaryView())
            ->setViewer($owner)->setProject($project)->setShowActions(false);
          $html = (string)hsprintf('%s', $view);
          if ($count === 5) {
            $this->assertFalse(strpos($html, 'other participant') !== false);
            $this->assertFalse(strpos($html, '另有') !== false);
          } else {
            $expected = ($locale === 'en_US')
              ? ($count === 6 ? 'and one other participant' : 'and 45 other participants')
              : ($count === 6 ? '另有 1 位参与者' : '另有 45 位参与者');
            $this->assertTrue(strpos($html, $expected) !== false,
              'The participant summary must count only the omitted people.');
          }
        } finally {
          unset($scope);
        }
      }
    }
  }

  public function testRevokedViewHidesPreviouslyReadableRecords() {
    $owner = $this->generateNewTestUser();
    $reader = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $record = $this->publish($owner, $project);
    $this->assertEqual(1, count(TeamProjectProgress::newQuery($reader, $project)->execute()));
    $project->setViewPolicy($owner->getPHID())->save();
    $this->assertEqual(array(), TeamProjectProgress::newQuery($reader, $project)->execute());
    $this->assertEqual((string)$record->getID(),
      (string)TeamProjectProgress::newQuery($owner, $project)->executeOne()->getID());
  }

  public function testAnonymousCanReadOnlyExplicitlyPublicHistory() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $this->publish($owner, $project);
    $anonymous = new PhabricatorUser();
    $this->assertEqual(array(), TeamProjectProgress::newQuery($anonymous, $project)->execute());
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('policy.allow-public', true);
    // Application visibility is cached for one request. A configuration
    // transition models a new request, just as core application-policy tests.
    PhabricatorCaches::destroyRequestCache();
    $project->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
    $this->assertEqual(1, count(TeamProjectProgress::newQuery($anonymous, $project)->execute()));
    $this->assertFalse(TeamProjectProgress::canPublish($anonymous, $project));
    $env->overrideEnvConfig('policy.allow-public', false);
    PhabricatorCaches::destroyRequestCache();
    $this->assertEqual(array(), TeamProjectProgress::newQuery($anonymous, $project)->execute());
    unset($env);
  }

  public function testOnlyEditorsCanPublishAllRecordTypes() {
    $owner = $this->generateNewTestUser();
    $member = $this->generateNewTestUser();
    $observer = $this->generateNewTestUser();
    $project = $this->newProject($owner, array($member));
    $this->assertTrue(TeamProjectProgress::canPublish($owner, $project));
    $this->assertFalse(TeamProjectProgress::canPublish($member, $project));
    $this->assertFalse(TeamProjectProgress::canPublish($observer, $project));
    $this->assertFalse(TeamProjectProgress::canPublish(new PhabricatorUser(), $project));

    $xaction = $this->publish($owner, $project, array(
      'authorPHID' => $member->getPHID(), 'dateCreated' => 1,
      'id' => 123, 'properties' => array('injected' => true),
    ));
    $this->assertEqual($owner->getPHID(), $xaction->getAuthorPHID());
    $this->assertTrue($xaction->getDateCreated() > 1);
    $this->assertEqual(array($owner->getPHID()),
      $xaction->getNewValue()['participants']);
    $this->assertFalse(isset($xaction->getNewValue()['properties']));
    $this->assertFalse(isset($xaction->getNewValue()['authorPHID']));
    $this->assertEqual('node', $xaction->getNewValue()['kind']);
    $this->assertEqual(TeamProjectProgressNodeTransaction::TRANSACTIONTYPE,
      $xaction->getTransactionType());
    $this->publish($owner, $project, array('kind' => 'comment'));
    $this->publish($owner, $project, array('kind' => 'milestone'));
    $this->assertCannotPublishAnyRecord($member, $project);
    $this->assertCannotPublishAnyRecord($observer, $project);
    // Read-only members still see the full history, but no rejected attempt
    // may append a transaction or update the current state.
    $this->assertEqual(3, count(TeamProjectProgress::newQuery($member, $project)->execute()));
    $this->assertEqual(3, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
  }

  public function testRevokedEditPermissionImmediatelyStopsPublication() {
    $owner = $this->generateNewTestUser();
    $editor = $this->generateNewTestUser();
    $project = $this->newProject($owner, array($editor));
    $this->assertFalse(TeamProjectProgress::canPublish($editor, $project));

    $this->setEditPolicy($owner, $project, PhabricatorPolicies::POLICY_USER);
    $authorized_project = $this->refresh($project);
    $this->assertTrue(TeamProjectProgress::canPublish($editor, $authorized_project));
    foreach (array('node', 'comment', 'update', 'milestone') as $kind) {
      $record = $this->publish($editor, $authorized_project, array('kind' => $kind));
      $this->assertEqual($editor->getPHID(), $record->getAuthorPHID());
    }
    $before = TeamProjectProgress::getSummary($project, $owner);

    $this->setEditPolicy($owner, $project, $owner->getPHID());
    // Deliberately use the object loaded while editing was still permitted.
    // Both the service and the native editor must honor the current policy.
    $this->assertFalse(TeamProjectProgress::canPublish($editor, $authorized_project));
    $this->assertTrue($this->refresh($project)->isUserMember($editor->getPHID()));
    $this->assertCannotPublishAnyRecord($editor, $authorized_project);
    $after = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual($before['updatedAt'], $after['updatedAt']);
    $this->assertEqual($before['updatedByPHID'], $after['updatedByPHID']);
    $this->assertEqual((string)$before['latest']->getID(), (string)$after['latest']->getID());
    $this->assertEqual(4, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
  }

  public function testHistoryIsAppendOnlyAndCommentsPreserveCurrentProgress() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['percent']);
    $this->assertEqual(null, $summary['status']);
    $this->assertEqual('No Records Published', $summary['statusName']);
    $this->assertEqual(null, $summary['latestProgress']);
    $this->assertEqual(null, $summary['latestNode']);
    $this->assertEqual('No Nodes Published', $summary['nodeStatusName']);
    $this->assertEqual(null, $summary['latestComment']);
    $initial_comment = $this->publish($owner, $project, array('kind' => 'comment'));
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['updatedAt']);
    $this->assertEqual(null, $summary['latestProgress']);
    $this->assertEqual((string)$initial_comment->getID(), (string)$summary['latestComment']->getID());
    $first = $this->publish($owner, $project, array('status' => 'active', 'percent' => '25'));
    $original_value = $first->getNewValue();
    $keynode = $this->publish($owner, $project, array(
      'kind' => 'node', 'title' => '首个集成版本', 'status' => 'planned', 'percent' => 70,
    ));
    $current = TeamProjectProgress::getSummary($project, $owner);
    $comment = $this->publish($owner, $project, array(
      'kind' => 'comment', 'status' => 'done', 'percent' => 100,
    ));
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['status']);
    $this->assertEqual(null, $summary['nodeStatus']);
    $this->assertEqual('Recorded', $summary['nodeStatusName']);
    $this->assertEqual(70, $summary['percent']);
    $this->assertEqual($current['updatedAt'], $summary['updatedAt']);
    $this->assertEqual((string)$keynode->getID(), (string)$summary['latestNode']->getID());
    $this->assertEqual((string)$comment->getID(), (string)$summary['latest']->getID());
    $this->assertEqual((string)$keynode->getID(), (string)$summary['latestProgress']->getID());
    $this->assertEqual((string)$comment->getID(), (string)$summary['latestComment']->getID());
    $this->assertEqual(null, $comment->getNewValue()['percent']);

    $this->publish($owner, $project, array('status' => 'done', 'percent' => ''));
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['nodeStatus']);
    $this->assertEqual('Recorded', $summary['nodeStatusName']);
    $this->assertEqual(70, $summary['percent']);
    $this->publish($owner, $project, array('status' => 'planned', 'percent' => ''));
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['status']);
    $this->assertEqual(null, $summary['nodeStatus']);
    $this->assertEqual(70, $summary['percent']);
    $stored = TeamProjectProgress::newQuery($owner, $project)
      ->withIDs(array($first->getID()))->executeOne();
    $this->assertEqual($original_value, $stored->getNewValue());
    $this->assertEqual(PhabricatorPolicies::POLICY_NOONE, $stored->getEditPolicy());
    $this->assertEqual(null, $stored->getCommentPHID());
    $this->assertRejected(function() use ($owner, $project, $stored) {
      $this->editor($owner)->applyTransactions($project, array($stored));
    }, PhabricatorApplicationTransactionStructureException::class);
    $this->assertEqual(6, count(TeamProjectProgress::newQuery($owner, $project)->execute()));

    // Zero is an explicit valid value; absence must not be confused with zero.
    $this->publish($owner, $project, array('percent' => '0'));
    $this->assertEqual(0, TeamProjectProgress::getSummary($project, $owner)['percent']);
  }

  public function testInvalidRecordsAndInvisibleParticipantsDoNotWrite() {
    $owner = $this->generateNewTestUser();
    $outsider = $this->generateNewTestUser();
    $disabled = $this->generateNewTestUser()->setIsDisabled(true)->save();
    $project = $this->newProject($owner, array(), true);
    $invalid = array(
      array('title' => ''), array('title' => array()),
      array('title' => str_repeat('测', 201)),
      array('body' => ''), array('body' => str_repeat('a', 20001)),
      array('status' => 'invalid'),
      array('percent' => 101),
      array('percent' => '-1'), array('percent' => '5.5'),
      array('percent' => true), array('percent' => array()),
      array('participants' => array($outsider->getPHID())),
      array('participants' => array($disabled->getPHID())),
      array('participants' => array($project->getPHID())),
    );
    foreach ($invalid as $input) {
      $this->assertRejected(function() use ($owner, $project, $input) {
        $this->publish($owner, $project, $input);
      }, PhabricatorApplicationTransactionValidationException::class);
    }
    $this->assertRejected(function() use ($owner, $project) {
      $this->publish($owner, $project, array('kind' => 'invalid'));
    }, InvalidArgumentException::class);
    foreach (array('update', 'milestone') as $kind) {
      $this->assertRejected(function() use ($owner, $project, $kind) {
        $xaction = id(new PhabricatorProjectTransaction())
          ->setTransactionType(TeamProjectProgress::getTransactionTypes()[$kind])
          ->setNewValue($this->record(array('kind' => $kind, 'status' => 'invalid')));
        $this->editor($owner)->applyTransactions($project, array($xaction));
      }, PhabricatorApplicationTransactionValidationException::class);
    }
    $this->assertEqual(0, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
    $this->assertEqual(null, TeamProjectProgress::getSummary($project, $owner)['updatedAt']);

    $xaction = $this->publish($owner, $project, array(
      'title' => '<script>alert(1)</script>', 'body' => '保留原始 **工作记录**。',
      'participants' => array($owner->getPHID(), $owner->getPHID()),
    ));
    $this->assertEqual(array($owner->getPHID()), $xaction->getNewValue()['participants']);
    $stored = TeamProjectProgress::newQuery($owner, $project)->executeOne();
    $title = (string)$stored->getTitle();
    $this->assertFalse(strpos($title, '<script>') !== false);
    $this->assertTrue(strpos($title, '&lt;script&gt;') !== false);
  }

  public function testPrivateChildPermissionsAlsoProtectProgressHistory() {
    $owner = $this->generateNewTestUser();
    $parent_member = $this->generateNewTestUser();
    $child_member = $this->generateNewTestUser();
    $parent = $this->newProject($owner, array($parent_member));
    $child = $this->newProject($owner, array($child_member), true, $parent);
    // Configure the native ACL explicitly: this suite does not depend on
    // whether first-child creation inherits its parent's members.
    $members = array($child_member->getPHID());
    if ($child->getMemberPHIDs() !== $members) {
      id(new ConduitCall('project.edit', array(
        'objectIdentifier' => $child->getPHID(),
        'transactions' => array(array(
          'type' => 'members.set', 'value' => $members,
        )),
      )))->setUser($owner)->execute();
      $child = $this->refresh($child);
    }
    $this->assertFalse(TeamProjectProgress::canPublish($child_member, $parent));
    $this->assertFalse(TeamProjectProgress::canPublish($parent_member, $child));
    $this->assertFalse(TeamProjectProgress::canPublish($child_member, $child));
    $this->publish($owner, $child, array(
      'kind' => 'milestone', 'participants' => array($child_member->getPHID()),
    ));
    $this->assertEqual(array(), TeamProjectProgress::newQuery($parent_member, $child)->execute());
    $this->assertRejected(function() use ($parent_member, $child) {
      TeamProjectProgress::getSummary($child, $parent_member);
    }, PhabricatorPolicyException::class);
    $this->assertEqual(1, count(TeamProjectProgress::newQuery($child_member, $child)->execute()));
    $this->assertEqual(array($child_member->getPHID()), $this->refresh($child)->getMemberPHIDs());
  }

  public function testKindFiltersAndCursorPagesPreserveCompleteHistory() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $first = $this->publish($owner, $project, array('kind' => 'milestone'));
    $expected = array((string)$first->getID());
    for ($ii = 0; $ii < 4; $ii++) {
      $expected[] = (string)$this->publish($owner, $project, array('kind' => 'comment'))->getID();
    }
    $pager = id(new AphrontCursorPagerView())->setPageSize(2);
    $seen = array();
    do {
      $page = TeamProjectProgress::getTransactions($owner, $project, $pager);
      foreach ($page as $xaction) {
        $seen[] = (string)$xaction->getID();
      }
      $next = $pager->getNextPageID();
      $pager = id(new AphrontCursorPagerView())->setPageSize(2)->setAfterID($next);
    } while ($next);
    $this->assertEqual(array_reverse($expected), $seen);
    $milestones = TeamProjectProgress::newQuery($owner, $project, 'milestone')->execute();
    $this->assertEqual(array((string)$first->getID()), array_map('strval', array_values(mpull($milestones, 'getID'))));
    $this->assertEqual((string)$first->getID(), (string)TeamProjectProgress::getSummary($project, $owner)['latestNode']->getID());
  }

  public function testCanonicalNodesMergeLegacyHistoryWithoutInheritingStatus() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $this->assertEqual(array('node', 'comment'), array_keys(TeamProjectProgress::getKindNames()));
    $this->assertEqual(4, count(TeamProjectProgress::getTransactionTypes()));
    $this->assertEqual('node', TeamProjectProgress::normalizeKind('update'));
    $this->assertEqual('node', TeamProjectProgress::normalizeKind('milestone'));
    $this->assertEqual('unknown', TeamProjectProgress::normalizeKind('unknown'));

    // Represent an existing installation's old project-wide state without
    // rewriting any application history. This object is fixture-only.
    $project->setProperty(TeamProjectProgress::SUMMARY_PROPERTY, array(
      'status' => 'done', 'percent' => 25,
    ))->save();
    $legacy_update = $this->publishLegacy($owner, $project, array(
      'kind' => 'update', 'status' => 'active', 'percent' => 10,
    ));
    $legacy_milestone = $this->publishLegacy($owner, $project, array(
      'kind' => 'milestone', 'status' => 'planned',
    ));
    // Mutate only the isolated old-row fixture to represent a historical
    // nullable payload. Current native writes always create version two.
    $old_payload = $legacy_milestone->getNewValue();
    $old_payload['status'] = null;
    $legacy_milestone->setNewValue($old_payload)->save();
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['nodeStatus']);
    $this->assertEqual('Unannotated', $summary['nodeStatusName']);
    $this->assertEqual('done', $summary['status']);
    $this->assertEqual(10, $summary['percent']);
    $this->assertEqual((string)$legacy_milestone->getID(), (string)$summary['latestNode']->getID());

    $nodes = array($legacy_update, $legacy_milestone);
    foreach (array('node', 'update', 'milestone') as $input_kind) {
      $node = $this->publish($owner, $project, array(
        'kind' => $input_kind, 'status' => 'done', 'percent' => '',
      ));
      $this->assertEqual('node', $node->getNewValue()['kind']);
      $this->assertEqual(TeamProjectProgressNodeTransaction::TRANSACTIONTYPE,
        $node->getTransactionType());
      $this->assertEqual(null, $node->getNewValue()['status']);
      $this->assertEqual(2, $node->getNewValue()['version']);
      $nodes[] = $node;
    }
    $comment = $this->publish($owner, $project, array('kind' => 'comment'));
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['nodeStatus']);
    $this->assertEqual('Recorded', $summary['nodeStatusName']);
    $this->assertEqual(10, $summary['percent']);
    $this->assertEqual('done', $summary['status']);
    $this->assertEqual((string)last($nodes)->getID(), (string)$summary['latestNode']->getID());
    $this->assertEqual($summary['latestNode'], $summary['latestProgress']);
    $this->assertFalse(array_key_exists('latestMilestone', $summary));
    $this->assertEqual((string)$comment->getID(), (string)$summary['latestComment']->getID());

    $expected = array_reverse(array_map('strval', mpull($nodes, 'getID')));
    foreach (array('node', 'update', 'milestone') as $query_kind) {
      $results = TeamProjectProgress::newQuery($owner, $project, $query_kind)->execute();
      $this->assertEqual($expected, array_values(array_map('strval', mpull($results, 'getID'))));
    }
    foreach (array($legacy_update, $legacy_milestone) as $legacy) {
      $stored = TeamProjectProgress::newQuery($owner, $project, 'node')
        ->withIDs(array($legacy->getID()))->executeOne();
      $this->assertEqual($legacy->getNewValue(), $stored->getNewValue());
      $this->assertEqual($legacy->getTransactionType(), $stored->getTransactionType());
      $title = (string)$stored->getTitleForFeed();
      $this->assertTrue(strpos($title, 'published Node') !== false);
      $this->assertTrue(strpos($title, '?entry='.$legacy->getID().'#entry-'.$legacy->getID()) !== false);
    }
  }

  public function testNewStageRecordsNeverStoreASecondExecutionState() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $statuses = array(null, '', 'planned', 'active', 'blocked', 'done');
    foreach (array('node', 'update', 'milestone') as $kind) {
      foreach ($statuses as $status) {
        // Use the native editor directly: old transaction names must not
        // bypass the shared workboard's single execution-state model.
        $record = id(new PhabricatorProjectTransaction())
          ->setTransactionType(TeamProjectProgress::getTransactionTypes()[$kind])
          ->setNewValue($this->record(array(
            'kind' => $kind, 'status' => $status, 'version' => 999,
            'authorPHID' => 'PHID-USER-forged', 'dateCreated' => 1,
            'id' => 123, 'properties' => array('injected' => true),
          )));
        $this->editor($owner)->applyTransactions($project, array($record));
        $value = $record->getNewValue();
        $this->assertEqual(null, $value['status']);
        $this->assertEqual(2, $value['version']);
        $this->assertEqual('Recorded', TeamProjectProgress::getNodeStatusName($record));
        $this->assertEqual($owner->getPHID(), $record->getAuthorPHID());
        $this->assertTrue($record->getDateCreated() > 1);
        foreach (array('authorPHID', 'dateCreated', 'id', 'properties') as $field) {
          $this->assertFalse(isset($value[$field]));
        }
      }
      $stored_type = TeamProjectProgress::newQuery($owner, $project)
        ->withIDs(array($record->getID()))->executeOne();
      $this->assertTrue(strpos((string)$stored_type->getTitle(),
        'published Project Record') !== false);
    }
    $summary = TeamProjectProgress::getSummary($project, $owner);
    $this->assertEqual(null, $summary['nodeStatus']);
    $this->assertEqual('Recorded', $summary['nodeStatusName']);
    $stored_new = TeamProjectProgress::newQuery($owner, $project)
      ->withIDs(array($record->getID()))->executeOne();
    $details = (string)$stored_new->renderChangeDetails($owner);
    $this->assertTrue(strpos($details, 'Record Status') !== false);
    $this->assertTrue(strpos($details, 'Recorded') !== false);
    $this->assertFalse(strpos($details, 'Node Status') !== false);

    // Represent an actual version-one node without rewriting it during reads.
    $legacy = $this->publish($owner, $project, array());
    $old_value = $legacy->getNewValue();
    unset($old_value['version']);
    $old_value['status'] = 'blocked';
    $legacy->setNewValue($old_value)->save();
    $old_author = $legacy->getAuthorPHID();
    $old_date = $legacy->getDateCreated();
    $this->assertEqual('Blocked', TeamProjectProgress::getSummary(
      $project, $owner)['nodeStatusName']);
    $stored = TeamProjectProgress::newQuery($owner, $project, 'node')
      ->withIDs(array($legacy->getID()))->executeOne();
    $this->assertEqual($old_value, $stored->getNewValue());
    $this->assertEqual($old_author, $stored->getAuthorPHID());
    $this->assertEqual((string)$old_date, (string)$stored->getDateCreated());
    $old_value['status'] = null;
    $legacy->setNewValue($old_value)->save();
    $this->assertEqual('Unannotated', TeamProjectProgress::getSummary(
      $project, $owner)['nodeStatusName']);
  }

  public function testStoredRecordsHaveSafeFeedLinksAndCompleteDetails() {
    $owner = $this->generateNewTestUser();
    $participant = $this->generateNewTestUser();
    $project = $this->newProject($owner, array($participant));
    $title = '<script>alert("record")</script> & 状态';
    $record = $this->publishLegacy($owner, $project, array(
      'kind' => 'milestone', 'title' => $title,
      'body' => '验收说明 **已经保留**。', 'status' => 'blocked', 'percent' => 65,
      'participants' => array($participant->getPHID()),
    ));
    $stored = TeamProjectProgress::newQuery($owner, $project)
      ->withIDs(array($record->getID()))->executeOne();
    // The old six-field payload needs no migration or new link metadata.
    $this->assertEqual(array('kind', 'title', 'body', 'status', 'percent', 'participants'),
      array_keys($stored->getNewValue()));
    $uri = '/project/progress/'.$project->getID().'/?entry='.$record->getID().
      '#entry-'.$record->getID();
    $html = (string)$stored->getTitleForFeed();
    $this->assertTrue(strpos($html, 'href="'.$uri.'"') !== false);
    $this->assertTrue(strpos($html, '&lt;script&gt;') !== false);
    $this->assertFalse(strpos($html, '<script>') !== false);
    $stored->setRenderingTarget(PhabricatorApplicationTransaction::TARGET_TEXT);
    $text = (string)$stored->getTitleForFeed();
    $this->assertTrue(strpos($text, PhabricatorEnv::getProductionURI($uri)) !== false);
    $this->assertTrue(strpos($text, $title) !== false);
    $this->assertFalse(strpos($text, '<a ') !== false);
    $stored->setRenderingTarget(PhabricatorApplicationTransaction::TARGET_HTML);

    $details = (string)$stored->renderChangeDetails($owner);
    foreach (array('Node', 'Node Status', 'Reported Project Completion', 'Blocked', '65%', $participant->getUsername(),
      $owner->getUsername(), $uri, '验收说明', '&lt;script&gt;') as $expected) {
      $this->assertTrue(strpos($details, $expected) !== false, $expected);
    }
    $this->assertFalse(strpos($details, '<script>') !== false);
  }

  public function testNativeFeedPublishesProgressAndTaskActivityWithoutPolicyLeaks() {
    // The native fixture base resets this option. Override only this test's
    // environment and execute only PublishWorker, never any mail worker.
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phabricator.silent', true);
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    try {
      $owner = $this->generateNewTestUser();
      $editor = $this->generateNewTestUser();
      $reader = $this->generateNewTestUser();
      $project = $this->newProject($owner, array());
      $this->setEditPolicy($owner, $project, PhabricatorPolicies::POLICY_USER);
      $records = array();
      foreach (array('node', 'comment', 'update', 'milestone') as $kind) {
        $input = array(
          'kind' => $kind, 'title' => 'Feed '.$kind,
          'status' => 'active', 'percent' => 40,
          'participants' => array($reader->getPHID()),
        );
        $records[] = ($kind === 'update' || $kind === 'milestone')
          ? $this->publishLegacy($editor, $project, $input)
          : $this->publish($editor, $project, $input);
      }
      $this->setEditPolicy($owner, $project, $owner->getPHID());
      $before = TeamProjectProgress::getSummary($project, $owner);
      $this->assertFalse(TeamProjectProgress::canPublish($editor, $project));
      $this->assertCannotPublishAnyRecord($reader, $project);

      $task = $this->newTask($owner, $project, false);
      $private_task = $this->newTask($owner, $project, true);
      $other_project = $this->newProject($owner, array());
      $other_task = $this->newTask($owner, $other_project, false);
      $this->assertTrue(PhabricatorPolicyFilter::hasCapability(
        $reader, $task, PhabricatorPolicyCapability::CAN_VIEW));
      $this->assertFalse(PhabricatorPolicyFilter::hasCapability(
        $reader, $task, PhabricatorPolicyCapability::CAN_EDIT));
      $this->assertTrue(PhabricatorPolicyFilter::hasCapability(
        $reader, $task, PhabricatorPolicyCapability::CAN_INTERACT));
      // Native task discussion remains governed by the task's interact policy.
      $this->editTask($reader, $task, array(array(
        'type' => 'comment', 'value' => 'Native task progress comment.',
      )));
      $this->editTask($owner, $task, array(array(
        'type' => 'status', 'value' => ManiphestTaskStatus::STATUS_CLOSED_RESOLVED,
      )));
      $comment = id(new ManiphestTransactionQuery())->setViewer($owner)
        ->withObjectPHIDs(array($task->getPHID()))
        ->withTransactionTypes(array(PhabricatorTransactions::TYPE_COMMENT))
        ->setLimit(1)->executeOne();
      $status = id(new ManiphestTransactionQuery())->setViewer($owner)
        ->withObjectPHIDs(array($task->getPHID()))
        ->withTransactionTypes(array(ManiphestTaskStatusTransaction::TRANSACTIONTYPE))
        ->setLimit(1)->executeOne();
      $this->assertEqual(ManiphestTaskStatus::STATUS_CLOSED_RESOLVED, $status->getNewValue());
      $this->assertEqual($reader->getPHID(), $comment->getAuthorPHID());

      $project_before = $this->feedForProject($reader, $project);
      $this->assertEqual(0, $this->countStoriesForTransactions($project_before, $records));
      $this->runPublishWorkers(array($project, $other_project, $task, $private_task, $other_task));
      $stories = $this->feedForProject($reader, $project);
      $feed_view = id(new PhabricatorFeedBuilder($stories))->setUser($reader)->buildView();
      $feed_html = (string)phutil_tag('div', array(), $feed_view);
      $this->assertTrue(strpos($feed_html, 'Native task progress comment.') !== false);
      $this->assertFalse(strpos($feed_html, 'Feed Story Failed to Render') !== false);
      $this->assertFalse(strpos($feed_html, $private_task->getTitle()) !== false);
      $this->assertEqual(4, $this->countStoriesForTransactions($stories, $records));
      foreach ($records as $record) {
        $this->assertEqual(1, $this->countStoriesForTransactions($stories, array($record)));
        $uri = '/project/progress/'.$project->getID().'/?entry='.$record->getID().
          '#entry-'.$record->getID();
        $this->assertTrue(strpos($feed_html, 'href="'.$uri.'"') !== false);
        foreach ($stories as $story) {
          if (!in_array($record->getPHID(), $story->getValue('transactionPHIDs', array()))) {
            continue;
          }
          $this->assertEqual($record->getAuthorPHID(), $story->getAuthorPHID());
          $text = $story->renderText();
          $this->assertTrue(strpos($text, PhabricatorEnv::getProductionURI($uri)) !== false);
        }
      }
      $this->assertEqual(1, $this->countStoriesForTransactions($stories, array($comment)));
      $this->assertEqual(1, $this->countStoriesForTransactions($stories, array($status)));
      foreach ($stories as $story) {
        $this->assertFalse($story->getPrimaryObjectPHID() === $private_task->getPHID());
        $this->assertFalse($story->getPrimaryObjectPHID() === $other_task->getPHID());
      }
      $owner_stories = $this->feedForProject($owner, $project);
      $this->assertTrue(in_array($private_task->getPHID(),
        mpull($owner_stories, 'getPrimaryObjectPHID')));
      $this->assertEqual(4, $this->countStoriesForTransactions(
        $this->feedForProject($editor, $project), $records));
      $after = TeamProjectProgress::getSummary($project, $owner);
      $this->assertEqual((string)$before['latest']->getID(), (string)$after['latest']->getID());
      $this->assertEqual($before['percent'], $after['percent']);
      $this->assertEqual(4, count(TeamProjectProgress::newQuery($owner, $project)->execute()));

      // Tightening the object policies must hide already-published stories,
      // including from the former editor who authored the progress records.
      id(new ConduitCall('project.edit', array(
        'objectIdentifier' => $project->getPHID(),
        'transactions' => array(array('type' => 'view', 'value' => $owner->getPHID())),
      )))->setUser($owner)->execute();
      foreach (array($editor, $reader) as $viewer) {
        $restricted = $this->feedForProject($viewer, $project);
        $this->assertEqual(0, $this->countStoriesForTransactions($restricted, $records));
        // Task visibility is independent: a project policy change alone must
        // not hide the still-public task's discussion or status change.
        $this->assertEqual(1, $this->countStoriesForTransactions($restricted, array($comment)));
        $this->assertEqual(1, $this->countStoriesForTransactions($restricted, array($status)));
        $this->assertEqual(array(), TeamProjectProgress::newQuery($viewer, $project)->execute());
      }
      $this->editTask($owner, $task, array(array(
        'type' => 'view', 'value' => $owner->getPHID(),
      )));
      foreach (array($editor, $reader) as $viewer) {
        $this->assertEqual(array(), $this->feedForProject($viewer, $project));
        $this->assertEqual(array(), TeamProjectProgress::newQuery($viewer, $project)->execute());
      }
      $this->assertEqual(4, $this->countStoriesForTransactions(
        $this->feedForProject($owner, $project), $records));
    } finally {
      unset($env);
    }
  }

  public function testNodeReferencesUseNativeMentionRelationships() {
    $owner = $this->generateNewTestUser();
    $reader = $this->generateNewTestUser();
    $control = $this->newProject($owner, array());
    $task = $this->newTask($owner, $control, false);
    $body = 'Review T'.$task->getID().' with @'.$reader->getUsername().'.';
    // Establish the native project-description behavior as the control.
    $description = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_CUSTOMFIELD)
      ->setMetadataValue('customfield:key', 'std:project:internal:description')
      ->setOldValue('')
      ->setNewValue($body);
    $this->editor($owner)->applyTransactions($control, array($description));
    $this->assertTrue(in_array($task->getPHID(), $this->mentionTargets($control)));

    foreach (array('node', 'comment', 'update', 'milestone') as $kind) {
      $project = $this->newProject($owner, array());
      $input = array('kind' => $kind, 'body' => $body);
      $record = in_array($kind, array('update', 'milestone'))
        ? $this->publishLegacy($owner, $project, $input)
        : $this->publish($owner, $project, $input);
      $this->assertTrue(in_array($task->getPHID(), $this->mentionTargets($project)),
        $kind.' must record the task relationship, like native project descriptions.');
      $this->assertTrue(in_array($project->getPHID(), $this->mentionTargets(
        $task, PhabricatorObjectMentionedByObjectEdgeType::EDGECONST)));
      $changes = $record->getRemarkupChanges();
      $this->assertEqual(1, count($changes));
      $this->assertEqual(null, $changes[0]->getOldValue());
      $this->assertEqual($body, $changes[0]->getNewValue());
      $this->assertEqual($record->getPHID(), $changes[0]->getTransaction()->getPHID());
      $this->assertFalse($project instanceof PhabricatorSubscribableInterface);
      $this->assertFalse(TeamProjectProgress::canPublish($reader, $project));
    }
  }

  public function testMentionedUsersRenderInNativeDetailsAndPublishFeed() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phabricator.silent', true);
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    try {
      $owner = $this->generateNewTestUser();
      $reader = $this->generateNewTestUser();
      $outsider = $this->generateNewTestUser();
      $project = $this->newProject($owner, array($reader), true);
      $records = array();
      foreach (array('node', 'comment') as $kind) {
        $records[] = $this->publish($owner, $project, array(
          'kind' => $kind,
          'body' => 'Review with @'.$reader->getUsername().
            '; restricted participant @'.$outsider->getUsername().'.',
        ));
      }
      // A native transaction query attaches only the querying viewer's
      // membership. Rendering @someone-else must load that user's policy
      // context instead of failing on missing attached project member data.
      foreach ($records as $record) {
        $stored = id(new PhabricatorProjectTransactionQuery())->setViewer($owner)
          ->withIDs(array($record->getID()))->executeOne();
        $html = (string)$stored->renderChangeDetails($owner);
        $this->assertTrue(strpos($html, '@'.$reader->getUsername()) !== false);
        $this->assertTrue(strpos($html, '@'.$outsider->getUsername()) !== false);
        $this->assertTrue(strpos($html, 'fa-eye-slash') !== false,
          'Mentioning a user without project access must still show the native access marker.');
      }
      // Execute only native PublishWorker tasks, never mail-delivery workers.
      $this->runPublishWorkers(array($project));
      $this->assertEqual(2, $this->countStoriesForTransactions(
        $this->feedForProject($reader, $project), $records));
      $this->assertEqual(0, $this->countStoriesForTransactions(
        $this->feedForProject($outsider, $project), $records));
      $this->assertEqual(array(), TeamProjectProgress::newQuery($outsider, $project)->execute());
    } finally {
      unset($env);
    }
  }

  public function testNodeReferencesPreservePrivateTaskAndFileAccess() {
    $owner = $this->generateNewTestUser();
    $reader = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $task = $this->newTask($owner, $project, true);
    $file = PhabricatorFile::newFromFileData(
      'Private file bytes '.Filesystem::readRandomCharacters(24), array(
        'storageEngines' => array(new PhabricatorTestStorageEngine()),
        'name' => 'private-node-reference-'.Filesystem::readRandomCharacters(16).'.txt',
        'authorPHID' => $owner->getPHID(),
        'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
        'canCDN' => false,
      ));
    $project_policies = array($project->getViewPolicy(), $project->getEditPolicy(),
      $project->getJoinPolicy());
    $record = $this->publish($owner, $project, array(
      'body' => 'Private references: {T'.$task->getID().'} {F'.$file->getID().'}',
    ));
    $attachment = id(new PhabricatorFileAttachment())->loadOneWhere(
      'objectPHID = %s AND filePHID = %s', $project->getPHID(), $file->getPHID());
    $this->assertTrue((bool)$attachment, 'A pasted file reference must be indexed.');
    $this->assertEqual(PhabricatorFileAttachment::MODE_REFERENCE,
      $attachment->getAttachmentMode());
    $this->assertFalse($attachment->isPolicyAttachment());
    $fresh_file = id(new PhabricatorFileQuery())->setViewer($owner)
      ->withPHIDs(array($file->getPHID()))->executeOne();
    $this->assertEqual(PhabricatorPolicies::POLICY_NOONE, $fresh_file->getViewPolicy());
    $this->assertEqual(array(), id(new PhabricatorFileQuery())->setViewer($reader)
      ->withPHIDs(array($file->getPHID()))->execute());
    $this->assertEqual(array(), id(new ManiphestTaskQuery())->setViewer($reader)
      ->withPHIDs(array($task->getPHID()))->execute());
    $task->reload();
    $this->assertEqual($owner->getPHID(), $task->getViewPolicy());
    $this->assertEqual($owner->getPHID(), $task->getEditPolicy());
    $fresh = $this->refresh($project);
    $this->assertEqual($project_policies, array($fresh->getViewPolicy(),
      $fresh->getEditPolicy(), $fresh->getJoinPolicy()));
    $this->assertEqual(array(), $fresh->getMemberPHIDs());
    $stored = id(new PhabricatorProjectTransactionQuery())->setViewer($reader)
      ->withIDs(array($record->getID()))->executeOne();
    $html = (string)$stored->renderChangeDetails($reader);
    $this->assertFalse(strpos($html, $task->getTitle()) !== false);
    $this->assertFalse(strpos($html, $file->getName()) !== false);
    $this->assertFalse(TeamProjectProgress::canPublish($reader, $fresh));
  }

  public function testPrivateNodeBacklinksDoNotRevealTheSourceProject() {
    $owner = $this->generateNewTestUser();
    $reader = $this->generateNewTestUser();
    $project = $this->newProject($owner, array(), true);
    $task = $this->newTask($owner, $project, false);
    $body = 'Private node marker '.Filesystem::readRandomCharacters(24).' T'.$task->getID();
    $this->publish($owner, $project, array('body' => $body));
    $this->assertTrue(in_array($project->getPHID(), $this->mentionTargets(
      $task, PhabricatorObjectMentionedByObjectEdgeType::EDGECONST)));
    $transactions = id(new ManiphestTransactionQuery())->setViewer($reader)
      ->withObjectPHIDs(array($task->getPHID()))->execute();
    $handles = id(new PhabricatorHandleQuery())->setViewer($reader)
      ->withPHIDs(array($owner->getPHID(), $project->getPHID(), $task->getPHID()))->execute();
    foreach ($transactions as $transaction) {
      $transaction->setHandles($handles);
      $title = (string)$transaction->getTitle();
      $this->assertFalse(strpos($title, $project->getName()) !== false);
      $this->assertFalse(strpos($title, $body) !== false);
    }
    $this->assertEqual(array(), TeamProjectProgress::newQuery($reader, $project)->execute());
  }

  public function testRevokedNodeAuthorDoesNotBlockBackgroundPublication() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phabricator.silent', true);
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'phorge');
    try {
      $owner = $this->generateNewTestUser();
      $writer = $this->generateNewTestUser();
      $project = $this->newProject($owner, array());
      $this->setEditPolicy($owner, $project, PhabricatorPolicies::POLICY_USER);
      $record = $this->publish($writer, $project, array(
        'body' => 'Please review @'.$owner->getUsername().'.',
      ));
      id(new ConduitCall('project.edit', array(
        'objectIdentifier' => $project->getPHID(),
        'transactions' => array(
          array('type' => 'view', 'value' => $owner->getPHID()),
          array('type' => 'edit', 'value' => $owner->getPHID()),
        ),
      )))->setUser($owner)->execute();
      $this->assertEqual(array(), TeamProjectProgress::newQuery($writer, $project)->execute());
      // The queued author's mail transaction becomes invisible. Native mail
      // filtering must skip it and still publish the owner's visible feed.
      $this->runPublishWorkers(array($project));
      $this->assertEqual(1, $this->countStoriesForTransactions(
        $this->feedForProject($owner, $project), array($record)));
      $this->assertEqual(0, $this->countStoriesForTransactions(
        $this->feedForProject($writer, $project), array($record)));
    } finally {
      unset($env);
    }
  }

  private function mentionTargets($object,
    $type = PhabricatorObjectMentionsObjectEdgeType::EDGECONST) {
    $query = id(new PhabricatorEdgeQuery())->withSourcePHIDs(array($object->getPHID()))
      ->withEdgeTypes(array($type));
    $query->execute();
    return $query->getDestinationPHIDs();
  }

  private function record(array $input = array()) {
    return $input + array(
      'kind' => 'node', 'title' => '完成本轮开发',
      'body' => '接口已联调，下一步进行验收。',
      'status' => 'planned', 'percent' => null, 'participants' => array(),
    );
  }

  private function taskCount() {
    $task = new ManiphestTask();
    $row = queryfx_one($task->establishConnection('r'),
      'SELECT COUNT(*) AS n FROM %T', $task->getTableName());
    return (int)$row['n'];
  }

  private function publish($actor, $project, array $input = array()) {
    return TeamProjectProgress::publish($actor, $project, $this->record($input),
      PhabricatorContentSource::newForSource(PhabricatorUnitTestContentSource::SOURCECONST));
  }

  private function publishLegacy($actor, $project, array $input) {
    $this->assertTrue(in_array($input['kind'], array('update', 'milestone')));
    $this->assertTrue($this->getPhabricatorTestCaseConfiguration()
      [self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES]);
    $record = id(new PhabricatorProjectTransaction())
      ->setTransactionType(TeamProjectProgress::getTransactionTypes()[$input['kind']])
      ->setNewValue($this->record($input));
    $this->editor($actor)->applyTransactions($project, array($record));
    // This fixture helper represents real version-one rows. Production native
    // editors always strip execution state now; only a storage-fixture test
    // may explicitly restore the historical payload after validation.
    $historical_value = $record->getNewValue();
    unset($historical_value['version']);
    $historical_value['status'] = idx($this->record($input), 'status');
    $record->setNewValue($historical_value)->save();
    return $record;
  }

  private function editor($actor) {
    return id(new PhabricatorProjectTransactionEditor())->setActor($actor)
      ->setContentSource(PhabricatorContentSource::newForSource(
        PhabricatorUnitTestContentSource::SOURCECONST));
  }

  private function assertCannotPublishAnyRecord($actor, $project) {
    foreach (TeamProjectProgress::getTransactionTypes() as $kind => $type) {
      $this->assertRejected(function() use ($actor, $project, $kind) {
        $this->publish($actor, $project, array('kind' => $kind));
      }, PhabricatorPolicyException::class);

      // Calling the native editor directly must not bypass the service gate.
      $this->assertRejected(function() use ($actor, $project, $kind, $type) {
        $xaction = id(new PhabricatorProjectTransaction())
          ->setTransactionType($type)
          ->setNewValue($this->record(array('kind' => $kind)));
        $this->editor($actor)->applyTransactions($project, array($xaction));
      }, PhabricatorApplicationTransactionValidationException::class);
    }
  }

  private function setEditPolicy($owner, $project, $policy) {
    id(new ConduitCall('project.edit', array(
      'objectIdentifier' => $project->getPHID(),
      'transactions' => array(array('type' => 'edit', 'value' => $policy)),
    )))->setUser($owner)->execute();
  }

  private function newTask($owner, $project, $private) {
    $result = id(new ConduitCall('maniphest.edit', array('transactions' => array(
      array('type' => 'title', 'value' => 'Feed Task '.Filesystem::readRandomCharacters(12)),
      array('type' => 'projects.add', 'value' => array($project->getPHID())),
      array('type' => 'view', 'value' => $private ? $owner->getPHID() : PhabricatorPolicies::POLICY_USER),
      array('type' => 'edit', 'value' => $owner->getPHID()),
    ))))->setUser($owner)->execute();
    return id(new ManiphestTaskQuery())->setViewer($owner)
      ->withPHIDs(array($result['object']['phid']))->executeOne();
  }

  private function editTask($actor, $task, array $transactions) {
    return id(new ConduitCall('maniphest.edit', array(
      'objectIdentifier' => $task->getPHID(), 'transactions' => $transactions,
    )))->setUser($actor)->execute();
  }

  private function runPublishWorkers(array $objects) {
    $this->assertFalse(PhabricatorGorgeTaskQueueClient::isConfigured());
    $tasks = id(new PhabricatorWorkerActiveTask())->loadAllWhere(
      'taskClass = %s AND objectPHID IN (%Ls) ORDER BY id ASC',
      'PhabricatorApplicationTransactionPublishWorker', mpull($objects, 'getPHID'));
    $this->assertTrue(count($tasks) > 0);
    foreach ($tasks as $task) {
      $data = id(new PhabricatorWorkerTaskData())->load($task->getDataID());
      $task->setData($data->getData());
      $result = $task->executeTask();
      $error = $result->getExecutionException();
      $this->assertTrue($result instanceof PhabricatorWorkerArchiveTask,
        $error ? $error->getMessage() : 'Publish worker must archive successfully.');
      $this->assertEqual(PhabricatorWorkerArchiveTask::RESULT_SUCCESS, $result->getResult());
    }
  }

  private function feedForProject($viewer, $project) {
    return id(new PhabricatorFeedQuery())->setViewer($viewer)
      ->withFilterPHIDs(array($project->getPHID()))->setLimit(100)->execute();
  }

  private function countStoriesForTransactions(array $stories, array $transactions) {
    $phids = mpull($transactions, 'getPHID');
    $count = 0;
    foreach ($stories as $story) {
      if (array_intersect($phids, $story->getValue('transactionPHIDs', array()))) {
        $count++;
      }
    }
    return $count;
  }

  private function newProject($owner, array $members, $private = false, $parent = null) {
    $transactions = array(
      array('type' => 'name', 'value' => 'Progress Test '.Filesystem::readRandomCharacters(16)),
      array('type' => 'members.set', 'value' => array_values(mpull($members, 'getPHID'))),
      array('type' => 'view', 'value' => $private ? $owner->getPHID() : PhabricatorPolicies::POLICY_USER),
      array('type' => 'edit', 'value' => $owner->getPHID()),
      array('type' => 'join', 'value' => PhabricatorPolicies::POLICY_NOONE),
    );
    if ($parent) {
      $transactions[] = array('type' => 'parent', 'value' => $parent->getPHID());
    }
    $result = id(new ConduitCall('project.edit', array('transactions' => $transactions)))
      ->setUser($owner)->execute();
    // Existing ACL regressions exercise legacy projects; new roles have their own suite.
    $project = $this->refresh($result['object']['phid']);
    return $this->refresh($project);
  }

  private function refresh($project) {
    $phid = is_string($project) ? $project : $project->getPHID();
    return id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))->needMembers(true)->needAncestorMembers(true)
      ->executeOne();
  }

  private function assertRejected($callback, $class) {
    $caught = null;
    try {
      $callback();
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof $class,
      $caught ? get_class($caught).': '.$caught->getMessage() : 'Expected rejection.');
  }
}
