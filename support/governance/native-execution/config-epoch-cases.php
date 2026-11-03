<?php

/** Native baseline-compatible DAO/CLI/editor generation tests, features off. */
function governance_project_config_epoch_cases($fixture,callable $check,PhabricatorUser $admin,array $state=array()) {
  $fixture->assertOwned();
  $config=(new PhabricatorConfigEntry())->establishConnection('w');
  $approval=null;
  if ($state) {
    foreach (array('default-null'=>null,'native-string'=>'3306') as $profile=>$port) {
      $port_override=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('mysql.port',$port);
      try {
        // These real calls acquire both fenced configuration and an independent
        // live producer-fence connection using the native port representation.
        $port_ticket=PhorgeProjectPolicyApprovalService::call($state['executor']['context'],'submit',array(
          'target_phid'=>$state['project']->getPHID(),'new_policy'=>$state['executor']['viewer']->getPHID(),
          'reason'=>'Synthetic native port representation','ttl_seconds'=>120));
        $port_approved=PhorgeProjectPolicyApprovalService::call($state['approver']['context'],'approve',array(
          'approval_id'=>$port_ticket['approval_id']));
        $stored=queryfx_one($state['project']->establishConnection('w'),'SELECT state FROM governance_projectapproval WHERE approvalID=%s',$port_ticket['approval_id']);
        $check($port_approved['approval_id']===$port_ticket['approval_id'] && $stored['state']==='approved','project-approval-native-mysql-port-'.$profile.'-actual-fences');
      } finally { unset($port_override); }
    }

    $approval=PhorgeProjectPolicyApprovalService::call($state['executor']['context'],'submit',array(
      'target_phid'=>$state['project']->getPHID(),'new_policy'=>$state['executor']['viewer']->getPHID(),
      'reason'=>'Synthetic config epoch ABA refusal','ttl_seconds'=>120));
  }
  $override=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false)
    ->overrideEnvConfig('governance.organization.enabled',false)->overrideEnvConfig('governance.media-exchange.enabled',false);
  $key='policy.allow-public';
  $epoch=function() use ($config,$key) {
    $row=queryfx_one($config,'SELECT epoch FROM %R WHERE namespace=%s AND configKey=%s',new PhorgeProjectConfigEpoch(),'default',$key);
    if (!$row) { throw new RuntimeException('configuration_generation_fixture_schema_required'); }
    return PhorgeProjectConfigEpochScope::canonicalEpoch($row['epoch']);
  };
  $cli=function($delete,$value=null) use ($key) {
    $workflow=$delete?new PhabricatorConfigManagementDeleteWorkflow():new PhabricatorConfigManagementSetWorkflow();
    $argv=array('config','--database',$key);
    if (!$delete) { $argv[]=$value; }
    $args=new PhutilArgumentParser($argv); $args->parseFull($workflow->getArguments());
    $status=$workflow->execute($args);
    // Native PhutilArgumentParser exits with ($status ?? 0). Delete's native
    // implementation returns null; set returns integer zero.
    if (!in_array($status,array(0,null),true)) { throw new RuntimeException('configuration_generation_native_cli_failed'); }
  };
  $before=$epoch();
  $cli(false,'true'); $set=$epoch(); $cli(true); $deleted=$epoch();
  $check((int)$set===(int)$before+1 && (int)$deleted===(int)$before+2,
    'native-config-generation-real-cli-set-delete-advances-while-features-off');
  $entry=PhabricatorConfigEntry::loadConfigEntry($key);
  $source=PhabricatorContentSource::newForSource('console',array());
  PhabricatorConfigEditor::storeNewValue($admin,$entry,false,$source);
  $ui=$epoch();
  $check((int)$ui===(int)$deleted+1 && $entry->getIsDeleted()==0 && $entry->getValue()===false,
    'native-config-generation-real-ui-editor-common-save-seam');
  $entry->setValue(true)->save(); $direct=$epoch();
  $entry->setValue(false)->save(); $back=$epoch();
  $check((int)$direct===(int)$ui+1 && (int)$back===(int)$ui+2,
    'native-config-generation-direct-native-dao-aba-monotonic');
  $entry->setValue(true)->update(); $explicit_update=$epoch();
  (new PhabricatorConfigEntry())->setNamespace('default')->setConfigKey($key)
    ->setIsDeleted(0)->setValue(false)->replace();
  $explicit_replace=$epoch();
  $check((int)$explicit_update===(int)$back+1 && (int)$explicit_replace===(int)$back+2,
    'native-config-generation-direct-update-and-replace-advance-once');
  // Native REPLACE can delete two distinct rows via tuple and PHID uniques.
  $other=(new PhabricatorConfigEntry())->setNamespace('default')->setConfigKey('phabricator.show-prototypes')
    ->setIsDeleted(0)->setValue(false)->insert();
  $other_before=queryfx_one($config,'SELECT epoch FROM %R WHERE namespace=%s AND configKey=%s',
    new PhorgeProjectConfigEpoch(),'default','phabricator.show-prototypes')['epoch'];
  (new PhabricatorConfigEntry())->setNamespace('default')->setConfigKey($key)
    ->setPHID($other->getPHID())->setIsDeleted(0)->setValue(false)->replace();
  $other_after=queryfx_one($config,'SELECT epoch FROM %R WHERE namespace=%s AND configKey=%s',
    new PhorgeProjectConfigEpoch(),'default','phabricator.show-prototypes')['epoch'];
  $check((int)$epoch()===(int)$explicit_replace+1 && (int)$other_after===(int)$other_before+1 &&
    (new PhabricatorConfigEntry())->loadOneWhere('namespace=%s AND configKey=%s','default','phabricator.show-prototypes')===null,
    'native-config-generation-replace-binds-tuple-and-phid-collision-victims');
  $entry=PhabricatorConfigEntry::loadConfigEntry($key); $back=$epoch();
  $mode=(new mysqli_driver())->report_mode; $trigger='`'.$fixture->getNamespace().'_config`.`governance_config_epoch_fail`'; $installed=false;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $setup=$fixture->getAdmin();
    $setup->query('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON '.$fixture->table('config','governance_configepoch').
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated-config-epoch-failure'");
    $installed=true;
    $rows=$setup->query("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
      $setup->real_escape_string($fixture->getNamespace().'_config')."' AND TRIGGER_NAME='governance_config_epoch_fail'")->fetch_assoc();
    $check((int)$rows['n']===1,'native-config-generation-owned-fault-trigger-installed');
    $failed=false;
    try { $entry->setValue(true)->save(); }
    catch (Throwable $error) {
      if (!in_array(get_class($error),array(AphrontQueryException::class,mysqli_sql_exception::class),true) ||
          $error->getCode()!==1644 || !preg_match('/\A(?:#1644:\s*)?isolated-config-epoch-failure\z/',$error->getMessage())) { throw $error; }
      $failed=true;
    }
    $stored=PhabricatorConfigEntry::loadConfigEntry($key);
    $check($failed && $stored->getValue()===false && $epoch()===$back && !$config->isInsideTransaction() &&
      !$config->isReadLocking() && !$config->isWriteLocking(),
      'native-config-generation-fault-rolls-back-native-value-and-epoch-with-no-lock-leak');
    // Existing callers retain their own outer transaction and write-lock mode.
    $config->openTransaction(); $config->beginWriteLocking();
    try {
      $failed=false;
      try { PhabricatorConfigEntry::loadConfigEntry($key)->setValue(true)->save(); }
      catch (Throwable $error) {
        if (!in_array(get_class($error),array(AphrontQueryException::class,mysqli_sql_exception::class),true) ||
            $error->getCode()!==1644 || !preg_match('/\A(?:#1644:\s*)?isolated-config-epoch-failure\z/',$error->getMessage())) { throw $error; }
        $failed=true;
      }
      $native_transaction_state=(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($config);
      $check($failed && $native_transaction_state->getDepth()===1 && $config->isWriteLocking() &&
        PhabricatorConfigEntry::loadConfigEntry($key)->getValue()===false && $epoch()===$back,
        'native-config-generation-fault-preserves-external-native-transaction-and-lock-level');
    } finally { $config->endWriteLocking(); $config->killTransaction(); }
  } finally {
    try {
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      if ($installed) { $fixture->getAdmin()->query('DROP TRIGGER '.$trigger); }
    } finally { mysqli_report($mode); }
  }
  $check(!PhorgeProjectExecutionCatalog::enabled() &&
    PhabricatorEnv::getEnvConfig('governance.organization.enabled')===false &&
    PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')===false,
    'native-config-generation-always-on-with-all-governance-features-disabled');
  // Physical native DAO deletion also advances; it never resets the seeded row.
  PhabricatorConfigEntry::loadConfigEntry($key)->delete();
  $check((int)$epoch()===(int)$back+1,'native-config-generation-physical-native-dao-delete-advances');
  if ($approval!==null) {
    $override->overrideEnvConfig('governance.project-approval.enabled',true);
    $failed=false;
    try { PhorgeProjectPolicyApprovalService::call($state['approver']['context'],'approve',array('approval_id'=>$approval['approval_id'])); }
    catch (RuntimeException $error) {
      if ($error->getMessage()!=='project_execution_sources_stale') { throw $error; }
      $failed=true;
    }
    $project_writer=$state['project']->establishConnection('w');
    $ticket=queryfx_one($project_writer,'SELECT state FROM governance_projectapproval WHERE approvalID=%s',$approval['approval_id']);
    $check($failed && $ticket['state']==='submitted' &&
      (new PhabricatorConfigEntry())->loadOneWhere('namespace=%s AND configKey=%s','default',$key)===null,
      'project-approval-native-config-aba-while-feature-off-invalidates-old-ticket-after-values-restored');
  }
  return array('actual_cli_set_delete'=>true,'actual_config_editor'=>true,'actual_native_dao'=>true,
    'features_off_aba'=>true,'stale_approval_case_exercised'=>$approval!==null,'transaction_rollback'=>true,'optional_backend_compatibility_claimed'=>false);
}
