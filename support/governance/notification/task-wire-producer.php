<?php
/** Test-only Task producer. Only an owned isolation parent may invoke this. */
require_once __DIR__.'/calendar-wire-producer.php';
enum GovernanceTaskWireMode {case QUEUED;case MANUAL;}
function governance_task_wire_binding($mail,$task,$recipient) {
  $id=governance_calendar_wire_positive_id($mail->getID());
  $task_id=governance_calendar_wire_positive_id($task->getID());
  if(!preg_match('/\APHID-TASK-[a-zA-Z0-9]{20}\z/D',$task->getPHID())||
    !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$recipient->getPHID())||
    $task->getURI()!=='/T'.$task_id||$mail->getRelatedPHID()!==$task->getPHID()||
    $mail->getPrivateMailPolicyValue('governance.mail-producer')!=='ManiphestTransactionEditor'||
    !(( $mail->getToPHIDs()===array($recipient->getPHID())&&$mail->getCcPHIDs()===array())||
      ($mail->getToPHIDs()===array()&&$mail->getCcPHIDs()===array($recipient->getPHID())))||
    $mail->getRawToAddresses()!==array()||
    !preg_match('/\APHID-MTAM-[a-zA-Z0-9]{20}\z/D',$mail->getPHID())||
    !preg_match('@\A/T[1-9][0-9]*\z@D',$task->getURI())) {
    throw new RuntimeException('task_wire_native_mail_binding_refused');
  }
  return array('mail_id'=>$id,'mail_phid'=>$mail->getPHID(),
    'task_phid'=>$task->getPHID(),'task_path'=>$task->getURI(),
    'recipient_phid'=>$recipient->getPHID(),
    'relay_event_id'=>'mail.prepare/'.$mail->getPHID().'/1');
}
function governance_task_wire_enqueue($fixture,callable $check,array $queue,array $sink,PhabricatorUser $actor,PhabricatorUser $recipient) {
  $fixture->assertOwned();
  $profile=PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  if(!PhorgePrivateMailPolicy::enabled()||PhabricatorEnv::getEnvConfig('metamta.one-mail-per-recipient')!==true||
    PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==false||
    PhabricatorEnv::getEnvConfig('cluster.databases')!==array()||PhabricatorEnv::isReadOnly()||
    (new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()!==false||
    ($queue['kind']??null)!=='taskqueue'||!is_resource($queue['process']??null)||
    !proc_get_status($queue['process'])['running']||
    !PhabricatorGorgeTaskQueueClient::isConfigured()||
    PhabricatorGorgeTaskQueueClient::getConfiguredURI()!==($queue['uri']??null)||
    !preg_match('@\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z@D',$queue['uri'])||
    ($sink['kind']??null)!=='owlmail'||
    !is_string($sink['binary_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$sink['binary_sha256'])||
    $sink['binary_sha256']!==getenv('PHORGE_GOVERNANCE_OWLMAIL_SHA256')||
    !is_resource($sink['process']??null)||!proc_get_status($sink['process'])['running']||
    !preg_match('@\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z@D',$sink['uri']??'')) {
    throw new RuntimeException('task_wire_owned_runtime_required');
  }
  foreach(array($actor,$recipient)as$user){
    $current=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($user->getPHID()))->needUserSettings(true)->executeOne();
    $email=$current?$current->loadPrimaryEmail():null;
    if(!$current||$current->getIsDisabled()||!$current->getIsApproved()||$current->getIsSystemAgent()||
      !$email||!$email->getIsVerified()||!str_ends_with($email->getAddress(),'@example.invalid')){
      throw new RuntimeException('task_wire_synthetic_current_actor_required');
    }
  }
  if($actor->getPHID()===$recipient->getPHID()){throw new RuntimeException('task_wire_distinct_actors_required');}
  governance_task_wire_assert_no_inherited_writes();
  $file=PhabricatorFile::newFromFileData('SYNTHETIC_TASK_ATTACHMENT_BYTES',array(
    'name'=>'SYNTHETIC_TASK_ATTACHMENT_NAME.txt','authorPHID'=>$actor->getPHID(),
    'viewPolicy'=>$actor->getPHID()));
  $task=ManiphestTask::initializeNewTask($actor)->setOwnerPHID($actor->getPHID())
    ->setViewPolicy($recipient->getPHID())->setEditPolicy($actor->getPHID());
  if($task->establishConnection('w')->isInsideTransaction()){throw new RuntimeException('task_wire_inherited_transaction_refused');}
  $workers=new PhabricatorWorkerActiveTask();$mails=new PhabricatorMetaMTAMail();
  $last=(int)queryfx_one($workers->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$workers)['n'];
  $before=(int)queryfx_one($mails->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$mails)['n'];
  $tx=new ManiphestTransaction();
  $comment=(clone $tx)->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
    ->attachComment($tx->getApplicationTransactionCommentObject()->setContent(
      'SYNTHETIC_TASK_PRIVATE_COMMENT {F'.$file->getID().'}'));
  (new ManiphestTransactionEditor())->setActor($actor)
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setIsSilent(false)
    ->applyTransactions($task,array(
      (clone $tx)->setTransactionType(PhabricatorTransactions::TYPE_CREATE),
      (clone $tx)->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)->setNewValue('SYNTHETIC_TASK_PRIVATE_TITLE'),$comment,
      (clone $tx)->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)
        ->setNewValue(array('+'=>array_fuse(array($recipient->getPHID()))))));
  $queued=$workers->loadAllWhere('id>%d AND taskClass=%s AND objectPHID=%s',$last,
    'PhabricatorApplicationTransactionPublishWorker',$task->getPHID());
  if(count($queued)!==1){throw new RuntimeException('task_wire_owned_worker_cardinality');}
  $worker=head($queued);$data_row=(new PhabricatorWorkerTaskData())->load($worker->getDataID());
  $data=$data_row?$data_row->getData():null;
  if(!is_array($data)||($data['objectPHID']??null)!==$task->getPHID()||
    ($data['actorPHID']??null)!==$actor->getPHID()||empty($data['xactionPHIDs'])||count($data['xactionPHIDs'])>20){
    throw new RuntimeException('task_wire_owned_worker_data_refused');}
  $transactions=(new ManiphestTransactionQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withObjectPHIDs(array($task->getPHID()))->withPHIDs($data['xactionPHIDs'])->execute();
  if(count($transactions)!==count(array_unique($data['xactionPHIDs']))){throw new RuntimeException('task_wire_transaction_binding_refused');}
  $visible=(new ManiphestTaskQuery())->setViewer($recipient)->withPHIDs(array($task->getPHID()))->executeOne();
  if(!$visible){throw new RuntimeException('task_wire_recipient_current_view_required');}
  $remote=(new PhabricatorGorgeTaskQueueClient())->getTask((int)$worker->getID());
  if(!is_array($remote)||($remote['id']??null)!==(int)$worker->getID()||
    ($remote['taskClass']??null)!==$worker->getTaskClass()||($remote['objectPHID']??null)!==$task->getPHID()){
    throw new RuntimeException('task_wire_real_queue_binding_refused');}
  return array('task'=>$task,'file'=>$file,'worker_id'=>(int)$worker->getID(),
    'profile'=>$profile,'before_mail_id'=>$before,'actor_phid'=>$actor->getPHID(),
    'recipient_phid'=>$recipient->getPHID(),'task_phid'=>$task->getPHID(),'mode'=>GovernanceTaskWireMode::QUEUED,'manual_native_publication'=>false,
    'actual_queue_worker_proved'=>false,'SMTP_proved'=>false,'browser_proved'=>false);
}
/** Inspect only; worker execution belongs to the real standalone Gorge consumer. */
function governance_task_wire_collect($fixture,array $enqueued,PhabricatorUser $recipient) {
  $fixture->assertOwned();PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  if(($enqueued['mode']??null)!==GovernanceTaskWireMode::QUEUED){throw new RuntimeException('task_wire_queued_mode_required');}
  $task=$enqueued['task'];$mails=new PhabricatorMetaMTAMail();$before=$enqueued['before_mail_id'];
  if($enqueued['recipient_phid']!==$recipient->getPHID()||!is_int($before)||$before<0){
    throw new RuntimeException('task_wire_collect_binding_refused');}
  $bindings=array();
  foreach($mails->loadAllWhere('id>%d AND relatedPHID=%s',$before,$task->getPHID())as$mail){
    if(array_merge($mail->getToPHIDs(),$mail->getCcPHIDs())!==array($recipient->getPHID())){continue;}
    $bindings[]=governance_task_wire_binding($mail,$task,$recipient);
  }
  if(count($bindings)>1){throw new RuntimeException('task_wire_recipient_mail_cardinality');}
  return $bindings;
}
/** Kept explicitly manual for staged inspection; never used by queued hold. */
function governance_task_wire_produce($fixture,callable $check,array $queue,array $sink,PhabricatorUser $actor,PhabricatorUser $recipient) {
  $enqueued=governance_task_wire_enqueue($fixture,$check,$queue,$sink,$actor,$recipient);
  $row=(new PhabricatorWorkerActiveTask())->load($enqueued['worker_id']);
  $data_row=$row?(new PhabricatorWorkerTaskData())->load($row->getDataID()):null;
  if(!$data_row){throw new RuntimeException('task_wire_manual_worker_missing');}
  (new PhabricatorApplicationTransactionPublishWorker($data_row->getData()))->executeTask();
  $bindings=governance_task_wire_collect($fixture,$enqueued,$recipient);
  if(count($bindings)!==1){throw new RuntimeException('task_wire_recipient_mail_cardinality');}
  return array_merge($enqueued,array('binding'=>head($bindings),'manual_native_publication'=>true,'mode'=>GovernanceTaskWireMode::MANUAL));
}
/** Refuse before *any* attachment/Task write, including distinct DB handles. */
function governance_task_wire_assert_no_inherited_writes() {
  foreach(array(new ManiphestTask(),new PhabricatorFile(),new PhabricatorWorkerActiveTask(),new PhabricatorMetaMTAMail())as$dao){
    if($dao->establishConnection('w')->isInsideTransaction()){
      throw new RuntimeException('task_wire_inherited_transaction_refused');}
  }
}
/** Native policy change: subscriber is deliberately not owner/automatic viewer. */
function governance_task_wire_revoke($fixture,array $produced,PhabricatorUser $actor,PhabricatorUser $recipient) {
  $fixture->assertOwned();PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  if(($produced['mode']??null)!==GovernanceTaskWireMode::QUEUED||!PhorgePrivateMailPolicy::enabled()||($produced['recipient_phid']??null)!==$recipient->getPHID()||
    !isset($produced['task'])||$produced['task']->getPHID()!==($produced['task_phid']??null)||
    $produced['task']->getOwnerPHID()!==$actor->getPHID()||$actor->getPHID()===$recipient->getPHID()){
    throw new RuntimeException('task_wire_revoke_binding_refused');}
  $task=$produced['task'];
  // Keep native editor errors distinct from an expected recipient policy denial.
  $cache=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$prior=$cache->getValue();
  $cache->setValue(null,new PhutilInRequestKeyValueCache());
  try{
    $before=(new ManiphestTaskQuery())->setViewer($recipient)->withPHIDs(array($task->getPHID()))->execute();
    if(count($before)!==1||head($before)->getPHID()!==$task->getPHID()){
      throw new RuntimeException('task_wire_pre_revoke_visibility_not_proved');}
    (new ManiphestTransactionEditor())->setActor($actor)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setIsSilent(true)
      ->applyTransactions($task,array((new ManiphestTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue($actor->getPHID())));
    // Query new objects after the native policy edit, without request-cache evidence.
    $cache->setValue(null,new PhutilInRequestKeyValueCache());
    $owned=(new ManiphestTaskQuery())->setViewer($actor)->withPHIDs(array($task->getPHID()))->execute();
    if(count($owned)!==1||head($owned)->getPHID()!==$task->getPHID()||head($owned)->getViewPolicy()!==$actor->getPHID()){
      throw new RuntimeException('task_wire_post_revoke_object_not_proved');}
    $visible=(new ManiphestTaskQuery())->setViewer($recipient)->withPHIDs(array($task->getPHID()))->execute();
    if($visible!==array()){throw new RuntimeException('task_wire_native_revocation_not_proved');}
    return array('current_task_view_denied'=>true,'SMTP_proved'=>false,'browser_proved'=>false);
  }finally{$cache->setValue(null,$prior);}
}
/** Read native queue lineage; no synthetic worker success booleans. */
function governance_task_wire_worker_evidence(array $binding,$outbox,$snapshot) {
  $result=array('prepare_task_id'=>null,'prepare_archive_success'=>false,'submit_task_id'=>null,'submit_archive_success'=>false);
  $id=$outbox->getQueueTaskID();if($id===null){return$result;}
  $id=governance_calendar_wire_positive_id($id);$result['prepare_task_id']=$id;
  $prepare=(new PhabricatorWorkerArchiveTask())->load($id);
  if($prepare){$data=(new PhabricatorWorkerTaskData())->load($prepare->getDataID());
    if($prepare->getTaskClass()!=='GorgeMailDeliveryWorker'||!$data||$data->getData()!==$binding['mail_id']){
      throw new RuntimeException('task_wire_native_prepare_lineage_refused');}
    $result['prepare_archive_success']=(int)$prepare->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;
  }
  if($snapshot===null){return$result;}
  if(!is_array($snapshot)||($snapshot['schemaVersion']??null)!==1||($snapshot['mailID']??null)!==$binding['mail_id']||
    ($snapshot['deliveryID']??null)!=='mail/'.$binding['mail_phid'].'/1'){
    throw new RuntimeException('task_wire_native_snapshot_lineage_refused');}
  $found=array();foreach(array(new PhabricatorWorkerActiveTask(),new PhabricatorWorkerArchiveTask())as$table){
    $rows=$table->loadAllWhere('taskClass=%s ORDER BY id LIMIT 65','GorgeMailSubmitWorker');
    if(count($rows)>64){throw new RuntimeException('task_wire_submit_lineage_budget');}
    foreach($rows as$row){$data=(new PhabricatorWorkerTaskData())->load($row->getDataID());$value=$data?$data->getData():null;
      if(!is_array($value)||($value['mailID']??null)!==$binding['mail_id']||($value['deliveryID']??null)!==$snapshot['deliveryID']){continue;}
      if(isset($found[(int)$row->getID()])){throw new RuntimeException('task_wire_duplicate_submit_lineage');}
      $found[(int)$row->getID()]=$row;
    }
  }
  if(count($found)>1){throw new RuntimeException('task_wire_submit_lineage_cardinality');}
  if($found){$row=head($found);$result['submit_task_id']=(int)$row->getID();
    $result['submit_archive_success']=$row instanceof PhabricatorWorkerArchiveTask&&
      (int)$row->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;}
  return$result;
}

/** Owned history evidence is retention, never an authorization grant. */
function governance_task_wire_history($fixture,array $enqueued,PhabricatorUser $recipient){
  $fixture->assertOwned();PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  if(($enqueued['mode']??null)!==GovernanceTaskWireMode::QUEUED||
    ($enqueued['recipient_phid']??null)!==$recipient->getPHID()||
    ($enqueued['task_phid']??null)!==$enqueued['task']->getPHID()){
    throw new RuntimeException('task_history_owner_binding_refused');}
  $notifications=(new PhabricatorFeedStoryNotification())->loadAllWhere(
    'primaryObjectPHID=%s AND userPHID=%s',$enqueued['task_phid'],$recipient->getPHID());
  if(count($notifications)>1){throw new RuntimeException('task_history_cardinality_refused');}
  if(!$notifications){return array('retained'=>false,'current_visible'=>false,'key'=>null);}
  $notification=head($notifications);$key=(string)$notification->getChronologicalKey();
  if(preg_match('/\A[1-9][0-9]{0,19}\z/D',$key)!==1){throw new RuntimeException('task_history_key_refused');}
  $dao=new PhabricatorFeedStoryData();
  $data=queryfx_one($dao->establishConnection('r'),'SELECT * FROM %R WHERE chronologicalKey=%s',$dao,$key);
  if(!$data){throw new RuntimeException('task_history_story_missing');}
  $payload=json_decode($data['storyData']??'',true,16,JSON_THROW_ON_ERROR);
  if(($data['storyType']??null)!=='PhabricatorApplicationTransactionFeedStory'||
    ($payload['objectPHID']??null)!==$enqueued['task_phid']){
    throw new RuntimeException('task_history_stored_primary_object_refused');}
  // Deserialize only this exact owned key, using the real current recipient.
  $stories=PhabricatorFeedStory::loadAllFromRows(array($key=>$data),$recipient);
  foreach($stories as$story){if($story->getPrimaryObjectPHID()!==$enqueued['task_phid']){
    throw new RuntimeException('task_history_primary_object_refused');}}
  $visible=(new PhabricatorNotificationQuery())->setViewer($recipient)
    ->withUserPHIDs(array($recipient->getPHID()))->withKeys(array($key))->execute();
  foreach($visible as$story){if((string)$story->getChronologicalKey()!==$key||$story->getPrimaryObjectPHID()!==$enqueued['task_phid']){
    throw new RuntimeException('task_history_visible_binding_refused');}}
  return array('retained'=>true,'current_visible'=>(bool)$visible,'key'=>$key);
}
