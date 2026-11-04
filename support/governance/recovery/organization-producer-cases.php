<?php

/** Genuine native DAO probes. These are not organization authorization APIs. */
function governance_recovery_organization_producer_cases($fixture,callable $check,array $state) {
  global $control;
  $fixture->assertOwned();
  if(!($control instanceof mysqli)){throw new RuntimeException('recovery_org_observer_required');}
  $conn=(new PhabricatorUser())->establishConnection('w');
  if($conn->isInsideTransaction()){throw new RuntimeException('recovery_org_fixture_outer_transaction');}
  $handles=array();$cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  $flags=null;$environment=null;
  $unit_factory=static function($unit){return (new PhorgeOrganizationUnit())
    ->setUnitID($unit)->setKind('team')->setState('active')->setName('Synthetic producer fixture')
    ->setParentID(null)->setVersion(1)->setVisibilityModel('restricted-admin-v1')
    ->setVisibilityVersion(1)->setAssociatedDepartmentID(null)->setContactUserPHID(null);};
  try{
    $flags=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',true)
      ->overrideEnvConfig('governance.account-recovery.incarnation',bin2hex(random_bytes(16)));
    $environment=governance_recovery_account_fixture_environment($fixture);
    $level=queryfx_one($conn,'SELECT @@SESSION.transaction_isolation v')['v'];
    foreach(array(false,true)as$rollback){
      $handle=governance_native_start_worker($fixture,array('action'=>'recovery.org.member.insert','token'=>$state['admin']['token']));
      $handles[]=$handle;governance_native_wait_organization_producer_ready($handle);
      $unit='recovery-producer-'.substr($handle['nonce'],0,16);
      $unit_factory($unit)->save();
      $conn->openTransaction();
      try{
        $guard=queryfx_one($conn,'SELECT id,version FROM organization_guard WHERE id=1 FOR UPDATE');
        if(!$guard||(string)$guard['id']!=='1'){throw new RuntimeException('recovery_org_guard_missing');}
        $parent=(int)queryfx_one($conn,'SELECT CONNECTION_ID() n')['n'];
        governance_native_continue_organization_producer($handle);
        $deadline=microtime(true)+4;$waiting=false;
        do{
          $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
            'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
            'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
            'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
            'WHERE r.PROCESSLIST_ID='.(int)$handle['mysql_thread'].' AND b.PROCESSLIST_ID='.$parent.
            " AND l.OBJECT_SCHEMA='".$control->real_escape_string($fixture->getNamespace().'_user').
            "' AND l.OBJECT_NAME='organization_guard' AND l.INDEX_NAME='PRIMARY'".
            " AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='WAITING'";
          $result=$control->query($sql);
          if(!$result){throw new RuntimeException('recovery_org_wait_observation_failed');}
          $waiting=(int)$result->fetch_assoc()['n']>0;$result->free();
          if($waiting){break;}usleep(20000);
        }while(microtime(true)<$deadline);
        $count=(int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_member WHERE unitID=%s',$unit)['n'];
        $check($waiting&&$count===0&&$conn->isInsideTransaction(),$rollback?
          'recovery-org-native-member-insert-waits-primary-mutex-until-rollback':
          'recovery-org-native-member-insert-waits-primary-mutex-until-commit');
        if($rollback){$conn->killTransaction();}else{$conn->saveTransaction();}
      }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
      $result=governance_native_finish_worker($handle);
      $rows=queryfx_all($conn,'SELECT id,unitID,userPHID,state,validFrom,validUntil,version FROM organization_member WHERE unitID=%s',$unit);
      $check(count($rows)===1&&$result['native_member_digest']===PhorgeRecoveryRestoreRequest::digest($rows[0])&&
        $result['restoration_authorized']===false&&!$conn->isInsideTransaction(),$rollback?
          'recovery-org-parent-rollback-releases-native-dao-producer':
          'recovery-org-parent-commit-releases-native-dao-producer');
    }
    // The DAO fence owns one nested layer; it must not commit its caller.
    $unit='recovery-nested-'.bin2hex(random_bytes(8));
    $guard_before=queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version'];
    $conn->openTransaction();
    try{
      $unit_factory($unit)->save();
      $check($conn->isInsideTransaction()&&
        (string)queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version']===PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($guard_before)&&
        (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_unit WHERE unitID=%s',$unit)['n']===1,
        'recovery-org-native-dao-preserves-caller-transaction');
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $check((int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_unit WHERE unitID=%s',$unit)['n']===0&&
      queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version']===$guard_before,
      'recovery-org-caller-rollback-removes-native-dao-write');
    // A physically active but untracked raw transaction must remain caller-owned.
    queryfx($conn,'START TRANSACTION');$refused=false;
    try{
      queryfx($conn,'UPDATE organization_guard SET version=version+1 WHERE id=1');
      $version=queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version'];
      try{$unit_factory('recovery-raw-'.bin2hex(random_bytes(8)))->save();}
      catch(AphrontQueryException$error){
        if(get_class($error)!==AphrontQueryException::class||$error->getCode()!==1568){throw$error;}$refused=true;
      }
      $check($refused&&!$conn->isInsideTransaction()&&
        queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version']===$version,
        'recovery-org-raw-start-exact1568-preserves-caller-write');
    }finally{queryfx($conn,'ROLLBACK');}
    $rejected=false;
    try{$unit_factory('recovery-replace-'.bin2hex(random_bytes(8)))->replace();}
    catch(RuntimeException$error){
      if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_org_producer_mode_unknown'){throw$error;}$rejected=true;
    }
    $check($rejected&&!$conn->isInsideTransaction(),'recovery-org-native-replace-refused-without-transaction');
    $guard=queryfx_one($conn,'SELECT id,version FROM organization_guard WHERE id=1');
    $conn->openTransaction();$missing=false;$caller_preserved=false;
    try{
      queryfx($conn,'DELETE FROM organization_guard WHERE id=1');
      try{$unit_factory('recovery-missing-'.bin2hex(random_bytes(8)))->save();}
      catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_org_producer_guard_missing'){throw$error;}$missing=true;
      }
      $caller_preserved=$conn->isInsideTransaction()&&queryfx_one($conn,'SELECT id FROM organization_guard WHERE id=1')===null;
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $check($missing&&$caller_preserved&&queryfx_one($conn,'SELECT id,version FROM organization_guard WHERE id=1')===$guard,
      'recovery-org-missing-mutex-not-initialized-caller-rollback-restores-row');
    $setup=$fixture->getAdmin();$qualified=$fixture->table('user','organization_guard');$changed=false;$bad_engine=false;
    try{
      if($setup->query('ALTER TABLE '.$qualified.' ENGINE=MyISAM')!==true){throw new RuntimeException('recovery_org_fixture_engine_change_failed');}$changed=true;
      try{$unit_factory('recovery-engine-'.bin2hex(random_bytes(8)))->save();}
      catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_org_producer_schema_unknown'){throw$error;}$bad_engine=true;
      }
    }finally{if($changed&&$setup->query('ALTER TABLE '.$qualified.' ENGINE=InnoDB')!==true){throw new RuntimeException('recovery_org_fixture_engine_restore_failed');}}
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','organization_guard');
    $check($bad_engine&&$engine['ENGINE']==='InnoDB'&&!$conn->isInsideTransaction(),
      'recovery-org-non-innodb-mutex-refused-and-owned-engine-restored');
    $off=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.enabled',false);
    $conn->openTransaction();
    try{
      $unit='recovery-off-'.bin2hex(random_bytes(8));$unit_factory($unit)->save();
      $check($conn->isInsideTransaction()&&(int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_unit WHERE unitID=%s',$unit)['n']===1,
        'recovery-org-feature-off-native-dao-path-remains-caller-owned');
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}unset($off);}
    $unit='recovery-aba-'.bin2hex(random_bytes(8));$unit_factory($unit)->save();
    $member=(new PhorgeOrganizationMember())->setUnitID($unit)->setUserPHID($state['admin']['context']->getUserPHID())
      ->setState('active')->setValidFrom(0)->setValidUntil(null)->setVersion(1)->save();
    $conn->openTransaction();
    try{
      $before=PhorgeRecoveryRestoreOrganizationSource::capture($conn,$member->getUserPHID());
      $row_before=queryfx_one($conn,'SELECT id,unitID,userPHID,state,validFrom,validUntil,version FROM organization_member WHERE id=%d',$member->getID());
      $member->setState('inactive')->save();$member->setState('active')->save();
      $after=PhorgeRecoveryRestoreOrganizationSource::capture($conn,$member->getUserPHID());
      $row_after=queryfx_one($conn,'SELECT id,unitID,userPHID,state,validFrom,validUntil,version FROM organization_member WHERE id=%d',$member->getID());
      $expected=PhorgeRecoveryOrganizationProducerFence::nextGuardVersion(
        PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($before['guard_version']));
      $check($row_before===$row_after&&$after['guard_version']===$expected&&
        PhorgeRecoveryRestoreRequest::digest($before)!==PhorgeRecoveryRestoreRequest::digest($after),
        'recovery-org-native-dao-aba-preserves-row-bytes-but-changes-generation');
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $prior=queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version'];
    $overflow=false;$preserved=false;$unit='recovery-overflow-'.bin2hex(random_bytes(8));
    $conn->openTransaction();
    try{
      queryfx($conn,'UPDATE organization_guard SET version=18446744073709551615 WHERE id=1');
      try{$unit_factory($unit)->save();}
      catch(RuntimeException$error){
        if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_org_producer_generation_exhausted'){throw$error;}$overflow=true;
      }
      $preserved=$conn->isInsideTransaction()&&
        (string)queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version']==='18446744073709551615'&&
        (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_unit WHERE unitID=%s',$unit)['n']===0;
    }finally{if($conn->isInsideTransaction()){$conn->killTransaction();}}
    $check($overflow&&$preserved&&queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version']===$prior,
      'recovery-org-generation-overflow-refuses-before-write-preserves-caller');
    $namespace=$fixture->getNamespace();$fixture->assertOwned();
    if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
      throw new RuntimeException('recovery_org_trigger_namespace_unknown');}
    $trigger='`'.$namespace.'_user`.`recovery_org_generation_fault`';$installed=false;
    $unit='recovery-fault-'.bin2hex(random_bytes(8));$refused=false;
    try{
      if($setup->query('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON '.$qualified.
        " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recovery_org_generation_fixture_fault'")!==true){throw new RuntimeException('recovery_org_fixture_trigger_create_failed');}$installed=true;
      $proof=$setup->query("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
        $namespace."_user' AND TRIGGER_NAME='recovery_org_generation_fault' AND EVENT_OBJECT_TABLE='organization_guard'");
      if($proof===false){throw new RuntimeException('recovery_org_fixture_trigger_observation_failed');}
      $visible=(int)$proof->fetch_assoc()['n'];$proof->free();
      $check($visible===1,'recovery-org-generation-fault-trigger-actually-installed');
      try{$unit_factory($unit)->save();}
      catch(AphrontQueryException$error){
        if(get_class($error)!==AphrontQueryException::class||$error->getCode()!==1644){throw$error;}$refused=true;
      }
    }finally{if($installed&&$setup->query('DROP TRIGGER '.$trigger)!==true){throw new RuntimeException('recovery_org_fixture_trigger_drop_failed');}}
    $check($refused&&!$conn->isInsideTransaction()&&
      queryfx_one($conn,'SELECT version FROM organization_guard WHERE id=1')['version']===$prior&&
      (int)queryfx_one($conn,'SELECT COUNT(*) n FROM organization_unit WHERE unitID=%s',$unit)['n']===0,
      'recovery-org-generation-sql-fault-rolls-back-native-row-and-guard-together');
    $check(queryfx_one($conn,'SELECT @@SESSION.transaction_isolation v')['v']===$level,
      'recovery-org-producer-does-not-change-session-isolation');
  }finally{
    if($conn->isInsideTransaction()){$conn->killTransaction();}
    governance_native_stop_fixture_handles($handles);
    try{if($environment){governance_recovery_account_restore_fixture_environment($environment);}}
    finally{unset($flags);$_COOKIE=$cookies;if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}}
  }
}
