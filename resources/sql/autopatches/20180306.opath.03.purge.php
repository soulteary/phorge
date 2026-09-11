<?php

// The Owners application has been removed, so PhabricatorOwnersPath no longer
// exists. This patch still has to run against installations whose schema
// predates it, and all it ever wanted from that class was a connection to the
// owners database and the table name, so declare the minimum here.
//
// Database "owners", table "owners_path": the same values
// PhabricatorOwnersDAO and PhabricatorLiskDAO::getTableName() produced.
final class PhabricatorOwnersPathPurgeMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'owners';
  }

  public function getTableName() {
    return 'owners_path';
  }

}

$table = new PhabricatorOwnersPathPurgeMigrationDAO();
$conn = $table->establishConnection('w');

$seen = array();
$iterator = new LiskRawMigrationIterator($conn, $table->getTableName());
foreach ($iterator as $path) {
  $package_id = $path['packageID'];
  $repository_phid = $path['repositoryPHID'];
  $path_index = $path['pathIndex'];

  if (!isset($seen[$package_id][$repository_phid][$path_index])) {
    $seen[$package_id][$repository_phid][$path_index] = true;
    continue;
  }

  queryfx(
    $conn,
    'DELETE FROM %T WHERE id = %d',
    $table->getTableName(),
    $path['id']);
}
