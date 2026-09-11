<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialAuxiliaryFieldMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

$conn_w = id(new DifferentialAuxiliaryFieldMigrationDAO())
  ->establishConnection('w');
$rows = new LiskRawMigrationIterator($conn_w, 'differential_auxiliaryfield');

echo pht('Modernizing Differential auxiliary field storage...')."\n";

$table_name = 'differential_customfieldstorage';
foreach ($rows as $row) {
  $id = $row['id'];
  echo pht('Migrating row %d...', $id)."\n";
  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T (objectPHID, fieldIndex, fieldValue)
      VALUES (%s, %s, %s)',
    $table_name,
    $row['revisionPHID'],
    PhabricatorHash::digestForIndex($row['name']),
    $row['value']);
}

echo pht('Done.')."\n";
