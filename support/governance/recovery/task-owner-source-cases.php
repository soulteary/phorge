<?php
/** Test-only preparation, before enabling the owned-RR/recovery flags. */
function governance_task_owner_prepare($fixture){
  $fixture->assertOwned();
  if(PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')!==false){throw new RuntimeException('task_owner_preparation_guard_unknown');}
  $suffix=substr($fixture->getNamespace(),-12);$users=array();
  foreach(array('actor','subject','other','disabled')as$role){$users[$role]=(new PhabricatorUser())->setUsername('task'.$role.$suffix)->setRealName('Synthetic Task Fixture')->setIsAdmin($role==='actor'?1:0)->setIsApproved(1)->setIsDisabled($role==='disabled'?1:0)->save();}
  return$users;
}
/** Real native Editor only; author never stands in for an owner grant. */
function governance_task_owner_edit($actor,$task,array$transactions){
  return(new ManiphestTransactionEditor())->setActor($actor)
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->setContinueOnNoEffect(true)->setIsSilent(true)->applyTransactions($task,$transactions);
}
function governance_task_owner_observe($fixture,$task){
  $statement=$fixture->getAdmin()->prepare('SELECT t.ownerPHID,(SELECT COUNT(*) FROM '.$fixture->table('maniphest','maniphest_transaction').' x WHERE x.objectPHID=t.phid) xactCount FROM '.$fixture->table('maniphest','maniphest_task').' t WHERE t.phid=?');
  try{$phid=$task->getPHID();$statement->bind_param('s',$phid);$statement->execute();$row=$statement->get_result()->fetch_assoc();if(!$row||!is_string($row['xactCount'])&&!is_int($row['xactCount'])){throw new RuntimeException('task_owner_observation_unknown');}return$row;}finally{$statement->close();}
}
function governance_task_owner_abort($scope){
  $facts=$scope->abort();if(!is_array($facts)||($facts['cleanup_proved']??null)!==true){throw new RuntimeException('task_owner_scope_cleanup_unproved');}return$facts;
}
/** Isolated focused entry; registered by the dedicated driver, SQL unrun. */
function governance_task_owner_native_cases($fixture,AphrontDatabaseConnection$observer,array$users,callable$check){
  $fixture->assertOwned();$actor=$users['actor'];$subject=$users['subject'];$other=$users['other'];
  $task=ManiphestTask::initializeNewTask($actor);
  governance_task_owner_edit($actor,$task,array((new ManiphestTransaction())->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic Task Owner Source')));
  $check($task->getOwnerPHID()===null&&!$task->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$actor),'task-owner-real-editor-author-not-owner');
  $assign=static fn($value)=>(new ManiphestTransaction())->setTransactionType(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE)->setNewValue($value);
  governance_task_owner_edit($actor,$task,array($assign($subject->getPHID())));
  $fresh=(new ManiphestTask())->load($task->getID());$before=governance_task_owner_observe($fixture,$task);
  $check($before['ownerPHID']===$subject->getPHID()&&$fresh->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$subject),'task-owner-real-reassign-commit-current-owner');
  $scope=PhorgeRecoveryOwnedRRScope::begin($observer);
  try{$facts=PhorgeRecoveryTaskOwnerSource::capture($scope,$subject->getPHID())->finish();$scope->rollback();}
  catch(Throwable$error){try{governance_task_owner_abort($scope);}catch(Throwable$cleanup){throw new PhutilAggregateException('task_owner_scope_cleanup_failed',array('primary'=>$error,'cleanup'=>$cleanup));}throw$error;}
  $check($facts['actual_SQL_reads_executed']===true&&$facts['complete']===false,'task-owner-real-qualified-native-User-RR-source');
  $writer=$task->establishConnection('w');
  if($writer->isInsideTransaction()||$writer->isReadLocking()||$writer->isWriteLocking()){throw new RuntimeException('task_owner_editor_outer_unknown');}
  $primary=null;$owned=$writer;
  try{$writer->openTransaction();governance_task_owner_edit($actor,(new ManiphestTask())->load($task->getID()),array($assign($other->getPHID())));}
  catch(Throwable$error){$primary=$error;throw$error;}
  finally{try{if($owned->isInsideTransaction()){$owned->killTransaction();}}catch(Throwable$cleanup){if($primary){throw new PhutilAggregateException('task_owner_editor_rollback_failed',array('primary'=>$primary,'cleanup'=>$cleanup));}throw$cleanup;}}
  $check(governance_task_owner_observe($fixture,$task)===$before,'task-owner-real-reassign-and-history-rollback');
  governance_task_owner_edit($actor,(new ManiphestTask())->load($task->getID()),array($assign(null)));
  $fresh=(new ManiphestTask())->load($task->getID());
  $check($fresh->getOwnerPHID()===null&&!$fresh->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$subject),'task-owner-real-unassign-removes-direct-automatic');
  $disabled=$users['disabled'];governance_task_owner_edit($actor,$fresh,array($assign($disabled->getPHID())));$disabledTask=(new ManiphestTask())->load($task->getID());
  $disabledScope=PhorgeRecoveryOwnedRRScope::begin($observer);
  try{$disabledFacts=PhorgeRecoveryTaskOwnerSource::capture($disabledScope,$disabled->getPHID())->finish();$disabledScope->rollback();}catch(Throwable$error){try{governance_task_owner_abort($disabledScope);}catch(Throwable$cleanup){throw new PhutilAggregateException('task_owner_disabled_cleanup_failed',array('primary'=>$error,'cleanup'=>$cleanup));}throw$error;}
  $check((bool)$disabled->getIsDisabled()&&$disabledTask->getOwnerPHID()===$disabled->getPHID()&&$disabledTask->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$disabled)&&$disabledFacts['Space_or_active_account_gate_proved']===false,'task-owner-native-disabled-assignee-still-direct-owner-not-account-admission');
  governance_task_owner_edit($actor,$disabledTask,array($assign(null)));$fresh=(new ManiphestTask())->load($task->getID());
  $check($fresh->getOwnerPHID()===null&&!$fresh->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$disabled),'task-owner-native-disabled-assignee-unassign-committed');
  return array('task'=>$fresh,'subject'=>$subject);
}
/** Strict engine-composite wait decoder. Does not interpret creator IDs as owners. */
function governance_task_owner_wait_rows(array$rows,$requester,$blocker,$expectedIndex='ownerPHID'){
  if(!in_array($expectedIndex,array('ownerPHID','PRIMARY'),true)||count($rows)!==1){return false;}$row=$rows[0];
  return array_keys($row)===array('requester','blocker','schema_name','table_name','index_name','mode','status')&&
    $row['requester']===$requester&&$row['blocker']===$blocker&&
    is_string($row['schema_name'])&&preg_match('/\Agovernance_native_[a-f0-9]{16}_maniphest\z/D',$row['schema_name'])&&
    $row['table_name']==='maniphest_task'&&$row['index_name']===$expectedIndex&&
    in_array($row['mode'],array('X,GAP,INSERT_INTENTION','X,INSERT_INTENTION','X','X,REC_NOT_GAP'),true)&&$row['status']==='WAITING';
}
/** Actual raw producer into an empty selected owner range, both terminal outcomes.
 * Caller provisions the native observer and ordinary fixture grants separately.
 * Auxiliary sockets are registered before identity queries by the owned fixture.
 */
function governance_task_owner_empty_range_cases($fixture,AphrontDatabaseConnection$observer,array$state,callable$check){
  $task=$state['task'];$subject=$state['subject'];
  foreach(array('commit','rollback')as$terminal){
    $fixture->assertOwned();$aux=$fixture->newOwnedSetupConnection();$setup=$fixture->getAdmin();$scope=null;$pending=false;$primary=null;
    try{
      $scope=PhorgeRecoveryOwnedRRScope::begin($observer);$facts=PhorgeRecoveryTaskOwnerSource::capture($scope,$subject->getPHID());
      $writer=(new PhabricatorUser())->establishConnection('w');
      $empty=queryfx_all($writer,'SELECT id FROM %T.maniphest_task FORCE INDEX (ownerPHID) WHERE ownerPHID=%s LIMIT 1 LOCK IN SHARE MODE',$fixture->getNamespace().'_maniphest',$subject->getPHID());if($empty!==array()){throw new RuntimeException('task_owner_empty_range_precondition_failed');}
      $blocker=queryfx_one($writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];$requester=(string)$aux->thread_id;
      $owner=$aux->real_escape_string($subject->getPHID());$id=(int)$task->getID();
      $sql='UPDATE '.$fixture->table('maniphest','maniphest_task')." SET ownerPHID='".$owner."' WHERE id=".$id;
      if($aux->query($sql,MYSQLI_ASYNC)!==true){throw new RuntimeException('task_owner_async_start_failed');}$pending=true;
      $deadline=microtime(true)+5;$waiting=false;
      do{
        $rows=queryfx_all($observer,'SELECT CAST(r.TRX_MYSQL_THREAD_ID AS CHAR) requester,CAST(b.TRX_MYSQL_THREAD_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_MODE mode,l.LOCK_STATUS status FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE=w.ENGINE AND l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID JOIN information_schema.INNODB_TRX r ON r.TRX_REQUESTED_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND r.TRX_STATE=%s JOIN information_schema.INNODB_TRX b ON b.TRX_ID=w.BLOCKING_ENGINE_TRANSACTION_ID WHERE r.TRX_MYSQL_THREAD_ID=%s AND b.TRX_MYSQL_THREAD_ID=%s','LOCK WAIT',$requester,$blocker);
        $waiting=microtime(true)<=$deadline&&governance_task_owner_wait_rows($rows,$requester,$blocker);
        if($waiting||microtime(true)>= $deadline){break;}usleep(250000);
      }while(microtime(true)<$deadline);
      $read=array($aux);$errors=array();$reject=array();$ready=mysqli_poll($read,$errors,$reject,0,0);
      $check($waiting&&$ready===0&&!$errors&&!$reject,'task-owner-empty-range-real-raw-update-waits-'.$terminal);
      $finished=$facts->finish();if($finished['complete']!==false){throw new RuntimeException('task_owner_unexpected_complete');}
      if($terminal==='commit'){$scope->commit();}else{$scope->rollback();}
      $read=array($aux);$errors=array();$reject=array();if(mysqli_poll($read,$errors,$reject,5,0)!==1||$errors||$reject||$aux->reap_async_query()!==true||$aux->affected_rows!==1){throw new RuntimeException('task_owner_async_completion_unknown');}$pending=false;
      $check(governance_task_owner_observe($fixture,$task)['ownerPHID']===$subject->getPHID(),'task-owner-empty-range-real-raw-update-after-'.$terminal);
      // Restore only this owned synthetic row, outside every held source scope.
      if($setup->query('UPDATE '.$fixture->table('maniphest','maniphest_task').' SET ownerPHID=NULL WHERE id='.$id)!==true||$setup->affected_rows!==1){throw new RuntimeException('task_owner_synthetic_restore_unproved');}
    }catch(Throwable$error){$primary=$error;throw$error;}
    finally{
      $cleanup=array();if($scope!==null){try{governance_task_owner_abort($scope);}catch(Throwable$error){$cleanup['scope']=$error;}}
      if($pending){try{$read=array($aux);$errors=array();$reject=array();if(mysqli_poll($read,$errors,$reject,5,0)!==1||$errors||$reject){throw new RuntimeException('task_owner_pending_cleanup_unknown');}$aux->reap_async_query();}catch(Throwable$error){$cleanup['pending']=$error;}}
      if($cleanup){if($primary){$cleanup=array('primary'=>$primary)+$cleanup;}throw new PhutilAggregateException('task_owner_raw_producer_cleanup_failed',$cleanup);}
      // Fixture's registered auxiliary lifecycle closes and proves thread absence.
    }
  }
}

/** Only a copy of this profile's native-created synthetic template; no user payload read into PHP. */
function governance_task_owner_insert_copy_sql($fixture,$template,$phid,$owner){
  if(!is_string($phid)||!preg_match('/\APHID-TASK-[a-zA-Z0-9]{20}\z/D',$phid)||!is_string($owner)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$owner)){throw new RuntimeException('task_owner_copy_identity_unknown');}
  $conn=$fixture->getAdmin();$q=static fn($value)=>"'".$conn->real_escape_string($value)."'";
  $columns='phid,authorPHID,ownerPHID,status,priority,title,description,dateCreated,dateModified,mailKey,ownerOrdering,originalEmailSource,subpriority,viewPolicy,editPolicy,spacePHID,properties,points,bridgedObjectPHID,subtype,closedEpoch,closerPHID';
  $values=$q($phid).',authorPHID,'.$q($owner).',status,priority,title,description,dateCreated,dateModified,mailKey,ownerOrdering,originalEmailSource,subpriority,viewPolicy,editPolicy,spacePHID,properties,points,NULL,subtype,closedEpoch,closerPHID';
  return'INSERT INTO '.$fixture->table('maniphest','maniphest_task').' ('.$columns.') SELECT '.$values.' FROM '.$fixture->table('maniphest','maniphest_task').' WHERE id='.(int)$template->getID();
}
function governance_task_owner_raw_insert_delete_cases($fixture,AphrontDatabaseConnection$observer,array$state,callable$check){
  foreach(array('insert','delete')as$operation){foreach(array('commit','rollback')as$terminal){
    $fixture->assertOwned();$setup=$fixture->getAdmin();$aux=$fixture->newOwnedSetupConnection();$phid=(new ManiphestTask())->generatePHID();$subject=$state['subject'];$scope=null;$pending=false;$primary=null;$exists=false;
    $insert=governance_task_owner_insert_copy_sql($fixture,$state['task'],$phid,$subject->getPHID());$literal="'".$setup->real_escape_string($phid)."'";
    try{
      if($operation==='delete'){if($setup->query($insert)!==true||$setup->affected_rows!==1){throw new RuntimeException('task_owner_delete_preparation_failed');}$exists=true;}
      $scope=PhorgeRecoveryOwnedRRScope::begin($observer);$facts=PhorgeRecoveryTaskOwnerSource::capture($scope,$subject->getPHID());
      $writer=(new PhabricatorUser())->establishConnection('w');$blocker=queryfx_one($writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];$requester=(string)$aux->thread_id;
      $sql=$operation==='insert'?$insert:'DELETE FROM '.$fixture->table('maniphest','maniphest_task').' WHERE phid='.$literal;
      if($aux->query($sql,MYSQLI_ASYNC)!==true){throw new RuntimeException('task_owner_copy_async_failed');}$pending=true;$deadline=microtime(true)+5;$waiting=false;
      do{$rows=queryfx_all($observer,'SELECT CAST(r.TRX_MYSQL_THREAD_ID AS CHAR) requester,CAST(b.TRX_MYSQL_THREAD_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_MODE mode,l.LOCK_STATUS status FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE=w.ENGINE AND l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID JOIN information_schema.INNODB_TRX r ON r.TRX_REQUESTED_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND r.TRX_STATE=%s JOIN information_schema.INNODB_TRX b ON b.TRX_ID=w.BLOCKING_ENGINE_TRANSACTION_ID WHERE r.TRX_MYSQL_THREAD_ID=%s AND b.TRX_MYSQL_THREAD_ID=%s','LOCK WAIT',$requester,$blocker);
        $waiting=microtime(true)<=$deadline&&governance_task_owner_wait_rows($rows,$requester,$blocker,$operation==='insert'?'ownerPHID':'PRIMARY');if($waiting||microtime(true)>=$deadline){break;}usleep(250000);
      }while(microtime(true)<$deadline);
      $read=array($aux);$errors=$reject=array();$ready=mysqli_poll($read,$errors,$reject,0,0);
      $check($waiting&&$ready===0&&!$errors&&!$reject,'task-owner-real-raw-'.$operation.'-waits-'.$terminal);
      $facts->finish();if($terminal==='commit'){$scope->commit();}else{$scope->rollback();}
      $read=array($aux);$errors=$reject=array();if(mysqli_poll($read,$errors,$reject,5,0)!==1||$errors||$reject||$aux->reap_async_query()!==true||$aux->affected_rows!==1){throw new RuntimeException('task_owner_copy_reap_unknown');}$pending=false;$exists=$operation==='insert';
      $count=$setup->query('SELECT COUNT(*) n FROM '.$fixture->table('maniphest','maniphest_task').' WHERE phid='.$literal)->fetch_assoc();
      $check($count&&in_array($count['n'],array('0','1',0,1),true)&&(int)$count['n']===($operation==='insert'?1:0),'task-owner-real-raw-'.$operation.'-completed-after-'.$terminal);
    }catch(Throwable$error){$primary=$error;throw$error;}
    finally{
      $errors=array();if($scope!==null){try{governance_task_owner_abort($scope);}catch(Throwable$error){$errors['scope']=$error;}}
      if($pending){try{$read=array($aux);$bad=$reject=array();if(mysqli_poll($read,$bad,$reject,5,0)!==1||$bad||$reject||$aux->reap_async_query()!==true){throw new RuntimeException('task_owner_copy_pending_cleanup_unknown');}$pending=false;$exists=$operation==='insert';}catch(Throwable$error){$errors['pending']=$error;}}
      if(!$pending){try{$count=$setup->query('SELECT COUNT(*) n FROM '.$fixture->table('maniphest','maniphest_task').' WHERE phid='.$literal)->fetch_assoc();if(!$count||!in_array($count['n'],array('0','1',0,1),true)){throw new RuntimeException('task_owner_copy_cleanup_identity_unknown');}if((int)$count['n']===1&&($setup->query('DELETE FROM '.$fixture->table('maniphest','maniphest_task').' WHERE phid='.$literal)!==true||$setup->affected_rows!==1)){throw new RuntimeException('task_owner_copy_cleanup_failed');}}catch(Throwable$error){$errors['copy-row']=$error;}}
      if($errors){if($primary){$errors=array('primary'=>$primary)+$errors;}throw new PhutilAggregateException('task_owner_copy_cleanup_failed',$errors);}
    }
  }}
}
