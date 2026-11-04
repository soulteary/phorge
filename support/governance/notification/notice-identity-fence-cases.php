<?php
function governance_notice_identity_missing_table(Throwable$error,$schema){
  return get_class($error)===AphrontSchemaQueryException::class&&$error->getCode()===0&&
    $error->getMessage()==="#1146: Table '".$schema.".governance_notice_identityepoch' doesn't exist";
}
/** Actual native User writes; no synthetic epoch increment stands in for a producer. */
function governance_notice_identity_fence_cases($fixture,callable$check,PhabricatorUser$user,array$auth){
  $fixture->assertOwned();$conn=$user->establishConnection('w');$schema=$fixture->getNamespace().'_user';
  $table='`'.$schema.'`.`governance_notice_identityepoch`';$hidden='`'.$schema.'`.`notice_fixture_hidden_generation`';
  $trigger='`'.$schema.'`.`notice_identity_owned_fault`';$trigger_installed=false;$renamed=false;$engine_changed=false;
  $primary=null;$scope=null;$errors=array();
  $generation=static function()use($conn,$user){$row=queryfx_one($conn,'SELECT generation FROM governance_notice_identityepoch WHERE userPHID=%s',$user->getPHID());return PhorgeGovernanceNoticeIdentityScope::generation($row?$row['generation']:'0');};
  $state=static function()use($conn,$user){return queryfx_one($conn,'SELECT isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList,accountSecret,conduitCertificate FROM %R WHERE id=%d',new PhabricatorUser(),$user->getID());};
  $fresh=static function()use($user){$fresh=(new PhabricatorUser())->load($user->getID());if(!$fresh||$fresh->getPHID()!==$user->getPHID()){throw new RuntimeException('notice_identity_fixture_user_missing');}return$fresh;};
  $notice_number=0;
  $notice=static function()use($fixture,$user,$conn,&$notice_number,$fresh){
    $viewer=$fresh();$auth=governance_native_request($viewer,new PhabricatorAuthSessionEngine());
    $operation='notice-identity-control-'.(++$notice_number);
    PhorgeOrganizationService::newForActor($auth['context'])->createUnit(array('operation_id'=>$operation,'unit_id'=>$operation,'kind'=>'team','name'=>'Synthetic notice identity control','parent_id'=>null));
    $meta=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
    $event=queryfx_one($meta,'SELECT eventID FROM governance_notice_event WHERE operationDigest=%s',hash('sha256',$operation));
    if(!$event){throw new RuntimeException('notice_identity_fixture_event_missing');}
    (new PhorgeGovernanceNoticeWorker(array('version'=>1,'event_id'=>$event['eventID'])))->executeTask();
    $map=queryfx_one($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s AND recipientPHID=%s',$event['eventID'],$user->getPHID());
    if(!$map){throw new RuntimeException('notice_identity_fixture_mapping_missing');}
    $mail=(new PhabricatorMetaMTAMail())->loadOneWhere('BINARY phid=BINARY %s',$map['mailPHID']);
    if(!$mail||!PhorgePrivateMailPolicy::authorize($mail)){throw new RuntimeException('notice_identity_fixture_new_binding_refused');}
    return$mail;
  };
  $raw=static function($field,$value)use($fresh){$target=$fresh();$target->{'set'.ucfirst($field)}($value)->save();};
  $runtime_refusal=static function(callable$call,$reason){try{$call();}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!==$reason){throw$error;}return true;}return false;};
  try{
    $check(PhabricatorEnv::getEnvConfig('governance.notifications.enabled')===true&&PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled')===false,
      'notice-identity-profile-notice-on-recovery-off-independent');
    $mail=$notice();$before=$generation();$original=$state();
    $check(PhorgePrivateMailPolicy::authorize($mail)&&$before!=='0','notice-identity-fresh-real-notice-after-raw-ABA-is-authorized');
    $current=$fresh();$current->save();
    $check($generation()===$before&&$state()===$original&&PhorgePrivateMailPolicy::authorize($mail),
      'notice-identity-real-unchanged-save-preserves-generation-and-delivery');
    $current=$fresh();$current->setRealName('Synthetic notice unrelated rename')->save();
    $check($generation()===$before&&$state()===$original&&PhorgePrivateMailPolicy::authorize($mail),
      'notice-identity-real-unrelated-save-preserves-generation-and-delivery');
    // Nested real saves may complete, but a caller rollback owns both rows.
    $conn->openTransaction();try{$raw('isAdmin',0);$raw('isAdmin',1);
      $changed=$generation()!==$before;$conn->killTransaction();}
    finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $check($changed&&$generation()===$before&&$state()===$original&&PhorgePrivateMailPolicy::authorize($mail),
      'notice-identity-outer-rollback-restores-real-user-and-notice-generation');
    $conn->openTransaction();try{
      $fresh()->setRealName('Synthetic outer preserved rename')->save();
      $conn->openTransaction();try{$raw('isAdmin',0);$raw('isAdmin',1);$conn->killTransaction();}
      catch(Throwable$error){if($conn->isInsideTransaction()){$conn->killTransaction();}throw$error;}
      $nested_restored=$generation()===$before&&$state()===$original;$conn->saveTransaction();
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $check($nested_restored&&$generation()===$before&&$fresh()->getRealName()==='Synthetic outer preserved rename'&&PhorgePrivateMailPolicy::authorize($mail),
      'notice-identity-nested-rollback-preserves-unrelated-outer-commit');
    $raw_name=$fresh()->getRealName();$raw_before=$generation();$raw_refused=false;$raw_retained=false;
    queryfx($conn,'START TRANSACTION');
    try{
      queryfx($conn,'UPDATE %R SET realName=%s WHERE id=%d',new PhabricatorUser(),'Synthetic untracked notice write',$user->getID());
      try{$raw('accountSecret',str_repeat('x',32));}
      catch(AphrontQueryException$error){if(get_class($error)!==AphrontQueryException::class||$error->getCode()!==1568){throw$error;}$raw_refused=true;}
      $raw_retained=!$conn->isInsideTransaction()&&$fresh()->getRealName()==='Synthetic untracked notice write';
    }finally{queryfx($conn,'ROLLBACK');}
    $check($raw_refused&&$raw_retained&&$fresh()->getRealName()===$raw_name&&$generation()===$raw_before,
      'notice-identity-untracked-raw-transaction-refused-without-commit-or-owner-rollback');
    $raw('isAdmin',0);$raw('isAdmin',1);
    $check($generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($before)&&!PhorgePrivateMailPolicy::authorize($mail),
      'notice-identity-post-rollback-real-restoration-advances-no-static-latch');
    $mail=$notice();$before=$generation();
    // Existing Editor still writes actual USER transactions and legacy roles.
    $operator=(new PhabricatorUser())->setUsername('gnidentityop'.substr($fixture->getNamespace(),-10))->setRealName('Synthetic notice operator')->setIsApproved(1)->setIsAdmin(1)->setIsEmailVerified(1)->save();
    $operator_auth=governance_native_request($operator,new PhabricatorAuthSessionEngine());
    $max_before=(int)queryfx_one($conn,'SELECT COALESCE(MAX(id),0) n FROM %R WHERE objectPHID=%s',new PhabricatorUserTransaction(),$user->getPHID())['n'];
    foreach(array(true,false)as$disabled){
      (new PhabricatorUserTransactionEditor())->setActor($operator_auth['viewer'])->setContentSourceFromRequest($operator_auth['request'])->setCancelURI('/people/')
        ->applyTransactions($fresh(),array((new PhabricatorUserTransaction())->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled)));
    }
    $events=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE objectPHID=%s AND id>%d AND transactionType=%s',new PhabricatorUserTransaction(),$user->getPHID(),$max_before,PhabricatorUserDisableTransaction::TRANSACTIONTYPE)['n'];
    $check($events===2&&$generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($before)&&!PhorgePrivateMailPolicy::authorize($mail),
      'notice-identity-real-UserEditor-ABA-keeps-native-events-and-invalidates-old-notice');
    $mail=$notice();$before=$generation();
    $legacy_before=queryfx_one($conn,'SELECT epoch FROM %R WHERE userPHID=%s',new PhorgeGovernanceIdentityEpoch(),$user->getPHID());
    foreach(array(true,false)as$agent){(new PhabricatorUserEditor())->setActor($operator_auth['viewer'])->makeSystemAgentUser($fresh(),$agent);}
    $legacy_after=queryfx_one($conn,'SELECT epoch FROM %R WHERE userPHID=%s',new PhorgeGovernanceIdentityEpoch(),$user->getPHID());
    $expected_legacy=PhorgeGovernanceNoticeIdentityScope::nextGeneration(PhorgeGovernanceNoticeIdentityScope::nextGeneration($legacy_before?(string)$legacy_before['epoch']:'1'));
    $check($legacy_after&&(string)$legacy_after['epoch']===$expected_legacy&&$generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($before)&&!PhorgePrivateMailPolicy::authorize($mail),
      'notice-identity-legacy-Editor-counts-unchanged-with-independent-notice-generation');
    $before=$generation();$legacy_prior=(string)$legacy_after['epoch'];$raw('isSystemAgent',1);
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON `'.$schema.'`.`organization_identityepoch` '.
      "FOR EACH ROW BEGIN IF NEW.userPHID='".$user->getPHID()."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-notice-legacy-fault'; END IF; END");$trigger_installed=true;
    $installed=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$schema."' AND TRIGGER_NAME='notice_identity_owned_fault'")->fetch_assoc();
    if((int)$installed['n']!==1){throw new RuntimeException('notice_identity_legacy_trigger_not_installed');}
    $legacy_fault=false;
    try{(new PhabricatorUserEditor())->setActor($operator_auth['viewer'])->makeSystemAgentUser($fresh(),false);}
    catch(AphrontQueryException$error){if($error->getCode()!==1644||!str_contains($error->getMessage(),'owned-notice-legacy-fault')){throw$error;}$legacy_fault=true;}
    $legacy_current=queryfx_one($conn,'SELECT epoch FROM %R WHERE userPHID=%s',new PhorgeGovernanceIdentityEpoch(),$user->getPHID());
    $check($legacy_fault&&(string)$state()['isSystemAgent']==='1'&&$generation()===$before&&
      $legacy_current&&(string)$legacy_current['epoch']===$legacy_prior&&!$conn->isInsideTransaction(),
      'notice-identity-legacy-Editor-late-fault-rolls-back-user-and-both-generations');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$trigger_installed=false;
    (new PhabricatorUserEditor())->setActor($operator_auth['viewer'])->makeSystemAgentUser($fresh(),false);
    $legacy_current=queryfx_one($conn,'SELECT epoch FROM %R WHERE userPHID=%s',new PhorgeGovernanceIdentityEpoch(),$user->getPHID());
    $check((string)$state()['isSystemAgent']==='0'&&$generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($before)&&
      (string)$legacy_current['epoch']===PhorgeGovernanceNoticeIdentityScope::nextGeneration($legacy_prior),
      'notice-identity-legacy-Editor-retry-after-rollback-has-no-generation-latch');
    // Disabled writes must never depend on notices config or its epoch table.
    $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled','unknown');
    $raw('isDisabled',1);
    $check((string)$state()['isDisabled']==='1','notice-identity-emergency-disable-ignores-broken-notice-configuration');
    $refused=$runtime_refusal(static function()use($raw){$raw('isDisabled',0);},'governance_notice_control_unknown');
    $check($refused&&(string)$state()['isDisabled']==='1'&&!$conn->isInsideTransaction(),
      'notice-identity-enable-with-broken-config-refuses-without-user-change');
    unset($scope);$scope=null;$raw('isDisabled',0);
    $contracted=0;$restored=0;
    foreach(array('isAdmin'=>array(0,1),'isApproved'=>array(0,1),'isSystemAgent'=>array(1,0),'isMailingList'=>array(1,0))as$field=>$values){
      $prior=$generation();$scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled','unknown');
      try{
        $raw($field,$values[0]);$after_contraction=$state();
        $denied=$runtime_refusal(static function()use($raw,$field,$values){$raw($field,$values[1]);},'governance_notice_control_unknown');
        if($denied&&(string)$after_contraction[$field]===(string)$values[0]&&$state()===$after_contraction&&$generation()===$prior&&!$conn->isInsideTransaction()){$contracted++;}
      }finally{unset($scope);$scope=null;}
      $raw($field,$values[1]);if($generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($prior)){$restored++;}
    }
    $check($contracted===4,'notice-identity-all-four-native-role-contractions-ignore-broken-notice-config');
    $check($restored===4,'notice-identity-all-four-native-role-restorations-require-monotonic-generation');
    $before=$generation();$guard_state=$state();
    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON '.$table.
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-notice-identity-fault'");$trigger_installed=true;
    $installed=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$schema."' AND TRIGGER_NAME='notice_identity_owned_fault'")->fetch_assoc();
    if((int)$installed['n']!==1){throw new RuntimeException('notice_identity_trigger_not_installed');}
    $raw('isDisabled',1);$fault=false;
    try{$raw('isDisabled',0);}catch(AphrontQueryException$error){if($error->getCode()!==1644||!str_contains($error->getMessage(),'owned-notice-identity-fault')){throw$error;}$fault=true;}
    $check($fault&&(string)$state()['isDisabled']==='1'&&$generation()===$before&&!$conn->isInsideTransaction(),
      'notice-identity-exact-generation-SQL-fault-rolls-back-native-enable');
    governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$trigger_installed=false;$raw('isDisabled',0);
    $check($state()===$guard_state&&$generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($before),
      'notice-identity-after-fault-real-enable-advances-and-no-rollback-latch');
    $before=$generation();
    governance_native_setup_query($fixture,'ALTER TABLE '.$table.' ENGINE=MyISAM');$engine_changed=true;
    $raw('isDisabled',1);$refused=$runtime_refusal(static function()use($raw){$raw('isDisabled',0);},'notice_identity_transactional_source_required');
    $check($refused&&(string)$state()['isDisabled']==='1'&&!$conn->isInsideTransaction(),
      'notice-identity-MyISAM-allows-emergency-disable-but-refuses-enable');
    governance_native_setup_query($fixture,'ALTER TABLE '.$table.' ENGINE=InnoDB');$engine_changed=false;$raw('isDisabled',0);
    $check($generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($before),'notice-identity-InnoDB-restored-real-enable-is-monotonic');
    $before=$generation();governance_native_setup_query($fixture,'RENAME TABLE '.$table.' TO '.$hidden);$renamed=true;
    $missing_proof=governance_native_setup_query($fixture,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='".$schema."' AND TABLE_NAME IN ('governance_notice_identityepoch','notice_fixture_hidden_generation') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
    if($missing_proof!==array(array('TABLE_NAME'=>'notice_fixture_hidden_generation','ENGINE'=>'InnoDB'))){throw new RuntimeException('notice_identity_missing_table_not_installed');}
    $raw('isDisabled',1);$missing=false;
    try{$raw('isDisabled',0);}catch(AphrontSchemaQueryException$error){if(!governance_notice_identity_missing_table($error,$schema)){throw$error;}$missing=true;}
    $check($missing&&(string)$state()['isDisabled']==='1'&&!$conn->isInsideTransaction(),
      'notice-identity-missing-table-allows-emergency-disable-but-refuses-enable');
    governance_native_setup_query($fixture,'RENAME TABLE '.$hidden.' TO '.$table);$renamed=false;$raw('isDisabled',0);
    $check($generation()===PhorgeGovernanceNoticeIdentityScope::nextGeneration($before),'notice-identity-table-return-does-not-reuse-restoring-generation');
    $before=$generation();queryfx($conn,'UPDATE governance_notice_identityepoch SET generation=18446744073709551615 WHERE userPHID=%s',$user->getPHID());
    try{
      $raw('isDisabled',1);$refused=$runtime_refusal(static function()use($raw){$raw('isDisabled',0);},'notice_identity_generation_exhausted');
      $check($refused&&(string)$state()['isDisabled']==='1'&&$generation()==='18446744073709551615'&&!$conn->isInsideTransaction(),
        'notice-identity-exhausted-generation-does-not-block-disable-or-wrap-enable');
    }finally{queryfx($conn,'UPDATE governance_notice_identityepoch SET generation=CAST(%s AS UNSIGNED) WHERE userPHID=%s',$before,$user->getPHID());}
    $raw('isDisabled',0);$fresh_notice=$notice();
    $check(PhorgePrivateMailPolicy::authorize($fresh_notice),'notice-identity-fresh-real-notice-remains-deliverable-after-fault-controls');
    $before=$generation();$scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.notifications.enabled',false);
    $raw('isDisabled',1);$raw('isDisabled',0);unset($scope);$scope=null;
    $check($generation()===$before,'notice-identity-flag-off-original-DAO-does-not-claim-generation-tracking');
    return array('actual_native_DAO_and_Editor_profile'=>true,'same_transaction_rollback'=>true,'emergency_disable_independent'=>true,
      'unrelated_saves_do_not_advance'=>true,'global_credentials_rotated'=>false,'activation_off_on_replay_proved'=>false,'all_account_writers_complete'=>false,'SMTP_proved'=>false);
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    try{unset($scope);}catch(Throwable$error){$errors['flags']=$error;}
    try{if($conn->isInsideTransaction()){$conn->killTransaction();}}catch(Throwable$error){$errors['transaction']=$error;}
    try{if($trigger_installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}}catch(Throwable$error){$errors['trigger']=$error;}
    try{if($renamed){governance_native_setup_query($fixture,'RENAME TABLE '.$hidden.' TO '.$table);}}catch(Throwable$error){$errors['table']=$error;}
    try{if($engine_changed){governance_native_setup_query($fixture,'ALTER TABLE '.$table.' ENGINE=InnoDB');}}catch(Throwable$error){$errors['engine']=$error;}
    if($errors){if($primary!==null){$errors=array('original'=>$primary)+$errors;}throw new PhutilAggregateException('notice_identity_fixture_cleanup_failed',$errors);}
  }
}
