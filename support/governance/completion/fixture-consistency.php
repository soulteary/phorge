<?php

/** Synthetic concurrent commits; only observer reads use the shared snapshot. */
function governance_completion_consistency($fixture, callable $check) {
  $fixture->assertOwned();
  // The runner acquired this writer pool after rejecting all inherited pools.
  // End its use before the reader scope refuses any inherited Lisk pool.
  LiskDAO::closeAllConnections();
  $ids = $fixture->getBaselineIDs();
  $reader = $fixture->getReaderConfig();
  $admin = $fixture->getAdmin();
  $table = $fixture->table('maniphest','maniphest_task');
  $binding = array('operation_id'=>'synthetic-completion-read-view',
    'actor'=>$ids['actor'],'executor'=>$ids['executor'],'approver'=>$ids['approver'],
    'target'=>$ids['policy_task'],'operation'=>'task.description',
    'object_class'=>ManiphestTask::class,
    'parameters_digest'=>hash('sha256','synthetic-read-view-parameters'),
    'actor_session'=>$ids['actor_session'],'executor_session'=>$ids['executor_session'],
    'approver_session'=>$ids['approver_session']);
  $select = $admin->prepare('SELECT viewPolicy FROM '.$table.' WHERE phid=?');
  $select->execute(array($ids['policy_task']));
  $original = $select->get_result()->fetch_assoc()['viewPolicy'];
  $select->close();
  $update = function($policy) use ($admin,$table,$ids) {
    $statement = $admin->prepare('UPDATE '.$table.' SET viewPolicy=? WHERE phid=?');
    $statement->execute(array($policy,$ids['policy_task'])); $statement->close();
  };
  $view = PhabricatorPolicyCapability::CAN_VIEW;
  $writer_stack = PhabricatorEnv::getConfigSourceStack();
  $writer_user = PhabricatorEnv::getEnvConfig('mysql.user');
  $assert_result = function($result,$expected,$name) use ($check,$view,$writer_stack,$writer_user) {
    $check($result['status'] === 'observed-native-single-read-view' &&
      $result['same_physical_connection'] === true &&
      $result['same_consistent_read_view'] === true && $result['native_query_count'] > 0 &&
      $result['observations'][$view]['policy_filter'] === $expected &&
      $result['actor_query_visibility'] === $expected &&
      !$result['can_execute'] && !$result['execution_freshness_proved'],$name);
    $check(PhabricatorEnv::getConfigSourceStack() === $writer_stack &&
      PhabricatorEnv::getEnvConfig('mysql.user') === $writer_user &&
      (new ReflectionProperty(LiskDAO::class,'connections'))->getValue() === array(),
      $name.'-owned-writer-routing-restored');
  };
  foreach (array(
    array('project',PhabricatorProject::class,'project.description',array($view,PhabricatorPolicyCapability::CAN_EDIT)),
    array('wiki',PhrictionDocument::class,'wiki.content',array($view)),
  ) as $family) {
    $family_binding = $binding;
    $family_binding['target']=$ids[$family[0]];
    $family_binding['object_class']=$family[1];
    $family_binding['operation']=$family[2];
    $family_result=PhorgeGovernanceNativeReadView::observe($family_binding,$family[3],$reader);
    $assert_result($family_result,'allow','read-view-real-native-'.$family[0].'-loader-profile');
    foreach ($family[3] as $capability) {
      $check($family_result['observations'][$capability]['policy_filter'] === 'allow',
        'read-view-real-native-'.$family[0].'-'.$capability.'-allow');
    }
  }
  $hidden_parent = PhorgeGovernanceNativeFixture::phid('WIKI','x');
  $open_child = PhorgeGovernanceNativeFixture::phid('WIKI','y');
  foreach (array(array($hidden_parent,'x','read-view-hidden-parent/',1,'no-one'),
    array($open_child,'y','read-view-hidden-parent/open-child/',2,'users')) as $wiki_shape) {
    list($doc_phid,$letter,$slug,$depth,$policy)=$wiki_shape;
    $content_phid=PhorgeGovernanceNativeFixture::phid('WRDS',$letter);
    $fixture->seedWikiBoundaryRow('phriction_content',array('phid'=>$content_phid,
      'documentPHID'=>$doc_phid,'version'=>1,'authorPHID'=>$ids['actor'],
      'title'=>'Synthetic Wiki boundary','slug'=>$slug,'content'=>'Synthetic boundary content',
      'description'=>'Synthetic boundary setup'));
    $fixture->seedWikiBoundaryRow('phriction_document',array('phid'=>$doc_phid,
      'slug'=>$slug,'depth'=>$depth,'status'=>PhrictionDocumentStatus::STATUS_EXISTS,
      'viewPolicy'=>$policy,'editPolicy'=>'users','spacePHID'=>$ids['space'],
      'contentPHID'=>$content_phid,'editedEpoch'=>time(),'maxVersion'=>1));
  }
  $wiki_binding=$binding;
  $wiki_binding['target']=$open_child;
  $wiki_binding['object_class']=PhrictionDocument::class;
  $wiki_binding['operation']='wiki.content';
  $hidden_result=PhorgeGovernanceNativeReadView::observe($wiki_binding,array($view),$reader);
  $check($hidden_result['status'] === 'observed-native-single-read-view' &&
    $hidden_result['same_physical_connection'] && $hidden_result['same_consistent_read_view'] &&
    $hidden_result['observations'][$view]['policy_filter'] === 'allow' &&
    $hidden_result['actor_query_visibility'] === 'deny' && !$hidden_result['can_execute'],
    'read-view-wiki-private-ancestor-local-allow-actor-query-deny');
  $scope = null;
  try {
    $scope = PhorgeGovernanceNativeReadView::begin($binding,array($view),$reader);
    $update('no-one');
    $assert_result($scope->finish(),'allow','read-view-a-to-b-observes-snapshot-a');
    $scope = null;
    $assert_result(PhorgeGovernanceNativeReadView::observe($binding,array($view),$reader),
      'deny','read-view-fresh-snapshot-observes-committed-b');
    $update($original);
    $scope = PhorgeGovernanceNativeReadView::begin($binding,array($view),$reader);
    $update('no-one'); $update($original);
    $assert_result($scope->finish(),'allow','read-view-a-b-a-remains-consistent-not-execution-freshness');
    $scope = null;
    $assert_result(PhorgeGovernanceNativeReadView::observe($binding,array($view),$reader),
      'allow','read-view-fresh-after-a-b-a-observes-current-a');
    $edge_table = $fixture->table('project','edge');
    $statement = $admin->prepare('SELECT * FROM '.$edge_table.' WHERE src=? AND type=60 AND dst=?');
    $statement->execute(array($ids['project'],$ids['actor']));
    $membership = $statement->get_result()->fetch_assoc(); $statement->close();
    if (!$membership) { throw new RuntimeException('Synthetic membership baseline required.'); }
    $membership_removed = false;
    try {
      $scope = PhorgeGovernanceNativeReadView::begin($binding,array($view),$reader);
      $statement = $admin->prepare('DELETE FROM '.$edge_table.' WHERE src=? AND type=60 AND dst=?');
      $statement->execute(array($ids['project'],$ids['actor']));
      $membership_removed = $statement->affected_rows === 1; $statement->close();
      $check($membership_removed,'read-view-group-source-fixture-revoked-on-second-connection');
      $assert_result($scope->finish(),'allow','read-view-group-revocation-sees-snapshot-membership');
      $scope = null;
      $assert_result(PhorgeGovernanceNativeReadView::observe($binding,array($view),$reader),
        'deny','read-view-fresh-group-revocation-observes-absence');
    } finally {
      if ($scope !== null) { $scope->close(); $scope=null; }
      if ($membership_removed) {
      $columns = array_keys($membership);
      foreach ($columns as $column) {
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/',$column)) {
          throw new RuntimeException('Owned edge column scope refused.');
        }
      }
      $statement = $admin->prepare('INSERT INTO '.$edge_table.' (`'.implode('`,`',$columns).
        '`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
      $statement->execute(array_values($membership)); $statement->close();
      }
    }
    $scope = PhorgeGovernanceNativeReadView::begin($binding,array($view),$reader);
    $pool = (new ReflectionProperty(LiskDAO::class,'connections'))->getValue();
    $bridge = $pool[$reader['namespace'].'_user:r'];
    foreach (array('UPDATE `user` SET id=id WHERE 1=0','COMMIT',
      'SELECT id FROM `user` FOR UPDATE',"SELECT GET_LOCK('synthetic-completion',0)",
      'SELECT SLEEP(0)',"SELECT 1 INTO OUTFILE '/tmp/synthetic-completion-refused'") as $index=>$sql) {
      $rejected = false;
      try { queryfx($bridge,'%Q',qsprintf($bridge,$sql)); }
      catch (RuntimeException $error) { $rejected = true; }
      $check($rejected,'read-view-forbidden-native-query-'.$index.'-refused');
    }
    $straight_join_all_rejected = true;
    foreach (array(
      'SELECT c.content FROM user u STRAIGHT_JOIN %T.%T c ON 1=1',
      'SELECT c.content FROM user `group` STRAIGHT_JOIN %T.%T c ON 1=1',
      'SELECT c.content FROM user `where`, %T.%T c',
      'SELECT id FROM user WHERE id IN (TABLE %T.%T)',
    ) as $straight_join_sql) {
      $query_count_before = $bridge->getObservation()['query_count'];
      $native_fetch_attempts_before = $bridge->getObservation()['native_fetch_attempts'];
      $straight_join_rejected = false;
      try {
        queryfx($bridge,'%Q',qsprintf($bridge,$straight_join_sql,
          $reader['namespace'].'_phriction','phriction_content'));
      } catch (RuntimeException $error) { $straight_join_rejected = true; }
      $straight_join_all_rejected = $straight_join_all_rejected &&
        $straight_join_rejected &&
        $bridge->getObservation()['query_count'] === $query_count_before &&
        $bridge->getObservation()['native_fetch_attempts'] === $native_fetch_attempts_before;
    }
    $check($straight_join_all_rejected,
      'read-view-straight-join-cross-schema-refused-before-fetch');
    $assert_result($scope->finish(),'allow','read-view-forbidden-queries-do-not-end-or-write-snapshot');
    $scope = null;
    $writer_config = $fixture->getWriterConfig();
    $foreign_probe = PhabricatorDatabaseRef::newRawConnection(array(
      'host'=>$writer_config['host'],'port'=>$writer_config['port'],
      'user'=>$writer_config['user'],'pass'=>$writer_config['password'],
      'database'=>$writer_config['namespace'].'_user'));
    try {
      $probe_before = queryfx_one($foreign_probe,'SELECT CONNECTION_ID() AS id')['id'];
      $scope = PhorgeGovernanceNativeReadView::begin($binding,array($view),$reader);
      $pool_property = new ReflectionProperty(LiskDAO::class,'connections');
      $drift_pool = $pool_property->getValue();
      $drift_pool['completion_caller_owned_probe'] = $foreign_probe;
      $pool_property->setValue(null,$drift_pool);
      $drift_result = $scope->finish(); $scope=null;
      $probe_after = queryfx_one($foreign_probe,'SELECT CONNECTION_ID() AS id')['id'];
      $remaining_pool = $pool_property->getValue();
      $check($drift_result['status'] === 'blocked' &&
        !isset($drift_result['observations']) && !$drift_result['can_execute'] &&
        $probe_before === $probe_after &&
        ($remaining_pool['completion_caller_owned_probe'] ?? null) === $foreign_probe,
        'read-view-pool-drift-blocks-without-closing-caller-owned-connection');
    } finally {
      if ($scope !== null) { $scope->close(); $scope=null; }
      $pool_property = new ReflectionProperty(LiskDAO::class,'connections');
      $remaining_pool = $pool_property->getValue();
      if (($remaining_pool['completion_caller_owned_probe'] ?? null) === $foreign_probe) {
        unset($remaining_pool['completion_caller_owned_probe']);
        $pool_property->setValue(null,$remaining_pool);
      }
      $foreign_probe->close();
    }
    $statement = $admin->prepare('SELECT description FROM '.$table.' WHERE phid=?');
    $statement->execute(array($ids['policy_task']));
    $original_description = $statement->get_result()->fetch_assoc()['description']; $statement->close();
    try {
      $statement = $admin->prepare('UPDATE '.$table.' SET description=REPEAT(?,70000) WHERE phid=?');
      $statement->execute(array('x',$ids['policy_task'])); $statement->close();
      $scope = PhorgeGovernanceNativeReadView::begin($binding,array($view),$reader);
      $pool = (new ReflectionProperty(LiskDAO::class,'connections'))->getValue();
      $budget_bridge = $pool[$reader['namespace'].'_maniphest:r'];
      $budget_result = $scope->finish(); $scope=null;
      $budget_observation = $budget_bridge->getObservation();
      $check($budget_result['status'] === 'blocked' &&
        !isset($budget_result['observations']) && !$budget_result['can_execute'] &&
        $budget_observation['pre_fetch_budget_rejections'] >= 1 &&
        $budget_observation['query_count'] === 0 &&
        $budget_observation['native_fetch_attempts'] === 0,
        'read-view-huge-native-task-row-refused-before-select-fetch');
      unset($budget_bridge,$pool);
    } finally {
      if ($scope !== null) { $scope->close(); $scope=null; }
      $statement = $admin->prepare('UPDATE '.$table.' SET description=? WHERE phid=?');
      $statement->execute(array($original_description,$ids['policy_task'])); $statement->close();
    }
    $scope = PhorgeGovernanceNativeReadView::begin($binding,array($view),$reader);
    $pool = (new ReflectionProperty(LiskDAO::class,'connections'))->getValue();
    $bridge = $pool[$reader['namespace'].'_user:r'];
    // Test-only borrowed private handle, never a public/raw-connection API.
    $physical = (new ReflectionProperty(PhorgeGovernanceSnapshotConnection::class,
      'connection'))->getValue($bridge);
    $physical->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
    $blocked = $scope->finish();
    $scope = null;
    $check($blocked['status'] === 'blocked' && !isset($blocked['observations']) &&
      !$blocked['can_execute'],'read-view-native-sql-mode-drift-blocked');
    unset($physical,$bridge,$pool);
    $check(PhabricatorEnv::getConfigSourceStack() === $writer_stack &&
      PhabricatorEnv::getEnvConfig('mysql.user') === $writer_user &&
      (new ReflectionProperty(LiskDAO::class,'connections'))->getValue() === array(),
      'read-view-sql-mode-drift-closes-owned-session-restores-writer');
  } finally {
    if ($scope !== null) { $scope->close(); }
    $update($original);
  }
  return array('same_physical_connection_tested'=>true,
    'same_consistent_read_view_tested'=>true,'a_b_and_a_b_a_tested'=>true,
    'execution_freshness_proved'=>false,'production_executor'=>false,
    'all_native_dependencies_closed'=>false);
}
