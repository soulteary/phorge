<?php
/** Dedicated isolated fixture candidate. Caller owns paired Gorge/config/cleanup; fresh SQL pending. */
function governance_recovery_file_source_prepare($fixture){
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class){throw new RuntimeException('file_source_fixture_unknown');}
  $fixture->assertOwned();
  if(PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')!==false){throw new RuntimeException('file_source_preparation_guard_unknown');}
  require_once __DIR__.'/task-owner-source-cases.php';
  // Reuse accepted real account/avatar preparation before recovery/RR admission.
  // No invented Handles, profile cache or PHP storage fallback.
  $users=governance_task_owner_prepare($fixture);$author=$users['subject'];
  $file=PhabricatorFile::newFromFileData('Synthetic isolated File source fixture.',array('name'=>'synthetic-file-source.txt','authorPHID'=>$author->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  $stored=(new PhabricatorFile())->load($file->getID());
  if(!$stored||$stored->getPHID()!==$file->getPHID()||$stored->getAuthorPHID()!==$author->getPHID()||$stored->getStorageEngine()!=='gorge'||$stored->getViewPolicy()!==PhabricatorPolicies::POLICY_NOONE){throw new RuntimeException('file_source_native_file_unproved');}
  // Ordinary author, not omnipotent/admin, edits the real Task. Any native
  // permission refusal is a failed prerequisite, never overridden in a fixture.
  $task=ManiphestTask::initializeNewTask($author);
  governance_task_owner_edit($author,$task,array((new ManiphestTransaction())->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic File Source Parent')));
  $derived=PhabricatorFile::newFromFileData('Synthetic isolated derived File fixture.',array('name'=>'synthetic-derived.txt','viewPolicy'=>PhabricatorPolicies::POLICY_NOONE));
  $derived=(new PhabricatorFile())->load($derived->getID());if(!$derived||$derived->getAuthorPHID()!==null||$derived->getStorageEngine()!=='gorge'){throw new RuntimeException('file_source_derived_storage_unknown');}
  return array('users'=>$users,'file'=>$stored,'derived'=>$derived,'task'=>$task);
}
function governance_recovery_file_source_native_attachment($fixture,array$state,$mode){
  $fixture->assertOwned();
  if(!in_array($mode,array(PhabricatorFileAttachment::MODE_ATTACH,PhabricatorFileAttachment::MODE_REFERENCE,PhabricatorFileAttachment::MODE_DETACH),true)){throw new RuntimeException('file_source_attachment_mode_unknown');}
  $task=(new ManiphestTask())->load($state['task']->getID());
  if(!$task||$task->getPHID()!==$state['task']->getPHID()){throw new RuntimeException('file_source_parent_identity_changed');}
  governance_task_owner_edit($state['users']['subject'],$task,array((new ManiphestTransaction())->setTransactionType(PhabricatorTransactions::TYPE_FILE)->setNewValue(array($state['file']->getPHID()=>$mode))));
  // No direct writes to attachment rows and no forged policy object attachments.
}
/** Fixed synthetic identities only; returned rows remain fixture-private. */
function governance_recovery_file_source_observe($fixture,array$state){
  $fixture->assertOwned();$setup=$fixture->getAdmin();$q=static fn($v)=>"'".$setup->real_escape_string($v)."'";
  $file=$q($state['file']->getPHID());$task=$q($state['task']->getPHID());$derived=$q($state['derived']->getPHID());
  $row=$setup->query('SELECT id,phid,authorPHID FROM '.$fixture->table('file','file').' WHERE phid='.$file)->fetch_assoc();if(!$row||$row['phid']!==$state['file']->getPHID()){throw new RuntimeException('file_source_observed_row_missing');}
  $attachments=$setup->query('SELECT id,attachmentMode FROM '.$fixture->table('file','file_attachment').' WHERE objectPHID='.$task.' AND filePHID='.$file.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
  $transforms=$setup->query('SELECT id,originalPHID,transform,transformedPHID FROM '.$fixture->table('file','file_transformedfile').' WHERE originalPHID='.$file.' AND transformedPHID='.$derived.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
  if(count($attachments)>1||count($transforms)>1){throw new RuntimeException('file_source_synthetic_relation_unknown');}
  return array('file'=>$row,'attachments'=>$attachments,'transforms'=>$transforms);
}
function governance_recovery_file_source_view($state,$viewer,$derived=false){
  $phid=($derived?$state['derived']:$state['file'])->getPHID();
  $rows=(new PhabricatorFileQuery())->setViewer($viewer)->withPHIDs(array($phid))->withIsDeleted(false)->setRaisePolicyExceptions(false)->execute();
  if(!is_array($rows)||count($rows)>1){throw new RuntimeException('file_source_native_view_result_unknown');}foreach($rows as$row){if(get_class($row)!==PhabricatorFile::class||$row->getPHID()!==$phid){throw new RuntimeException('file_source_native_view_identity_changed');}}
  return count($rows)===1;
}
/** Actual low-level relation DAO, not an image generator or transform backend. */
function governance_recovery_file_source_relation(array$state){
  return(new PhabricatorTransformedFile())->setOriginalPHID($state['file']->getPHID())->setTransformedPHID($state['derived']->getPHID())->setTransform('fixture:opaque-native-dao')->save();
}
function governance_recovery_file_source_native_cases($fixture,$observer,array$state,callable$check){
  $author=$state['users']['subject'];$reader=$state['users']['other'];$seen=governance_recovery_file_source_observe($fixture,$state);
  $check(!$author->getIsAdmin()&&$seen['file']['authorPHID']===$author->getPHID()&&$state['file']->getStorageEngine()==='gorge','file-source-native-Gorge-file-ordinary-author-persisted');
  $check(governance_recovery_file_source_view($state,$author)&&!governance_recovery_file_source_view($state,$reader),'file-source-native-FileQuery-author-allow-ordinary-other-deny-existing-row');
  $scope=PhorgeRecoveryOwnedRRScope::begin($observer);
  try{$facts=PhorgeRecoveryFileSourceFacts::capture($scope,$author->getPHID())->finish();$scope->rollback();}catch(Throwable$error){try{governance_task_owner_abort($scope);}catch(Throwable$cleanup){throw new PhutilAggregateException('file_source_initial_scope_cleanup_failed',array('primary'=>$error,'cleanup'=>$cleanup));}throw$error;}
  $check($facts['actual_SQL_reads_executed']===true&&$facts['complete']===false&&$facts['parent_objects_authorized']===false,'file-source-real-author-rooted-same-native-User-RR-facts-partial');
  governance_recovery_file_source_native_attachment($fixture,$state,PhabricatorFileAttachment::MODE_REFERENCE);$seen=governance_recovery_file_source_observe($fixture,$state);
  $check(count($seen['attachments'])===1&&$seen['attachments'][0]['attachmentMode']==='reference'&&!governance_recovery_file_source_view($state,$reader),'file-source-native-reference-is-not-parent-policy-grant');
  governance_recovery_file_source_native_attachment($fixture,$state,PhabricatorFileAttachment::MODE_ATTACH);$seen=governance_recovery_file_source_observe($fixture,$state);
  $check(count($seen['attachments'])===1&&$seen['attachments'][0]['attachmentMode']==='attach'&&governance_recovery_file_source_view($state,$reader),'file-source-native-attach-ordinary-parent-VIEW-allows-existing-file');
  governance_recovery_file_source_native_attachment($fixture,$state,PhabricatorFileAttachment::MODE_REFERENCE);$seen=governance_recovery_file_source_observe($fixture,$state);
  $check(count($seen['attachments'])===1&&$seen['attachments'][0]['attachmentMode']==='attach','file-source-native-reference-does-not-downgrade-attach');
  $relation=governance_recovery_file_source_relation($state);$seen=governance_recovery_file_source_observe($fixture,$state);
  $check(count($seen['transforms'])===1&&governance_recovery_file_source_view($state,$reader,true),'file-source-native-relation-DAO-original-VIEW-not-transform-generation');
  // Own only the actual File writer transaction. Do not claim a separate Task
  // writer or its history became atomic merely because this rollback succeeds.
  $writer=$relation->establishConnection('w');$id=$relation->getID();$before=$seen;
  governance_spaces_fixture_scope($writer,function($owned)use($id){$row=(new PhabricatorTransformedFile())->load($id);if(!$row||$row->establishConnection('w')!==$owned){throw new RuntimeException('file_source_relation_writer_unknown');}$row->delete();});
  $check(governance_recovery_file_source_observe($fixture,$state)===$before,'file-source-native-File-DAO-relation-delete-rollback-independent-observation');
  governance_recovery_file_source_native_attachment($fixture,$state,PhabricatorFileAttachment::MODE_DETACH);$seen=governance_recovery_file_source_observe($fixture,$state);
  $check($seen['attachments']===array()&&!governance_recovery_file_source_view($state,$reader)&&!governance_recovery_file_source_view($state,$reader,true),'file-source-native-detach-removes-parent-and-derived-VIEW-with-originals-existing');
  $row=(new PhabricatorTransformedFile())->load($id);if(!$row){throw new RuntimeException('file_source_relation_cleanup_missing');}$row->delete();
  return$state;
}
/** Actual waiting transaction owner, never the Performance Schema creator. */
function governance_recovery_file_source_wait_rows(array$rows,$requester,$blocker,$schema,$table,$index){
  $allowed=array('file'=>array('authorPHID'),'file_attachment'=>array('key_file','PRIMARY'),'file_transformedfile'=>array('originalPHID','PRIMARY'));
  if(!isset($allowed[$table])||!in_array($index,$allowed[$table],true)||count($rows)!==1){return false;}$row=$rows[0];
  return array_keys($row)===array('requester','blocker','schema_name','table_name','index_name','mode','status')&&$row['requester']===$requester&&$row['blocker']===$blocker&&is_string($schema)&&preg_match('/\Agovernance_native_[a-f0-9]{16}_file\z/D',$schema)&&$row['schema_name']===$schema&&$row['table_name']===$table&&$row['index_name']===$index&&in_array($row['mode'],array('X,GAP,INSERT_INTENTION','X,INSERT_INTENTION','X','X,REC_NOT_GAP'),true)&&$row['status']==='WAITING';
}
function governance_recovery_file_source_wait($observer,$aux,$blocker,$schema,$table,$index){
  $deadline=microtime(true)+5;$waiting=false;$requester=(string)$aux->thread_id;
  do{$rows=queryfx_all($observer,'SELECT CAST(r.TRX_MYSQL_THREAD_ID AS CHAR) requester,CAST(b.TRX_MYSQL_THREAD_ID AS CHAR) blocker,l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_MODE mode,l.LOCK_STATUS status FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE=w.ENGINE AND l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID JOIN information_schema.INNODB_TRX r ON r.TRX_REQUESTED_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND r.TRX_STATE=%s JOIN information_schema.INNODB_TRX b ON b.TRX_ID=w.BLOCKING_ENGINE_TRANSACTION_ID WHERE r.TRX_MYSQL_THREAD_ID=%s AND b.TRX_MYSQL_THREAD_ID=%s','LOCK WAIT',$requester,$blocker);
    $waiting=microtime(true)<=$deadline&&governance_recovery_file_source_wait_rows($rows,$requester,$blocker,$schema,$table,$index);if($waiting||microtime(true)>=$deadline){break;}usleep(250000);
  }while(microtime(true)<$deadline);
  $read=array($aux);$errors=array();$reject=array();return$waiting&&mysqli_poll($read,$errors,$reject,0,0)===0&&!$errors&&!$reject;
}
/** Raw producer fixture SQL, including native nondefault timestamps. */
function governance_recovery_file_transform_insert_sql($fixture,array$state){
  $fixture->assertOwned();$setup=$fixture->getAdmin();$q=static fn($v)=>"'".$setup->real_escape_string($v)."'";
  return'INSERT INTO '.$fixture->table('file','file_transformedfile').' (originalPHID,transform,transformedPHID,dateCreated,dateModified) VALUES ('.$q($state['file']->getPHID()).",'fixture:opaque-native-dao',".$q($state['derived']->getPHID()).',1,1)';
}
/** Fixed synthetic raw producer matrix. No native image generator is claimed. */
function governance_recovery_file_source_raw_cases($fixture,$observer,array$state,callable$check){
  foreach(array('author-update','attachment-insert','attachment-delete','transform-insert','transform-delete')as$operation){foreach(array('commit','rollback')as$terminal){
    $fixture->assertOwned();$setup=$fixture->getAdmin();$q=static fn($v)=>"'".$setup->real_escape_string($v)."'";$file=$q($state['file']->getPHID());$task=$q($state['task']->getPHID());$derived=$q($state['derived']->getPHID());$subject=$state['users']['subject']->getPHID();$other=$state['users']['other']->getPHID();$scope=null;$pending=false;$primary=null;$aux=$fixture->newOwnedSetupConnection();
    $attachment=$fixture->table('file','file_attachment');$transform=$fixture->table('file','file_transformedfile');$filetable=$fixture->table('file','file');$id=(int)$state['file']->getID();$relationID=null;
    try{
      $session=$aux->query('SELECT @@autocommit ac')->fetch_assoc();if(!$session||!in_array($session['ac'],array(1,'1'),true)){throw new RuntimeException('file_source_aux_autocommit_unknown');}
      if($operation==='attachment-delete'){governance_recovery_file_source_native_attachment($fixture,$state,PhabricatorFileAttachment::MODE_ATTACH);}
      if($operation==='transform-delete'){$relationID=governance_recovery_file_source_relation($state)->getID();}
      $before=governance_recovery_file_source_observe($fixture,$state);
      switch($operation){
        case'author-update':$table='file';$index='authorPHID';$selected=$other;$sql='UPDATE '.$filetable.' SET authorPHID='.$q($other).' WHERE id='.$id.' AND phid='.$file.' AND authorPHID='.$q($subject);break;
        case'attachment-insert':$table='file_attachment';$index='key_file';$selected=$subject;if($before['attachments']!==array()){throw new RuntimeException('file_source_attachment_absence_unknown');}$sql='INSERT INTO '.$attachment.' (objectPHID,filePHID,attachmentMode,attacherPHID,dateCreated,dateModified) VALUES ('.$task.','.$file.",'attach',NULL,1,1)";break;
        case'attachment-delete':$table='file_attachment';$index='PRIMARY';$selected=$subject;if(count($before['attachments'])!==1){throw new RuntimeException('file_source_attachment_delete_unknown');}$sql='DELETE FROM '.$attachment.' WHERE id='.(int)$before['attachments'][0]['id'];break;
        case'transform-insert':$table='file_transformedfile';$index='originalPHID';$selected=$subject;if($before['transforms']!==array()){throw new RuntimeException('file_source_transform_absence_unknown');}$sql=governance_recovery_file_transform_insert_sql($fixture,$state);break;
        case'transform-delete':$table='file_transformedfile';$index='PRIMARY';$selected=$subject;if(count($before['transforms'])!==1){throw new RuntimeException('file_source_transform_delete_unknown');}$sql='DELETE FROM '.$transform.' WHERE id='.(int)$relationID;break;
      }
      $scope=PhorgeRecoveryOwnedRRScope::begin($observer);$facts=PhorgeRecoveryFileSourceFacts::capture($scope,$selected);$writer=(new PhabricatorUser())->establishConnection('w');
      if($operation==='author-update'){$empty=queryfx_all($writer,'SELECT id FROM %T.file FORCE INDEX (authorPHID) WHERE authorPHID=%s LIMIT 1 LOCK IN SHARE MODE',$fixture->getNamespace().'_file',$other);if($empty!==array()){throw new RuntimeException('file_source_author_absence_unknown');}}
      $blocker=queryfx_one($writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];if($aux->query($sql,MYSQLI_ASYNC)!==true){throw new RuntimeException('file_source_async_start_failed');}$pending=true;
      $check(governance_recovery_file_source_wait($observer,$aux,$blocker,$fixture->getNamespace().'_file',$table,$index),'file-source-real-raw-'.$operation.'-waits-'.$terminal);
      if($facts->finish()['complete']!==false){throw new RuntimeException('file_source_unexpected_complete');}if($terminal==='commit'){$scope->commit();}else{$scope->rollback();}
      $read=array($aux);$errors=array();$reject=array();if(mysqli_poll($read,$errors,$reject,5,0)!==1||$errors||$reject||$aux->reap_async_query()!==true||$aux->affected_rows!==1){throw new RuntimeException('file_source_async_completion_unknown');}$pending=false;
      $after=governance_recovery_file_source_observe($fixture,$state);
      $ok=$operation==='author-update'?$after['file']['authorPHID']===$other:(str_ends_with($operation,'insert')?count($after[$table==='file_attachment'?'attachments':'transforms'])===1:$after[$table==='file_attachment'?'attachments':'transforms']===array());
      $check($ok,'file-source-real-raw-'.$operation.'-completed-after-'.$terminal);
    }catch(Throwable$error){$primary=$error;throw$error;}
    finally{
      $cleanup=array();$scopeClean=true;if($scope!==null){try{governance_task_owner_abort($scope);}catch(Throwable$error){$scopeClean=false;$cleanup['scope']=$error;}}
      if($pending){try{$read=array($aux);$errors=array();$reject=array();if(mysqli_poll($read,$errors,$reject,5,0)!==1||$errors||$reject){throw new RuntimeException('file_source_pending_cleanup_unknown');}$aux->reap_async_query();$pending=false;}catch(Throwable$error){$cleanup['pending']=$error;}}
      try{if(!$scopeClean||$pending){throw new RuntimeException('file_source_synthetic_cleanup_deferred_unknown_scope_or_pending');}if($operation==='author-update'){$sql='UPDATE '.$filetable.' SET authorPHID='.$q($subject).' WHERE id='.$id.' AND phid='.$file.' AND authorPHID IN ('.$q($subject).','.$q($other).')';}elseif(str_starts_with($operation,'attachment-')){$sql='DELETE FROM '.$attachment.' WHERE objectPHID='.$task.' AND filePHID='.$file;}else{$sql='DELETE FROM '.$transform.' WHERE originalPHID='.$file.' AND transformedPHID='.$derived." AND transform='fixture:opaque-native-dao'";}if($setup->query($sql)!==true){throw new RuntimeException('file_source_synthetic_restore_failed');}}catch(Throwable$error){$cleanup['synthetic']=$error;}
      if($cleanup){if($primary){$cleanup=array('primary'=>$primary)+$cleanup;}throw new PhutilAggregateException('file_source_raw_cleanup_failed',$cleanup);}
      // Fixture owns all ten auxiliary lifecycles and proves session absence.
    }
  }}
}
