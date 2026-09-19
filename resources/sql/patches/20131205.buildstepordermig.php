<?php

// The Harbormaster application has been removed, so its models no longer
// exist. This patch still has to run against installations whose schema
// predates it, so it declares the minimum it needs and reads raw rows instead
// of Lisk objects. HarbormasterDAO is retained for the legacy "harbormaster"
// database.
final class HarbormasterStepOrderMigrationPlanDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildplan';
  }

}

$table = new HarbormasterStepOrderMigrationPlanDAO();
$conn_w = $table->establishConnection('w');

// Since the build step query orders on "sequence", we can't use the built in
// database access here.

$plan_iterator = new LiskRawMigrationIterator($conn_w, $table->getTableName());

foreach ($plan_iterator as $plan) {
  $planname = $plan['name'];
  echo pht('Migrating steps in %s...', $planname)."\n";

  $rows = queryfx_all(
    $conn_w,
    'SELECT id, sequence FROM harbormaster_buildstep '.
    'WHERE buildPlanPHID = %s '.
    'ORDER BY id ASC',
    $plan['phid']);

  $sequence = 1;
  foreach ($rows as $row) {
    $id = $row['id'];
    $existing = $row['sequence'];
    if ($existing != 0) {
      echo "  - ".pht('%d (already migrated)...', $id)."\n";
      continue;
    }
    echo "  - ".pht('%d to position %s...', $id, $sequence)."\n";
    queryfx(
      $conn_w,
      'UPDATE harbormaster_buildstep '.
      'SET sequence = %d '.
      'WHERE id = %d',
      $sequence,
      $id);
    $sequence++;
  }
}

echo pht('Done.')."\n";
