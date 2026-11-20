<?php

// Update the PhrictionDocument and PhrictionContent tables to refer to one
// another by PHID instead of by ID.

$document_table = 'phriction_document';
$content_table = 'phriction_content';

$conn = $this->getConn('phriction');

$document_iterator = new LiskRawMigrationIterator(
  $conn,
  $document_table);
foreach ($document_iterator as $row) {
  $content_id = $row['contentID'];

  $content_row = queryfx_one(
    $conn,
    'SELECT phid, dateCreated FROM %T WHERE id = %d',
    $content_table,
    $content_id);

  if (!$content_row) {
    continue;
  }

  queryfx(
    $conn,
    'UPDATE %T SET contentPHID = %s, editedEpoch = %d WHERE id = %d',
    $document_table,
    $content_row['phid'],
    $content_row['dateCreated'],
    $row['id']);
}

$content_iterator = new LiskRawMigrationIterator(
  $conn,
  $content_table);
foreach ($content_iterator as $row) {
  $document_id = $row['documentID'];

  $document_row = queryfx_one(
    $conn,
    'SELECT phid FROM %T WHERE id = %d',
    $document_table,
    $document_id);
  if (!$document_row) {
    continue;
  }

  queryfx(
    $conn,
    'UPDATE %T SET documentPHID = %s WHERE id = %d',
    $content_table,
    $document_row['phid'],
    $row['id']);
}
