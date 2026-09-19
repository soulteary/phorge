<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialActiveDiffMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

$table = new DifferentialActiveDiffMigrationDAO();
$conn = $table->establishConnection('w');
$diff_table = new DifferentialDiff();

$iterator = new LiskRawMigrationIterator($conn, $table->getTableName());
foreach ($iterator as $revision) {
  $revision_id = $revision['id'];

  $diff_row = queryfx_one(
    $conn,
    'SELECT phid FROM %T WHERE revisionID = %d ORDER BY id DESC LIMIT 1',
    $diff_table->getTableName(),
    $revision_id);

  if ($diff_row) {
    queryfx(
      $conn,
      'UPDATE %T SET activeDiffPHID = %s WHERE id = %d',
      $table->getTableName(),
      $diff_row['phid'],
      $revision_id);
  }
}
