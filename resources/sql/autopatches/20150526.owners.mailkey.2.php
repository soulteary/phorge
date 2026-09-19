<?php

// The Owners application has been removed, so PhabricatorOwnersPackage no
// longer exists. This patch still has to run against installations whose
// schema predates it, and all it ever wanted from that class was a connection
// to the owners database and the table name, so declare the minimum here.
//
// Database "owners", table "owners_package": the same values
// PhabricatorOwnersDAO and PhabricatorLiskDAO::getTableName() produced.
final class PhabricatorOwnersMailKeyMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'owners';
  }

  public function getTableName() {
    return 'owners_package';
  }

}

$table = new PhabricatorOwnersMailKeyMigrationDAO();
$conn_w = $table->establishConnection('w');
$iterator = new LiskRawMigrationIterator($conn_w, $table->getTableName());
foreach ($iterator as $package) {
  $id = $package['id'];

  echo pht('Adding mail key for package %d...', $id);
  echo "\n";

  queryfx(
    $conn_w,
    'UPDATE %T SET mailKey = %s WHERE id = %d',
    $table->getTableName(),
    Filesystem::readRandomCharacters(20),
    $id);
}
