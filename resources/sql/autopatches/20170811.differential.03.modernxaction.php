<?php

$map = array(
  '0' => 'needs-review',
  '1' => 'needs-revision',
  '2' => 'accepted',
  '3' => 'published',
  '4' => 'abandoned',
  '5' => 'changes-planned',
);

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialStatusXactionMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_transaction';
  }

}

$table = new DifferentialStatusXactionMigrationDAO();
$conn = $table->establishConnection('w');

$iterator = new LiskRawMigrationIterator($conn, $table->getTableName());
foreach ($iterator as $xaction) {
  $type = $xaction['transactionType'];

  if (($type != 'differential:status') &&
      ($type != 'differential.revision.status')) {
    continue;
  }

  // Raw rows hold the JSON-encoded values the Lisk accessors decoded.
  $old = phutil_json_decode($xaction['oldValue']);
  $new = phutil_json_decode($xaction['newValue']);

  $old = idx($map, $old, $old);
  $new = idx($map, $new, $new);

  queryfx(
    $conn,
    'UPDATE %T SET transactionType = %s, oldValue = %s, newValue = %s
      WHERE id = %d',
    $table->getTableName(),
    'differential.revision.status',
    json_encode($old),
    json_encode($new),
    $xaction['id']);
}
