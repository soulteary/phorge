<?php

// Populate the "maxVersion" column by copying the maximum "version" from the
// content table.

$document_table = 'phriction_document';
$content_table = 'phriction_content';

$conn = $this->getConn('phriction');

$iterator = new LiskRawMigrationIterator(
  $conn,
  $document_table);
foreach ($iterator as $row) {
  $content = queryfx_one(
    $conn,
    'SELECT MAX(version) max FROM %T WHERE documentPHID = %s',
    $content_table,
    $row['phid']);
  if (!$content) {
    continue;
  }

  queryfx(
    $conn,
    'UPDATE %T SET maxVersion = %d WHERE id = %d',
    $document_table,
    $content['max'],
    $row['id']);
}
