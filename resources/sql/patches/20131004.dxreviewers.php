<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialReviewerMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

$table = new DifferentialReviewerMigrationDAO();
$conn_w = $table->establishConnection('w');

// DifferentialRevisionHasReviewerEdgeType::EDGECONST and
// DifferentialReviewerStatus::STATUS_ADDED. Both classes were removed with
// Differential revisions; an edge type is a stored integer and a reviewer
// status is a stored string, so the literals are what rows written before the
// removal carry.
$reviewer_edge = 35;
$status_added = 'added';

// NOTE: We migrate by revision because the relationship table doesn't have
// an "id" column.

$revisions = new LiskRawMigrationIterator($conn_w, $table->getTableName());
foreach ($revisions as $revision) {
  $revision_id = $revision['id'];
  $revision_phid = $revision['phid'];

  echo pht('Migrating reviewers for %s...', "D{$revision_id}")."\n";

  $reviewer_phids = queryfx_all(
    $conn_w,
    'SELECT objectPHID FROM %T WHERE revisionID = %d
      AND relation = %s ORDER BY sequence',
    'differential_relationship',
    $revision_id,
    'revw');
  $reviewer_phids = ipull($reviewer_phids, 'objectPHID');

  if (!$reviewer_phids) {
    continue;
  }

  $editor = new PhabricatorEdgeEditor();
  foreach ($reviewer_phids as $dst) {
    if (phid_get_type($dst) == PhabricatorPHIDConstants::PHID_TYPE_UNKNOWN) {
      // At least one old install ran into some issues here. Skip the row if we
      // can't figure out what the destination PHID is.
      continue;
    }

    $editor->addEdge(
      $revision_phid,
      $reviewer_edge,
      $dst,
      array(
        'data' => array(
          'status' => $status_added,
        ),
      ));
  }

  $editor->save();
}

echo pht('Done.')."\n";
