<?php

// The Harbormaster application has been removed, so its models no longer
// exist. This patch still has to run against installations whose schema
// predates it, so it declares the minimum it needs and reads raw rows instead
// of Lisk objects. HarbormasterDAO is retained for the legacy "harbormaster"
// database.
final class HarbormasterAbortedMigrationBuildableDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildable';
  }

}

final class HarbormasterAbortedMigrationBuildDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_build';
  }

}

$table = new HarbormasterAbortedMigrationBuildableDAO();
$conn = $table->establishConnection('w');

$build_table = new HarbormasterAbortedMigrationBuildDAO();

$iterator = new LiskRawMigrationIterator($conn, $table->getTableName());
foreach ($iterator as $buildable) {
  if ($buildable['buildableStatus'] !== 'building') {
    continue;
  }

  $aborted = queryfx_one(
    $conn,
    'SELECT * FROM %T WHERE buildablePHID = %s AND buildStatus = %s
      LIMIT 1',
    $build_table->getTableName(),
    $buildable['phid'],
    'aborted');
  if (!$aborted) {
    continue;
  }

  queryfx(
    $conn,
    'UPDATE %T SET buildableStatus = %s WHERE id = %d',
    $table->getTableName(),
    'failed',
    $buildable['id']);
}
