<?php

/** Inspect actual native schema comparison; never execute an adjustment. */
function governance_native_schema_cases($fixture, callable $check) {
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
  $check(count($comparison)===1,'native-schema-comparison-single-owned-native-primary');
  $server=head($comparison);
  $database=$server->getDatabase($fixture->getNamespace().'_user');
  $check($database instanceof PhabricatorConfigDatabaseSchema && !$database->getAccessDenied(),
    'native-schema-comparison-owned-people-database-readable');
  $tables=array('organization_guard','organization_identityepoch','organization_unit',
    'organization_member','organization_appointment','organization_primarydepartment',
    'organization_operation','organization_audit','organization_approval',
    'governance_mediabrowser','governance_mediaexchange','governance_mediacredential','governance_mediaquota',
    'organization_transfer','organization_handover','organization_projectdisposition','organization_outbox');
  foreach($tables as $name) {
    $table=$database->getTable($name);
    $check($table instanceof PhabricatorConfigTableSchema && !$table->getAllIssues(),
      'native-schema-'.$name.'-matches-retained-adjustment-spec');
  }
  $mail_database=$server->getDatabase($fixture->getNamespace().'_metamta');
  foreach(array('governance_notice_event','governance_notice_delivery')as$name){
    $table=$mail_database->getTable($name);
    $check($table instanceof PhabricatorConfigTableSchema&&!$table->getAllIssues(),
      'native-schema-'.$name.'-matches-retained-adjustment-spec');
  }
  return array('native_schema_comparison_executed'=>true,'candidate_tables_compared'=>count($tables)+2,
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
