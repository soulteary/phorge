<?php

// PhabricatorRepositoryPushLog is gone. Its generatePHID() produced a PHID of
// type "PSHL"; that is the stored prefix these rows carry, so the literal
// replaces the removed PHID type constant.
final class PhabricatorPushLogPHIDMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_pushlog';
  }

}

$table = new PhabricatorPushLogPHIDMigrationDAO();
$conn_w = $table->establishConnection('w');

echo pht('Assigning PHIDs to push logs...')."\n";

$logs = new LiskRawMigrationIterator($conn_w, $table->getTableName());
foreach ($logs as $log) {
  $id = $log['id'];
  echo pht('Updating %s...', $id)."\n";
  queryfx(
    $conn_w,
    'UPDATE %T SET phid = %s WHERE id = %d',
    $table->getTableName(),
    PhabricatorPHID::generateNewPHID('PSHL'),
    $id);
}

echo pht('Done.')."\n";
