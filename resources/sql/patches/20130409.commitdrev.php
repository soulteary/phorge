<?php

// The commit and commit-data models are gone with tracked repositories. The
// tables and their rows are not: the schema history which creates them is
// retained, and this patch still has to run against installations whose schema
// predates it.

final class PhabricatorCommitDrevMigrationCommitDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commit';
  }

}

final class PhabricatorCommitDrevMigrationDataDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commitdata';
  }

}

echo pht('Migrating %s to edges...', 'differential.revisionPHID')."\n";
$commit_table = new PhabricatorCommitDrevMigrationCommitDAO();
$data_table = new PhabricatorCommitDrevMigrationDataDAO();
$editor = new PhabricatorEdgeEditor();
$conn_w = $commit_table->establishConnection('w');
$edges = 0;

$commits = new LiskRawMigrationIterator($conn_w, $commit_table->getTableName());
foreach ($commits as $commit) {
  $data = queryfx_one(
    $conn_w,
    'SELECT commitDetails FROM %T WHERE commitID = %d',
    $data_table->getTableName(),
    $commit['id']);
  if (!$data) {
    continue;
  }

  $details = phutil_json_decode($data['commitDetails']);
  $revision_phid = idx($details, 'differential.revisionPHID');
  if (!$revision_phid) {
    continue;
  }

  // DiffusionCommitHasRevisionEdgeType::EDGECONST. The class was removed with
  // Differential revisions; edge type constants are stored integers, so the
  // literal is what rows written before the removal carry.
  $commit_drev = 32;
  $editor->addEdge($commit['phid'], $commit_drev, $revision_phid);
  $edges++;
  if ($edges % 256 == 0) {
    echo '.';
    $editor->save();
    $editor = new PhabricatorEdgeEditor();
  }
}

echo '.';
$editor->save();
echo "\n".pht('Done.')."\n";
