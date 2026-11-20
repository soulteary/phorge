<?php

// See T13193. We're about to drop the "documentID" column, which is part of
// a UNIQUE KEY. In MariaDB, we must first drop the "documentID" key or we get
// into deep trouble.

// There's no "IF EXISTS" modifier for "ALTER TABLE" so run this as a PHP patch
// instead of an SQL patch.

$table = 'phriction_content';
$conn = $this->getConn('phriction');

try {
  queryfx(
    $conn,
    'ALTER TABLE %T DROP KEY documentID',
    $table);
} catch (AphrontQueryException $ex) {
  // Ignore.
}
