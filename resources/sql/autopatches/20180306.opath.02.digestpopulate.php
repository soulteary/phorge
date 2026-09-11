<?php

// The Owners application has been removed, so PhabricatorOwnersPath no longer
// exists. This patch still has to run against installations whose schema
// predates it, and all it ever wanted from that class was a connection to the
// owners database and the table name, so declare the minimum here.
//
// Database "owners", table "owners_path": the same values
// PhabricatorOwnersDAO and PhabricatorLiskDAO::getTableName() produced.
final class PhabricatorOwnersPathDigestMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'owners';
  }

  public function getTableName() {
    return 'owners_path';
  }

}

$table = new PhabricatorOwnersPathDigestMigrationDAO();
$conn = $table->establishConnection('w');

$iterator = new LiskRawMigrationIterator($conn, $table->getTableName());
foreach ($iterator as $path) {
  $index = PhabricatorHash::digestForIndex($path['path']);

  if ($index === $path['pathIndex']) {
    continue;
  }

  queryfx(
    $conn,
    'UPDATE %T SET pathIndex = %s WHERE id = %d',
    $table->getTableName(),
    $index,
    $path['id']);
}
