<?php
/** Separate real Gorge publication profile. No worker daemon or SMTP claim. */
function governance_private_mail_business_gorge_cases($fixture,callable $check,array $queue){
  $fixture->assertOwned();
  require_once __DIR__.'/business-gorge-queue.php';
  require_once __DIR__.'/business-gorge-probe.php';
  if(($queue['kind']??null)!=='taskqueue'||!is_resource($queue['process']??null)||!proc_get_status($queue['process'])['running']||
    !PhabricatorGorgeTaskQueueClient::isConfigured()||PhabricatorGorgeTaskQueueClient::getConfiguredURI()!==$queue['uri']){
    throw new RuntimeException('business_gorge_real_owned_queue_required');
  }
  $processes=0;$directories=0;$created=0;$live=0;
  $probe=governance_business_gorge_probe_factory($fixture,$queue,$processes,$directories,$created,$live);
  $suffix=substr($fixture->getNamespace(),-10);$users=array();
  foreach(array('author','owner','subscriber')as$role){
    $user=(new PhabricatorUser())->setUsername('pmgorge'.$role.$suffix)->setRealName('Synthetic Gorge publication '.$role)
      ->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
      ->setIsPrimary(1)->setIsVerified(1)->save();$users[$role]=$user;
  }
  $auth=governance_native_request($users['author'],new PhabricatorAuthSessionEngine());
  $tasks=new PhabricatorWorkerActiveTask();$archive=new PhabricatorWorkerArchiveTask();$samples=array();
  foreach(array('task','project')as$family){foreach(array('normal','outer-commit','outer-rollback')as$ending){
    $object=$family==='task'?ManiphestTask::initializeNewTask($auth['viewer'])->setOwnerPHID($users['owner']->getPHID()):
      PhabricatorProject::initializeNewProject($auth['viewer']);
    $object->setViewPolicy(PhabricatorPolicies::POLICY_USER);
    $tx=$family==='task'?new ManiphestTransaction():new PhabricatorProjectTransaction();
    $name=(clone $tx)->setTransactionType($family==='task'?ManiphestTaskTitleTransaction::TRANSACTIONTYPE:PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue('Synthetic business gorge '.$family.' '.$ending.' '.$suffix);
    $content=clone $tx;
    if($family==='task'){
      $content->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
        ->attachComment($content->getApplicationTransactionCommentObject()->setContent('SYNTHETIC_PRIVATE_BUSINESS_COMMENT'));
    }else{
      // Projects have native description custom fields, not comment objects.
      $fields=PhabricatorCustomField::getObjectFields($object,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS);
      $fields->readFieldsFromStorage($object);
      $field=PhabricatorCustomField::getObjectField($object,PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS,
        'std:project:internal:description');
      if(!$field){throw new RuntimeException('business_publication_native_project_description_missing');}
      $content->setTransactionType(PhabricatorTransactions::TYPE_CUSTOMFIELD)
        ->setMetadataValue('customfield:key',$field->getFieldKey())
        ->setOldValue($field->getOldValueForApplicationTransactions())->setNewValue('SYNTHETIC_PRIVATE_BUSINESS_COMMENT');
    }
    // Native Web and Conduit creation both emit this real core transaction.
    $create=(clone $tx)->setTransactionType(PhabricatorTransactions::TYPE_CREATE);
    $changes=array($create,$name,$content);
    if($family==='task'){$changes[]=(clone $tx)->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)
      ->setNewValue(array('+'=>array_fuse(array($users['subscriber']->getPHID()))));}
    $last=(int)queryfx_one($tasks->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$tasks)['n'];
    $connection=$object->establishConnection('w');if($connection->isInsideTransaction()){throw new RuntimeException('business_gorge_inherited_transaction');}
    if($ending!=='normal'){$connection->openTransaction();}
    try{
      $enqueue_calls=0;$profiler=PhutilServiceProfiler::getInstance();
      $listeners=new ReflectionProperty(PhutilServiceProfiler::class,'listeners');$prior_listeners=$listeners->getValue($profiler);
      $profiler->addListener(static function($phase,$id,$event)use(&$enqueue_calls,$queue){
        if($phase==='end'&&($event['type']??null)==='http'&&($event['uri']??null)===$queue['uri'].'/api/queue/enqueue'){$enqueue_calls++;}
      });
      try{$applied=$object->getApplicationTransactionEditor()->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])
        ->setIsSilent(false)->applyTransactions($object,$changes);
      }finally{$listeners->setValue($profiler,$prior_listeners);}
      if($family==='project'){
        $creates=0;$visible_creates=0;$fields=0;$hidden_fields=0;$visible_total=0;
        foreach($applied as$applied_transaction){
          $type=$applied_transaction->getTransactionType();$hidden=$applied_transaction->shouldHideForMail($applied);
          if(!$hidden){$visible_total++;}
          if($type===PhabricatorTransactions::TYPE_CREATE){$creates++;if(!$hidden){$visible_creates++;}}
          if(in_array($type,array(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,PhabricatorTransactions::TYPE_CUSTOMFIELD),true)){
            $fields++;if($applied_transaction->getIsCreateTransaction()&&$hidden){$hidden_fields++;}}
        }
        $check($creates===1&&$visible_creates===1&&$visible_total===1&&$fields===2&&$hidden_fields===2,
          'private-business-gorge-project-'.$ending.'-actual-native-create-envelope-visible-initial-fields-hidden');
      }
      $queued=$tasks->loadAllWhere('id>%d AND taskClass=%s AND objectPHID=%s',$last,'PhabricatorApplicationTransactionPublishWorker',$object->getPHID());
      if(count($queued)!==1){throw new RuntimeException('business_gorge_enqueue_cardinality');}$task=head($queued);
      $remote=(new PhabricatorGorgeTaskQueueClient())->getTask($task->getID());
      $check($enqueue_calls>=1&&is_array($remote)&&($remote['id']??null)===(int)$task->getID()&&($remote['taskClass']??null)===$task->getTaskClass()&&
        ($remote['objectPHID']??null)===$object->getPHID(),
        'private-business-gorge-'.$family.'-'.$ending.'-native-editor-http-enqueue-real-task');
      $owner='business-gorge-'.bin2hex(random_bytes(16));
      $leased=governance_business_gorge_lease($fixture,$queue,(int)$task->getID(),$owner);
      $task=(new PhabricatorWorkerActiveTask())->load($leased['id']);
      if($ending==='normal'){
        // An explicit fenced yield is not a claim that source denial retries.
        governance_business_gorge_http($queue,'resolve',array('taskID'=>$leased['id'],'leaseOwner'=>$owner,
          'leaseExpires'=>$leased['leaseExpires'],'outcome'=>'yield','duration'=>0),$owner);
        $awakened=(new PhabricatorGorgeTaskQueueClient())->awaken(array((int)$task->getID()));
        if(!is_array($awakened)||($awakened['awakened']??null)!==1){throw new RuntimeException('business_gorge_awaken_not_proved');}
        $leased=governance_business_gorge_lease($fixture,$queue,(int)$task->getID(),$owner);
        $task=(new PhabricatorWorkerActiveTask())->load($leased['id']);
        $check($leased['id']===(int)$task->getID()&&$task->getLeaseOwner()===$owner,
          'private-business-gorge-'.$family.'-fenced-yield-awaken-same-task-released-again');
      }else{
        $prepared=$probe($task,$object,'prepare');
        $check($prepared['handler_result']==='prepared'&&$prepared['source_visible']===false&&$prepared['envelopes']===array()&&
          $prepared['publication_completed']===false&&$connection->isInsideTransaction(),
          'private-business-gorge-'.$family.'-'.$ending.'-real-handler-prepare-before-source-commit-no-mail');
        if($ending==='outer-commit'){$connection->saveTransaction();}else{$connection->killTransaction();}
      }
      $result=$probe($task,$object,'execute');
      $archived=$archive->load((int)$task->getID());
      $active=(new PhabricatorWorkerActiveTask())->load((int)$task->getID());
      if($ending==='outer-rollback'){
        $okay=$result['handler_result']==='permanent-failure'&&$result['native_publication_missing_source_permanent']===true&&
          $result['source_visible']===false&&$result['envelopes']===array()&&$archived&&
          (int)$archived->getResult()===PhabricatorWorkerArchiveTask::RESULT_FAILURE&&$active===null;
      }else{
        $seen=array();$safe=true;
        foreach($result['envelopes']as$envelope){$seen=array_merge($seen,$envelope['recipients']);
          $safe=$safe&&$envelope['recipient_count']===1&&$envelope['policy_authorized']===true&&$envelope['prepared']===true&&
            $envelope['no_private_producer_payload']===true&&$envelope['physical_gate_allowed']===true&&$envelope['quarantined']===false;}
        $required=$family==='task'?array($users['owner']->getPHID(),$users['subscriber']->getPHID()):array($users['author']->getPHID());
        $okay=$result['handler_result']==='success'&&$result['source_visible']===true&&$safe&&
          ($family!=='project'||($result['native_project_description_present']??null)===true)&&
          count($result['envelopes'])>=count($required)&&array_diff($required,$seen)===array()&&$archived&&
          (int)$archived->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS&&$active===null;
      }
      $check((bool)$okay,'private-business-gorge-'.$family.'-'.$ending.'-native-handler-and-fenced-queue-outcome-current-source');
      $samples[$family][$ending]=array('handler_result'=>$result['handler_result'],'mail_envelopes'=>count($result['envelopes']));
    }finally{if($connection->isInsideTransaction()){$connection->killTransaction();}}
  }}
  return array('actual_native_project_create_envelope_verified'=>true,'actual_native_editor_http_enqueue'=>true,'actual_service_leases_and_fenced_outcomes'=>true,
    'actual_native_conduit_handler'=>true,'worker_execute_http_gateway_proved'=>false,'gorge_worker_daemon_proved'=>false,
    'explicit_yield_retry_proved'=>true,'permanent_source_denial_automatically_retried'=>false,
    'physical_smtp_proved'=>false,'samples'=>$samples,'independent_php_workers_executed'=>$processes,
    'private_directories_removed'=>$directories,'private_directories_remaining'=>$created-$directories,'live_processes_after_cleanup'=>$live);
}
