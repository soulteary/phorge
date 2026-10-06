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

// The commit and audit request models have since gone with Diffusion. This
// patch wanted them for connections, table names, and one piece of behaviour:
// PhabricatorRepositoryCommit::updateAuditStatus(), which derived a commit's
// summary status from its outstanding requests. That derivation is reproduced
// below, with the stored strings the removed constants held.
final class PhabricatorOwnersAuditMigrationCommitDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commit';
  }

  /**
   * PhabricatorRepositoryCommit::updateAuditStatus(), reproduced.
   *
   * Request statuses are PhabricatorAuditRequestStatus values and the result
   * is a DiffusionCommitAuditStatus value; both classes are gone, and both
   * sets are stored strings.
   */
  public static function newAuditStatus(array $statuses, $current_status) {
    $any_concern = false;
    $any_accept = false;
    $any_need = false;

    foreach ($statuses as $status) {
      switch ($status) {
        case 'audit-required':
        case 'requested':
          $any_need = true;
          break;
        case 'accepted':
          $any_accept = true;
          break;
        case 'concerned':
          $any_concern = true;
          break;
      }
    }

    if ($any_concern) {
      if ($current_status === 'needs-verification') {
        // If the change is in "Needs Verification", we keep it there as
        // long as any auditors still have concerns.
        return 'needs-verification';
      }
      return 'concern-raised';
    }

    if ($any_accept) {
      if ($any_need) {
        return 'partially-audited';
      }
      return 'audited';
    }

    if ($any_need) {
      return 'needs-audit';
    }

    return 'none';
  }

}

final class PhabricatorOwnersAuditMigrationRequestDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_auditrequest';
  }

}

$commit_table = new PhabricatorOwnersAuditMigrationCommitDAO();
$request_table = new PhabricatorOwnersAuditMigrationRequestDAO();
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
    $commits = queryfx_all(
      $conn,
      'SELECT id, phid, auditStatus FROM %T WHERE phid IN (%Ls)',
      $commit_table->getTableName(),
      $chunk);

    $requests = queryfx_all(
      $conn,
      'SELECT commitPHID, auditStatus FROM %T WHERE commitPHID IN (%Ls)',
      $request_table->getTableName(),
      $chunk);

    $request_map = array();
    foreach ($requests as $request) {
      $request_map[$request['commitPHID']][] = $request['auditStatus'];
    }

    foreach ($commits as $commit) {
      $old_status = $commit['auditStatus'];

      $new_status = PhabricatorOwnersAuditMigrationCommitDAO::newAuditStatus(
        idx($request_map, $commit['phid'], array()),
        $old_status);

      if ($new_status === $old_status) {
        continue;
      }

      queryfx(
        $conn,
        'UPDATE %T SET auditStatus = %s WHERE id = %d',
        $commit_table->getTableName(),
        $new_status,
        $commit['id']);

      $changed++;
    }
  }

  echo pht('Resynchronized the audit status of %d commit(s).', $changed)."\n";
}
