<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialCommentTextMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

$revision_table = new DifferentialCommentTextMigrationDAO();
$conn_w = $revision_table->establishConnection('w');
$rows = new LiskRawMigrationIterator($conn_w, 'differential_comment');

// DifferentialRevisionPHIDType::TYPECONST. The class was removed with
// Differential revisions; a PHID type is a stored string, so the literal is
// what PHIDs written before the removal carry.
$revision_type = 'DREV';

$content_source = PhabricatorContentSource::newForSource(
  PhabricatorOldWorldContentSource::SOURCECONST)->serialize();

echo pht('Migrating Differential comment text to modern storage...')."\n";
foreach ($rows as $row) {
  $id = $row['id'];
  echo pht('Migrating Differential comment %d...', $id)."\n";
  if (!strlen($row['content'])) {
    echo pht('Comment has no text, continuing.')."\n";
    continue;
  }

  $revision_row = queryfx_one(
    $conn_w,
    'SELECT phid FROM %T WHERE id = %d',
    $revision_table->getTableName(),
    $row['revisionID']);
  if (!$revision_row) {
    echo pht('Comment has no valid revision, continuing.')."\n";
    continue;
  }

  $revision_phid = $revision_row['phid'];

  $dst_table = 'differential_inline_comment';

  $xaction_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorApplicationTransactionTransactionPHIDType::TYPECONST,
    $revision_type);

  $comment_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorPHIDConstants::PHID_TYPE_XCMT,
    $revision_type);

  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T
      (phid, transactionPHID, authorPHID, viewPolicy, editPolicy,
        commentVersion, content, contentSource, isDeleted,
        dateCreated, dateModified, revisionPHID, changesetID,
        legacyCommentID)
      VALUES (%s, %s, %s, %s, %s,
        %d, %s, %s, %d,
        %d, %d, %s, %nd,
        %d)',
    'differential_transaction_comment',

    // phid, transactionPHID, authorPHID, viewPolicy, editPolicy
    $comment_phid,
    $xaction_phid,
    $row['authorPHID'],
    'public',
    $row['authorPHID'],

    // commentVersion, content, contentSource, isDeleted
    1,
    $row['content'],
    $content_source,
    0,

    // dateCreated, dateModified, revisionPHID, changesetID, legacyCommentID
    $row['dateCreated'],
    $row['dateModified'],
    $revision_phid,
    null,
    $row['id']);
}

echo pht('Done.')."\n";
