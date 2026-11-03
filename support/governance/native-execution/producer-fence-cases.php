<?php

/** Actual independent SQL producers; not a mock or a whole-execution receipt. */
function governance_project_producer_fence_cases($fixture, callable $check, PhabricatorUser $actor) {
  $fixture->assertOwned(); $config=$fixture->getWriterConfig();
  $target=(new PhabricatorProject())->establishConnection('w');
  $writer=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
  $observer=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
  $mode=(new mysqli_driver())->report_mode;
  $fence=null; $hook=null; $rule_phid=null; $inserted_hook=null;
  $writer->set_charset('utf8mb4'); $observer->set_charset('utf8mb4');
  $writer->query('SET SESSION innodb_lock_wait_timeout=10');
  $wait_pending=function($sql,$scenario) use ($writer,$observer,$check) {
    $writer->query($sql,MYSQLI_ASYNC);
    $deadline=microtime(true)+4; $seen=false;
    do {
      foreach ($observer->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $row) {
        if ((int)$row['Id']===$writer->thread_id && $row['Info']===$sql) { $seen=true; break; }
      }
      if ($seen) { break; }
      usleep(20000);
    } while (microtime(true)<$deadline);
    $read=array($writer); $errors=array(); $reject=array();
    $pending=($seen && mysqli::poll($read,$errors,$reject,0,1000)===0);
    $check($pending,'project-producer-statement-running-and-pending-under-range-fence-'.$scenario);
  };
  $still_pending=function($scenario) use ($writer,$check) {
    $read=array($writer); $errors=array(); $reject=array();
    $check(mysqli::poll($read,$errors,$reject,0,1000)===0,
      'project-producer-fence-survives-real-target-commit-'.$scenario);
  };
  $finish=function($scenario) use ($writer,$check) {
    $read=array($writer); $errors=array(); $reject=array();
    $ready=mysqli::poll($read,$errors,$reject,5,0);
    $check($ready===1 && $writer->reap_async_query()===true,
      'project-producer-unblocks-only-after-independent-fence-release-'.$scenario);
  };
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    if (PhabricatorGorgeTaskQueueClient::isConfigured() ||
        (new ReflectionProperty(PhabricatorWorker::class,'runAllTasksInProcess'))->getValue()) {
      throw new RuntimeException('project_fence_fixture_background_refused');
    }
    $rule_phid=(new HeraldRule())->generatePHID();
    $rule_sql='INSERT INTO '.$fixture->table('herald','herald_rule').
      ' (name,authorPHID,contentType,mustMatchAll,configVersion,dateCreated,dateModified,repetitionPolicy,ruleType,phid,isDisabled) VALUES '.
      "('Synthetic fenced rule','".$writer->real_escape_string($actor->getPHID())."','".
      $writer->real_escape_string((new PhabricatorProjectHeraldAdapter())->getAdapterContentType()).
      "',1,38,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'every','global','".$writer->real_escape_string($rule_phid)."',1)";
    $target->openTransaction(); $fence=PhorgeProjectExecutionProducerFence::acquire($target);
    $receipt=$fence->getReceipt();
    $check($receipt['transaction_access_mode']==='READ WRITE' &&
      $receipt['sql_operations']==='fixed locking SELECT only' && !$receipt['whole_native_pipeline_atomic'],
      'project-producer-fence-honest-access-mode-and-atomicity-boundary');
    $wait_pending($rule_sql,'rule-insert'); $target->saveTransaction(); $still_pending('rule-insert');
    $fence->release(); $fence=null; $finish('rule-insert');
    $row=queryfx_one($target,'SELECT COUNT(*) n FROM %R WHERE BINARY phid=BINARY %s',new HeraldRule(),$rule_phid);
    $check((int)$row['n']===1,'project-native-rule-actually-inserted-after-fence-release');
    $target->openTransaction();
    $refused=false;
    try { $unexpected=PhorgeProjectExecutionProducerFence::acquire($target); $unexpected->release(); }
    catch (RuntimeException $error) {
      if ($error->getMessage()!=='project_execution_fence_producers_unsupported') { throw $error; }
      $refused=true;
    } finally { $target->killTransaction(); }
    $check($refused,'project-current-rule-profile-explicitly-unsupported');
    queryfx($target,'DELETE FROM %R WHERE BINARY phid=BINARY %s',new HeraldRule(),$rule_phid); $rule_phid=null;

    $hook=HeraldWebhook::initializeNewWebhook($actor)->setName('Synthetic fenced hook')
      ->setWebhookURI('https://fixture.invalid/no-delivery')->setStatus(HeraldWebhook::HOOKSTATUS_DISABLED)->save();
    $hook_id=$hook->getID();
    $target->openTransaction(); $fence=PhorgeProjectExecutionProducerFence::acquire($target);
    $sql='UPDATE '.$fixture->table('herald','herald_webhook')." SET status='firehose' WHERE id=".(int)$hook_id;
    $wait_pending($sql,'hook-status'); $target->saveTransaction(); $still_pending('hook-status');
    $fence->release(); $fence=null; $finish('hook-status');
    $row=queryfx_one($target,'SELECT status FROM %R WHERE id=%d',new HeraldWebhook(),$hook_id);
    $check($row['status']===HeraldWebhook::HOOKSTATUS_FIREHOSE,
      'project-existing-webhook-status-change-blocked-until-fence-release');
    $hook->delete(); $hook=null;

    $inserted_hook=(new HeraldWebhook())->generatePHID();
    $sql='INSERT INTO '.$fixture->table('herald','herald_webhook').
      ' (phid,name,webhookURI,viewPolicy,editPolicy,status,hmacKey,dateCreated,dateModified) VALUES '.
      "('".$writer->real_escape_string($inserted_hook)."','Synthetic inserted hook','https://fixture.invalid/no-delivery','users','no-one','firehose','".
      str_repeat('0',32)."',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())";
    $target->openTransaction(); $fence=PhorgeProjectExecutionProducerFence::acquire($target);
    $wait_pending($sql,'hook-insert'); $target->saveTransaction(); $still_pending('hook-insert');
    $fence->release(); $fence=null; $finish('hook-insert');
    $target->openTransaction(); $refused=false;
    try { $unexpected=PhorgeProjectExecutionProducerFence::acquire($target); $unexpected->release(); }
    catch (RuntimeException $error) {
      if ($error->getMessage()!=='project_execution_fence_producers_unsupported') { throw $error; }
      $refused=true;
    } finally { $target->killTransaction(); }
    $check($refused,'project-current-firehose-hook-profile-explicitly-unsupported');
    queryfx($target,'DELETE FROM %R WHERE BINARY phid=BINARY %s',new HeraldWebhook(),$inserted_hook); $inserted_hook=null;
    $check(!$target->isInsideTransaction(),'project-producer-fence-test-leaves-no-target-transaction');
  } finally {
    if ($fence!==null) { $fence->release(); }
    if ($target->isInsideTransaction()) { $target->killTransaction(); }
    // Closing an outstanding asynchronous statement cancels this owned socket.
    $writer->close(); $observer->close();
    if ($rule_phid!==null) { queryfx($target,'DELETE FROM %R WHERE BINARY phid=BINARY %s',new HeraldRule(),$rule_phid); }
    if ($hook!==null) { $hook->delete(); }
    if ($inserted_hook!==null) { queryfx($target,'DELETE FROM %R WHERE BINARY phid=BINARY %s',new HeraldWebhook(),$inserted_hook); }
    mysqli_report($mode);
  }
  return array('actual_native_producer_range_waits'=>true,'actual_native_webhook_status_wait'=>true,
    'primary_target_transaction_not_wrapped'=>true,'native_postcommit_execution_not_yet_tested'=>true);
}
