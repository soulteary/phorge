<?php

// Synthetic users and sessions only. This CLI fixture is never a web route.
$namespace = getenv('ATOMIC_WORKSPACE_NAMESPACE');
if (PHP_SAPI !== 'cli' || getenv('ATOMIC_TEST_DISPOSABLE') !== '1' ||
    !is_string($namespace) ||
    !preg_match('/\Aatomic_workspace_[a-f0-9]{16}\z/', $namespace)) {
  throw new Exception('Use the isolated workspace acceptance driver.');
}
require_once dirname(__DIR__, 2).'/scripts/__init_script__.php';
if (PhabricatorEnv::getEnvConfig('storage.default-namespace') !== $namespace ||
    PhabricatorEnv::getEnvConfig('mysql.host') !== '127.0.0.1' ||
    (int)PhabricatorEnv::getEnvConfig('mysql.port') !== (int)getenv('GORGE_TEST_MYSQL_PORT') ||
    !PhabricatorEnv::getEnvConfig('phabricator.silent')) {
  throw new Exception('Refusing any database outside the disposable run.');
}

function atomic_workspace_project($phid) {
  return id(new PhabricatorProjectQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($phid))->needMembers(true)->executeOne();
}

$mode = idx($argv, 1, 'seed');
if ($mode === 'schema') {
  // The trimmed fork's quickstart can leave retired tables. Accept only that
  // native surplus condition, never a missing table/column or wrong type.
  $apis = array();
  try {
    foreach (PhabricatorDatabaseRef::getMasterDatabaseRefs() as $ref) {
      $apis[] = id(new PhabricatorStorageManagementAPI())
        ->setRef($ref)->setUser($ref->getUser())->setHost($ref->getHost())
        ->setPort($ref->getPort())->setPassword($ref->getPass())
        ->setNamespace($namespace);
    }
    $query = id(new PhabricatorConfigSchemaQuery())->setAPIs($apis);
    $actual = $query->loadActualSchemata();
    $expected = $query->loadExpectedSchemata();
    foreach ($query->buildComparisonSchemata($expected, $actual) as $schema) {
      foreach ($schema->getAllIssues() as $issue) {
        if (!in_array($issue, array(
          PhabricatorConfigStorageSchema::ISSUE_SURPLUS,
          PhabricatorConfigStorageSchema::ISSUE_SURPLUSKEY,
          PhabricatorConfigStorageSchema::ISSUE_SUBWARN,
          PhabricatorConfigStorageSchema::ISSUE_SUBFAIL), true)) {
          throw new Exception('The disposable schema has a required adjustment.');
        }
      }
    }
    echo "Disposable native schema is usable.\n";
  } finally {
    foreach ($apis as $api) { PhabricatorLiskDAO::popStorageNamespace(); }
    PhabricatorLiskDAO::closeAllConnections();
  }
  exit;
}

if ($mode === 'counts') {
  $task = new ManiphestTask();
  $project = new PhabricatorProjectTransaction();
  $counts = array();
  foreach (array('tasks' => $task, 'transactions' => $project) as $key => $dao) {
    $row = queryfx_one($dao->establishConnection('r'),
      'SELECT COUNT(*) AS n FROM %T', $dao->getTableName());
    $counts[$key] = (int)$row['n'];
  }
  echo json_encode($counts)."\n";
  exit;
}
if ($mode === 'snapshot') {
  $viewer = PhabricatorUser::getOmnipotentUser();
  $tasks = id(new ManiphestTaskQuery())->setViewer($viewer)->setLimit(1000)->execute();
  $positions = id(new PhabricatorProjectColumnPositionQuery())->setViewer($viewer)
    ->withObjectPHIDs(mpull($tasks, 'getPHID'))->setLimit(1000)->execute();
  $columns = id(new PhabricatorProjectColumnQuery())->setViewer($viewer)
    ->withPHIDs(mpull($positions, 'getColumnPHID'))->execute();
  $columns = mpull($columns, 'getName', 'getPHID');
  $authors = id(new PhabricatorPeopleQuery())->setViewer($viewer)
    ->withPHIDs(mpull($tasks, 'getAuthorPHID'))->execute();
  $authors = mpull($authors, 'getUsername', 'getPHID');
  $snapshot = array();
  foreach ($tasks as $task) {
    $placed = array();
    foreach ($positions as $position) {
      if ($position->getObjectPHID() === $task->getPHID()) {
        $placed[] = array('phid' => $position->getColumnPHID(),
          'name' => idx($columns, $position->getColumnPHID()),
          'boardPHID' => $position->getBoardPHID());
      }
    }
    $edges = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array($task->getPHID()))
      ->withEdgeTypes(array(PhabricatorProjectObjectHasProjectEdgeType::EDGECONST));
    $edges->execute();
    $snapshot[] = array('id' => (int)$task->getID(), 'title' => $task->getTitle(),
      'status' => $task->getStatus(), 'columns' => $placed,
      'authorPHID' => $task->getAuthorPHID(),
      'authorUsername' => idx($authors, $task->getAuthorPHID()),
      'description' => $task->getDescription(), 'priority' => $task->getPriority(),
      'viewPolicy' => $task->getViewPolicy(), 'editPolicy' => $task->getEditPolicy(),
      'projectPHIDs' => $edges->getDestinationPHIDs());
  }
  echo json_encode($snapshot, JSON_PRETTY_PRINT)."\n";
  exit;
}
if ($mode !== 'seed') { throw new Exception('Unknown fixture operation.'); }

$token = substr($namespace, -16);
$users = array();
foreach (array('owner', 'reader', 'observer', 'chinese') as $role) {
  $user = id(new PhabricatorUser())->setUsername('atomic'.$role.$token)
    ->setRealName('Atomic Workspace '.$role)->setIsApproved(1)
    ->setIsEmailVerified(1)->save();
  id(new PhabricatorUserEmail())->setUserPHID($user->getPHID())
    ->setAddress($user->getUsername().'@workspace.invalid')
    ->setIsPrimary(1)->setIsVerified(1)->save();
  id(new PhabricatorUserPreferences())->setUserPHID($user->getPHID())
    ->setPreference(PhabricatorTranslationSetting::SETTINGKEY,
      in_array($role, array('reader', 'chinese')) ? 'zh_CN' : 'en_US')->save();
  $users[$role] = id(new PhabricatorPeopleQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($user->getPHID()))->needUserSettings(true)->executeOne();
}
$owner = $users['owner'];
$edit = id(new PhabricatorPolicy())->setRules(array(array(
  'action' => PhabricatorPolicy::ACTION_ALLOW,
  'rule' => PhabricatorUsersPolicyRule::class,
  'value' => array($owner->getPHID(), $users['chinese']->getPHID()),
)))->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->save();
$projects = array();
foreach (array('main', 'private') as $key) {
  $result = id(new ConduitCall('project.edit', array('transactions' => array(
    array('type' => 'name', 'value' => 'Atomic Workspace '.$key.' '.$token),
    array('type' => 'view', 'value' => $key === 'private'
      ? $owner->getPHID() : PhabricatorPolicies::POLICY_USER),
    array('type' => 'edit', 'value' => $key === 'private'
      ? $owner->getPHID() : $edit->getPHID()),
    array('type' => 'join', 'value' => PhabricatorPolicies::POLICY_NOONE),
    array('type' => 'members.set', 'value' => $key === 'main'
      ? array($users['reader']->getPHID()) : array()),
  ))))->setUser($owner)->execute();
  $p = atomic_workspace_project($result['object']['phid']);
  $projects[$key] = array('id' => (int)$p->getID(), 'phid' => $p->getPHID(),
    'name' => $p->getName(), 'board' => $p->getWorkboardURI(),
    'history' => '/project/progress/'.$p->getID().'/');
}
$main = atomic_workspace_project($projects['main']['phid']);
$source = PhabricatorContentSource::newForSource('console', array());
$columns = array();
foreach (array('Backlog', 'Doing', 'Review', 'Done') as $i => $name) {
  $column = PhabricatorProjectColumn::initializeNewColumn($owner)
    ->setProjectPHID($main->getPHID())->setSequence($i)
    ->setProperty('isDefault', $i === 0)->attachProject($main);
  id(new PhabricatorProjectColumnTransactionEditor())->setActor($owner)
    ->setContentSource($source)->setContinueOnNoEffect(true)
    ->applyTransactions($column, array(id(new PhabricatorProjectColumnTransaction())
      ->setTransactionType(PhabricatorProjectColumnNameTransaction::TRANSACTIONTYPE)
      ->setNewValue($name)));
  $columns[] = array('phid' => $column->getPHID(), 'name' => $name);
}
id(new PhabricatorProjectTransactionEditor())->setActor($owner)
  ->setContentSource($source)->setContinueOnNoEffect(true)
  ->applyTransactions($main, array(id(new PhabricatorProjectTransaction())
    ->setTransactionType(PhabricatorProjectWorkboardTransaction::TRANSACTIONTYPE)
    ->setNewValue(1)));
$tasks = array();
foreach (range(1, 12) as $i) {
  $title = 'Atomic Long Column '.$i;
  $result = id(new ConduitCall('maniphest.edit', array('transactions' => array(
    array('type' => 'title', 'value' => $title),
    array('type' => 'view', 'value' => PhabricatorPolicies::POLICY_USER),
    array('type' => 'edit', 'value' => $owner->getPHID()),
    array('type' => 'status', 'value' => $i === 12 ? 'resolved' : 'open'),
    array('type' => 'projects.add', 'value' => array($main->getPHID())),
  ))))->setUser($owner)->execute();
  $tasks[] = array('id' => (int)$result['object']['id'],
    'phid' => $result['object']['phid'], 'title' => $title);
}
$node = TeamProjectProgress::publish($owner, $main, array(
  'kind' => 'node', 'title' => 'Atomic Seed Record',
  'body' => 'Synthetic project result. Native tasks retain their own execution state.',
  'percent' => 35,
), $source);
$seed = array('id' => (int)$node->getID(), 'title' => $node->getNewValue()['title']);
for ($i = 1; $i <= 51; $i++) {
  TeamProjectProgress::publish($owner, $main, array(
    'kind' => 'comment', 'title' => 'Atomic Paging Comment '.$i,
    'body' => 'Synthetic pagination record.',
  ), $source);
}
$fixture = array('namespace' => $namespace, 'projects' => $projects,
  'columns' => $columns, 'tasks' => $tasks, 'node' => $seed,
  'draft' => '/maniphest/task/edit/?projectNode='.$node->getID());
foreach ($users as $role => $user) {
  $fixture['users'][$role] = array('username' => $user->getUsername(),
    'phid' => $user->getPHID(), 'session' => id(new PhabricatorAuthSessionEngine())
      ->establishSession(PhabricatorAuthSession::TYPE_WEB, $user->getPHID(), false));
}
// Captured only to a mode-0600 private file by the driver, never into reports.
echo json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
