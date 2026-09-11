<?php

// The Harbormaster application has been removed, so its models no longer
// exist. This patch still has to run against installations whose schema
// predates it, so it declares the minimum it needs and reads raw rows instead
// of Lisk objects. HarbormasterDAO is retained for the legacy "harbormaster"
// database.
final class HarbormasterDependsOnMigrationPlanDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildplan';
  }

}

final class HarbormasterDependsOnMigrationStepDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildstep';
  }

}

$plan_table = new HarbormasterDependsOnMigrationPlanDAO();
$step_table = new HarbormasterDependsOnMigrationStepDAO();
$conn_w = $plan_table->establishConnection('w');

$plan_iterator = new LiskRawMigrationIterator(
  $conn_w,
  $plan_table->getTableName());

foreach ($plan_iterator as $plan) {

  echo pht(
    "Migrating build plan %d: %s...\n",
    $plan['id'],
    $plan['name']);

  // Load all build steps in order using the step sequence.
  $steps = queryfx_all(
    $conn_w,
    'SELECT id, phid, details FROM %T WHERE buildPlanPHID = %s '.
    'ORDER BY sequence ASC;',
    $step_table->getTableName(),
    $plan['phid']);

  $previous_phid = null;
  foreach ($steps as $step) {
    $id = $step['id'];

    $raw_details = $step['details'];
    if (strlen((string)$raw_details)) {
      $details = phutil_json_decode($raw_details);
    } else {
      $details = array();
    }

    if (idx($details, 'dependsOn') !== null) {
      // This plan already contains steps with depends_on set, so
      // we skip since there's nothing to migrate.
      break;
    }

    if ($previous_phid === null) {
      $depends_on = array();
    } else {
      $depends_on = array($previous_phid);
    }

    $details['dependsOn'] = $depends_on;

    queryfx(
      $conn_w,
      'UPDATE %T SET details = %s WHERE id = %d',
      $step_table->getTableName(),
      json_encode($details),
      $id);

    $previous_phid = $step['phid'];

    echo pht(
      "  Migrated build step %d.\n",
      $id);
  }

}
