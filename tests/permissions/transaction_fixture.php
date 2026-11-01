<?php

// Included only after route_fixture.php verifies disposable ownership and flags.
if (!isset($fixture, $owner, $namespace) || PHP_SAPI !== 'cli') {
  throw new Exception('Owned native route fixture required.');
}
$directory = getenv('PERMISSION_ROUTES_PRIVATE');
$state_path = $directory.'/transaction-fixture.json';
function transaction_actor($role) {
  global $fixture, $omni;
  return id(new PhabricatorPeopleQuery())->setViewer($omni)
    ->withPHIDs(array($fixture['users'][$role]['phid']))
    ->needUserSettings(true)->executeOne();
}
function transaction_editor($actor) {
  return id(new PhabricatorProjectTransactionEditor())->setActor($actor)
    ->setContentSource(PhabricatorContentSource::newForSource('console', array()))
    ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)->setIsSilent(true);
}
function transaction_change($type, $value) {
  return id(new PhabricatorProjectTransaction())->setTransactionType($type)->setNewValue($value);
}
function transaction_state($phid) {
  $p=route_project($phid); $x=new PhabricatorProjectTransaction();
  $rows=queryfx_all($x->establishConnection('r'), 'SELECT * FROM %T WHERE objectPHID = %s ORDER BY id', $x->getTableName(), $phid);
  return array('name'=>$p->getName(), 'color'=>$p->getColor(),
    'editPolicy'=>$p->getEditPolicy(), 'transactions'=>count($rows),
    'historyHash'=>hash('sha256', json_encode($rows)));
}
final class PermissionTransactionBarrier extends PhabricatorEditorExtension {
  const EXTENSIONKEY = 'disposable-permission-barrier';
  public function getExtensionName() { return 'Disposable transaction barrier'; }
  public function setObject($object) { return $this; }
  public function validateTransactions($object, array $xactions) {
    $dir=getenv('PERMISSION_ROUTES_PRIVATE');
    file_put_contents($dir.'/transaction-ready', 'locked');
    $deadline=microtime(true)+40;
    while (!is_file($dir.'/transaction-release')) {
      if (microtime(true)>$deadline) { throw new Exception('Transaction barrier timed out.'); }
      usleep(10000);
    }
    return array();
  }
}
if ($mode==='transaction-seed') {
  $actor=transaction_actor('owner');$result=array();
  foreach (array('serial','rollback','bulkAllowed','bulkRevoked','stale') as $key) {
    $p=PhabricatorProject::initializeNewProject($actor)
      ->setName('TransactionBoundary'.$fixture['token'].' '.$key)
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy(PhabricatorPolicies::POLICY_USER)
      ->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $result[$key]=$p->getPHID();
  }
  file_put_contents($state_path, json_encode($result));chmod($state_path,0600);
  echo json_encode(array('seeded'=>count($result)));return;
}
$state=json_decode(file_get_contents($state_path),true);
$actor=transaction_actor('childEditor');$owner_actor=transaction_actor('owner');
if ($mode==='transaction-stale') {
  $stale=route_project($state['stale']);$was_editable=$stale->getEditPolicy()===PhabricatorPolicies::POLICY_USER;
  transaction_editor($owner_actor)->applyTransactions(route_project($state['stale']),array(transaction_change(PhabricatorTransactions::TYPE_EDIT_POLICY,$owner_actor->getPHID())));
  $before=transaction_state($state['stale']);$denied=false;
  try { transaction_editor($actor)->applyTransactions($stale,array(transaction_change(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Forbidden stale object mutation'))); }
  catch (PhabricatorPolicyException $ex) { $denied=true; }
  echo json_encode(array('preloadedBeforeRevocation'=>$was_editable,'nativeReloadDenied'=>$denied,'stateUnchanged'=>$before===transaction_state($state['stale'])));return;
}
if ($mode==='transaction-hold') {
  $editor=transaction_editor($actor);
  // Inject a validation barrier into this instance only, never the class map.
  $property=new ReflectionProperty(PhabricatorApplicationTransactionEditor::class, 'extensions');
  $extension=id(new PermissionTransactionBarrier())->setEditor($editor)->setViewer($actor);
  $property->setValue($editor,array($extension));
  $editor->applyTransactions(route_project($state['serial']),array(transaction_change(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Serialized edit committed')));
  echo json_encode(array('committed'=>true));return;
}
if ($mode==='transaction-revoke') {
  $p=route_project($state['serial']);
  $connection=queryfx_one($p->establishConnection('w'),'SELECT CONNECTION_ID() AS id');
  file_put_contents($directory.'/transaction-revoker',json_encode($connection));
  transaction_editor($owner_actor)->applyTransactions($p,array(transaction_change(PhabricatorTransactions::TYPE_EDIT_POLICY,$owner_actor->getPHID())));
  echo json_encode(array('revoked'=>true));return;
}
if ($mode==='transaction-lock-wait') {
  $id=json_decode(file_get_contents($directory.'/transaction-revoker'),true)['id'];
  $p=new PhabricatorProject();
  $rows=queryfx_all($p->establishConnection('r'), 'SELECT w.REQUESTING_ENGINE_TRANSACTION_ID FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=%d', $id);
  echo json_encode(array('waiting'=>count($rows)>0));return;
}
if ($mode==='transaction-verify') {
  $before=transaction_state($state['serial']);$denied=false;
  try { transaction_editor($actor)->applyTransactions(route_project($state['serial']),array(transaction_change(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Forbidden after revoke'))); }
  catch (PhabricatorPolicyException $ex) { $denied=true; }
  $after=transaction_state($state['serial']);
  echo json_encode(array('serializedEditCommitted'=>$before['name']==='Serialized edit committed',
    'revocationCommitted'=>$before['editPolicy']===$owner_actor->getPHID(),
    'subsequentEditDenied'=>$denied, 'denialPreservedState'=>$before===$after));return;
}
if ($mode==='transaction-rollback') {
  $p=route_project($state['rollback']);$x=new PhabricatorProjectTransaction();$conn=$x->establishConnection('w');
  $before=transaction_state($p->getPHID());$failed=false;
  // Failure occurs after object save, at history insertion, inside native txn.
  queryfx($conn, "CREATE TRIGGER permission_boundary_failure BEFORE INSERT ON %T FOR EACH ROW BEGIN IF NEW.objectPHID = %s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'isolated rollback boundary'; END IF; END", $x->getTableName(), $p->getPHID());
  $changes=array(transaction_change(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Atomic new name'),transaction_change(PhabricatorProjectColorTransaction::TRANSACTIONTYPE,'red'));
  try { transaction_editor($actor)->applyTransactions($p,$changes); }
  catch (Exception $ex) { $failed=str_contains($ex->getMessage(),'isolated rollback boundary'); }
  finally { queryfx($conn,'DROP TRIGGER permission_boundary_failure'); }
  $after=transaction_state($p->getPHID());
  transaction_editor($actor)->applyTransactions(route_project($p->getPHID()),array(transaction_change(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Atomic new name'),transaction_change(PhabricatorProjectColorTransaction::TRANSACTIONTYPE,'red')));
  $success=transaction_state($p->getPHID());
  echo json_encode(array('injectedFailureObserved'=>$failed,'objectAndHistoryRolledBack'=>$before===$after,
    'positiveMultiTransactionCommit'=>$success['name']==='Atomic new name' && $success['color']==='red' && $success['transactions']===$before['transactions']+2));return;
}
if ($mode==='transaction-bulk') {
  $type=new PhabricatorEditEngineBulkJobType();
  $job=PhabricatorWorkerBulkJob::initializeNewJob($actor,$type,array('objectPHIDs'=>array($state['bulkAllowed'],$state['bulkRevoked']),'xactions'=>array(array('type'=>PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'new'=>'Bulk permitted change'))))->setIsSilent(true)->setStatus(PhabricatorWorkerBulkJob::STATUS_CONFIRM)->save();
  $tasks=$type->createTasks($job);
  // Selection was made while both objects were editable. Revoke before worker.
  transaction_editor($owner_actor)->applyTransactions(route_project($state['bulkRevoked']),array(transaction_change(PhabricatorTransactions::TYPE_EDIT_POLICY,$owner_actor->getPHID())));
  $before=transaction_state($state['bulkRevoked']);
  $denied=false;
  foreach ($tasks as $task) {
    try { $type->runTask($actor,$job,$task); }
    catch (PhabricatorPolicyException $ex) {
      if ($task->getObjectPHID()!==$state['bulkRevoked']) { throw $ex; }
      $denied=true;
    }
  }
  $after=transaction_state($state['bulkRevoked']);$allowed=transaction_state($state['bulkAllowed']);
  echo json_encode(array('selectedTwoObjects'=>count($tasks)===2,'revokedItemPolicyException'=>$denied,'allowedItemCommitted'=>$allowed['name']==='Bulk permitted change','revokedItemUnchanged'=>$before===$after,'perObjectNotWholeBatchAtomic'=>$allowed['name']==='Bulk permitted change' && $before===$after));return;
}
if ($mode==='transaction-bulk-rollback') {
  $type=new PhabricatorEditEngineBulkJobType();
  $job=PhabricatorWorkerBulkJob::initializeNewJob($actor,$type,array('objectPHIDs'=>array($state['bulkAllowed'],$state['rollback']),
    'xactions'=>array(array('type'=>PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'new'=>'Second bulk change'),array('type'=>PhabricatorProjectColorTransaction::TRANSACTIONTYPE,'new'=>'blue'))))->setIsSilent(true)->setStatus(PhabricatorWorkerBulkJob::STATUS_CONFIRM)->save();
  $tasks=array_values($type->createTasks($job));$x=new PhabricatorProjectTransaction();$conn=$x->establishConnection('w');
  $before=transaction_state($state['bulkAllowed']);$failed=false;
  queryfx($conn,"CREATE TRIGGER permission_boundary_failure BEFORE INSERT ON %T FOR EACH ROW BEGIN IF NEW.objectPHID = %s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'isolated rollback boundary'; END IF; END",$x->getTableName(),$state['bulkAllowed']);
  try { $type->runTask($actor,$job,$tasks[0]); }
  catch (Exception $ex) { $failed=str_contains($ex->getMessage(),'isolated rollback boundary'); }
  finally { queryfx($conn,'DROP TRIGGER permission_boundary_failure'); }
  $after=transaction_state($state['bulkAllowed']);$next_before=transaction_state($state['rollback']);
  $type->runTask($actor,$job,$tasks[1]);$next=transaction_state($state['rollback']);
  echo json_encode(array('bulkFailureObserved'=>$failed,'failedItemFieldsAndHistoryRolledBack'=>$before===$after,
    'separateItemCommits'=>$next['name']==='Second bulk change' && $next['color']==='blue' && $next['transactions']===$next_before['transactions']+2));return;
}
throw new Exception('Unknown transaction boundary operation.');
