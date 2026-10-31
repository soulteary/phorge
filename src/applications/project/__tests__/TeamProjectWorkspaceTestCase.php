<?php

final class TeamProjectWorkspaceTestCase extends PhabricatorTestCase {

  private $workspaceEnvironment;
  private $workspaceLocale;

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  protected function willRunTests() {
    parent::willRunTests();
    $this->workspaceLocale = PhabricatorEnv::beginScopedLocale('en_US');
    $this->workspaceEnvironment = PhabricatorEnv::beginScopedEnv();
    $this->workspaceEnvironment->overrideEnvConfig('project.history-state', 'active');
    $this->workspaceEnvironment->overrideEnvConfig('project.workspace-mode', 'unified');
    $this->workspaceEnvironment->overrideEnvConfig('project.history-task-drafts', true);
  }

  protected function didRunTests() {
    unset($this->workspaceEnvironment);
    unset($this->workspaceLocale);
    parent::didRunTests();
  }

  protected function willRunOneTest($test) {
    parent::willRunOneTest($test);
    set_time_limit(30);
  }

  public function testDisabledDraftEntryDoesNotDisableOrdinaryTasksOrOldRecords() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $record = $this->publish($owner, $project);
    $before = $this->taskCount();
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('project.history-task-drafts', false);
    $this->assertRejected(function() use ($owner, $record) {
      TeamProjectProgress::newTaskDraft($owner, $record->getID());
    }, PhabricatorPolicyException::class);
    $this->assertEqual($before, $this->taskCount());
    $native = $this->newTask($owner, $project, false);
    $this->assertTrue((bool)$native->getID());
    $this->assertEqual($before + 1, $this->taskCount());
    foreach (array('read-only', 'off') as $state) {
      $env->overrideEnvConfig('project.history-task-drafts', true);
      $env->overrideEnvConfig('project.history-state', $state);
      $this->assertRejected(function() use ($owner, $record) {
        TeamProjectProgress::newTaskDraft($owner, $record->getID());
      }, PhabricatorPolicyException::class);
      $this->assertEqual(1, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
    }
    unset($env);
  }

  public function testStaleDisabledActorCannotOpenTaskDraft() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $record = $this->publish($owner, $project);
    $before = $this->taskCount();
    $stale = clone $owner;
    $owner->setIsDisabled(1)->save();
    $this->assertRejected(function() use ($stale, $record) {
      TeamProjectProgress::newTaskDraft($stale, $record->getID());
    }, PhabricatorPolicyException::class);
    $this->assertEqual($before, $this->taskCount());
  }

  public function testExplicitNativeSaveUsesCurrentDefaultsAndDoesNotCopySourceGrants() {
    $owner = $this->generateNewTestUser();
    $writer = $this->generateNewTestUser();
    $project = $this->newProject($owner, array($writer), true);
    $this->setEditPolicy($owner, $project, PhabricatorPolicies::POLICY_USER);
    $record = $this->publish($owner, $project, array('percent' => 90));
    $before = $this->taskCount();
    $draft = TeamProjectProgress::newTaskDraft($writer, $record->getID());
    $this->assertEqual($before, $this->taskCount());
    $native_defaults = ManiphestTask::initializeNewTask($writer);
    $result = id(new ConduitCall('maniphest.edit', array('transactions' => array(
      array('type' => 'title', 'value' => $draft->getTitle()),
      array('type' => 'description', 'value' => $draft->getDescription()),
      array('type' => 'projects.add', 'value' => $draft->getProjectPHIDs()),
    ))))->setUser($writer)->execute();
    $saved = id(new ManiphestTaskQuery())->setViewer($writer)
      ->withPHIDs(array($result['object']['phid']))->executeOne();
    $this->assertEqual($before + 1, $this->taskCount());
    $this->assertEqual($writer->getPHID(), $saved->getAuthorPHID());
    $this->assertEqual($native_defaults->getStatus(), $saved->getStatus());
    $this->assertEqual($native_defaults->getViewPolicy(), $saved->getViewPolicy());
    $this->assertEqual($native_defaults->getEditPolicy(), $saved->getEditPolicy());
    $this->assertEqual(null, $saved->getOwnerPHID());
    $this->assertEqual($draft->getDescription(), $saved->getDescription());
    $this->assertEqual(90, TeamProjectProgress::getSummary($project, $owner)['percent']);
  }

  public function testWorkspaceVisibilityAndCustomStatusDoNotChangeNativeConfiguration() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $task = $this->newTask($owner, $project, false);
    $configured = PhabricatorEnv::getEnvConfig('maniphest.statuses');
    $custom = $configured['open'];
    unset($custom['special']);
    $custom['name'] = 'Review <Check>';
    $configured['reviewing'] = $custom;
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('maniphest.statuses', $configured);
    $this->editTask($owner, $task, array(array('type' => 'status', 'value' => 'reviewing')));
    $stored = id(new ManiphestTaskQuery())->setViewer($owner)
      ->withPHIDs(array($task->getPHID()))->executeOne();
    $card = id(new ProjectBoardTaskCard())->setViewer($owner)
      ->setTask($stored)->setProjectHandles(array());
    $html = (string)hsprintf('%s', $card->getItem());
    $this->assertTrue(strpos($html, 'Review &lt;Check&gt;') !== false);
    $this->assertEqual(1, substr_count($html, 'team-project-card-status'));
    $env->overrideEnvConfig('project.workspace-mode', 'native');
    $html = (string)hsprintf('%s', $card->getItem());
    $this->assertEqual(0, substr_count($html, 'team-project-card-status'));
    $this->assertEqual('reviewing', $stored->getStatus());
    $this->assertEqual($configured, PhabricatorEnv::getEnvConfig('maniphest.statuses'));
    unset($env);
  }

  public function testProjectShortcutsRetainProjectScopeAndManiphestAvailability() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $html = (string)hsprintf('%s', TeamProjectWorkspaceView::newProjectActions($owner, $project));
    foreach (array('/feed/?projectPHIDs='.$project->getPHID(),
      '/maniphest/?projectPHIDs='.$project->getPHID(),
      '/project/manage/'.$project->getID().'/') as $uri) {
      $this->assertTrue(strpos($html, $uri) !== false, $uri);
    }
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phabricator.uninstalled-applications', array(
      PhabricatorManiphestApplication::class => true,
    ));
    PhabricatorCaches::destroyRequestCache();
    $html = (string)hsprintf('%s', TeamProjectWorkspaceView::newProjectActions($owner, $project));
    $this->assertFalse(strpos($html, '/maniphest/') !== false);
    $this->assertTrue(strpos($html, '/feed/?projectPHIDs='.$project->getPHID()) !== false);
    unset($env);
    PhabricatorCaches::destroyRequestCache();
  }

  public function testSharedControlsKeepNativeDropdownMetadataAndSigils() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $control = id(new PHUIListItemView())->setName('Sort: Status')
      ->setIcon('fa-sort')->setHref('#')
      ->addSigil('boards-dropdown-menu')->addClass('test-board-control')
      ->setMetadata(array('items' => array('native-status'), 'marker' => 'preserved'));
    $view = id(new TeamProjectWorkspaceView())->setViewer($owner)
      ->setProject($project)->addBoardControl($control);
    $html = (string)hsprintf('%s', $view);
    $this->assertTrue(strpos($html, 'boards-dropdown-menu') !== false);
    $this->assertTrue(strpos($html, 'test-board-control') !== false);
    $this->assertTrue(strpos($html, 'Sort: Status') !== false);
    $this->assertTrue(strpos($html, 'data-meta') !== false);
  }

  public function testSharedWorkCardsUseNativeStatusTransactions() {
    $owner = $this->generateNewTestUser();
    $project = $this->newProject($owner, array());
    $record = $this->publish($owner, $project, array('percent' => 35));
    $task = $this->newTask($owner, $project, false);
    foreach (array('resolved', 'open', 'wontfix', 'open') as $status) {
      $this->editTask($owner, $task, array(array(
        'type' => 'status', 'value' => $status,
      )));
      $stored = id(new ManiphestTaskQuery())->setViewer($owner)
        ->withPHIDs(array($task->getPHID()))->executeOne();
      $this->assertEqual($status, $stored->getStatus());
      $this->assertEqual(in_array($status, array('resolved', 'wontfix')), $stored->isClosed());
      $card = id(new ProjectBoardTaskCard())->setViewer($owner)
        ->setTask($stored)->setProjectHandles(array())->getItem();
      $card_html = (string)hsprintf('%s', $card);
      $this->assertEqual(1, substr_count($card_html, 'team-project-card-status'));
      $this->assertTrue(strpos($card_html,
        ManiphestTaskStatus::getTaskStatusName($status)) !== false);
      $summary = TeamProjectProgress::getSummary($project, $owner);
      $this->assertEqual(35, $summary['percent']);
      $this->assertEqual((string)$record->getID(), (string)$summary['latestNode']->getID());
    }
    $this->assertEqual(1, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
    $transactions = id(new ManiphestTransactionQuery())->setViewer($owner)
      ->withObjectPHIDs(array($task->getPHID()))
      ->withTransactionTypes(array(ManiphestTaskStatusTransaction::TRANSACTIONTYPE))
      ->execute();
    $this->assertEqual(4, count($transactions));

    // Retiring the old standalone entry must not strand a stored default.
    $configs = id(new PhabricatorProfileMenuItemConfigurationQuery())
      ->setViewer($owner)->withProfilePHIDs(array($project->getPHID()))
      ->execute();
    foreach ($configs as $config) {
      $config->setVisibility(
        PhabricatorProfileMenuItemConfiguration::VISIBILITY_VISIBLE)->save();
    }
    id(PhabricatorProfileMenuItemConfiguration::initializeNewBuiltin())
      ->setProfilePHID($project->getPHID())
      ->setBuiltinKey(TeamProjectProgressProfileMenuItem::BUILTINKEY)
      ->setMenuItemKey(TeamProjectProgressProfileMenuItem::MENUITEMKEY)
      ->setVisibility(PhabricatorProfileMenuItemConfiguration::VISIBILITY_DEFAULT)
      ->save();
    $menu = id(new PhabricatorProjectProfileMenuEngine())
      ->setViewer($owner)->setProfileObject($project);
    $this->assertEqual(PhabricatorProject::ITEM_WORKBOARD,
      $menu->getDefaultMenuItemConfiguration()->getBuiltinKey());
  }

  public function testTaskDraftsPreserveSourceAndUseNativeCreationDefaults() {
    $owner = $this->generateNewTestUser();
    $editor = $this->generateNewTestUser();
    $project = $this->newProject($owner, array($editor), true);
    $this->setEditPolicy($owner, $project, PhabricatorPolicies::POLICY_USER);
    $legacy = $this->publishLegacy($owner, $project, array(
      'kind' => 'milestone', 'title' => '旧阶段记录', 'body' => '原始阶段成果。',
      'status' => 'done', 'percent' => 75,
      'participants' => array($owner->getPHID()),
    ));
    $legacy_value = $legacy->getNewValue();
    $record_count = count(TeamProjectProgress::newQuery($owner, $project)->execute());
    $before = $this->taskCount();
    $default = ManiphestTask::initializeNewTask($editor);
    $task = TeamProjectProgress::newTaskDraft($editor, (string)$legacy->getID());
    $this->assertEqual(null, $task->getID());
    $this->assertEqual(null, $task->getPHID());
    $this->assertEqual(null, $task->getDateCreated());
    $this->assertEqual($editor->getPHID(), $task->getAuthorPHID());
    $this->assertEqual($default->getViewPolicy(), $task->getViewPolicy());
    $this->assertEqual($default->getEditPolicy(), $task->getEditPolicy());
    $this->assertEqual($default->getStatus(), $task->getStatus());
    $this->assertEqual($default->getPriority(), $task->getPriority());
    $this->assertEqual($default->getSpacePHID(), $task->getSpacePHID());
    $this->assertEqual(null, $task->getOwnerPHID());
    $this->assertEqual(array($project->getPHID()), $task->getProjectPHIDs());
    $this->assertEqual('旧阶段记录', $task->getTitle());
    $source_uri = '/project/progress/'.$project->getID().'/?entry='.
      $legacy->getID().'#entry-'.$legacy->getID();
    $old_author = $editor->loadHandles(array($owner->getPHID()))
      [$owner->getPHID()]->getFullName();
    foreach (array('原始阶段成果。', $source_uri, $old_author,
      gmdate('Y-m-d H:i:s', $legacy->getDateCreated()),
      'Original Node Status: Completed (Historical Information)') as $part) {
      $this->assertTrue(strpos($task->getDescription(), $part) !== false, $part);
    }
    // Drafting from a completed record never closes the native new task.
    $this->assertFalse($task->isClosed());
    $this->assertEqual($before, $this->taskCount());
    $this->assertEqual($record_count,
      count(TeamProjectProgress::newQuery($owner, $project)->execute()));
    $stored = TeamProjectProgress::newQuery($owner, $project)
      ->withIDs(array($legacy->getID()))->executeOne();
    $this->assertEqual($legacy_value, $stored->getNewValue());
    $this->assertEqual($legacy->getAuthorPHID(), $stored->getAuthorPHID());
    $this->assertEqual((string)$legacy->getDateCreated(), (string)$stored->getDateCreated());

    $new = $this->publish($owner, $project, array('status' => null));
    $new_draft = TeamProjectProgress::newTaskDraft($editor, $new->getID());
    $this->assertEqual($new->getNewValue()['title'], $new_draft->getTitle());
    $update = $this->publishLegacy($owner, $project, array(
      'kind' => 'update', 'status' => 'active'));
    $update_draft = TeamProjectProgress::newTaskDraft($editor, $update->getID());
    $this->assertEqual($default->getStatus(), $update_draft->getStatus());
    $this->assertTrue(strpos($update_draft->getDescription(),
      'Original Node Status: In Progress (Historical Information)') !== false);
    $this->assertEqual($before, $this->taskCount());
  }

  public function testTaskDraftsRejectPrivateReadOnlyRevokedAndNonNodeSources() {
    $owner = $this->generateNewTestUser();
    $reader = $this->generateNewTestUser();
    $outsider = $this->generateNewTestUser();
    $project = $this->newProject($owner, array($reader), true);
    $node = $this->publish($owner, $project, array('status' => null));
    $comment = $this->publish($owner, $project, array('kind' => 'comment'));
    $before = $this->taskCount();
    foreach (array($reader, $outsider, new PhabricatorUser()) as $viewer) {
      $this->assertRejected(function() use ($viewer, $node) {
        TeamProjectProgress::newTaskDraft($viewer, $node->getID());
      }, PhabricatorPolicyException::class);
    }
    $this->assertRejected(function() use ($owner, $comment) {
      TeamProjectProgress::newTaskDraft($owner, $comment->getID());
    }, PhabricatorPolicyException::class);
    $this->assertRejected(function() use ($owner) {
      TeamProjectProgress::newTaskDraft($owner, 'not-an-id');
    }, InvalidArgumentException::class);
    $this->assertRejected(function() use ($owner) {
      TeamProjectProgress::newTaskDraft($owner, PHP_INT_MAX);
    }, PhabricatorPolicyException::class);
    $this->setEditPolicy($owner, $project, PhabricatorPolicies::POLICY_USER);
    $this->assertTrue(TeamProjectProgress::canPublish($reader, $project));
    TeamProjectProgress::newTaskDraft($reader, $node->getID());
    $this->setEditPolicy($owner, $project, $owner->getPHID());
    $this->assertRejected(function() use ($reader, $node) {
      TeamProjectProgress::newTaskDraft($reader, $node->getID());
    }, PhabricatorPolicyException::class);
    $reader->setIsDisabled(true)->save();
    $this->assertRejected(function() use ($reader, $node) {
      TeamProjectProgress::newTaskDraft($reader, $node->getID());
    }, PhabricatorPolicyException::class);
    $this->assertEqual($before, $this->taskCount());
    $this->assertEqual(2, count(TeamProjectProgress::newQuery($owner, $project)->execute()));
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
