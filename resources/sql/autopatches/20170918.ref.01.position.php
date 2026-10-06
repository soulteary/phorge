<?php

// PhabricatorRepositoryRefPosition is gone; the table is not. The model was
// used for a connection, a table name, and row deletion.
final class PhabricatorRefPositionMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_refposition';
  }

}

$table = new PhabricatorRefPositionMigrationDAO();
$conn = $table->establishConnection('w');
$key_name = 'key_position';

try {
  queryfx(
    $conn,
    'ALTER TABLE %T DROP KEY %T',
    $table->getTableName(),
    $key_name);
} catch (AphrontQueryException $ex) {
  // This key may or may not exist, depending on exactly when the install
  // ran previous migrations and adjustments. We're just dropping it if it
  // does exist.

  // We're doing this first (outside of the lock) because the MySQL
  // documentation says "if you ALTER TABLE a locked table, it may become
  // unlocked".
}

// The rows are read before the table is locked: LiskRawMigrationIterator
// pages with its own SELECTs, and "LOCK TABLES ... WRITE" would not permit
// them under the same name.
$positions = array();
foreach (new LiskRawMigrationIterator($conn, $table->getTableName())
  as $position) {

  $positions[] = $position;
}

queryfx(
  $conn,
  'LOCK TABLES %T WRITE',
  $table->getTableName());

$seen = array();
foreach ($positions as $position) {
  $cursor_id = $position['cursorID'];
  $hash = $position['commitIdentifier'];

  // If this is the first copy of this row we've seen, mark it as seen and
  // move on.
  if (empty($seen[$cursor_id][$hash])) {
    $seen[$cursor_id][$hash] = true;
    continue;
  }

  // Otherwise, get rid of this row as it duplicates a row we saw previously.
  queryfx(
    $conn,
    'DELETE FROM %T WHERE id = %d',
    $table->getTableName(),
    $position['id']);
}

queryfx(
  $conn,
  'ALTER TABLE %T ADD UNIQUE KEY %T (cursorID, commitIdentifier)',
  $table->getTableName(),
  $key_name);

queryfx(
  $conn,
  'UNLOCK TABLES');
