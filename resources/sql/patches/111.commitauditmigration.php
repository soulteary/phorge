<?php

// The commit and commit-data models are gone with tracked repositories. The
// tables and their rows are not: the schema history which creates them is
// retained, and this patch still has to run against installations whose schema
// predates it.

final class PhabricatorCommitAuditMigrationCommitDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commit';
  }

}

final class PhabricatorCommitAuditMigrationDataDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commitdata';
  }

}

echo pht('Updating old commit authors...')."\n";
$table = new PhabricatorCommitAuditMigrationCommitDAO();
$table->openTransaction();

$conn = $table->establishConnection('w');
$data = new PhabricatorCommitAuditMigrationDataDAO();
$commits = queryfx_all(
  $conn,
  'SELECT c.id id, c.authorPHID authorPHID, d.commitDetails details
    FROM %T c JOIN %T d ON d.commitID = c.id
    WHERE c.authorPHID IS NULL
    FOR UPDATE',
  $table->getTableName(),
  $data->getTableName());

foreach ($commits as $commit) {
  $id = $commit['id'];
  $details = json_decode($commit['details'], true);
  $author_phid = idx($details, 'authorPHID');
  if ($author_phid) {
    queryfx(
      $conn,
      'UPDATE %T SET authorPHID = %s WHERE id = %d',
      $table->getTableName(),
      $author_phid,
      $id);
    echo "#{$id}\n";
  }
}

$table->saveTransaction();
echo pht('Done.')."\n";


echo pht('Updating old commit %s...', 'mailKeys')."\n";
$table->openTransaction();

$commits = queryfx_all(
  $conn,
  'SELECT id FROM %T WHERE mailKey = %s FOR UPDATE',
  $table->getTableName(),
  '');

foreach ($commits as $commit) {
  $id = $commit['id'];
  queryfx(
    $conn,
    'UPDATE %T SET mailKey = %s WHERE id = %d',
    $table->getTableName(),
    Filesystem::readRandomCharacters(20),
    $id);
  echo "#{$id}\n";
}

$table->saveTransaction();
echo pht('Done.')."\n";
