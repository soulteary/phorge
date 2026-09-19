<?php

// PhabricatorAuditTransaction is gone, and so is
// DiffusionCommitStateTransaction. "transactionType" is a stored string, so
// the literal "diffusion.commit.state" is what existing rows carry.
final class PhabricatorAuditStateXactionMigrationDAO
  extends PhabricatorAuditDAO {

  public function getTableName() {
    return 'audit_transaction';
  }

}

$table = new PhabricatorAuditStateXactionMigrationDAO();
$conn = $table->establishConnection('w');

$status_map = array(
  0 => 'none',
  1 => 'needs-audit',
  2 => 'concern-raised',
  3 => 'partially-audited',
  4 => 'audited',
  5 => 'needs-verification',
);

$state_type = 'diffusion.commit.state';

foreach (new LiskRawMigrationIterator($conn, $table->getTableName())
  as $xaction) {

  if ($xaction['transactionType'] !== $state_type) {
    continue;
  }

  $old_value = phutil_json_decode($xaction['oldValue']);
  $new_value = phutil_json_decode($xaction['newValue']);

  $any_change = false;

  if (is_scalar($old_value) && isset($status_map[$old_value])) {
    $old_value = $status_map[$old_value];
    $any_change = true;
  }

  if (is_scalar($new_value) && isset($status_map[$new_value])) {
    $new_value = $status_map[$new_value];
    $any_change = true;
  }

  if (!$any_change) {
    continue;
  }

  queryfx(
    $conn,
    'UPDATE %T SET oldValue = %s, newValue = %s WHERE id = %d',
    $table->getTableName(),
    phutil_json_encode($old_value),
    phutil_json_encode($new_value),
    $xaction['id']);
}
