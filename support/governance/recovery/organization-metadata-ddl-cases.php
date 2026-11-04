<?php

/** Actual asynchronous incoming-FK DDL vs the candidate write-MDL profile. */
function governance_recovery_organization_metadata_ddl_cases($fixture,callable $check) {
  global $control;
  $fixture->assertOwned();$namespace=$fixture->getNamespace();
  if(!($control instanceof mysqli)||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    throw new RuntimeException('recovery_org_ddl_owned_observer_required');}
  $writer=(new PhabricatorUser())->establishConnection('w');$setup=$fixture->newOwnedSetupConnection();
  if($writer->isInsideTransaction()){throw new RuntimeException('recovery_org_ddl_outer_transaction');}
  $child_schema=$namespace.'_policy';$parent_schema=$namespace.'_user';
  // An unknown existing object is never adopted or deleted by this fixture.
  $table='recovery_org_fk_probe';$quoted='`'.$child_schema.'`.`'.$table.'`';
  $marker='recovery-org-fk:'.bin2hex(random_bytes(16));$attempted=false;$pending=false;
  $primary=array_fill_keys(PhorgeRecoveryOrganizationMetadataObservation::tables(),'id');
  $primary['organization_operation']='operationID';$primary['organization_approval']='approvalID';
  $exists=static function()use($control,$child_schema,$table){
    $schema=$control->real_escape_string($child_schema);
    $result=$control->query("SELECT ENGINE,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA='".$schema."' AND TABLE_NAME='".$table."'");
    if(!$result){throw new RuntimeException('recovery_org_ddl_ownership_observation_failed');}
    $row=$result->fetch_assoc();$result->free();return$row;
  };
  if($exists()!==null){throw new RuntimeException('recovery_org_ddl_unknown_existing_table');}
  $primary_error=null;
  try{
    foreach(array('create','alter')as$operation){
      foreach(array(false,true)as$rollback){
        if($operation==='alter'){
          $attempted=true;
          if($setup->query('CREATE TABLE '.$quoted.' (id INT UNSIGNED NOT NULL PRIMARY KEY, parentID INT UNSIGNED NULL) ENGINE=InnoDB COMMENT=\''.$marker.'\'')!==true){throw new RuntimeException('recovery_org_ddl_create_failed');}
        }
        $writer->openTransaction();
        try{
          foreach($primary as$name=>$key){
            queryfx_all($writer,'SELECT 1 FROM %T WHERE 1=0 LOCK IN SHARE MODE',$name);
          }
          // Candidate shared-write MDL with zero matched rows: no production
          // authorization invokes this profile until these races are proved.
          foreach($primary as$name=>$key){
            queryfx($writer,'UPDATE %T SET %C=%C WHERE 1=0',$name,$key,$key);
            if($writer->getAffectedRows()!==0){throw new RuntimeException('recovery_org_ddl_noop_changed_rows');}
          }
          $parent=(int)queryfx_one($writer,'SELECT CONNECTION_ID() n')['n'];
          $fk='FOREIGN KEY (parentID) REFERENCES `'.$parent_schema.'`.organization_guard(id)';
          $sql=$operation==='create'?'CREATE TABLE '.$quoted.' (id INT UNSIGNED NOT NULL PRIMARY KEY, parentID INT UNSIGNED NULL, '.$fk.') ENGINE=InnoDB COMMENT=\''.$marker.'\'':
            'ALTER TABLE '.$quoted.' ADD CONSTRAINT recovery_org_probe_fk '.$fk;
          $attempted=true;if($setup->query($sql,MYSQLI_ASYNC)!==true){throw new RuntimeException('recovery_org_ddl_async_start_failed');}$pending=true;
          $deadline=microtime(true)+4;$waiting=false;
          do{
            $sql='SELECT COUNT(*) n FROM performance_schema.metadata_locks requested '.
              'JOIN performance_schema.threads requester ON requester.THREAD_ID=requested.OWNER_THREAD_ID '.
              'JOIN performance_schema.metadata_locks blocker ON blocker.OBJECT_TYPE=requested.OBJECT_TYPE '.
              'AND blocker.OBJECT_SCHEMA=requested.OBJECT_SCHEMA AND blocker.OBJECT_NAME=requested.OBJECT_NAME '.
              'JOIN performance_schema.threads holder ON holder.THREAD_ID=blocker.OWNER_THREAD_ID '.
              'WHERE requester.PROCESSLIST_ID='.(int)$setup->thread_id.' AND holder.PROCESSLIST_ID='.$parent.
              " AND requested.OBJECT_SCHEMA='".$control->real_escape_string($parent_schema)."'".
              " AND requested.OBJECT_NAME='organization_guard' AND requested.LOCK_STATUS='PENDING'".
              " AND blocker.LOCK_STATUS='GRANTED' AND blocker.LOCK_TYPE='SHARED_WRITE'";
            $result=$control->query($sql);if(!$result){throw new RuntimeException('recovery_org_ddl_wait_observation_failed');}
            $waiting=(int)$result->fetch_assoc()['n']>0;$result->free();
            if($waiting){break;}
            $read=array($setup);$error=$reject=array();
            if(mysqli_poll($read,$error,$reject,0,0)>0){break;}
            usleep(20000);
          }while(microtime(true)<$deadline);
          $check($waiting&&$writer->isInsideTransaction(),
            'recovery-org-incoming-'.$operation.'-fk-waits-all-table-write-mdl-'.($rollback?'rollback':'commit'));
          if($rollback){$writer->killTransaction();}else{$writer->saveTransaction();}
        }finally{if($writer->isInsideTransaction()){$writer->killTransaction();}}
        $read=array($setup);$error=$reject=array();
        if(mysqli_poll($read,$error,$reject,4,0)!==1){throw new RuntimeException('recovery_org_ddl_completion_timeout');}
        $pending=false;if($setup->reap_async_query()!==true){throw new RuntimeException('recovery_org_ddl_execution_failed');}
        $result=$control->query("SELECT COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='".
          $control->real_escape_string($child_schema)."' AND TABLE_NAME='".$table."' AND UNIQUE_CONSTRAINT_SCHEMA='".
          $control->real_escape_string($parent_schema)."' AND REFERENCED_TABLE_NAME='organization_guard'");
        if(!$result){throw new RuntimeException('recovery_org_ddl_constraint_observation_failed');}
        $count=(int)$result->fetch_assoc()['n'];$result->free();
        $check($count===1,'recovery-org-incoming-'.$operation.'-fk-completes-after-'.($rollback?'rollback':'commit'));
        $row=$exists();if(!$row||$row['TABLE_COMMENT']!==$marker||$row['ENGINE']!=='InnoDB'){
          throw new RuntimeException('recovery_org_ddl_probe_ownership_changed');}
        if($setup->query('DROP TABLE '.$quoted)!==true){throw new RuntimeException('recovery_org_ddl_drop_failed');}$attempted=false;
      }
    }
  }catch(Throwable$error){$primary_error=$error;throw$error;}finally{
    try{
    if($writer->isInsideTransaction()){$writer->killTransaction();}
    // An async DDL may have completed despite response loss. Inspect the fixed
    // owned object independently before cleanup; never delete foreign state.
    if($pending){
      $read=array($setup);$error=$reject=array();
      if(mysqli_poll($read,$error,$reject,4,0)!==1){throw new RuntimeException('recovery_org_ddl_pending_cleanup_failed');}
      if($setup->reap_async_query()!==true){throw new RuntimeException('recovery_org_ddl_pending_execution_failed');}
    }
    if($attempted){$row=$exists();if($row!==null){
      if($row['TABLE_COMMENT']!==$marker||$row['ENGINE']!=='InnoDB'){
        throw new RuntimeException('recovery_org_ddl_cleanup_foreign_object');}
      if($setup->query('DROP TABLE '.$quoted)!==true){throw new RuntimeException('recovery_org_ddl_cleanup_drop_failed');}
    }}
    }catch(Throwable$cleanup){
      if($primary_error!==null){throw new PhutilAggregateException('recovery_org_ddl_cleanup_failed',
        array('original'=>$primary_error,'cleanup'=>$cleanup));}
      throw$cleanup;
    }
  }
}
