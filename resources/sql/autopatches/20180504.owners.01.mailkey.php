<?php

// The Owners application has been removed, so PhabricatorOwnersPackage no
// longer exists. This patch still has to run against installations whose
// schema predates it, and all it ever wanted from that class was a connection
// to the owners database and the table name, so declare the minimum here.
//
// Database "owners", table "owners_package": the same values
// PhabricatorOwnersDAO and PhabricatorLiskDAO::getTableName() produced.
final class PhabricatorOwnersMailPropertyMigrationDAO
  extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'owners';
  }

  public function getTableName() {
    return 'owners_package';
  }

}

$packages_table = new PhabricatorOwnersMailPropertyMigrationDAO();
$packages_conn = $packages_table->establishConnection('w');
$packages_name = $packages_table->getTableName();

$properties_table = new PhabricatorMetaMTAMailProperties();
$conn = $properties_table->establishConnection('w');

$iterator = new LiskRawMigrationIterator($packages_conn, $packages_name);
foreach ($iterator as $package) {
  queryfx(
    $conn,
    'INSERT IGNORE INTO %T
        (objectPHID, mailProperties, dateCreated, dateModified)
      VALUES
        (%s, %s, %d, %d)',
    $properties_table->getTableName(),
    $package['phid'],
    phutil_json_encode(
      array(
        'mailKey' => $package['mailKey'],
      )),
    PhabricatorTime::getNow(),
    PhabricatorTime::getNow());
}
