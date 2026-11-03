<?php
/** Actual AppEditor publication ordering; SQL queue profile explicitly scoped. */
function governance_private_mail_business_publication_cases($fixture,callable $check,array $queue){
  unset($GLOBALS['governance_private_business_child_failure']);
  $fixture->assertOwned();
  if(($queue['kind']??null)!=='taskqueue'||!is_resource($queue['process']??null)||!proc_get_status($queue['process'])['running']||
    !preg_match('/\Ahttp:\/\/127\.0\.0\.1:[1-9][0-9]{0,4}\z/D',$queue['uri']??'')||
    !preg_match('/\A[a-f0-9]{64}\z/D',$queue['token']??'')){throw new RuntimeException('business_publication_real_queue_required');}
  $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('gorge.taskqueue.owner','phorge')->overrideEnvConfig('gorge.taskqueue.uri',null);
  try{
  $processes=0;$directories=0;$created=0;$live=0;
  $probe=static function($task,$object)use($fixture,$queue,&$processes,&$directories,&$created,&$live){
    $directory=sys_get_temp_dir().'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));$owner=bin2hex(random_bytes(32));
    if(!mkdir($directory,0700)){throw new RuntimeException('private_mail_race_directory_failed');}
    $created++;$stat=lstat($directory);$process=null;$pipes=array();
    try{
      $write=static function($name,$value)use($directory){$path=$directory.'/'.$name;if(file_put_contents($path,$value)===false||!chmod($path,0600)){throw new RuntimeException('private_mail_race_private_write_failed');}};
      $write('owner',$owner);$row=$fixture->getAdmin()->query('SELECT owner_nonce FROM '.$fixture->table('governance','fixture_guard').' WHERE id=1')->fetch_assoc();
      $data=array('callback_origin'=>'http://127.0.0.1:1','file_token'=>PhabricatorEnv::getEnvConfig('gorge.file.token'),
        'file_uri'=>PhabricatorEnv::getEnvConfig('gorge.file.uri'),'fixture_nonce'=>$row['owner_nonce'],'mailer_token'=>bin2hex(random_bytes(32)),
        'mailer_uri'=>'http://127.0.0.1:1','owner'=>$owner,'service_token'=>bin2hex(random_bytes(32)),
        'source_sha256'=>hash_file('sha256',dirname(phutil_get_library_root('phabricator')).'/resources/sql/quickstart.sql'),
        'writer'=>$fixture->getWriterConfig());
      $write('queue.json',json_encode(array('owner'=>$owner,'uri'=>$queue['uri'],'token'=>$queue['token']),JSON_THROW_ON_ERROR));
      $write('router.json',json_encode($data,JSON_THROW_ON_ERROR));$write('probe.json',json_encode(array('owner'=>$owner,'task_id'=>(int)$task->getID(),'object_phid'=>$object->getPHID()),JSON_THROW_ON_ERROR));
      $process=proc_open(array(PHP_BINARY,__DIR__.'/business-publication-worker.php',$directory),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
      if(!is_resource($process)){throw new RuntimeException('private_mail_race_spawn_failed');}$processes++;$live++;fclose($pipes[0]);unset($pipes[0]);
      foreach($pipes as$pipe){stream_set_blocking($pipe,false);}$out='';$err='';$deadline=microtime(true)+15;$exit=null;
      do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);
        if(strlen($out)>16384||strlen($err)>4096){throw new RuntimeException('private_mail_race_output_budget');}
        if(!$status['running']){$exit=$status['exitcode'];break;}if(microtime(true)>$deadline){throw new RuntimeException('private_mail_race_worker_timeout');}usleep(20000);
      }while(true);
      $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);foreach($pipes as$pipe){fclose($pipe);}$pipes=array();proc_close($process);$process=null;$live--;
      $result=json_decode($out,true,8,JSON_THROW_ON_ERROR);
      if($exit!==0||$err!==''||!is_array($result)||($result['passed']??null)!==true||($result['actual_owned_taskqueue_http_ready']??null)!==true){
        // Only bounded diagnostic metadata reaches the failed fixture receipt.
        // Never retain the child's message, stderr, envelope or credentials.
        $diagnostic=array('exit_code'=>(int)$exit,'stderr_present'=>$err!=='');
        $child=is_array($result)?($result['failure']??null):null;
        if(is_array($child)&&is_string($child['type']??null)&&preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/D',$child['type'])&&
          is_string($child['file']??null)&&preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D',$child['file'])&&
          is_int($child['line']??null)&&$child['line']>0&&$child['line']<100000){
          $diagnostic['error_type']=$child['type'];$diagnostic['source_file']=$child['file'];$diagnostic['source_line']=$child['line'];
        }
        $GLOBALS['governance_private_business_child_failure']=$diagnostic;
        throw new RuntimeException('private_mail_race_worker_failed');}
      return$result;
    }finally{
      if(is_resource($process)){proc_terminate($process);foreach($pipes as$pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($process);$live--;}
      $current=lstat($directory);if(!$current||$current['dev']!==$stat['dev']||$current['ino']!==$stat['ino']||is_link($directory)){throw new RuntimeException('private_mail_race_cleanup_owner_refused');}
      foreach(array('owner','router.json','probe.json','queue.json')as$name){$path=$directory.'/'.$name;if(file_exists($path)){if(is_link($path)||!unlink($path)){throw new RuntimeException('private_mail_race_cleanup_failed');}}}
      if(!rmdir($directory)){throw new RuntimeException('private_mail_race_cleanup_failed');}$directories++;
    }
  };


  $suffix=substr($fixture->getNamespace(),-10);$users=array();
  foreach(array('author','owner','subscriber')as$role){$u=(new PhabricatorUser())->setUsername('pmbusiness'.$role.$suffix)
    ->setRealName('Synthetic publication '.$role)->setIsApproved(1)->setIsEmailVerified(1)->save();
    (new PhabricatorUserEmail())->setUserPHID($u->getPHID())->setAddress($u->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();$users[$role]=$u;}
  $auth=governance_native_request($users['author'],new PhabricatorAuthSessionEngine());$samples=array();
  $tasks=new PhabricatorWorkerActiveTask();
  foreach(array('task','project')as$family){foreach(array('normal','outer-commit','outer-rollback')as$ending){
    $object=$family==='task'?ManiphestTask::initializeNewTask($auth['viewer'])->setOwnerPHID($users['owner']->getPHID()):
      PhabricatorProject::initializeNewProject($auth['viewer']);
    $object->setViewPolicy(PhabricatorPolicies::POLICY_USER);
    $tx=$family==='task'?new ManiphestTransaction():new PhabricatorProjectTransaction();
    $name=clone $tx;$name->setTransactionType($family==='task'?ManiphestTaskTitleTransaction::TRANSACTIONTYPE:PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue('Synthetic business '.$family.' '.$ending.' '.$suffix);
    $comment=clone $tx;$comment->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
      ->attachComment($comment->getApplicationTransactionCommentObject()->setContent('SYNTHETIC_PRIVATE_BUSINESS_COMMENT'));
    $changes=array($name,$comment);
    if($family==='task'){$changes[]=(clone $tx)->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)->setNewValue(array('+' => array_fuse(array($users['subscriber']->getPHID()))));}
    $last=(int)queryfx_one($tasks->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$tasks)['n'];
    $connection=$object->establishConnection('w');if($connection->isInsideTransaction()){throw new RuntimeException('business_publication_inherited_context_refused');}
    $outer=$ending!=='normal';if($outer){$connection->openTransaction();}
    try{
      $editor=$object->getApplicationTransactionEditor()->setActor($auth['viewer'])->setContentSourceFromRequest($auth['request'])->setIsSilent(false);
      $editor->applyTransactions($object,$changes);
      $queued=$tasks->loadAllWhere('id>%d AND taskClass=%s AND objectPHID=%s ORDER BY id',$last,'PhabricatorApplicationTransactionPublishWorker',$object->getPHID());
      if(count($queued)!==1){throw new RuntimeException('business_publication_actual_queue_cardinality_refused');}$task=head($queued);
      $first=$probe($task,$object);
      if($outer){
        $check($connection->isInsideTransaction()&&$first['source_visible']===false&&$first['native_publication_missing_source_permanent']===true&&
          $first['publication_completed']===false&&$first['envelopes']===array(),
          'private-business-'.$family.'-'.$ending.'-independent-actual-worker-before-outer-commit-missing-source-not-mail-quarantine');
        if($ending==='outer-commit'){$connection->saveTransaction();}else{$connection->killTransaction();}
        // This direct second execution is a diagnostic, not a claim that a
        // daemon retries tasks classified permanently failed on first attempt.
        $second=$probe($task,$object);
      }else{$second=$first;}
      if($ending==='outer-rollback'){
        $check($second['source_visible']===false&&$second['native_publication_missing_source_permanent']===true&&$second['envelopes']===array(),
          'private-business-'.$family.'-outer-rollback-orphan-worker-cannot-create-mail');
      }else{
        $seen=array();$safe=true;foreach($second['envelopes']as$envelope){$safe=$safe&&$envelope['recipient_count']===1&&
          $envelope['policy_authorized']===true&&$envelope['prepared']===true&&$envelope['no_private_producer_payload']===true&&$envelope['physical_gate_allowed']===true&&$envelope['quarantined']===false;$seen=array_merge($seen,$envelope['recipients']);}
        $required=$family==='task'?array($users['owner']->getPHID(),$users['subscriber']->getPHID()):array($users['author']->getPHID());
        $check($second['source_visible']===true&&$second['publication_completed']===true&&$safe&&
          count($second['envelopes'])>=count($required)&&array_diff($required,$seen)===array(),
          'private-business-'.$family.'-'.$ending.'-actual-publisher-current-source-and-isolated-native-recipient-envelopes');
      }

      if($family==='task'&&$ending!=='outer-rollback'){
        $target=array();foreach($second['envelopes']as$envelope){foreach($envelope['recipients']as$recipient){$target[$recipient]=$envelope['mail_id'];}}
        $object->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
        try{$gates=array();foreach(array('owner','subscriber')as$role){$notice=(new PhabricatorMetaMTAMail())->load($target[$users[$role]->getPHID()]);$snapshot=$notice->getParameters()['gorge.delivery-snapshot'];
          $gates[$role]=$notice->authorizeGorgePhysicalDelivery($snapshot['deliveryID'],$snapshot['authorization']['messageHash'],phutil_json_encode($snapshot['message']))['allowed'];}
          $check($gates['owner']===true&&$gates['subscriber']===false,
            'private-business-task-'.$ending.'-fanout-current-native-owner-automatic-and-revoked-subscriber-are-independent');
        }finally{$object->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();}
      }
      $samples[$family][$ending]=array('precompletion_source_visible'=>$first['source_visible'],
        'precompletion_native_permanent_failure'=>$first['native_publication_missing_source_permanent'],
        'postcompletion_source_visible'=>$second['source_visible'],'postcompletion_mail_envelopes'=>count($second['envelopes']));
    }finally{if($connection->isInsideTransaction()){$connection->killTransaction();}}
  }}
  return array('actual_native_sql_queue_profile'=>true,'actual_child_native_mail_taskqueue_http_proved'=>true,'paired_gorge_queue_profile_proved'=>false,
    'actual_task_and_project_editors'=>true,'samples'=>$samples,
    'independent_php_workers_executed'=>$processes,'private_directories_removed'=>$directories,
    'private_directories_remaining'=>$created-$directories,'live_processes_after_cleanup'=>$live,'manual_diagnostic_reexecution_is_daemon_retry'=>false,
    'physical_smtp_proved'=>false,'production_permission_flags_changed'=>false);

  }finally{unset($scope);}
}
