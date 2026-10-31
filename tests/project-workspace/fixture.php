<?php

// This executable creates disposable users/sessions. Never target a live site.
$run = getenv('WORKSPACE_ACCEPTANCE_RUN');
if (PHP_SAPI !== 'cli' || getenv('WORKSPACE_ACCEPTANCE') !== '1' ||
    !preg_match('/^phorge-workspace-([a-f0-9]{12})$/D', $run, $matches)) {
  throw new Exception('Use the disposable project-workspace acceptance runner.');
}
$root = getenv('WORKSPACE_PHORGE_ROOT') ?: dirname(__DIR__, 2);
require_once $root.'/scripts/__init_script__.php';
if (PhabricatorEnv::getEnvConfig('storage.default-namespace') !==
      'workspace_acceptance_'.$matches[1] ||
    PhabricatorEnv::getEnvConfig('mysql.host') !== $run.'-db' ||
    !PhabricatorEnv::getEnvConfig('phabricator.silent') ||
    PhabricatorGorgeTaskQueueClient::isConfigured()) {
  throw new Exception("Only this run's disposable native database is allowed.");
}

function progress_ui_check($condition, $message) {
  if (!$condition) {
    throw new Exception($message);
  }
}

function progress_ui_project($phid) {
  return id(new PhabricatorProjectQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($phid))
    ->needMembers(true)
    ->executeOne();
}

function progress_ui_board($actor, $project, $enabled, array $names = array('待处理', '处理中')) {
  $source = PhabricatorContentSource::newForSource('console', array());
  $columns = array();
  foreach ($names as $sequence => $name) {
    $column = PhabricatorProjectColumn::initializeNewColumn($actor)
      ->setProjectPHID($project->getPHID())
      ->setSequence($sequence)
      ->setProperty('isDefault', $sequence === 0)
      ->attachProject($project);
    id(new PhabricatorProjectColumnTransactionEditor())
      ->setActor($actor)->setContentSource($source)
      ->setContinueOnNoEffect(true)
      ->applyTransactions($column, array(
        id(new PhabricatorProjectColumnTransaction())
          ->setTransactionType(PhabricatorProjectColumnNameTransaction::TRANSACTIONTYPE)
          ->setNewValue($name),
      ));
    $columns[] = array('phid' => $column->getPHID(), 'name' => $name);
  }
  foreach ($enabled ? array(1) : array(1, 0) as $value) {
    id(new PhabricatorProjectTransactionEditor())
      ->setActor($actor)->setContentSource($source)
      ->setContinueOnNoEffect(true)
      ->applyTransactions($project, array(
        id(new PhabricatorProjectTransaction())
          ->setTransactionType(PhabricatorProjectWorkboardTransaction::TRANSACTIONTYPE)
          ->setNewValue($value),
      ));
  }
  return $columns;
}

if (isset($argv[1]) && $argv[1] === '--verify-drafts') {
  $fixture = phutil_json_decode(file_get_contents('php://stdin'));
  $count = count(id(new ManiphestTask())->loadAllWhere('1 = 1'));
  progress_ui_check($count === $fixture['initialTaskCount'],
    'Opening a draft wrote a task before Save.');
  echo json_encode(array('passed' => true, 'taskCount' => $count))."\n";
  exit;
}

if (isset($argv[1]) && $argv[1] === '--drain-feed') {
  $fixture = phutil_json_decode(file_get_contents('php://stdin'));
  progress_ui_check(PhabricatorEnv::getEnvConfig('phabricator.silent') &&
    !PhabricatorGorgeTaskQueueClient::isConfigured(),
    'Feed checks require the silent native queue in an isolated environment.');
  $object_phids = array($fixture['projects']['main']['phid']);
  foreach ($fixture['tasks'] as $task) {
    $object_phids[] = $task['phid'];
  }
  $jobs = id(new PhabricatorWorkerActiveTask())->loadAllWhere(
    'taskClass = %s AND objectPHID IN (%Ls)',
    'PhabricatorApplicationTransactionPublishWorker', $object_phids);
  usort($jobs, function($a, $b) { return $a->getID() - $b->getID(); });
  foreach ($jobs as $job) {
    $data = id(new PhabricatorWorkerTaskData())->load($job->getDataID());
    $job->setData($data->getData());
    $result = $job->executeTask();
    if ($result->getExecutionException()) {
      throw $result->getExecutionException();
    }
    progress_ui_check($result instanceof PhabricatorWorkerArchiveTask &&
      (int)$result->getResult() === PhabricatorWorkerArchiveTask::RESULT_SUCCESS,
      'The selected native publication worker did not finish.');
  }
  echo json_encode(array('passed' => true, 'publishedTasks' => count($jobs)))."\n";
  exit;
}

if (isset($argv[1]) && $argv[1] === '--verify') {
  $fixture = phutil_json_decode(file_get_contents('php://stdin'));
  $owner = id(new PhabricatorPeopleQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($fixture['owner']['phid']))
    ->needUserSettings(true)
    ->executeOne();
  $project = progress_ui_project($fixture['projects']['main']['phid']);
  $member = id(new PhabricatorPeopleQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($fixture['member']['phid']))
    ->needUserSettings(true)
    ->executeOne();
  progress_ui_check(in_array($member->getPHID(), $project->getMemberPHIDs()) &&
    PhabricatorPolicyFilter::hasCapability(
      $member, $project, PhabricatorPolicyCapability::CAN_VIEW) &&
    !PhabricatorPolicyFilter::hasCapability(
      $member, $project, PhabricatorPolicyCapability::CAN_EDIT),
    'The member must remain a visible project member without edit permission.');
  $entries = TeamProjectProgress::newQuery($owner, $project)->execute();
  progress_ui_check(count($entries) === 3,
    'Expected exactly three records persisted through browser forms.');
  $by_title = array();
  $kinds = array();
  foreach ($entries as $entry) {
    $value = $entry->getNewValue();
    $by_title[$value['title']] = $entry;
    $kinds[] = $value['kind'];
    progress_ui_check(in_array($value['title'], $fixture['titles']) &&
      $entry->getAuthorPHID() === $owner->getPHID(),
      'Unexpected persisted record title or author.');
    progress_ui_check($entry->getTransactionType() === ($value['kind'] === 'comment'
      ? TeamProjectProgressCommentTransaction::TRANSACTIONTYPE
      : TeamProjectProgressNodeTransaction::TRANSACTIONTYPE),
      'New browser records must use canonical transaction storage types.');
  }
  sort($kinds);
  progress_ui_check($kinds === array('comment', 'node', 'node'),
    'New browser records must use only canonical node and comment kinds.');
  $node_entry = $by_title[$fixture['titles']['node']];
  $completed_entry = $by_title[$fixture['titles']['completedNode']];
  $comment_entry = $by_title[$fixture['titles']['comment']];
  $comment = $comment_entry->getNewValue();
  progress_ui_check($comment['status'] === null && $comment['percent'] === null,
    'A work comment must not carry project state changes.');
  $update = $node_entry->getNewValue();
  progress_ui_check(in_array($fixture['member']['phid'], $update['participants']),
    'The participant selected in the browser must persist.');
  $summary = TeamProjectProgress::getSummary($project, $owner);
  progress_ui_check($summary['nodeStatus'] === null && $summary['percent'] === 40 &&
    $completed_entry->getNewValue()['percent'] === null &&
    $node_entry->getNewValue()['status'] === null &&
    $completed_entry->getNewValue()['status'] === null &&
    $node_entry->getNewValue()['version'] === 2 &&
    $completed_entry->getNewValue()['version'] === 2,
    'New project history must not maintain a second task execution status.');
  progress_ui_check($summary['latestNode']->getID() === $completed_entry->getID() &&
    $summary['latestComment']->getID() === $comment_entry->getID(),
    'The node summary and discussion summary must remain separate.');
  $archive = progress_ui_project($fixture['projects']['archive']['phid']);
  $archive_nodes = TeamProjectProgress::newQuery($owner, $archive, 'node')->execute();
  progress_ui_check(count($archive_nodes) === 3 &&
    array_values(array_unique(mpull($archive_nodes, 'getTransactionType'))) === array(
      TeamProjectProgressMilestoneTransaction::TRANSACTIONTYPE,
      TeamProjectProgressUpdateTransaction::TRANSACTIONTYPE,
      TeamProjectProgressNodeTransaction::TRANSACTIONTYPE),
    'The node query must include unchanged old update/milestone and new node storage types.');
  $archive_summary = TeamProjectProgress::getSummary($archive, $owner);
  progress_ui_check($archive_summary['nodeStatus'] === null &&
    $archive_summary['nodeStatusName'] === '未标注' && $archive_summary['percent'] === 10,
    'A legacy node without a status must not inherit another node status.');
  foreach ($fixture['legacySnapshots'] as $snapshot) {
    $stored = id(new PhabricatorProjectTransaction())->load($snapshot['id']);
    progress_ui_check($stored && $stored->getNewValue() === $snapshot['value'] &&
      $stored->getAuthorPHID() === $snapshot['authorPHID'] &&
      (int)$stored->getDateCreated() === $snapshot['dateCreated'],
      'Opening old records and card drafts must preserve historical attribution and payloads.');
  }
  $private_task = id(new ManiphestTaskQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($fixture['tasks']['private']['phid']))->executeOne();
  progress_ui_check(!PhabricatorPolicyFilter::hasCapability(
    $member, $private_task, PhabricatorPolicyCapability::CAN_VIEW),
    'Mentioning the private task must not grant participants task visibility.');
  $public_task = id(new ManiphestTaskQuery())->setViewer($member)
    ->withPHIDs(array($fixture['tasks']['public']['phid']))->executeOne();
  progress_ui_check(PhabricatorPolicyFilter::canInteract($member, $public_task) &&
    !PhabricatorPolicyFilter::hasCapability(
      $member, $public_task, PhabricatorPolicyCapability::CAN_EDIT) &&
    $public_task->getStatus() === 'resolved',
    'Task interaction remains independent and moving ordinary columns must preserve completed state.');
  $task_comments = id(new ManiphestTransactionQuery())->setViewer($owner)
    ->withObjectPHIDs(array($public_task->getPHID()))
    ->withTransactionTypes(array(PhabricatorTransactions::TYPE_COMMENT))
    ->needComments(true)->execute();
  progress_ui_check(count($task_comments) === 1 &&
    head($task_comments)->getAuthorPHID() === $member->getPHID() &&
    head($task_comments)->getComment()->getContent() === $fixture['taskComment'],
    'The read-only project member must retain the native task comment.');
  $private = progress_ui_project($fixture['projects']['private']['phid']);
  progress_ui_check(count(TeamProjectProgress::newQuery($owner, $private)->execute()) === 0,
    'The private project must not acquire records from a denied request.');
  $created_cards = id(new ManiphestTask())->loadAllWhere(
    'title = %s', $fixture['cardTitle']);
  progress_ui_check(count($created_cards) === 1,
    'Opening drafts must not create tasks; exactly one browser card was saved.');
  progress_ui_check(count(id(new ManiphestTask())->loadAllWhere('1 = 1')) ===
    $fixture['initialTaskCount'] + 1,
    'Opening and rejecting draft URLs must not create additional task rows.');
  $created_card = head($created_cards);
  progress_ui_check($created_card->getAuthorPHID() === $owner->getPHID() &&
    $created_card->getStatus() === 'open' &&
    $created_card->getViewPolicy() === $owner->getPHID() &&
    $created_card->getEditPolicy() === $owner->getPHID(),
    'A new card must keep its current author, native status and private policies.');
  $created_card = id(new ManiphestTaskQuery())->setViewer($owner)
    ->withPHIDs(array($created_card->getPHID()))
    ->needProjectPHIDs(true)->executeOne();
  progress_ui_check(in_array($project->getPHID(), $created_card->getProjectPHIDs()),
    'The new card must belong to the intended project.');
  echo json_encode(array('passed' => true, 'recordCount' => count($entries),
    'checks' => array('three forms persisted native records', 'actual authors retained',
      'read-only member retained as participant', 'comment did not change state',
      'project history has no duplicate execution state', 'discussion kept separate from project records',
      'legacy update and milestone storage included in unified node query',
      'legacy missing node state stays unannotated',
      'task references did not widen task permissions',
      'native task discussion retained independent interaction rights',
      'denied requests wrote no records', 'only one browser-created private work card')))."\n";
  exit;
}

$token = Filesystem::readRandomCharacters(8);
$users = array();
foreach (array('owner', 'member', 'observer') as $role) {
  $user = id(new PhabricatorUser())
    ->setUsername('workspaceui'.$role.$token)
    ->setRealName('Isolated Progress UI '.$role)
    ->setIsApproved(1)
    ->setIsEmailVerified(1)
    ->save();
  id(new PhabricatorUserEmail())
    ->setUserPHID($user->getPHID())
    ->setAddress($user->getUsername().'@workspace.invalid')
    ->setIsPrimary(1)
    ->setIsVerified(1)
    ->save();
  $users[$role] = id(new PhabricatorPeopleQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($user->getPHID()))
    ->needUserSettings(true)
    ->executeOne();
}

$owner = $users['owner'];
// Configure this disposable application's defaults through the native editor.
// Existing tasks below retain their explicit ACLs. No production policy or
// deployment-specific policy extension is read or modified by this fixture.
$owner->setIsAdmin(1)->save();
$task_application = id(new PhabricatorApplicationQuery())->setViewer($owner)
  ->withClasses(array(PhabricatorManiphestApplication::class))->executeOne();
$default_transactions = array();
foreach (array(ManiphestDefaultViewCapability::CAPABILITY,
               ManiphestDefaultEditCapability::CAPABILITY) as $capability) {
  $default_transactions[] = id($task_application->getApplicationTransactionTemplate())
    ->setTransactionType(PhabricatorApplicationPolicyChangeTransaction::TRANSACTIONTYPE)
    ->setMetadataValue(PhabricatorApplicationPolicyChangeTransaction::METADATA_ATTRIBUTE,
      $capability)
    ->setNewValue($owner->getPHID());
}
id(new PhabricatorApplicationEditor())->setActor($owner)
  ->setContentSource(PhabricatorContentSource::newForSource('console', array()))
  ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)
  ->applyTransactions($task_application, $default_transactions);
$owner->setIsAdmin(0)->save();
$projects = array();
foreach (array('main', 'archive', 'private', 'discussion') as $key) {
  $result = id(new ConduitCall('project.edit', array('transactions' => array(
    array('type' => 'name', 'value' => 'Progress UI '.$key.' '.$token),
    array('type' => 'view', 'value' => $key === 'private'
      ? $owner->getPHID() : PhabricatorPolicies::POLICY_USER),
    array('type' => 'edit', 'value' => $owner->getPHID()),
    array('type' => 'join', 'value' => PhabricatorPolicies::POLICY_NOONE),
    array('type' => 'members.set', 'value' => $key === 'private'
      ? array() : array($users['member']->getPHID())),
  ))))->setUser($owner)->execute();
  $project = progress_ui_project($result['object']['phid']);
  $projects[$key] = array('id' => (int)$project->getID(),
    'phid' => $project->getPHID(), 'name' => $project->getName());
}
$board_columns = progress_ui_board($owner,
  progress_ui_project($projects['main']['phid']), true,
  array('待处理', '处理中', '待复核', '已验收'));
progress_ui_board($owner, progress_ui_project($projects['archive']['phid']), false);

$tasks = array();
foreach (array('public', 'private', 'outside', 'colleague') as $key) {
  $title = 'UI task '.$key.' '.$token;
  $description = 'Isolated '.$key.' task details '.$token;
  $task_result = id(new ConduitCall('maniphest.edit', array('transactions' => array(
    array('type' => 'title', 'value' => $title),
    array('type' => 'description', 'value' => $description),
    array('type' => 'view', 'value' => $key === 'private'
      ? $owner->getPHID() : PhabricatorPolicies::POLICY_USER),
    array('type' => 'edit', 'value' => $key === 'colleague'
      ? $users['member']->getPHID() : $owner->getPHID()),
    array('type' => 'projects.add', 'value' => array(
      $projects[$key === 'outside' ? 'archive' : 'main']['phid'])),
  ))))->setUser($key === 'colleague' ? $users['member'] : $owner)->execute();
  $tasks[$key] = array('id' => (int)$task_result['object']['id'],
    'phid' => $task_result['object']['phid'], 'title' => $title,
    'description' => $description);
}
$task_comment = 'UI native task comment '.$token;
id(new ConduitCall('maniphest.edit', array(
  'objectIdentifier' => $tasks['public']['phid'],
  'transactions' => array(array('type' => 'comment', 'value' => $task_comment)),
)))->setUser($users['member'])->execute();
id(new ConduitCall('maniphest.edit', array(
  'objectIdentifier' => $tasks['public']['phid'],
  'transactions' => array(array('type' => 'status', 'value' => 'resolved')),
)))->setUser($owner)->execute();

$long_column_tasks = array();
for ($ii = 1; $ii <= 12; $ii++) {
  $title = 'UI long-column '.$ii.' '.$token;
  $task_result = id(new ConduitCall('maniphest.edit', array('transactions' => array(
    array('type' => 'title', 'value' => $title),
    array('type' => 'view', 'value' => PhabricatorPolicies::POLICY_USER),
    array('type' => 'edit', 'value' => $owner->getPHID()),
    array('type' => 'projects.add', 'value' => array($projects['main']['phid'])),
  ))))->setUser($owner)->execute();
  $long_column_tasks[] = array('id' => (int)$task_result['object']['id'],
    'phid' => $task_result['object']['phid'], 'title' => $title);
}

$discussion_title = 'UI 项目讨论待定 '.$token;
TeamProjectProgress::publish($owner,
  progress_ui_project($projects['discussion']['phid']), array(
    'kind' => 'comment', 'title' => $discussion_title,
    'body' => '仅有项目讨论，尚未提交进度更新。',
  ), PhabricatorContentSource::newForSource(
    PhabricatorUnitTestContentSource::SOURCECONST, array()));

// Preserve actual legacy transaction types alongside the canonical node type.
$archive = progress_ui_project($projects['archive']['phid']);
$archive_node = TeamProjectProgress::publish($owner, $archive, array(
  'kind' => 'node', 'title' => 'UI 统一节点 '.$token,
  'body' => '新节点与历史记录一起筛选。', 'status' => 'done',
  'percent' => 5,
), PhabricatorContentSource::newForSource(
  PhabricatorUnitTestContentSource::SOURCECONST, array()));
// Only the guarded disposable fixture may seed a true pre-v2 row. Every
// public write path now normalizes to v2 even if an old caller sends status.
$archive_value = $archive_node->getNewValue();
$archive_value['status'] = 'done';
unset($archive_value['version']);
$archive_node->setNewValue($archive_value)->save();
$legacy = array();
foreach (array('update', 'milestone') as $kind) {
  $xaction = id(new PhabricatorProjectTransaction())
    ->setTransactionType($kind === 'update'
      ? TeamProjectProgressUpdateTransaction::TRANSACTIONTYPE
      : TeamProjectProgressMilestoneTransaction::TRANSACTIONTYPE)
    ->setNewValue(array('kind' => $kind,
      'title' => 'UI 历史节点 '.$kind.' '.$token,
      'body' => '保留原始旧记录和早期验收依据。',
      'status' => $kind === 'update' ? 'active' : 'planned',
      'percent' => $kind === 'update' ? 10 : null,
      'participants' => array($users['member']->getPHID())));
  $archive->getApplicationTransactionEditor()->setActor($owner)
    ->setContentSource(PhabricatorContentSource::newForSource(
      PhabricatorUnitTestContentSource::SOURCECONST, array()))
    ->applyTransactions($archive, array($xaction));
  // Restore only synthetic pre-v2 fixture payloads after the current editor
  // has validated and attributed the write. Never weaken the production
  // editor merely to manufacture historical samples for compatibility tests.
  $legacy_value = $xaction->getNewValue();
  $legacy_value['kind'] = $kind;
  $legacy_value['status'] = $kind === 'update' ? 'active' : null;
  unset($legacy_value['version']);
  $xaction->setNewValue($legacy_value)->save();
  $legacy[$kind] = $xaction;
}
$old_keynode = $legacy['milestone'];
$old_keynode_title = $old_keynode->getNewValue()['title'];
$legacy_snapshots = array();
foreach (array($archive_node, $legacy['update'], $legacy['milestone']) as $entry) {
  $legacy_snapshots[] = array('id' => (int)$entry->getID(),
    'value' => $entry->getNewValue(), 'authorPHID' => $entry->getAuthorPHID(),
    'dateCreated' => (int)$entry->getDateCreated());
}
for ($ii = 1; $ii <= 55; $ii++) {
  TeamProjectProgress::publish($owner, $archive, array(
    'kind' => 'comment', 'title' => 'UI 历史工作评论 '.$ii.' '.$token,
    'body' => '隔离分页验证记录 '.$ii,
  ), PhabricatorContentSource::newForSource(
    PhabricatorUnitTestContentSource::SOURCECONST, array()));
}

$fixture = array(
  'scope' => 'Disposable workspace acceptance namespace; synthetic objects only.',
  'applicationContainer' => preg_replace('/-db$/', '-web',
    PhabricatorEnv::getEnvConfig('mysql.host')),
  'projects' => $projects,
  'tasks' => $tasks,
  'taskComment' => $task_comment,
  'cardTitle' => 'UI 合并看板新卡片 '.$token,
  'boardColumns' => $board_columns,
  'longColumnTasks' => $long_column_tasks,
  'initialTaskCount' => count(id(new ManiphestTask())->loadAllWhere('1 = 1')),
  'discussionTitle' => $discussion_title,
  'titles' => array(
    'node' => 'UI 登录接口节点 '.$token,
    'comment' => 'UI 联调反馈 '.$token,
    'completedNode' => 'UI 首轮验收节点 '.$token,
  ),
  'archiveNodes' => array(
    array('id' => (int)$archive_node->getID(), 'title' => $archive_node->getNewValue()['title']),
    array('id' => (int)$legacy['update']->getID(), 'title' => $legacy['update']->getNewValue()['title']),
    array('id' => (int)$legacy['milestone']->getID(), 'title' => $legacy['milestone']->getNewValue()['title'])),
  'oldKeynode' => array('id' => (int)$old_keynode->getID(), 'title' => $old_keynode_title),
  'legacySnapshots' => $legacy_snapshots,
);
foreach ($users as $role => $user) {
  $fixture[$role] = array(
    'username' => $user->getUsername(),
    'realName' => $user->getRealName(),
    'phid' => $user->getPHID(),
    'session' => id(new PhabricatorAuthSessionEngine())->establishSession(
      PhabricatorAuthSession::TYPE_WEB, $user->getPHID(), false),
  );
}
// This contains synthetic sessions and must only be captured to a private file.
echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
