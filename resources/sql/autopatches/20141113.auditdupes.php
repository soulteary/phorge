<?php

// PhabricatorRepositoryAuditRequest is gone with the Audit application. The
// rows remain, and this patch still has to run against older schemas, so it
// walks them raw and deletes by id rather than through the model.
final class PhabricatorAuditDupesMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_auditrequest';
  }

}

$table = new PhabricatorAuditDupesMigrationDAO();
$conn_w = $table->establishConnection('w');

echo pht('Removing duplicate Audit requests...')."\n";
$seen_audit_map = array();
foreach (new LiskRawMigrationIterator($conn_w, $table->getTableName())
  as $request) {

  $commit_phid = $request['commitPHID'];
  $auditor_phid = $request['auditorPHID'];
  if (isset($seen_audit_map[$commit_phid][$auditor_phid])) {
    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE id = %d',
      $table->getTableName(),
      $request['id']);
  }

  if (!isset($seen_audit_map[$commit_phid])) {
    $seen_audit_map[$commit_phid] = array();
  }

  $seen_audit_map[$commit_phid][$auditor_phid] = 1;
}

echo pht('Done.')."\n";
