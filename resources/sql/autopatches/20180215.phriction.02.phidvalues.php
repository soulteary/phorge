<?php

$table = 'phriction_content';
$conn = $this->getConn('phriction');

foreach (new LiskRawMigrationIterator($conn, $table) as $row) {
  if (strlen($row['phid'])) {
    continue;
  }

  queryfx(
    $conn,
    'UPDATE %T SET phid = %s WHERE id = %d',
    $table,
    PhabricatorPHID::generateNewPHID('WRDS'),
    $row['id']);
}
