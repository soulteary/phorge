<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialDependencyMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

echo pht('Migrating differential dependencies to edges...')."\n";
$table = new DifferentialDependencyMigrationDAO();
$conn = $table->establishConnection('w');
$table->openTransaction();

// DifferentialRevisionPHIDType::TYPECONST and
// DifferentialRevisionDependsOnRevisionEdgeType::EDGECONST. Both classes were
// removed with Differential revisions; a PHID type is a stored string and an
// edge type is a stored integer, so the literals are what rows written before
// the removal carry.
$revision_type = 'DREV';
$depends_on_edge = 5;

$iterator = new LiskRawMigrationIterator($conn, $table->getTableName());
foreach ($iterator as $rev) {
  $id = $rev['id'];
  echo pht('Revision %d: ', $id);

  // getAttachedPHIDs() read array_keys() of this map for the given type.
  $raw_attached = $rev['attached'];
  if (strlen((string)$raw_attached)) {
    $attached = phutil_json_decode($raw_attached);
  } else {
    $attached = array();
  }

  $deps = array_keys(idx($attached, $revision_type, array()));
  if (!$deps) {
    echo "-\n";
    continue;
  }

  $editor = new PhabricatorEdgeEditor();
  foreach ($deps as $dep) {
    $editor->addEdge($rev['phid'], $depends_on_edge, $dep);
  }
  $editor->save();
  echo pht('OKAY')."\n";
}

$table->saveTransaction();
echo pht('Done.')."\n";
