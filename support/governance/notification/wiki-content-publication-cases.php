<?php
/** Real existing-document content edits. No move-mail, daemon or SMTP claim. */
function governance_private_mail_wiki_content_cases($fixture,callable $check,array $queue) {
  $fixture->assertOwned();
  require_once __DIR__.'/business-gorge-queue.php';
  require_once __DIR__.'/wiki-content-probe.php';
  if(($queue['kind']??null)!=='taskqueue'||!is_resource($queue['process']??null)||
    !proc_get_status($queue['process'])['running']||!PhabricatorGorgeTaskQueueClient::isConfigured()||
    PhabricatorGorgeTaskQueueClient::getConfiguredURI()!==$queue['uri']) {
    throw new RuntimeException('wiki_publication_owned_queue_required');
  }
  require_once __DIR__.'/wiki-render-service.php';
  $render=governance_wiki_render_start($fixture);
  try{
  $check(is_resource($render['process'])&&proc_get_status($render['process'])['running'],'private-wiki-content-paired55-render-owned-ready');
  $processes=0;$directories=0;$created=0;$live=0;
  $probe=governance_wiki_content_probe_factory($fixture,$queue,$render,$processes,$directories,$created,$live);
  $suffix=substr($fixture->getNamespace(),-10);$users=array();
  foreach(array('author','subscriber')as$role) {
    $user=(new PhabricatorUser())->setUsername('pmwiki'.$role.$suffix)
      ->setRealName('Synthetic Wiki publication '.$role)->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();$users[$role]=$user;
  }
  $auth=governance_native_request($users['author'],new PhabricatorAuthSessionEngine());
  $root=(new PhrictionDocumentQuery())->setViewer($auth['viewer'])->withSlugs(array('/'))->needContent(true)->executeOne();
  if(!$root) {
    $root=PhrictionDocument::initializeNewDocument($auth['viewer'],'/');
    $root->getApplicationTransactionEditor()->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])
      ->setIsSilent(true)->applyTransactions($root,array(
        (new PhrictionTransaction())->setTransactionType(PhrictionDocumentTitleTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic Wiki publication root'),
        (new PhrictionTransaction())->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)->setNewValue('Synthetic existing parent content')));
  }
  $check($root->getID()!==null&&PhabricatorPolicyFilter::hasCapability($auth['viewer'],$root,PhabricatorPolicyCapability::CAN_EDIT),
    'private-wiki-content-real-native-editable-root-precondition');
  $active=new PhabricatorWorkerActiveTask();$archive=new PhabricatorWorkerArchiveTask();$samples=array();
  foreach(array('normal','outer-commit','outer-rollback')as$ending) {
    $document=PhrictionDocument::initializeNewDocument($auth['viewer'],'synthetic-wiki-publication-'.$ending.'-'.$suffix.'/')
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy(PhabricatorPolicies::POLICY_USER);
    $document->getApplicationTransactionEditor()->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])
      ->setIsSilent(true)->applyTransactions($document,array(
        (new PhrictionTransaction())->setTransactionType(PhrictionDocumentTitleTransaction::TRANSACTIONTYPE)
          ->setNewValue('Synthetic Wiki publication '.$ending),
        (new PhrictionTransaction())->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)
          ->setNewValue('Synthetic prior nonempty content'),
        (new PhrictionTransaction())->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)
          ->setNewValue(array('+'=>array_fuse(array($users['subscriber']->getPHID()))))));
    $document=(new PhrictionDocumentQuery())->setViewer($auth['viewer'])->withPHIDs(array($document->getPHID()))
      ->needContent(true)->requireCapabilities(array(PhabricatorPolicyCapability::CAN_VIEW,PhabricatorPolicyCapability::CAN_EDIT))->executeOne();
    if(!$document){throw new RuntimeException('wiki_publication_real_edit_target_required');}
    $connection=$document->establishConnection('w');
    if($connection->isInsideTransaction()){throw new RuntimeException('wiki_publication_inherited_transaction');}
    $last=(int)queryfx_one($active->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$active)['n'];
    if($ending!=='normal'){$connection->openTransaction();}
    try {
      $enqueue_calls=0;$profiler=PhutilServiceProfiler::getInstance();
      $listeners=new ReflectionProperty(PhutilServiceProfiler::class,'listeners');$prior=$listeners->getValue($profiler);
      $profiler->addListener(static function($phase,$id,$event)use(&$enqueue_calls,$queue){
        if($phase==='end'&&($event['type']??null)==='http'&&($event['uri']??null)===$queue['uri'].'/api/queue/enqueue'){$enqueue_calls++;}
      });
      try {
        $applied=$document->getApplicationTransactionEditor()->setActor($auth['viewer'])
          ->setContentSourceFromRequest($auth['request'])->setContentVersion($document->getMaxVersion())
          ->setIsSilent(false)->applyTransactions($document,array((new PhrictionTransaction())
            ->setTransactionType(PhrictionDocumentContentTransaction::TRANSACTIONTYPE)->setNewValue('SYNTHETIC_PRIVATE_WIKI_CONTENT_EDIT')));
      } finally {$listeners->setValue($profiler,$prior);}
      $content=array_values(array_filter($applied,static function($transaction){
        return $transaction->getTransactionType()===PhrictionDocumentContentTransaction::TRANSACTIONTYPE;
      }));
      $check(count($content)===1&&!$content[0]->getIsCreateTransaction()&&
        $content[0]->getOldValue()==='Synthetic prior nonempty content'&&
        $content[0]->getNewValue()==='SYNTHETIC_PRIVATE_WIKI_CONTENT_EDIT'&&!$content[0]->shouldHideForMail($applied),
        'private-wiki-content-'.$ending.'-actual-existing-content-transaction-visible-for-mail');
      $queued=$active->loadAllWhere('id>%d AND taskClass=%s AND objectPHID=%s',$last,
        'PhabricatorApplicationTransactionPublishWorker',$document->getPHID());
      if(count($queued)!==1){throw new RuntimeException('wiki_publication_enqueue_cardinality');}$task=head($queued);
      $remote=(new PhabricatorGorgeTaskQueueClient())->getTask($task->getID());
      $check($enqueue_calls>=1&&is_array($remote)&&($remote['id']??null)===(int)$task->getID()&&
        ($remote['objectPHID']??null)===$document->getPHID()&&($remote['taskClass']??null)===$task->getTaskClass(),
        'private-wiki-content-'.$ending.'-native-editor-real-gorge-http-enqueue');
      $owner='business-gorge-'.bin2hex(random_bytes(16));
      $lease=governance_business_gorge_lease($fixture,$queue,(int)$task->getID(),$owner);
      $task=(new PhabricatorWorkerActiveTask())->load($lease['id']);
      if($ending==='normal'){
        $absent=$probe($task,$document,'execute-no-render');
        $still_queued=(new PhabricatorGorgeTaskQueueClient())->getTask($task->getID());
        $check($absent['handler_result']==='failure'&&$absent['missing_render_transient']===true&&
          $absent['publication_completed']===false&&$absent['envelopes']===array()&&$absent['native_gorge_prose_diff_calls']===0&&
          is_array($still_queued)&&($still_queued['id']??null)===(int)$task->getID()&&$archive->load((int)$task->getID())===null,
          'private-wiki-content-missing-render-transient-no-mail-or-success');
      }
      if($ending!=='normal') {
        $prepared=$probe($task,$document,'prepare');
        $check($prepared['handler_result']==='prepared'&&$prepared['source_visible']===true&&
          $prepared['native_wiki_content_present']===false&&$prepared['envelopes']===array()&&
          $prepared['publication_completed']===false&&$prepared['native_gorge_prose_diff_calls']===0&&$connection->isInsideTransaction(),
          'private-wiki-content-'.$ending.'-independent-prepare-sees-old-content-before-commit-no-mail');
        if($ending==='outer-commit'){$connection->saveTransaction();}else{$connection->killTransaction();}
      }
      $result=$probe($task,$document,'execute');$archived=$archive->load((int)$task->getID());
      $remaining=(new PhabricatorWorkerActiveTask())->load((int)$task->getID());
      if($ending==='outer-rollback') {
        $okay=$result['handler_result']==='permanent-failure'&&$result['native_gorge_prose_diff_calls']===0&&$result['native_publication_missing_source_permanent']===true&&
          $result['source_visible']===true&&$result['native_wiki_content_present']===false&&$result['envelopes']===array()&&
          $archived&&(int)$archived->getResult()===PhabricatorWorkerArchiveTask::RESULT_FAILURE&&$remaining===null;
      } else {
        $seen=array();$safe=true;
        foreach($result['envelopes']as$envelope) {
          $seen=array_merge($seen,$envelope['recipients']);
          $safe=$safe&&$envelope['recipient_count']===1&&$envelope['policy_authorized']===true&&$envelope['prepared']===true&&
            $envelope['no_private_producer_payload']===true&&$envelope['physical_gate_allowed']===true&&$envelope['quarantined']===false;
        }
        $okay=$result['handler_result']==='success'&&$result['native_gorge_prose_diff_calls']>=1&&$result['source_visible']===true&&$result['native_wiki_content_present']===true&&
          $safe&&count($result['envelopes'])>=1&&count($result['envelopes'])<=2&&
          array_diff($seen,array($users['author']->getPHID(),$users['subscriber']->getPHID()))===array()&&
          in_array($users['subscriber']->getPHID(),$seen,true)&&
          $archived&&(int)$archived->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS&&$remaining===null;
      }
      $check((bool)$okay,'private-wiki-content-'.$ending.'-native-publisher-current-source-and-fenced-outcome');
      $samples[$ending]=array('handler_result'=>$result['handler_result'],'safe_subscriber_mail'=>$ending!=='outer-rollback');
    } finally {if($connection->isInsideTransaction()){$connection->killTransaction();}}
  }
  $check($processes===6&&$directories===6&&$created===6&&$live===0,
    'private-wiki-content-owned-independent-workers-and-directories-clean');
  $summary=array('actual_existing_wiki_content_editor'=>true,'actual_paired_queue_and_native_handler'=>true,'actual_paired55_prose_diff'=>true,'missing_render_transient_no_mail_proved'=>true,
    'before_commit_probe_is_prepare_only'=>true,'real_before_commit_execute_race_proved'=>false,
    'move_mail_claimed'=>false,'automatic_retry_after_permanent_source_failure'=>false,
    'HTTP_gateway_proved'=>false,'Go_daemon_proved'=>false,'SMTP_proved'=>false,'samples'=>$samples,
    'independent_php_workers_executed'=>$processes,'private_directories_removed'=>$directories,
    'private_directories_remaining'=>$created-$directories,'live_processes_after_cleanup'=>$live);
  }finally{$cleanup=governance_wiki_render_stop($render);
    $check($cleanup['process_stopped']&&$cleanup['private_storage_removed'],'private-wiki-content-render-owned-process-storage-clean');}
  $summary['render_cleanup']=$cleanup;
  return$summary;
}
