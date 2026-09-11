<?php

// The Paste application has been removed, so PhabricatorPaste no longer
// exists. This patch still has to run against installations whose schema
// predates it, and all it ever wanted from that class was a connection to the
// paste database and the table name, so declare the minimum here rather than
// keeping the application alive for one migration.
//
// Database "paste", table "paste": the same values PhabricatorPasteDAO and
// PhabricatorLiskDAO::getTableName() produced.
final class PhabricatorPasteMailKeyMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'paste';
  }

  public function getTableName() {
    return 'paste';
  }

}

$paste_table = new PhabricatorPasteMailKeyMigrationDAO();
$paste_conn = $paste_table->establishConnection('w');

$properties_table = new PhabricatorMetaMTAMailProperties();
$conn = $properties_table->establishConnection('w');

$iterator = new LiskRawMigrationIterator(
  $paste_conn,
  $paste_table->getTableName());

foreach ($iterator as $row) {
  // The mailKey field might be unpopulated.
  // This should have happened in the 20130805.pastemailkeypop.php migration,
  // but that will not work on newer installations, because the paste table
  // was renamed in between.
  $mailkey = $row['mailKey'] ?? Filesystem::readRandomCharacters(20);

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
        'mailKey' => $mailkey,
      )),
    PhabricatorTime::getNow(),
    PhabricatorTime::getNow());
}
