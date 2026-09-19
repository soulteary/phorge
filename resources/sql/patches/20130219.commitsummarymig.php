<?php

// The commit and commit-data models are gone with tracked repositories. The
// tables and their rows are not: the schema history which creates them is
// retained, and this patch still has to run against installations whose schema
// predates it.

final class PhabricatorCommitSummaryMigrationCommitDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commit';
  }

}

final class PhabricatorCommitSummaryMigrationDataDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commitdata';
  }

}

echo pht('Backfilling commit summaries...')."\n";

$table = new PhabricatorCommitSummaryMigrationCommitDAO();
$conn_w = $table->establishConnection('w');
$data_table = new PhabricatorCommitSummaryMigrationDataDAO();

$commits = new LiskRawMigrationIterator($conn_w, $table->getTableName());
foreach ($commits as $commit) {
  $id = $commit['id'];
  echo pht('Filling Commit #%d', $id)."\n";

  if (phutil_nonempty_string($commit['summary'])) {
    continue;
  }

  $data = queryfx_one(
    $conn_w,
    'SELECT summary FROM %T WHERE commitID = %d',
    $data_table->getTableName(),
    $id);

  if (!$data) {
    continue;
  }

  queryfx(
    $conn_w,
    'UPDATE %T SET summary = %s WHERE id = %d',
    $table->getTableName(),
    $data['summary'],
    $id);
}

echo pht('Done.')."\n";
