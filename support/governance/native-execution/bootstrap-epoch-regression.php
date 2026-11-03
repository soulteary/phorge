<?php

/** Installation omissions must reject actual native writes, including flag-off. */
function governance_bootstrap_epoch_regression(mysqli $control,$database) {
  $mode=(new mysqli_driver())->report_mode;mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  try{
  $expected=PhorgeProjectExecutionCatalog::configurationKeys();sort($expected,SORT_STRING);
  $table='`'.$database.'`.governance_configepoch';
  $rows=$control->query('SELECT namespace,configKey,epoch FROM '.$table.' ORDER BY configKey')->fetch_all(MYSQLI_ASSOC);
  $keys=array();foreach($rows as $row){
    if($row['namespace']!=='default'){throw new RuntimeException('Unexpected installed epoch namespace.');}
    PhorgeProjectConfigEpochScope::canonicalEpoch($row['epoch']);$keys[]=$row['configKey'];
  }
  if($keys!==$expected){throw new RuntimeException('Installed epochs differ from mandatory native source keys.');}
  $key='policy.allow-public';$saved=null;
  foreach($rows as $row){if($row['configKey']===$key){$saved=$row;}}
  if($saved===null){throw new RuntimeException('Required installed epoch missing.');}
  $conn=(new PhabricatorConfigEntry())->establishConnection('w');
  $before=queryfx_all($conn,'SELECT * FROM %R WHERE namespace=%s AND configKey=%s',new PhabricatorConfigEntry(),'default',$key);
  $write=function() use($key){PhabricatorConfigEntry::loadConfigEntry($key)->setIsDeleted(0)->setValue(true)->save();};
  $row_refused=false;$table_refused=false;
  try{
    $control->query("DELETE FROM ".$table." WHERE namespace='default' AND configKey='policy.allow-public'");
    try{$write();}catch(RuntimeException $ex){
      if(get_class($ex)!==RuntimeException::class || $ex->getMessage()!=='configuration_generation_schema_required'){throw $ex;}
      $row_refused=true;
    }
  }finally{
    $control->query("INSERT INTO ".$table." (namespace,configKey,epoch) VALUES ('default','policy.allow-public',".$saved['epoch'].")");
  }
  $renamed=false;
  try{
    $control->query('RENAME TABLE '.$table.' TO `'.$database.'`.governance_configepoch_absent');$renamed=true;
    try{$write();}catch(AphrontSchemaQueryException $ex){
      if(get_class($ex)!==AphrontSchemaQueryException::class ||
        !str_starts_with($ex->getMessage(),'#1146:') ||
        strpos($ex->getMessage(),$database.'.governance_configepoch')===false){throw $ex;}
      $table_refused=true;
    }
  }finally{
    if($renamed){$control->query('RENAME TABLE `'.$database.'`.governance_configepoch_absent TO '.$table);}
  }
  $after=queryfx_all($conn,'SELECT * FROM %R WHERE namespace=%s AND configKey=%s',new PhabricatorConfigEntry(),'default',$key);
  if(!$row_refused || !$table_refused || $before!==$after ||
    $conn->isInsideTransaction() || $conn->isReadLocking() || $conn->isWriteLocking()){
    throw new RuntimeException('Mandatory installed generation failure did not preserve native state.');
  }
  return array('exact_mandatory_keys_verified'=>true,'actual_missing_row_write_refused'=>true,
    'actual_missing_table_write_refused'=>true,'actual_config_state_preserved'=>true,
    'native_transaction_and_lock_levels_restored'=>true,'optional_features_enabled'=>false);
  }finally{mysqli_report($mode);}
}
