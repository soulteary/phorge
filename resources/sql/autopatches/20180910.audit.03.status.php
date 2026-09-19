<?php

// The commit model is gone; the table and its auditStatus column are not.
final class PhabricatorAuditStatusMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_commit';
  }

}

$table = new PhabricatorAuditStatusMigrationDAO();
$conn = $table->establishConnection('w');

$status_map = array(
  0 => 'none',
  1 => 'needs-audit',
  2 => 'concern-raised',
  3 => 'partially-audited',
  4 => 'audited',
  5 => 'needs-verification',
);

foreach ($status_map as $old_status => $new_status) {
  queryfx(
    $conn,
    'UPDATE %R SET auditStatus = %s WHERE auditStatus = %s',
    $table,
    $new_status,
    $old_status);
}
