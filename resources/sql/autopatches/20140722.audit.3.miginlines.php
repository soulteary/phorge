<?php

// PhabricatorAuditTransaction is gone. It survived the Audit removal by
// moving into Diffusion with its name and application intact, and has now gone
// with Diffusion. This patch wanted it only for a connection on the legacy
// "audit" database.
//
// "CMIT" is the stored PHID type of a commit; the PHID type class went with
// commits.
final class PhabricatorAuditInlineMigrationDAO extends PhabricatorAuditDAO {

  public function getTableName() {
    return 'audit_transaction';
  }

}

$audit_table = new PhabricatorAuditInlineMigrationDAO();
$conn_w = $audit_table->establishConnection('w');
$conn_w->openTransaction();

$src_table = 'audit_inlinecomment';
$dst_table = 'audit_transaction_comment';

echo pht('Migrating Audit inline comments to new format...')."\n";

$content_source = PhabricatorContentSource::newForSource(
  PhabricatorOldWorldContentSource::SOURCECONST)->serialize();

$rows = new LiskRawMigrationIterator($conn_w, $src_table);
foreach ($rows as $row) {
  $id = $row['id'];

  echo pht('Migrating inline #%d...', $id);

  if ($row['auditCommentID']) {
    $xaction_phid = PhabricatorPHID::generateNewPHID(
      PhabricatorApplicationTransactionTransactionPHIDType::TYPECONST,
      'CMIT');
  } else {
    $xaction_phid = null;
  }

  $comment_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorPHIDConstants::PHID_TYPE_XCMT,
    'CMIT');

  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T
      (id, phid, transactionPHID, authorPHID, viewPolicy, editPolicy,
        commentVersion, content, contentSource, isDeleted,
        dateCreated, dateModified, commitPHID, pathID,
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

    // dateCreated, dateModified, commitPHID, pathID
    $row['dateCreated'],
    $row['dateModified'],
    $row['commitPHID'],
    $row['pathID'],

    // isNewFile, lineNumber, lineLength, hasReplies, legacyCommentID
    $row['isNewFile'],
    $row['lineNumber'],
    $row['lineLength'],
    0,
    $row['auditCommentID']);

}

$conn_w->saveTransaction();
echo pht('Done.')."\n";
