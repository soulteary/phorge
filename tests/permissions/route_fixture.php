<?php

$namespace = getenv('PERMISSION_ROUTES_NAMESPACE');
$private = getenv('PERMISSION_ROUTES_PRIVATE');
$root = dirname(__DIR__, 2);
if (PHP_SAPI !== 'cli' || getenv('ATOMIC_TEST_DISPOSABLE') !== '1' ||
    !preg_match('/\Aatomic_routes_[a-f0-9]{16}\z/', $namespace ?: '') ||
    !is_string($private) || !is_file($private.'/owner.json')) {
  throw new Exception('Explicit disposable route fixture required.');
}
$owner = json_decode(file_get_contents($private.'/owner.json'), true);
if (getenv('PHORGE_DEPLOYMENT_CONFIG') !== $root.'/conf/local/deployment.json' ||
    getenv('PHORGE_CONTROL_PLANE') !== 'deployment' || $owner['root'] !== $root || $owner['namespace'] !== $namespace ||
    !hash_equals($owner['configurationHash'], hash_file('sha256', $root.'/conf/local/local.json')) ||
    !hash_equals($owner['deploymentHash'], hash_file('sha256', $root.'/conf/local/deployment.json'))) {
  throw new Exception('Disposable configuration ownership mismatch.');
}
require_once $root.'/scripts/__init_script__.php';
if (PhabricatorEnv::getEnvConfig('storage.default-namespace') !== $namespace ||
    PhabricatorEnv::getEnvConfig('mysql.host') !== '127.0.0.1' ||
    !PhabricatorEnv::getEnvConfig('phabricator.silent') ||
    PhabricatorEnv::getEnvConfig('projects.default-navigation') !== 'flat' ||
    PhabricatorEnv::getEnvConfig('gorge.search.source-scan') ||
    PhabricatorEnv::getEnvConfig('gorge.search.projection-shadow') ||
    PhabricatorEnv::getEnvConfig('project.history-state') !== 'off' ||
    PhabricatorEnv::getEnvConfig('project.workspace-mode') !== 'native' ||
    PhabricatorEnv::getEnvConfig('project.history-task-drafts')) {
  throw new Exception('Disposable native-mode scope refused.');
}
$mode = $argv[1] ?? '';
$omni = PhabricatorUser::getOmnipotentUser();
function route_project($phid) {
  return id(new PhabricatorProjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withPHIDs(array($phid))->needMembers(true)->executeOne();
}
function route_index($project) {
  $project = route_project($project->getPHID());
  $engine = id(new PhabricatorIndexEngine())->setObject($project)->setParameters(array('force'=>true));
  if ($engine->shouldIndexObject()) { $engine->indexObject(); }
  $project->newFulltextEngine()->setObject($project)->buildFulltextIndexes();
}
function route_edge($project, $user, $add) {
  $editor = new PhabricatorEdgeEditor();
  $args = array($project->getPHID(), PhabricatorProjectProjectHasMemberEdgeType::EDGECONST, $user->getPHID());
  if ($add) { $editor->addEdge(...$args); } else { $editor->removeEdge(...$args); }
  $editor->save();
}
if ($mode === 'seed') {
  $token = substr($namespace, -16);
  $roles = array('owner','parentEditor','childEditor','member','outsider','legacy','administrator','disabled','unapproved');
  $users = array();
  foreach ($roles as $role) {
    $u = id(new PhabricatorUser())->setUsername('route'.strtolower($role).$token)
      ->setRealName('Isolated Route '.$role)->setIsApproved(1)->setIsEmailVerified(1)
      ->setIsAdmin(in_array($role, array('owner','administrator'), true) ? 1 : 0)->save();
    id(new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@route.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();
    $users[$role] = $u;
  }
  $projects = array();
  $spec = array(
    'visibleRoot'=>array(null,false), 'ordinary'=>array('visibleRoot',false),
    'milestone'=>array('ordinary',true), 'privateSibling'=>array('visibleRoot',false),
    'grandparent'=>array(null,false), 'hiddenParent'=>array('grandparent',false),
    'openChild'=>array('hiddenParent',false), 'hiddenMilestone'=>array('openChild',true),
    'legacyParent'=>array(null,false), 'legacyLeaf'=>array('legacyParent',false),
    'memberParent'=>array(null,false), 'memberLeaf'=>array('memberParent',false),
  );
  $source = PhabricatorContentSource::newForSource('console', array());
  foreach ($spec as $key=>$shape) {
    list($parent_key, $milestone) = $shape;
    $parent = $parent_key === null ? null : $projects[$parent_key];
    $p = PhabricatorProject::initializeNewProject($users['owner'], $parent);
    $p->setPHID($p->generatePHID());
    $x = array(id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue('RouteSetup'.$token.' '.$key));
    if ($parent) {
      $x[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType($milestone ? PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE : PhabricatorProjectParentTransaction::TRANSACTIONTYPE)
        ->setNewValue($parent->getPHID());
    }
    id(new PhabricatorProjectTransactionEditor())->setActor($users['owner'])->setContentSource($source)
      ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)->applyTransactions($p, $x);
    if ($parent) {
      if ($milestone) { $parent->setHasMilestones(1); } else { $parent->setHasSubprojects(1); }
      $parent->save();
    }
    $p->setHasWorkboard(1)->save();
    id(new PhabricatorProjectColumn())->setProjectPHID($p->getPHID())->setName('Native Backlog')
      ->setSequence(0)->setProperty('isDefault',true)->setStatus(PhabricatorProjectColumn::STATUS_ACTIVE)->save();
    $projects[$key] = $p;
  }
  foreach ($projects as $key=>$p) {
    id(new PhabricatorProjectTransactionEditor())->setActor($users['owner'])->setContentSource($source)
      ->setContinueOnNoEffect(true)->applyTransactions($p,array(id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue('RouteCanary'.$token.' '.$key)));
  }
  // Normal native direct/member materialization, before the historical anomaly.
  foreach (array('openChild','legacyLeaf','memberLeaf') as $key) { route_edge($projects[$key],$users['owner'],true); }
  foreach (array('openChild','memberLeaf') as $key) { route_edge($projects[$key],$users['member'],true); }
  foreach ($projects as $p) { route_index($p); }
  foreach ($projects as $key=>$p) {
    $p = route_project($p->getPHID());
    if (!$p->isMilestone()) {
      $p->setViewPolicy(in_array($key,array('legacyParent','memberParent'),true) ? PhabricatorPolicies::POLICY_NOONE :
        (in_array($key,array('hiddenParent','privateSibling'),true) ? $users['owner']->getPHID() : PhabricatorPolicies::POLICY_USER));
      $p->setEditPolicy($key==='visibleRoot' ? $users['parentEditor']->getPHID() :
        ($key==='ordinary' ? $users['childEditor']->getPHID() : $users['owner']->getPHID()));
      $p->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    }
    $projects[$key] = $p;
  }
  // Deliberately preserve an old direct edge on a parent, absent from type 60.
  // It has no provenance claim and must never be automatically deleted/promoted.
  route_edge($projects['legacyParent'],$users['legacy'],true);
  $legacy_material = PhabricatorEdgeQuery::loadDestinationPHIDs($projects['legacyParent']->getPHID(),60);
  if (in_array($users['legacy']->getPHID(),$legacy_material,true)) { throw new Exception('Historical fixture unexpectedly materialized.'); }
  // Seed real native stories without running mail or generic worker queues.
  // Delivery is out of scope: subsequent requests exercise native Feed policy.
  foreach ($projects as $p) {
    $xactions=id(new PhabricatorProjectTransactionQuery())->setViewer($omni)
      ->withObjectPHIDs(array($p->getPHID()))->execute();
    if (!$xactions) { throw new Exception('Missing native project transactions.'); }
    id(new PhabricatorFeedStoryPublisher())
      ->setStoryType('PhabricatorApplicationTransactionFeedStory')
      ->setStoryData(array('objectPHID'=>$p->getPHID(),'transactionPHIDs'=>mpull($xactions,'getPHID')))
      ->setStoryTime(time())->setStoryAuthorPHID($users['owner']->getPHID())
      ->setRelatedPHIDs(array($p->getPHID()))->setPrimaryObjectPHID($p->getPHID())
      ->publish();
  }
  $fixture=array('token'=>$token,'projects'=>array(),'users'=>array());
  foreach ($projects as $key=>$p) {
    $stories=id(new PhabricatorFeedQuery())->setViewer($omni)->withFilterPHIDs(array($p->getPHID()))->setLimit(100)->execute();
    if (!$stories) { throw new Exception('Missing positive feed control: '.$key); }
    $story=head($stories);
    $fixture['projects'][$key]=array('id'=>(int)$p->getID(),'phid'=>$p->getPHID(),'name'=>$p->getName(),
      'slug'=>$p->getPrimarySlug(),'storyKey'=>(string)$story->getChronologicalKey());
  }
  foreach ($users as $role=>$u) {
    $api=PhabricatorConduitToken::initializeNewToken($u->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
    $fixture['users'][$role]=array('phid'=>$u->getPHID(),'username'=>$u->getUsername(),'api'=>$api->getToken(),
      'session'=>id(new PhabricatorAuthSessionEngine())->establishSession(PhabricatorAuthSession::TYPE_WEB,$u->getPHID(),false));
  }
  $users['disabled']->setIsDisabled(1)->save();$users['unapproved']->setIsApproved(0)->save();
  echo json_encode($fixture,JSON_THROW_ON_ERROR)."\n";
  exit;
}
$fixture=json_decode(file_get_contents($private.'/fixture.json'),true);
if ($mode==='seed-boundaries') {
  $users=array();foreach($fixture['users'] as $role=>$row){$users[$role]=id(new PhabricatorPeopleQuery())->setViewer($omni)->withPHIDs(array($row['phid']))->needUserSettings(true)->executeOne();}
  $actor=$users['owner'];$result=array('projects'=>array(),'tasks'=>array());
  $default=PhabricatorSpacesNamespace::initializeNewNamespace($actor)->setNamespaceName('Boundary Default')->setIsDefaultNamespace(1)->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
  $private=PhabricatorSpacesNamespace::initializeNewNamespace($actor)->setNamespaceName('Boundary Private')->setViewPolicy($actor->getPHID())->save();
  $rule=id(new PhabricatorPolicy())->setRules(array(array('action'=>PhabricatorPolicy::ACTION_ALLOW,'rule'=>PhabricatorUsersPolicyRule::class,'value'=>array($actor->getPHID(),$users['childEditor']->getPHID()))))->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->save();
  foreach(array('space','custom') as $kind){
    $created=id(new ConduitCall('project.edit',array('transactions'=>array(
      array('type'=>'name','value'=>'BoundarySetup'.$fixture['token'].' '.$kind),
      array('type'=>'view','value'=>$kind==='custom'?$rule->getPHID():PhabricatorPolicies::POLICY_USER),
      array('type'=>'edit','value'=>$actor->getPHID()),
    ))))->setUser($actor)->execute();
    $p=route_project($created['object']['phid']);
    if($kind==='space'){$p->setSpacePHID($private->getPHID())->save();}
    id(new ConduitCall('project.edit',array('objectIdentifier'=>$p->getPHID(),'transactions'=>array(array('type'=>'name','value'=>'BoundaryProject'.$fixture['token'].' '.$kind)))))->setUser($actor)->execute();
    $p=route_project($p->getPHID());$p->setHasWorkboard(1)->save();
    id(new PhabricatorProjectColumn())->setProjectPHID($p->getPHID())->setName('Boundary Backlog')->setSequence(0)->setProperty('isDefault',true)->setStatus(PhabricatorProjectColumn::STATUS_ACTIVE)->save();
    route_index($p);
    $transactions=id(new PhabricatorProjectTransactionQuery())->setViewer($omni)->withObjectPHIDs(array($p->getPHID()))->execute();
    $story=id(new PhabricatorFeedStoryPublisher())->setStoryType('PhabricatorApplicationTransactionFeedStory')->setStoryData(array('objectPHID'=>$p->getPHID(),'transactionPHIDs'=>mpull($transactions,'getPHID')))->setStoryTime(time())->setStoryAuthorPHID($actor->getPHID())->setRelatedPHIDs(array($p->getPHID()))->setPrimaryObjectPHID($p->getPHID())->publish();
    $result['projects'][$kind]=array('id'=>(int)$p->getID(),'phid'=>$p->getPHID(),'name'=>$p->getName(),'slug'=>$p->getPrimarySlug(),'storyKey'=>(string)$story->getChronologicalKey());
  }
  foreach(array('public','private') as $kind){
    $created=id(new ConduitCall('maniphest.edit',array('transactions'=>array(
      array('type'=>'title','value'=>'BoundarySetup'.$fixture['token'].' '.$kind),
      array('type'=>'view','value'=>$kind==='private'?$actor->getPHID():PhabricatorPolicies::POLICY_USER),
      array('type'=>'edit','value'=>$actor->getPHID()),
      array('type'=>'projects.add','value'=>array($fixture['projects']['ordinary']['phid'])),
    ))))->setUser($actor)->execute();
    $phid=$created['object']['phid'];$title='BoundaryTask'.$fixture['token'].' '.$kind;
    id(new ConduitCall('maniphest.edit',array('objectIdentifier'=>$phid,'transactions'=>array(array('type'=>'title','value'=>$title)))))->setUser($actor)->execute();
    $transactions=id(new ManiphestTransactionQuery())->setViewer($omni)->withObjectPHIDs(array($phid))->execute();
    $story=id(new PhabricatorFeedStoryPublisher())->setStoryType('PhabricatorApplicationTransactionFeedStory')->setStoryData(array('objectPHID'=>$phid,'transactionPHIDs'=>mpull($transactions,'getPHID')))->setStoryTime(time())->setStoryAuthorPHID($actor->getPHID())->setRelatedPHIDs(array($phid))->setPrimaryObjectPHID($phid)->publish();
    $result['tasks'][$kind]=array('id'=>(int)$created['object']['id'],'phid'=>$phid,'title'=>$title,'storyKey'=>(string)$story->getChronologicalKey());
  }
  echo json_encode($result,JSON_THROW_ON_ERROR)."\n";exit;
}
if ($mode==='write-state') {
  $p=new PhabricatorProject();$conn=$p->establishConnection('r');
  $rows=queryfx_all($conn,'SELECT id,name,viewPolicy,editPolicy,joinPolicy FROM %T WHERE phid IN (%Ls) ORDER BY id',$p->getTableName(),array_column($fixture['projects'],'phid'));
  $tx=new PhabricatorProjectTransaction();$count=queryfx_one($conn,'SELECT COUNT(*) n FROM %T WHERE objectPHID IN (%Ls)',$tx->getTableName(),array_column($fixture['projects'],'phid'));
  echo json_encode(array('fingerprint'=>hash('sha256',json_encode(array($rows,$count))),'transactions'=>(int)$count['n']));exit;
}
if ($mode==='revoke') {
  $u=id(new PhabricatorUser())->loadOneWhere('phid = %s',$fixture['users']['member']['phid']);
  $p=route_project($fixture['projects']['openChild']['phid']);route_edge($p,$u,false);
  // Rebuild only the affected native membership chain, not fulltext or feed.
  id(new PhabricatorProjectsMembershipIndexEngineExtension())->rematerialize($p);
  $p=route_project($fixture['projects']['visibleRoot']['phid']);
  $p->setEditPolicy($fixture['users']['owner']['phid'])->save();
  echo "{\"revoked\":true,\"searchReindexed\":false,\"feedRepublished\":false}\n";
  exit;
}
if ($mode==='oracle') {
  $result=array();
  foreach ($fixture['users'] as $role=>$record) {
    $u=id(new PhabricatorUser())->loadOneWhere('phid = %s',$record['phid']);
    if ($u->getIsDisabled() || !$u->getIsApproved()) { $result[$role]=array('view'=>array(),'edit'=>array());continue; }
    foreach (array('view','edit') as $cap) {
      $q=id(new PhabricatorProjectQuery())->setViewer($u)->withPHIDs(array_column($fixture['projects'],'phid'));
      if($cap==='edit'){$q->requireCapabilities(array(PhabricatorPolicyCapability::CAN_VIEW,PhabricatorPolicyCapability::CAN_EDIT));}
      $phids=mpull($q->execute(),'getPHID');
      $result[$role][$cap]=array_keys(array_filter($fixture['projects'],static fn($p)=>in_array($p['phid'],$phids,true)));
      sort($result[$role][$cap]);
    }
  }
  $result['anonymous']=array('view'=>array(),'edit'=>array());
  echo json_encode($result,JSON_THROW_ON_ERROR)."\n";exit;
}
if($mode==='state') {
  $result=array();
  $p=new PhabricatorProject();$conn=$p->establishConnection('r');
  $result['projects']=queryfx_all($conn,'SELECT id,phid,name,viewPolicy,editPolicy,joinPolicy,parentProjectPHID,milestoneNumber,hasSubprojects,isMembershipLocked,spacePHID FROM %T WHERE phid IN (%Ls) ORDER BY id',$p->getTableName(),array_column($fixture['projects'],'phid'));
  $result['edges']=queryfx_all($conn,'SELECT src,type,dst FROM edge WHERE src IN (%Ls) ORDER BY src,type,dst',array_column($fixture['projects'],'phid'));
  $user=new PhabricatorUser();$result['actors']=queryfx_all($user->establishConnection('r'),'SELECT phid,isAdmin,isDisabled,isApproved FROM %T WHERE phid IN (%Ls) ORDER BY phid',$user->getTableName(),array_column($fixture['users'],'phid'));
  echo json_encode(array('fingerprint'=>hash('sha256',json_encode($result))),JSON_THROW_ON_ERROR)."\n";exit;
}
throw new Exception('Unknown disposable fixture operation.');
