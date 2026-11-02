<?php

/**
 * Writable synthetic integration tests. Never a production executor and never
 * a read-only observation: native commits and queue records are intentional.
 */
function governance_completion_pipeline($fixture, callable $check) {
  if (!is_object($fixture) ||
      get_class($fixture) !== PhorgeGovernanceCompletionFixture::class) {
    throw new RuntimeException('pipeline_exact_owned_fixture_required');
  }
  $fixture->assertOwned();
  $runtime_profile = PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  $namespace = $fixture->getNamespace();
  if (PHP_SAPI !== 'cli' ||
      getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
      PhabricatorEnv::getEnvConfig('storage.default-namespace') !== $namespace ||
      PhabricatorGorgeTaskQueueClient::isConfigured()) {
    throw new RuntimeException('pipeline_owned_native_scope_required');
  }
  foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
    'PHORGE_CONTROL_PLANE') as $selector) {
    $value = getenv($selector);
    if ($value !== false && $value !== '') {
      throw new RuntimeException('pipeline_deployment_selector_refused');
    }
  }
  $config = $fixture->getWriterConfig();
  foreach (array('mysql.host' => 'host', 'mysql.port' => 'port',
    'mysql.user' => 'user', 'mysql.pass' => 'password',
    'storage.default-namespace' => 'namespace') as $key => $field) {
    if (PhabricatorEnv::getEnvConfig($key) !== $config[$field]) {
      throw new RuntimeException('pipeline_writer_routing_refused');
    }
  }
  if (PhabricatorEnv::getEnvConfig('cluster.databases') !== array() ||
      !in_array(PhabricatorEnv::getEnvConfig('gorge.db.uri'), array(null, ''), true) ||
      (new ReflectionProperty(LiskDAO::class, 'connections'))->getValue() !== array()) {
    throw new RuntimeException('pipeline_inherited_or_alternate_database_refused');
  }
  $in_process = new ReflectionProperty(PhabricatorWorker::class,
    'runAllTasksInProcess');
  if ($in_process->getValue() !== false) {
    throw new RuntimeException('pipeline_background_execution_refused');
  }
  $admin = $fixture->getAdmin();
  $count = static function($app, $table, $where = '') use ($fixture, $admin) {
    $fixture->assertOwned();
    return (int)$admin->query('SELECT COUNT(*) FROM '.$fixture->table($app, $table).
      ($where === '' ? '' : ' WHERE '.$where))->fetch_row()[0];
  };
  $quoted = static function($value) use ($admin) {
    return "'".$admin->real_escape_string($value)."'";
  };
  $queue = static function($phid) use ($count, $quoted) {
    return $count('worker', 'worker_activetask',
      "taskClass='PhabricatorApplicationTransactionPublishWorker' AND objectPHID=".
      $quoted($phid));
  };
  $tx_count = static function($app, $model, $phid) use ($count, $quoted) {
    return $count($app, $model->getTableName(), 'objectPHID='.$quoted($phid));
  };
  $reload_project = static function($phid) {
    return (new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))->needMembers(true)->needAncestorMembers(true)
      ->executeOne();
  };
  $reload_task = static function($phid) {
    return (new ManiphestTaskQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))->needSubscriberPHIDs(true)->needProjectPHIDs(true)
      ->executeOne();
  };
  $reload_wiki = static function($phid) {
    return (new PhrictionDocumentQuery())->needContent(true)
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))->executeOne();
  };
  $source = PhabricatorContentSource::newForSource('console', array());
  $edit = static function($object, $actor, array $transactions) use ($source) {
    return $object->getApplicationTransactionEditor()->setActor($actor)
      ->setContentSource($source)->setIsSilent(false)
      ->setContinueOnNoEffect(true)->applyTransactions($object, $transactions);
  };
  $users = array();
  foreach (array('actor', 'oldowner', 'nextowner', 'commenter') as $role) {
    $user = (new PhabricatorUser())->setUsername('gc'.$role.substr($namespace, -12))
      ->setRealName('Synthetic Pipeline '.$role)->setIsApproved(1)
      ->setIsAdmin($role === 'actor' ? 1 : 0)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())
      ->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $users[$role] = $user;
  }
  $actor = $users['actor'];
  $space = (new PhabricatorSpacesNamespaceQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withIsDefaultNamespace(true)->executeOne();
  if (!$space) {
    $space = PhabricatorSpacesNamespace::initializeNewNamespace($actor)
      ->setNamespaceName('Synthetic Pipeline Default')->setIsDefaultNamespace(1)
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
  }
  PhabricatorCaches::destroyRequestCache();
  $check($count('herald', 'herald_rule') === 0, 'pipeline-herald-no-rules-fixture');
  $herald_before = $count('herald', 'herald_transcript');

  $project = PhabricatorProject::initializeNewProject($actor)
    ->setSpacePHID($space->getPHID());
  $edit($project, $actor, array((new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic Pipeline Project')));
  $project = $reload_project($project->getPHID());
  $check($project && $project->getName() === 'Synthetic Pipeline Project',
    'pipeline-project-create-committed');
  $check($tx_count('project', new PhabricatorProjectTransaction(),
    $project->getPHID()) > 0, 'pipeline-project-native-records-persisted');
  $check($queue($project->getPHID()) > 0, 'pipeline-project-publishing-queued');
  $edit($project, $actor, array((new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic Pipeline Project Updated')));
  $check($reload_project($project->getPHID())->getName() ===
    'Synthetic Pipeline Project Updated', 'pipeline-project-update-committed');

  $child = PhabricatorProject::initializeNewProject($actor, $project);
  $edit($child, $actor, array((new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic Pipeline Child'), (new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectParentTransaction::TRANSACTIONTYPE)
    ->setNewValue($project->getPHID())));
  $child = $reload_project($child->getPHID());
  $check($child->getParentProjectPHID() === $project->getPHID() &&
    !$child->isMilestone(), 'pipeline-native-child-parent-link-created');
  $milestone = PhabricatorProject::initializeNewProject($actor, $child);
  $edit($milestone, $actor, array((new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic Pipeline Milestone'), (new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE)
    ->setNewValue($child->getPHID())));
  $milestone = $reload_project($milestone->getPHID());
  $check($milestone->isMilestone() &&
    $milestone->getParentProjectPHID() === $child->getPHID(),
    'pipeline-native-milestone-parent-link-created');
  foreach (array('parent' => $project, 'milestone' => $milestone) as $kind => $p) {
    $p = $reload_project($p->getPHID());
    $before_records = $tx_count('project', new PhabricatorProjectTransaction(),
      $p->getPHID());
    $before_queue = $queue($p->getPHID());
    $failed = false;
    try {
      $edit($p, $actor, array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue('edge:type',
          PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
        ->setNewValue(array('+' => array_fuse(array($users['nextowner']->getPHID()))))));
    } catch (PhabricatorApplicationTransactionValidationException $error) {
      $failed = true;
    }
    $check($failed && $tx_count('project', new PhabricatorProjectTransaction(),
      $p->getPHID()) === $before_records && $queue($p->getPHID()) === $before_queue,
      'pipeline-'.$kind.'-member-native-validation-rejected');
  }

  foreach (array(PhabricatorProjectParentTransaction::TRANSACTIONTYPE,
    PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE) as $type) {
    $failed = false;
    try {
      $edit($reload_project($child->getPHID()), $actor,
        array((new PhabricatorProjectTransaction())->setTransactionType($type)
          ->setNewValue($project->getPHID())));
    } catch (PhabricatorApplicationTransactionValidationException $error) {
      $failed = true;
    }
    $check($failed && $reload_project($child->getPHID())->getParentProjectPHID() ===
      $project->getPHID(), 'pipeline-existing-project-'.$type.'-rejected');
  }

  $task = ManiphestTask::initializeNewTask($actor)
    ->setOwnerPHID($users['oldowner']->getPHID())->setSpacePHID($space->getPHID());
  $edit($task, $actor, array((new ManiphestTransaction())
    ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic Pipeline Task'), (new ManiphestTransaction())
    ->setTransactionType(ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic task body')));
  $task = $reload_task($task->getPHID());
  $check($task && $task->getTitle() === 'Synthetic Pipeline Task',
    'pipeline-task-create-committed');
  $check($queue($task->getPHID()) > 0, 'pipeline-task-publishing-queued');
  $edit($task, $actor, array((new ManiphestTransaction())
    ->setTransactionType(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE)
    ->setNewValue($users['nextowner']->getPHID())));
  $task = $reload_task($task->getPHID());
  $check($task->getOwnerPHID() === $users['nextowner']->getPHID(),
    'pipeline-task-owner-change-committed');
  $subscribers = PhabricatorSubscribersQuery::loadSubscribersForPHID(
    $task->getPHID());
  $check(in_array($users['oldowner']->getPHID(), $subscribers, true),
    'pipeline-support-oldowner-subscribed');
  $comment_tx = new ManiphestTransaction();
  $comment_tx->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
    ->attachComment($comment_tx->getApplicationTransactionCommentObject()
      ->setContent('Synthetic pipeline comment'));
  $edit($task, $users['commenter'], array($comment_tx));
  $check($count('maniphest', $comment_tx->getApplicationTransactionCommentObject()
    ->getTableName()) > 0, 'pipeline-comment-record-persisted');
  $subscribers = PhabricatorSubscribersQuery::loadSubscribersForPHID($task->getPHID());
  $check(in_array($users['commenter']->getPHID(), $subscribers, true),
    'pipeline-support-comment-implicit-cc');

  // Current product writes and reads bytes through the real paired Gorge
  // service. Historical PHP storage engines remain read-only.
  $file = PhabricatorFile::newFromFileData('synthetic file body', array(
    'name' => 'synthetic.txt', 'authorPHID' => $actor->getPHID(),
    'viewPolicy' => PhabricatorPolicies::POLICY_USER));
  $check($file->getStorageEngine() === 'gorge' &&
    $file->loadFileData() === 'synthetic file body',
    'pipeline-current-gorge-file-bytes-roundtrip');
  $edit($reload_task($task->getPHID()), $actor, array((new ManiphestTransaction())
    ->setTransactionType(ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic attached reference {F'.$file->getID().'}')));
  $check($reload_task($task->getPHID())->getDescription() ===
    'Synthetic attached reference {F'.$file->getID().'}',
    'pipeline-mapped-task-description');
  $check($count('file', (new PhabricatorFileAttachment())->getTableName(),
    'objectPHID='.$quoted($task->getPHID()).' AND filePHID='.$quoted($file->getPHID())) > 0,
    'pipeline-support-native-file-reference-attachment');
  $file_transactions = (new ManiphestTransactionQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withObjectPHIDs(array($task->getPHID()))
    ->withTransactionTypes(array(PhabricatorTransactions::TYPE_FILE))->execute();
  $check((bool)$file_transactions, 'pipeline-support-native-file-transaction-record');

  // The full quickstart has no Wiki root document. Native child creation
  // requires a real editable ancestor; create it through the same editor.
  $wiki_root = (new PhrictionDocumentQuery())->needContent(true)
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withSlugs(array('/'))->executeOne();
  if (!$wiki_root) {
    $wiki_root = PhrictionDocument::initializeNewDocument($actor, '/')
      ->setSpacePHID($space->getPHID());
    $edit($wiki_root, $actor, array((new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentTitleTransaction::TRANSACTIONTYPE)
      ->setNewValue('Synthetic Pipeline Wiki Root'), (new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
      ->setNewValue('Synthetic parent document')));
  }
  $check($wiki_root->getID() !== null,
    'pipeline-real-wiki-root-ancestor-prepared');

  $wiki = PhrictionDocument::initializeNewDocument($actor, 'synthetic-pipeline/')
    ->setSpacePHID($space->getPHID());
  $edit($wiki, $actor, array((new PhrictionTransaction())
    ->setTransactionType(PhrictionDocumentTitleTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic Pipeline Wiki'), (new PhrictionTransaction())
    ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic pipeline wiki body')));
  $wiki = $reload_wiki($wiki->getPHID());
  $check($wiki && $wiki->getContent()->getContent() ===
    'Synthetic pipeline wiki body', 'pipeline-wiki-content-final-effect-committed');
  $version = (int)$wiki->getContent()->getVersion();
  $edit($wiki, $actor, array((new PhrictionTransaction())
    ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic pipeline wiki next body')));
  $wiki = $reload_wiki($wiki->getPHID());
  $check($wiki->getContent()->getContent() === 'Synthetic pipeline wiki next body' &&
    (int)$wiki->getContent()->getVersion() > $version,
    'pipeline-wiki-update-new-version-committed');
  $check($tx_count('phriction', new PhrictionTransaction(), $wiki->getPHID()) > 0 &&
    $queue($wiki->getPHID()) > 0, 'pipeline-wiki-records-and-publishing-queued');
  $check($count('herald', 'herald_transcript') > $herald_before,
    'pipeline-real-no-rule-herald-transcripts');

  // Exercise the mapped existing-object fields separately from create NAME.
  foreach (array('Synthetic initial stored description',
    'Synthetic mapped project description') as $description) {
    $project = $reload_project($project->getPHID());
    $transaction_fields = PhabricatorCustomField::getObjectFields($project,
      PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS);
    $transaction_fields->readFieldsFromStorage($project);
    $description_field = PhabricatorCustomField::getObjectField($project,
      PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS,
      'std:project:internal:description');
    if (!$description_field) {
      throw new RuntimeException('pipeline_builtin_description_field_missing');
    }
    $old_description = $description_field->getOldValueForApplicationTransactions();
    if ($description === 'Synthetic mapped project description') {
      $check($old_description === 'Synthetic initial stored description',
        'pipeline-customfield-old-value-loaded-from-native-storage');
    }
    $edit($project, $actor, array((new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_CUSTOMFIELD)
      ->setMetadataValue('customfield:key', $description_field->getFieldKey())
      ->setOldValue($old_description)->setNewValue($description)));
  }
  $project = $reload_project($project->getPHID());
  $fields = PhabricatorCustomField::getObjectFields($project,
    PhabricatorCustomField::ROLE_VIEW);
  $fields->readFieldsFromStorage($project);
  $field = PhabricatorCustomField::getObjectField($project,
    PhabricatorCustomField::ROLE_VIEW, 'std:project:internal:description');
  $check($field && $field->getProxy()->getFieldValue() ===
    'Synthetic mapped project description', 'pipeline-mapped-project-description');
  $edit($reload_task($task->getPHID()), $actor, array((new ManiphestTransaction())
    ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic mapped task title')));
  $check($reload_task($task->getPHID())->getTitle() === 'Synthetic mapped task title',
    'pipeline-mapped-task-title');
  $edit($wiki, $actor, array((new PhrictionTransaction())
    ->setTransactionType(PhrictionDocumentTitleTransaction::TRANSACTIONTYPE)
    ->setNewValue('Synthetic mapped wiki title')));
  $wiki = $reload_wiki($wiki->getPHID());
  $check($wiki->getContent()->getTitle() === 'Synthetic mapped wiki title',
    'pipeline-mapped-wiki-title');

  $other_space = PhabricatorSpacesNamespace::initializeNewNamespace($actor)
    ->setNamespaceName('Synthetic Pipeline Other')
    ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
  // Native Space discovery is request-cached. A normal Space-create form
  // redirects before another object's edit; model that fresh request here.
  PhabricatorCaches::destroyRequestCache();
  $active_spaces = PhabricatorSpacesNamespaceQuery::getViewerActiveSpaces($actor);
  $check(isset($active_spaces[$other_space->getPHID()]),
    'pipeline-new-space-visible-after-native-request-boundary');
  foreach (array(array('project', $reload_project($project->getPHID()),
      new PhabricatorProjectTransaction(), $reload_project),
    array('task', $reload_task($task->getPHID()), new ManiphestTransaction(), $reload_task),
    array('wiki', $wiki, new PhrictionTransaction(), $reload_wiki)) as $spec) {
    list($family, $object, $template, $reload) = $spec;
    foreach (array(PhabricatorTransactions::TYPE_VIEW_POLICY,
      PhabricatorTransactions::TYPE_EDIT_POLICY) as $type) {
      $tx = clone $template;
      $edit($object, $actor, array($tx->setTransactionType($type)
        ->setNewValue(PhabricatorPolicies::POLICY_ADMIN)));
      $object = $reload($object->getPHID());
      $actual = $type === PhabricatorTransactions::TYPE_VIEW_POLICY ?
        $object->getViewPolicy() : $object->getEditPolicy();
      $check($actual === PhabricatorPolicies::POLICY_ADMIN,
        'pipeline-mapped-'.$family.'-'.$type);
    }
    if ($family === 'project') {
      $edit($object, $actor, array((clone $template)
        ->setTransactionType(PhabricatorTransactions::TYPE_JOIN_POLICY)
        ->setNewValue(PhabricatorPolicies::POLICY_ADMIN)));
      $object = $reload($object->getPHID());
      $check($object->getJoinPolicy() === PhabricatorPolicies::POLICY_ADMIN,
        'pipeline-mapped-project-join-policy');
    }
    $edit($object, $actor, array((clone $template)
      ->setTransactionType(PhabricatorTransactions::TYPE_SPACE)
      ->setNewValue($other_space->getPHID())));
    $object = $reload($object->getPHID());
    $check($object->getSpacePHID() === $other_space->getPHID(),
      'pipeline-mapped-'.$family.'-space');
  }
  // Current native membership and tagging remain independent of new roles.
  foreach (array(true, false) as $add) {
    $edit($reload_project($child->getPHID()), $actor,
      array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue('edge:type',
          PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
        ->setNewValue(array(($add ? '+' : '-') =>
          array_fuse(array($users['nextowner']->getPHID()))))));
    $members = $reload_project($child->getPHID())->getMemberPHIDs();
    $check(in_array($users['nextowner']->getPHID(), $members, true) === $add,
      'pipeline-mapped-project-member-'.($add ? 'add' : 'remove'));
  }
  foreach (array(array('task', $reload_task($task->getPHID()), new ManiphestTransaction()),
    array('wiki', $reload_wiki($wiki->getPHID()), new PhrictionTransaction())) as $spec) {
    list($family, $object, $template) = $spec;
    $edit($object, $actor, array($template
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type',
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST)
      ->setNewValue(array('+' => array_fuse(array($child->getPHID()))))));
    $destinations = PhabricatorEdgeQuery::loadDestinationPHIDs($object->getPHID(),
      PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
    $check(in_array($child->getPHID(), $destinations, true),
      'pipeline-mapped-'.$family.'-project-edge');
  }
  $board = $reload_project($child->getPHID())->setHasWorkboard(1)->save();
  (new PhabricatorProjectColumn())->setProjectPHID($board->getPHID())
    ->setName('Synthetic Native Backlog')->setSequence(0)
    ->setProperty('isDefault', true)
    ->setStatus(PhabricatorProjectColumn::STATUS_ACTIVE)->save();
  $column = (new PhabricatorProjectColumn())->setProjectPHID($board->getPHID())
    ->setName('Synthetic Native Target Column')->setSequence(1)
    ->setProperty('isDefault', false)
    ->setStatus(PhabricatorProjectColumn::STATUS_ACTIVE)->save();
  $edit($reload_task($task->getPHID()), $actor, array((new ManiphestTransaction())
    ->setTransactionType(ManiphestTaskColumnTransaction::TRANSACTIONTYPE)
    ->setNewValue($column->getPHID())));
  $check($count('project', (new PhabricatorProjectColumnPosition())->getTableName(),
    'objectPHID='.$quoted($task->getPHID()).' AND columnPHID='.$quoted($column->getPHID())) > 0,
    'pipeline-mapped-task-column');
  $column_records = (new ManiphestTransactionQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withObjectPHIDs(array($task->getPHID()))
    ->withTransactionTypes(array(ManiphestTaskColumnTransaction::TRANSACTIONTYPE))
    ->execute();
  $check((bool)$column_records, 'pipeline-native-column-move-record-not-noop');
  $source_wiki = $reload_wiki($wiki->getPHID());
  $source_body_before_move = $source_wiki->getContent()->getContent();
  $source_title_before_move = $source_wiki->getContent()->getTitle();
  $destination_wiki = PhrictionDocument::initializeNewDocument($actor,
    'synthetic-pipeline-moved/')->setSpacePHID($other_space->getPHID());
  // Mirror PhrictionMoveController's dedicated move contract. Only this
  // operation permits missing generic fields; source content comes from move.
  $destination_wiki->getApplicationTransactionEditor()->setActor($actor)
    ->setContentSource($source)->setIsSilent(false)->setContinueOnNoEffect(true)
    ->setContinueOnMissingFields(true)
    ->applyTransactions($destination_wiki, array((new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE)
      ->setNewValue($source_wiki)));
  $moved = $reload_wiki($destination_wiki->getPHID());
  $source_after_move = $reload_wiki($source_wiki->getPHID());
  $check($moved->getPHID() !== $source_after_move->getPHID() &&
    $moved->getContent()->getContent() === $source_body_before_move &&
    $moved->getContent()->getTitle() === $source_title_before_move &&
    $source_after_move->getStatus() === PhrictionDocumentStatus::STATUS_MOVED &&
    $source_after_move->getContent()->getContent() === '',
    'pipeline-mapped-wiki-move-and-subeditor');
  $check((int)$moved->getContent()->getChangeType() ===
      PhrictionChangeType::CHANGE_MOVE_HERE &&
    (int)$moved->getContent()->getChangeRef() === (int)$source_after_move->getID() &&
    (int)$source_after_move->getContent()->getChangeType() ===
      PhrictionChangeType::CHANGE_MOVE_AWAY &&
    (int)$source_after_move->getContent()->getChangeRef() === (int)$moved->getID(),
    'pipeline-native-wiki-move-cross-references-persisted');

  // A real SQL failure occurs after native object save but before external/
  // final effects. The native editor must rollback its open SQL transaction.
  $task = $reload_task($task->getPHID());
  $old_title = $task->getTitle();
  $records = $tx_count('maniphest', new ManiphestTransaction(), $task->getPHID());
  $queued = $queue($task->getPHID());
  $trigger = '`'.$namespace.'_maniphest`.`governance_pipeline_fail`';
  $created = false;
  try {
    $fixture->assertOwned();
    $admin->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.
      $fixture->table('maniphest', (new ManiphestTransaction())->getTableName()).
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated-pipeline-failure'");
    $created = true;
    $failed = false;
    try {
      $edit($task, $actor, array((new ManiphestTransaction())
        ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
        ->setNewValue('Synthetic Must Roll Back')));
    } catch (Throwable $error) {
      if ((int)$error->getCode() !== 1644 ||
          !str_contains($error->getMessage(), 'isolated-pipeline-failure')) {
        throw $error;
      }
      $failed = true;
    }
    $check($failed, 'pipeline-injected-record-write-failure-observed');
  } finally {
    if ($created) {
      $fixture->assertOwned();
      $admin->query('DROP TRIGGER '.$trigger);
    }
  }
  $check($reload_task($task->getPHID())->getTitle() === $old_title,
    'pipeline-midwrite-object-row-rolled-back');
  $check($tx_count('maniphest', new ManiphestTransaction(), $task->getPHID()) ===
    $records, 'pipeline-midwrite-transaction-records-rolled-back');
  $check($queue($task->getPHID()) === $queued,
    'pipeline-midwrite-no-publishing-enqueued');

  // Existing object validation opens a real native locking transaction. Check
  // its rejection without calling the failure an absence of all side effects.
  $task = $reload_task($task->getPHID());
  $failed = false;
  try {
    $edit($task, $actor, array((new ManiphestTransaction())
      ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
      ->setNewValue('')));
  } catch (PhabricatorApplicationTransactionValidationException $error) {
    $failed = true;
  }
  $check($failed && $reload_task($task->getPHID())->getTitle() === $old_title,
    'pipeline-invalid-task-validation-rejects-unchanged-row');
  $check($tx_count('maniphest', new ManiphestTransaction(), $task->getPHID()) ===
    $records && $queue($task->getPHID()) === $queued,
    'pipeline-validation-no-record-or-publishing');

  $wiki_count = $count('phriction', (new PhrictionDocument())->getTableName());
  $failed = false;
  try {
    $empty_wiki = PhrictionDocument::initializeNewDocument($actor,
      'synthetic-invalid-empty/')->setSpacePHID($space->getPHID());
    $edit($empty_wiki, $actor, array((new PhrictionTransaction())
      ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
      ->setNewValue('')));
  } catch (PhabricatorApplicationTransactionValidationException $error) {
    $failed = true;
  }
  $check($failed && $count('phriction', (new PhrictionDocument())->getTableName()) ===
    $wiki_count, 'pipeline-empty-wiki-create-native-validation-rejected');

  $legacy = PhorgeGovernanceCapabilityMap::classify(PhabricatorProject::class,
    PhabricatorProjectTransaction::class,
    PhorgeProjectLegacyMembersTransaction::TRANSACTIONTYPE);
  $check($legacy['status'] === 'blocked' && !$legacy['can_execute'],
    'pipeline-historical-member-write-remains-blocked-contract-only');

  $check(PhorgeGovernanceNativeRuntimeProfile::assertCurrent() === $runtime_profile,
    'pipeline-fixed-native-library-profile-unchanged');
  return array('runtime_profile' => $runtime_profile, 'scope' => 'writable-owned-native-pipeline-current-product-cut',
    'writes_expected' => true, 'can_execute' => false,
    'production_executor' => false, 'worker_delivery_executed' => false,
    'arbitrary_extension_closure_proved' => false,
    'all_external_effects_rollback_proved' => false);
}
