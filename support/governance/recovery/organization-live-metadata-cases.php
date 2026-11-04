<?php

/** Reuse only the existing explicitly isolated bootstrap identity, in memory. */
function governance_recovery_org_metadata_control($fixture,$restricted=false) {
  $fixture->assertOwned();
  if(getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'){
    throw new RuntimeException('recovery_metadata_fixture_scope');}
  if($restricted){
    $config=$fixture->getWriterConfig();
    return new AphrontMySQLiDatabaseConnection(array('host'=>$config['host'],'port'=>$config['port'],
      'database'=>$config['namespace'].'_user','user'=>$config['user'],
      'pass'=>new PhutilOpaqueEnvelope($config['password']),'timeout'=>3));
  }
  $dsn=getenv('PHORGE_GOVERNANCE_FIXTURE_DSN');$user=getenv('PHORGE_GOVERNANCE_FIXTURE_USER');
  $password=getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD');
  if(!is_string($dsn)||!preg_match('/\Amysql:host=127\.0\.0\.1;port=([1-9][0-9]{0,4});dbname=governance_fixture_bootstrap;charset=utf8mb4\z/D',$dsn,$match)||
    (int)$match[1]>65535||$user!=='root'||!is_string($password)||$password===''||strlen($password)>4096){
    throw new RuntimeException('recovery_metadata_fixture_control_scope');}
  // This is not an application metadata credential API. Nothing is persisted,
  // and no account/grant is created or widened by constructing this fixture.
  return new AphrontMySQLiDatabaseConnection(array('host'=>'127.0.0.1','port'=>(int)$match[1],
    'database'=>'','user'=>$user,
    'pass'=>new PhutilOpaqueEnvelope($password),'timeout'=>3));
}

/** Native permission exceptions do not retain errno in their constructor. */
function governance_recovery_org_metadata_limited_denial(Throwable $error,$expected_user) {
  if(get_class($error)!==AphrontAccessDeniedQueryException::class||$error->getCode()!==0||
    !is_string($expected_user)||!preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/D',$expected_user)){
    return false;}
  $prefix="#1142: SELECT command denied to user '".$expected_user."'@'";
  if(!str_starts_with($error->getMessage(),$prefix)){return false;}
  $pattern=<<<'REGEX'
/\A[^'\r\n]+' for table 'user'(?:\n|\z)/D
REGEX;
  return preg_match($pattern,substr($error->getMessage(),strlen($prefix)))===1;
}

function governance_recovery_org_live_metadata_cases($fixture,callable $check,array $state) {
  global $control;
  $fixture->assertOwned();$namespace=$fixture->getNamespace();
  if(!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    throw new RuntimeException('recovery_metadata_fixture_namespace');}
  $writer=(new PhabricatorUser())->establishConnection('w');$controls=array();
  $metadata=governance_recovery_org_metadata_control($fixture);$controls[]=$metadata;
  $limited=governance_recovery_org_metadata_control($fixture,true);$controls[]=$limited;
  $capture=static function($observer)use($writer,$state){
    if($writer->isInsideTransaction()||$observer->isInsideTransaction()){
      throw new RuntimeException('recovery_metadata_fixture_outer_transaction');}
    $writer_owned=false;$observer_owned=false;$primary=null;
    try{
      $writer->openTransaction();$writer_owned=true;
      $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($state['admin']['context'],$writer);
      if(!$actor->isSystemAdmin()){throw new RuntimeException('recovery_metadata_fixture_admin');}
      $observer->openTransaction();$observer_owned=true;
      $facts=PhorgeRecoveryOrganizationMetadataObservation::capture($writer,$observer);
      PhorgeGovernanceNativeIdentitySource::revalidate($actor,$writer);
      return $facts;
    }catch(Throwable$error){$primary=$error;throw$error;}finally{
      $errors=array();
      foreach(array(array($observer_owned,$observer),array($writer_owned,$writer))as$index=>$owned){
        try{if($owned[0]&&$owned[1]->isInsideTransaction()){$owned[1]->killTransaction();}}
        catch(Throwable$error){$errors['transaction-'.$index]=$error;}
      }
      if($errors){if($primary!==null){$errors=array('original'=>$primary)+$errors;}
        throw new PhutilAggregateException('recovery_metadata_capture_cleanup_failed',$errors);}
    }
  };
  $refuse=static function()use($capture,$metadata){
    try{$capture($metadata);}
    catch(RuntimeException$error){
      if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_metadata_native_producers_unknown'){throw$error;}
      return true;
    }
    return false;
  };
  $trigger='`'.$namespace.'_user`.`recovery_metadata_visibility_probe`';
  $child='`'.$namespace.'_policy`.`recovery_metadata_fk_probe`';
  $guard=$fixture->table('user','organization_guard');$created_trigger=false;$created_child=false;$created_outgoing=false;
  $primary_error=null;$cleanup_errors=array();$control_threads=array();
  try{
    if(!($control instanceof mysqli)){throw new RuntimeException('recovery_metadata_fixture_observer');}
    foreach($controls as$connection){
      $id=queryfx_one($connection,'SELECT CONNECTION_ID() n')['n'];
      if(!ctype_digit((string)$id)||(int)$id<1){throw new RuntimeException('recovery_metadata_fixture_thread');}
      $control_threads[]=(int)$id;
    }
    $before=$capture($metadata);
    $identity=queryfx_one($metadata,'SELECT CURRENT_ROLE() role_value,@@proxy_user proxy_value');
    $check($identity===array('role_value'=>'NONE','proxy_value'=>null)&&
      $before['global_visibility_grant_row_locked']===true&&
      $before['observed_trigger_and_incoming_outgoing_fk_absence']===true&&
      $before['all_twelve_table_mdl_acquired']===true&&$before['restoration_authorized']===false&&
      $before['concurrent_ddl_absence_fence_proved']===false,
      'recovery-org-live-global-grant-role-none-proxy-null-observation-is-not-permit');
    $limited_refused=false;
    try{$capture($limited);}
    catch(AphrontAccessDeniedQueryException$error){
      if(!governance_recovery_org_metadata_limited_denial($error,$fixture->getWriterConfig()['user'])){throw$error;}$limited_refused=true;
    }
    $check($limited_refused&&!$writer->isInsideTransaction()&&!$limited->isInsideTransaction(),
      'recovery-org-live-limited-metadata-visibility-exact1142-never-proves-absence');

    governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.
      $fixture->table('user','organization_member').' FOR EACH ROW SET @recovery_metadata_visibility_probe=1');$created_trigger=true;
    try{
      $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".
        $namespace."_user' AND TRIGGER_NAME='recovery_metadata_visibility_probe'");
      $installed=(int)$proof->fetch_assoc()['n']===1;$proof->free();
      $check($installed&&$refuse(),'recovery-org-live-actual-trigger-metadata-blocks-current-absence');
    }finally{if($created_trigger){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);$created_trigger=false;}}

    governance_native_setup_query($fixture,'CREATE TABLE '.$child.' (id INT UNSIGNED NOT NULL PRIMARY KEY,parentID INT UNSIGNED NULL,'.
      'CONSTRAINT recovery_metadata_incoming FOREIGN KEY(parentID) REFERENCES '.$guard.'(id)) ENGINE=InnoDB');$created_child=true;
    try{
      $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='".
        $namespace."_policy' AND TABLE_NAME='recovery_metadata_fk_probe' AND UNIQUE_CONSTRAINT_SCHEMA='".$namespace."_user' AND REFERENCED_TABLE_NAME='organization_guard'");
      $installed=(int)$proof->fetch_assoc()['n']===1;$proof->free();
      $check($installed&&$refuse(),'recovery-org-live-incoming-fk-from-unregistered-child-table-is-visible-and-blocks');
    }finally{if($created_child){governance_native_setup_query($fixture,'DROP TABLE '.$child);$created_child=false;}}

    governance_native_setup_query($fixture,'CREATE TABLE '.$child.' (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');$created_child=true;
    governance_native_setup_query($fixture,'INSERT INTO '.$child.' (id) VALUES (1)');
    governance_native_setup_query($fixture,'ALTER TABLE '.$guard.' ADD CONSTRAINT recovery_metadata_outgoing FOREIGN KEY(id) REFERENCES '.$child.'(id)');$created_outgoing=true;
    try{
      $proof=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='".
        $namespace."_user' AND TABLE_NAME='organization_guard' AND REFERENCED_TABLE_NAME='recovery_metadata_fk_probe'");
      $installed=(int)$proof->fetch_assoc()['n']===1;$proof->free();
      $check($installed&&$refuse(),'recovery-org-live-outgoing-fk-to-other-schema-is-visible-and-blocks');
    }finally{
      if($created_outgoing){governance_native_setup_query($fixture,'ALTER TABLE '.$guard.' DROP FOREIGN KEY recovery_metadata_outgoing');$created_outgoing=false;}
      if($created_child){governance_native_setup_query($fixture,'DROP TABLE '.$child);$created_child=false;}
    }
    $after=$capture($metadata);
    $check($after===$before&&!$writer->isInsideTransaction()&&!$metadata->isInsideTransaction(),
      'recovery-org-live-metadata-owned-ddl-cleanup-restores-observation-and-closes-transactions');
  }catch(Throwable$error){$primary_error=$error;throw$error;}
  finally{
    foreach(array('outgoing','trigger','child')as$kind){
      try{
        if($kind==='outgoing'&&$created_outgoing){governance_native_setup_query($fixture,'ALTER TABLE '.$guard.' DROP FOREIGN KEY recovery_metadata_outgoing');}
        if($kind==='trigger'&&$created_trigger){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}
        if($kind==='child'&&$created_child){governance_native_setup_query($fixture,'DROP TABLE '.$child);}
      }catch(Throwable$error){$cleanup_errors[$kind]=$error;}
    }
    foreach($controls as$index=>$connection){
      try{if($connection->isInsideTransaction()){$connection->killTransaction();}$connection->close();}
      catch(Throwable$error){$cleanup_errors['control-'.$index]=$error;}
    }
    if($control_threads){
      try{
        $deadline=microtime(true)+2;$absent=false;
        do{
          $q=$control->query('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE ID IN ('.implode(',',$control_threads).')');
          if(!$q){throw new RuntimeException('recovery_metadata_control_cleanup_observation');}
          $absent=(int)$q->fetch_assoc()['n']===0;$q->free();if($absent){break;}usleep(20000);
        }while(microtime(true)<$deadline);
        if(!$absent){throw new RuntimeException('recovery_metadata_control_remaining');}
      }catch(Throwable$error){$cleanup_errors['control-absence']=$error;}
    }
    if($cleanup_errors){
      if($primary_error!==null){$cleanup_errors=array('original'=>$primary_error)+$cleanup_errors;}
      throw new PhutilAggregateException('recovery_metadata_fixture_cleanup_failed',$cleanup_errors);
    }
  }
}
