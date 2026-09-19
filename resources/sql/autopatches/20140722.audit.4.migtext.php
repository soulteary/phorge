<?php

// PhabricatorAuditTransaction is gone. It survived the Audit removal by
// moving into Diffusion with its name and application intact, and has now gone
// with Diffusion. This patch wanted it only for a connection on the legacy
// "audit" database.
//
// "CMIT" is the stored PHID type of a commit.
final class PhabricatorAuditTextMigrationDAO extends PhabricatorAuditDAO {

  public function getTableName() {
    return 'audit_transaction';
  }

}

$conn_w = id(new PhabricatorAuditTextMigrationDAO())->establishConnection('w');
$rows = new LiskRawMigrationIterator($conn_w, 'audit_comment');

$content_source = PhabricatorContentSource::newForSource(
  PhabricatorOldWorldContentSource::SOURCECONST)->serialize();

echo pht('Migrating Audit comment text to modern storage...')."\n";
foreach ($rows as $row) {
  $id = $row['id'];
  echo pht('Migrating Audit comment %d...', $id)."\n";
  if (!strlen($row['content'])) {
    echo pht('Comment has no text, continuing.')."\n";
    continue;
  }

  $xaction_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorApplicationTransactionTransactionPHIDType::TYPECONST,
    'CMIT');

  $comment_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorPHIDConstants::PHID_TYPE_XCMT,
    'CMIT');

  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T
      (phid, transactionPHID, authorPHID, viewPolicy, editPolicy,
        commentVersion, content, contentSource, isDeleted,
        dateCreated, dateModified, commitPHID, pathID,
        legacyCommentID)
      VALUES (%s, %s, %s, %s, %s,
        %d, %s, %s, %d,
        %d, %d, %s, %nd,
        %d)',
    'audit_transaction_comment',

    // phid, transactionPHID, authorPHID, viewPolicy, editPolicy
    $comment_phid,
    $xaction_phid,
    $row['actorPHID'],
    'public',
    $row['actorPHID'],

    // commentVersion, content, contentSource, isDeleted
    1,
    $row['content'],
    $content_source,
    0,

    // dateCreated, dateModified, commitPHID, pathID, legacyCommentID
    $row['dateCreated'],
    $row['dateModified'],
    $row['targetPHID'],
    null,
    $row['id']);
}

echo pht('Done.')."\n";
