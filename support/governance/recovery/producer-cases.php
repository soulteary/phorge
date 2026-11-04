<?php

/** Inspect actual native schema comparison; never execute an adjustment. */
function governance_recovery_producer_schema_cases($fixture, callable $check) {
  $fixture->assertOwned();
  $namespace_property=new ReflectionProperty(PhabricatorLiskDAO::class,'namespaceStack');
  $before=$namespace_property->getValue();
  if($before!==array()){throw new RuntimeException('native_schema_inherited_namespace_refused');}
  $query=new PhabricatorConfigSchemaQuery();$apis=array();
  try {
    // Native getAPI() creates a new StorageManagementAPI and pushes a namespace
    // on each uncached lookup. Own/cache these exact APIs and restore only their
    // pushes; do not leave an implicit namespace override for later fixtures.
    foreach($query->getRefs()as$ref){
      $api=(new PhabricatorStorageManagementAPI())->setRef($ref)
        ->setUser($ref->getUser())->setHost($ref->getHost())->setPort($ref->getPort())
        ->setPassword($ref->getPass());
      $api->setNamespace($fixture->getNamespace());$apis[]=$api;
    }
    $query->setAPIs($apis);
  $expected=$query->loadExpectedSchemata();
  $actual=$query->loadActualSchemata();
  $comparison=$query->buildComparisonSchemata($expected,$actual);
  if(count($comparison)!==1){throw new RuntimeException('recovery_producer_schema_primary_unknown');}
  $server=head($comparison);
  $database=$server->getDatabase($fixture->getNamespace().'_user');
  if(!($database instanceof PhabricatorConfigDatabaseSchema)||$database->getAccessDenied()){
    throw new RuntimeException('recovery_producer_schema_database_unknown');}
  $tables=array('recovery_restore_request','recovery_restore_operation','recovery_restore_audit');
  foreach($tables as $name) {
    $table=$database->getTable($name);
    $check($table instanceof PhabricatorConfigTableSchema && !$table->getAllIssues(),
      'recovery-producer-schema-'.$name.'-matches-retained-adjustment-spec');
  }
  return array('native_schema_comparison_executed'=>true,'candidate_tables_compared'=>count($tables),
    'schema_adjustment_executed'=>false,'all_existing_schema_issues_claimed_resolved'=>false);
  } finally {
    $failed=false;$closed=array();
    foreach($apis as$api){
      $connections=(new ReflectionProperty(PhabricatorStorageManagementAPI::class,'conns'))->getValue($api);
      foreach($connections as$users){foreach($users as$databases){foreach($databases as$connection){
        $key=spl_object_id($connection);
        if(isset($closed[$key])){continue;}$closed[$key]=true;
        try{$connection->close();}catch(Throwable$error){$failed=true;}
      }}}
    }
    $owned=$before;foreach($apis as$api){$owned[]=$fixture->getNamespace();}
    if($namespace_property->getValue()!==$owned){$failed=true;}
    else{foreach($apis as$api){PhabricatorLiskDAO::popStorageNamespace();}}
    if($failed||$namespace_property->getValue()!==$before){
      throw new RuntimeException('native_schema_owned_context_cleanup_failed');}
  }
}

/** Real selected User and Session producer boundaries, never a restore permit. */
function governance_recovery_native_producer_cases($fixture,callable $check,array $state) {
  $fixture->assertOwned();
  $schema=governance_recovery_producer_schema_cases($fixture,$check);
  require_once __DIR__.'/guard-cases.php';
  $user=governance_recovery_guard_cases($fixture,$check,$state);
  require_once __DIR__.'/session-producer-cases.php';
  $sessions=governance_recovery_session_producer_cases($fixture,$check,$state);
  return array('schema'=>$schema,'user'=>$user,'sessions'=>$sessions,
    'selected_mechanical_producers_only'=>true,'restore_service_review_profile_proved'=>false,
    'all_13_source_families_complete'=>false,'can_execute'=>false);
}
