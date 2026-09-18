<?php

// Remove audit requests whose auditor is an Owners package, and resynchronize
// the commits they were holding.
//
// "repository_auditrequest.auditorPHID" is a stored PHID. Removing the
// application left rows naming packages which no longer resolve, and the
// authority calculation no longer grants anyone authority over them, so the
// request can never be accepted or resigned from. Meanwhile the commit's
// summary status is derived from its requests, so every affected commit stays
// in "Needs Audit", "Partially Audited" or "Concern Raised" permanently, with
// no auditor anyone can act as.
//
// There is nothing to convert these requests into -- they named package
// membership, and there are no packages -- so they are deleted and each
// affected commit's summary status is recomputed from whatever human and
// project auditors remain. A commit with no other auditors returns to "No
// Audit", which is what it would have been had the package never been added.

$commit_table = new PhabricatorRepositoryCommit();
$request_table = new PhabricatorRepositoryAuditRequest();
$conn = $commit_table->establishConnection('w');

// PhabricatorOwnersPackagePHIDType::TYPECONST was 'OPKG'. The class was
// removed with the application; a PHID type is a stored string, so this
// prefix is what existing rows carry.
$package_prefix = 'PHID-OPKG-';

$commit_phids = ipull(
  queryfx_all(
    $conn,
    'SELECT DISTINCT commitPHID FROM %T WHERE auditorPHID LIKE %>',
    $request_table->getTableName(),
    $package_prefix),
  'commitPHID');

if (!$commit_phids) {
  echo pht('No commits carry Owners package audit requests.')."\n";
} else {
  echo pht(
    'Removing Owners package audit requests from %d commit(s)...',
    count($commit_phids))."\n";

  queryfx(
    $conn,
    'DELETE FROM %T WHERE auditorPHID LIKE %>',
    $request_table->getTableName(),
    $package_prefix);

  $changed = 0;
  foreach (array_chunk($commit_phids, 100) as $chunk) {
    $commits = $commit_table->loadAllWhere('phid IN (%Ls)', $chunk);
    $requests = $request_table->loadAllWhere('commitPHID IN (%Ls)', $chunk);
    $request_map = mgroup($requests, 'getCommitPHID');

    foreach ($commits as $commit) {
      $old_status = $commit->getAuditStatus();

      $commit->updateAuditStatus(
        idx($request_map, $commit->getPHID(), array()));

      $new_status = $commit->getAuditStatus();
      if ($new_status === $old_status) {
        continue;
      }

      queryfx(
        $conn,
        'UPDATE %T SET auditStatus = %s WHERE id = %d',
        $commit_table->getTableName(),
        $new_status,
        $commit->getID());

      $changed++;
    }
  }

  echo pht('Resynchronized the audit status of %d commit(s).', $changed)."\n";
}
