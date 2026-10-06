<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialInlineMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

$revision_table = new DifferentialInlineMigrationDAO();
$conn_w = $revision_table->establishConnection('w');
$conn_w->openTransaction();

// DifferentialRevisionPHIDType::TYPECONST. The class was removed with
// Differential revisions; a PHID type is a stored string, so the literal is
// what PHIDs written before the removal carry.
$revision_type = 'DREV';

$src_table = 'differential_inlinecomment';
$dst_table = 'differential_transaction_comment';

echo pht('Migrating Differential inline comments to new format...')."\n";

$content_source = PhabricatorContentSource::newForSource(
  PhabricatorOldWorldContentSource::SOURCECONST)->serialize();

$rows = new LiskRawMigrationIterator($conn_w, $src_table);
foreach ($rows as $row) {
  $id = $row['id'];

  $revision_id = $row['revisionID'];

  echo pht('Migrating inline #%d (%s)...', $id, "D{$revision_id}")."\n";

  $revision_row = queryfx_one(
    $conn_w,
    'SELECT phid FROM %T WHERE id = %d',
    $revision_table->getTableName(),
    $revision_id);
  if (!$revision_row) {
    continue;
  }

  $revision_phid = $revision_row['phid'];

  if ($row['commentID']) {
    $xaction_phid = PhabricatorPHID::generateNewPHID(
      PhabricatorApplicationTransactionTransactionPHIDType::TYPECONST,
      $revision_type);
  } else {
    $xaction_phid = null;
  }

  $comment_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorPHIDConstants::PHID_TYPE_XCMT,
    $revision_type);

  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T
      (id, phid, transactionPHID, authorPHID, viewPolicy, editPolicy,
        commentVersion, content, contentSource, isDeleted,
        dateCreated, dateModified, revisionPHID, changesetID,
        isNewFile, lineNumber, lineLength, hasReplies, legacyCommentID)
      VALUES (%d, %s, %ns, %s, %s, %s,
        %d, %s, %s, %d,
        %d, %d, %s, %nd,
        %d, %d, %d, %d, %nd)',
    $dst_table,

    // id, phid, transactionPHID, authorPHID, viewPolicy, editPolicy
    $row['id'],
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

    // dateCreated, dateModified, revisionPHID, changesetID
    $row['dateCreated'],
    $row['dateModified'],
    $revision_phid,
    $row['changesetID'],

    // isNewFile, lineNumber, lineLength, hasReplies, legacyCommentID
    $row['isNewFile'],
    $row['lineNumber'],
    $row['lineLength'],
    0,
    $row['commentID']);

}

$conn_w->saveTransaction();
echo pht('Done.')."\n";
