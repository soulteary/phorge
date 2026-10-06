<?php

// PhabricatorRepositoryRefCursor is gone. Ref cursor PHIDs are of type
// "RREF", which is the stored prefix these rows carry.
final class PhabricatorRefCursorPHIDMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_refcursor';
  }

}

$table = new PhabricatorRefCursorPHIDMigrationDAO();
$conn_w = $table->establishConnection('w');

foreach (new LiskRawMigrationIterator($conn_w, $table->getTableName())
  as $cursor) {

  if (phutil_nonempty_string($cursor['phid'])) {
    continue;
  }

  queryfx(
    $conn_w,
    'UPDATE %T SET phid = %s WHERE id = %d',
    $table->getTableName(),
    PhabricatorPHID::generateNewPHID('RREF'),
    $cursor['id']);
}
