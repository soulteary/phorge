<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialArmageddonMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

$revision_table = new DifferentialArmageddonMigrationDAO();
$conn_w = $revision_table->establishConnection('w');
$rows = new LiskRawMigrationIterator($conn_w, 'differential_comment');

// These constants came from classes which were removed with Differential
// revisions. Actions, transaction types and PHID types are stored strings and
// edge types are stored integers, so the literals are what rows written before
// the removal carry.

// DifferentialAction::ACTION_COMMENT, ACTION_ADDREVIEWERS, ACTION_ADDCCS and
// ACTION_UPDATE.
$action_comment = 'none';
$action_add_reviewers = 'add_reviewers';
$action_add_ccs = 'add_ccs';
$action_update = 'update';

// DifferentialTransaction::TYPE_INLINE and TYPE_ACTION.
$type_inline = 'differential:inline';
$type_action = 'differential:action';

// DifferentialRevisionUpdateTransaction::TRANSACTIONTYPE.
$type_revision_update = 'differential:update';

// DifferentialRevisionHasReviewerEdgeType::EDGECONST.
$reviewer_edge = 35;

// DifferentialRevisionPHIDType::TYPECONST.
$revision_type = 'DREV';

$content_source = PhabricatorContentSource::newForSource(
  PhabricatorOldWorldContentSource::SOURCECONST)->serialize();

echo pht('Migrating Differential comments to modern storage...')."\n";
foreach ($rows as $row) {
  $id = $row['id'];
  echo pht('Migrating comment %d...', $id)."\n";

  $revision_row = queryfx_one(
    $conn_w,
    'SELECT phid FROM %T WHERE id = %d',
    $revision_table->getTableName(),
    $row['revisionID']);
  if (!$revision_row) {
    echo pht('No revision, continuing.')."\n";
    continue;
  }

  $revision_phid = $revision_row['phid'];

  $comments = queryfx_all(
    $conn_w,
    'SELECT * FROM %T WHERE legacyCommentID = %d',
    'differential_transaction_comment',
    $id);

  $main_comments = array();
  $inline_comments = array();

  foreach ($comments as $comment) {
    if ($comment['changesetID']) {
      $inline_comments[] = $comment;
    } else {
      $main_comments[] = $comment;
    }
  }

  $metadata = json_decode($row['metadata'], true);
  if (!is_array($metadata)) {
    $metadata = array();
  }

  $key_cc = 'added-ccs';
  $key_add_rev = 'added-reviewers';
  $key_rem_rev = 'removed-reviewers';
  $key_diff_id = 'diff-id';

  $xactions = array();

  // Build the main action transaction.
  switch ($row['action']) {
    case $action_comment:
    case $action_add_reviewers:
    case $action_add_ccs:
    case $action_update:
    case $type_inline:
      // These actions will have their transactions created by other rules.
      break;
    default:
      // Otherwise, this is a normal action (like an accept or reject).
      $xactions[] = array(
        'type' => $type_action,
        'old' => null,
        'new' => $row['action'],
      );
      break;
  }

  // Build the diff update transaction, if one exists.
  $diff_id = idx($metadata, $key_diff_id);
  if (!is_scalar($diff_id)) {
    $diff_id = null;
  }

  if ($diff_id || $row['action'] == $action_update) {
    $xactions[] = array(
      'type' => $type_revision_update,
      'old' => null,
      'new' => $diff_id,
    );
  }

  // Build the add/remove reviewers transaction, if one exists.
  $add_rev = idx($metadata, $key_add_rev, array());
  if (!is_array($add_rev)) {
    $add_rev = array();
  }
  $rem_rev = idx($metadata, $key_rem_rev, array());
  if (!is_array($rem_rev)) {
    $rem_rev = array();
  }

  if ($add_rev || $rem_rev) {
    $old = array();
    foreach ($rem_rev as $phid) {
      if (!is_scalar($phid)) {
        continue;
      }
      $old[$phid] = array(
        'src' => $revision_phid,
        'type' => $reviewer_edge,
        'dst' => $phid,
      );
    }

    $new = array();
    foreach ($add_rev as $phid) {
      if (!is_scalar($phid)) {
        continue;
      }
      $new[$phid] = array(
        'src' => $revision_phid,
        'type' => $reviewer_edge,
        'dst' => $phid,
      );
    }

    $xactions[] = array(
      'type' => PhabricatorTransactions::TYPE_EDGE,
      'old' => $old,
      'new' => $new,
      'meta' => array(
        'edge:type' => $reviewer_edge,
      ),
    );
  }

  // Build the CC transaction, if one exists.
  $add_cc = idx($metadata, $key_cc, array());
  if (!is_array($add_cc)) {
    $add_cc = array();
  }

  if ($add_cc) {
    $xactions[] = array(
      'type' => PhabricatorTransactions::TYPE_SUBSCRIBERS,
      'old' => array(),
      'new' => array_fuse($add_cc),
    );
  }


  // Build the main comment transaction.
  foreach ($main_comments as $main) {
    $xactions[] = array(
      'type' => PhabricatorTransactions::TYPE_COMMENT,
      'old' => null,
      'new' => null,
      'phid' => $main['transactionPHID'],
      'comment' => $main,
    );
  }

  // Build inline comment transactions.
  foreach ($inline_comments as $inline) {
    $xactions[] = array(
      'type' => $type_inline,
      'old' => null,
      'new' => null,
      'phid' => $inline['transactionPHID'],
      'comment' => $inline,
    );
  }

  foreach ($xactions as $xaction) {
    // Generate a new PHID, if we don't already have one from the comment
    // table. We pregenerated into the comment table to make this a little
    // easier, so we only need to write to one table.
    $xaction_phid = idx($xaction, 'phid');
    if (!$xaction_phid) {
      $xaction_phid = PhabricatorPHID::generateNewPHID(
        PhabricatorApplicationTransactionTransactionPHIDType::TYPECONST,
        $revision_type);
    }
    unset($xaction['phid']);

    $comment_phid = null;
    $comment_version = 0;
    if (idx($xaction, 'comment')) {
      $comment_phid = $xaction['comment']['phid'];
      $comment_version = 1;
    }

    $old = idx($xaction, 'old');
    $new = idx($xaction, 'new');
    $meta = idx($xaction, 'meta', array());

    queryfx(
      $conn_w,
      'INSERT INTO %T (phid, authorPHID, objectPHID, viewPolicy, editPolicy,
          commentPHID, commentVersion, transactionType, oldValue, newValue,
          contentSource, metadata, dateCreated, dateModified)
        VALUES (%s, %s, %s, %s, %s, %ns, %d, %s, %ns, %ns, %s, %s, %d, %d)',
      'differential_transaction',

      // PHID, authorPHID, objectPHID
      $xaction_phid,
      (string)$row['authorPHID'],
      $revision_phid,

      // viewPolicy, editPolicy, commentPHID, commentVersion
      'public',
      (string)$row['authorPHID'],
      $comment_phid,
      $comment_version,

      // transactionType, oldValue, newValue, contentSource, metadata
      $xaction['type'],
      json_encode($old),
      json_encode($new),
      $content_source,
      json_encode($meta),

      // dates
      $row['dateCreated'],
      $row['dateModified']);
  }

}
echo pht('Done.')."\n";
