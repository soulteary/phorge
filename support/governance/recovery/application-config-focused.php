<?php

/** Fresh owned ConfigEntry / actual ConfigDatabaseSource. No row/absence lease. */
function governance_recovery_application_config_focused($fixture,callable $check) {
  $fixture->assertOwned();
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if (!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/D',$revision)) {
    throw new RuntimeException('recovery_config_facts_revision_required');
  }
  require_once dirname(__DIR__).'/native-organization/workers.php';
  $stack_property=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');
  $cache_property=new ReflectionProperty(PhabricatorEnv::class,'cache');
  $original=$stack_property->getValue();$cache=$cache_property->getValue();
  if (get_class($original)!==PhabricatorConfigStackSource::class) {
    throw new RuntimeException('recovery_config_facts_stack_unknown');
  }
  $sources=$original->getStack();$namespace=$fixture->getNamespace();
  $key='auth.require-email-verification';
  $dao=(new PhabricatorConfigEntry())->setNamespace($namespace)->setConfigKey($key)->setIsDeleted(0)->setValue(true);
  $writer=$dao->establishConnection('w');$setup=$fixture->getAdmin();
  if ($writer->isInsideTransaction()||$writer->isReadLocking()||$writer->isWriteLocking()) {
    throw new RuntimeException('recovery_config_facts_outer_transaction');
  }
  $install=function()use($namespace,$sources,$stack_property,$cache_property){
    $database=new PhabricatorConfigDatabaseSource($namespace);
    $stack=new PhabricatorConfigStackSource();
    foreach (array_reverse($sources)as$source){$stack->pushSource($source);}
    $stack->pushSource($database);$stack_property->setValue(null,$stack);$cache_property->setValue(null,array());
    return $database;
  };
  $read=function()use($fixture,$setup,$namespace,$key){
    $mode=(new mysqli_driver())->report_mode;
    try{mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      $result=$setup->query('SELECT value,isDeleted FROM '.$fixture->table('config','config_entry').
        " WHERE namespace='".$setup->real_escape_string($namespace)."' AND configKey='".$key."'");
      $rows=$result->fetch_all(MYSQLI_ASSOC);$result->free();
      if(count($rows)>1){throw new RuntimeException('recovery_config_facts_row_identity');}
      return $rows?array(json_decode($rows[0]['value'],true,16,JSON_THROW_ON_ERROR),(int)$rows[0]['isDeleted']):null;
    }finally{mysqli_report($mode);}
  };
  try{
    $route=PhabricatorEnv::getEnvConfig('storage.default-namespace');
    $same=queryfx_one($writer,'SELECT @@server_uuid uuid,CONNECTION_ID() thread,DATABASE() db');
    $control=$setup->query('SELECT @@server_uuid uuid,CONNECTION_ID() thread,@@autocommit ac')->fetch_assoc();
    $check(get_class($writer)===AphrontMySQLiDatabaseConnection::class&&$route===$namespace&&
      $same['db']===$namespace.'_config'&&$writer===(new PhabricatorConfigEntry())->establishConnection('r')&&
      $same['uuid']===$control['uuid']&&
      (int)$same['thread']!==(int)$control['thread']&&(string)$control['ac']==='1'&&$read()===null,
      'recovery-config-owned-native-writer-independent-observer');
    $database=$install();$facts=PhorgeRecoveryApplicationConfigFacts::capture();$summary=$facts->finish();
    $check($database->getKeys(array($key))===array()&&$summary['complete']===false,
      'recovery-config-real-empty-database-source-default-fallback');
    $dao->save();$check($read()===array(true,0),'recovery-config-real-native-DAO-commit-visible');
    $cached=PhorgeRecoveryApplicationConfigFacts::capture();
    $check($database->getKeys(array($key))===array()&&$cached->finish()['SQL_row_or_absence_lease']===false,
      'recovery-config-cached-empty-source-not-current-SQL-proof');
    $database=$install();$check($database->getKeys(array($key))===array($key=>true)&&
      PhorgeRecoveryApplicationConfigFacts::capture()->finish()['complete']===false,
      'recovery-config-reloaded-database-source-native-precedence');
    $writer->openTransaction();
    try{
      $dao->setValue(false)->save();
      $check($read()===array(true,0),'recovery-config-uncommitted-native-change-not-observer-visible');
      $writer->killTransaction();
    }catch(Throwable$error){
      try{if($writer->isInsideTransaction()){$writer->killTransaction();}}
      catch(Throwable$cleanup){throw new PhutilAggregateException('recovery_config_facts_rollback_failed',array($error,$cleanup));}
      throw$error;
    }
    $database=$install();$check($read()===array(true,0)&&$database->getKeys(array($key))===array($key=>true),
      'recovery-config-real-native-rollback-original-value-restored');
    $dao=(new PhabricatorConfigEntry())->loadOneWhere('namespace=%s AND configKey=%s',$namespace,$key);
    if(!$dao){throw new RuntimeException('recovery_config_facts_owned_entry_missing');}
    $dao->setIsDeleted(1)->save();$database=$install();
    $check($read()===array(true,1)&&$database->getKeys(array($key))===array(),
      'recovery-config-native-deleted-entry-excluded');
    $dao->setIsDeleted(0)->setValue(null)->save();$database=$install();$refused=false;
    try{PhorgeRecoveryApplicationConfigFacts::capture();}
    catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!=='recovery_config_facts_type_unknown'){throw$error;}$refused=true;}
    $check($refused&&$database->getKeys(array($key))===array($key=>null),
      'recovery-config-real-null-not-missing-or-default');
    $dao->delete();$database=$install();
    $check($read()===null&&$database->getKeys(array($key))===array()&&
      PhorgeRecoveryApplicationConfigFacts::capture()->finish()['complete']===false,
      'recovery-config-owned-row-deleted-and-default-fallback-restored');
    return array('profile'=>'native-config-source-facts-v1','source_revision'=>$revision,
      'selected_option_count'=>10,'actual_ConfigDatabaseSource'=>true,'native_DAO_commit_and_rollback'=>true,
      'independent_observer_same_primary'=>true,'database_source_cache_is_SQL_lease'=>false,
      'deployment_immutability_proved'=>false,'continuous_lease_or_ABA_proved'=>false,
      'complete'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false);
  }finally{
    $stack_property->setValue(null,$original);$cache_property->setValue(null,$cache);
  }
}
