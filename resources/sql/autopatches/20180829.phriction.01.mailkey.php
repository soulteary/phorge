<?php

$document_table = 'phriction_document';
$document_conn = $this->getConn('phriction');
$document_name = $document_table;

$properties_table = new PhabricatorMetaMTAMailProperties();
$conn = $properties_table->establishConnection('w');

$iterator = new LiskRawMigrationIterator($document_conn, $document_name);
foreach ($iterator as $row) {
  queryfx(
    $conn,
    'INSERT IGNORE INTO %T
        (objectPHID, mailProperties, dateCreated, dateModified)
      VALUES
        (%s, %s, %d, %d)',
    $properties_table->getTableName(),
    $row['phid'],
    phutil_json_encode(
      array(
        'mailKey' => $row['mailKey'],
      )),
    PhabricatorTime::getNow(),
    PhabricatorTime::getNow());
}
