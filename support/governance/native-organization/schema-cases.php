<?php

/** Inspect actual native schema comparison; never execute an adjustment. */
function governance_native_schema_cases($fixture, callable $check) {
  $fixture->assertOwned();
  $query=new PhabricatorConfigSchemaQuery();
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
    'governance_mediabrowser','governance_mediaexchange','governance_mediacredential','governance_mediaquota');
  foreach($tables as $name) {
    $table=$database->getTable($name);
    $check($table instanceof PhabricatorConfigTableSchema && !$table->getAllIssues(),
      'native-schema-'.$name.'-matches-retained-adjustment-spec');
  }
  return array('native_schema_comparison_executed'=>true,'candidate_tables_compared'=>count($tables),
    'schema_adjustment_executed'=>false,'all_existing_schema_issues_claimed_resolved'=>false);
}
