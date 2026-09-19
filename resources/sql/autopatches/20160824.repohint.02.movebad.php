<?php

// This patch moved rows out of "repository_badcommit" and into the commit
// hint table. DiffusionCommitQuery and PhabricatorRepositoryCommitHint are
// both gone, so the commit is resolved with a direct query and the hint is
// written with the same INSERT that updateHint() performed. "unreadable" is
// the stored value of HINT_UNREADABLE.
final class PhabricatorBadCommitMigrationCommitDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commit';
  }

}

final class PhabricatorBadCommitMigrationHintDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commithint';
  }

}

final class PhabricatorBadCommitMigrationRepositoryDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository';
  }

}

$commit_table = new PhabricatorBadCommitMigrationCommitDAO();
$hint_table = new PhabricatorBadCommitMigrationHintDAO();
$repository_table = new PhabricatorBadCommitMigrationRepositoryDAO();
$conn = $commit_table->establishConnection('w');

$rows = queryfx_all(
  $conn,
  'SELECT fullCommitName FROM repository_badcommit');

foreach ($rows as $row) {
  $identifier = $row['fullCommitName'];

  // "fullCommitName" is "r" plus the repository callsign plus the commit
  // identifier, which is how DiffusionCommitQuery resolved it.
  $commit = null;
  if (preg_match('/^r([A-Z]+)([a-z0-9]+)\z/', $identifier, $matches)) {
    $commit = queryfx_one(
      $conn,
      'SELECT c.commitIdentifier commitIdentifier, r.phid repositoryPHID
        FROM %T c JOIN %T r ON c.repositoryID = r.id
        WHERE r.callsign = %s AND c.commitIdentifier LIKE %>',
      $commit_table->getTableName(),
      $repository_table->getTableName(),
      $matches[1],
      $matches[2]);
  }

  if (!$commit) {
    echo tsprintf(
      "%s\n",
      pht(
        'Skipped hint for "%s", this is not a valid commit.',
        $identifier));
  } else {
    queryfx(
      $conn,
      'INSERT INTO %T
        (repositoryPHID, oldCommitIdentifier, newCommitIdentifier, hintType)
        VALUES (%s, %s, %ns, %s)
        ON DUPLICATE KEY UPDATE
          newCommitIdentifier = VALUES(newCommitIdentifier),
          hintType = VALUES(hintType)',
      $hint_table->getTableName(),
      $commit['repositoryPHID'],
      $commit['commitIdentifier'],
      null,
      'unreadable');

    echo tsprintf(
      "%s\n",
      pht(
        'Updated commit hint for "%s".',
        $identifier));
  }
}
