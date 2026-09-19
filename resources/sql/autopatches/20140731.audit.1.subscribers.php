<?php

// As above: the audit request model is gone, the rows are not.
final class PhabricatorAuditSubscribersMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_auditrequest';
  }

}

$table = new PhabricatorAuditSubscribersMigrationDAO();
$conn_w = $table->establishConnection('w');

echo pht('Migrating Audit subscribers to subscriptions...')."\n";
foreach (new LiskRawMigrationIterator($conn_w, $table->getTableName())
  as $request) {

  $id = $request['id'];

  echo pht("Migrating audit %d...\n", $id);

  if ($request['auditStatus'] != 'cc') {
    // This isn't a "subscriber", so skip it.
    continue;
  }

  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T (src, type, dst) VALUES (%s, %d, %s)',
    PhabricatorEdgeConfig::TABLE_NAME_EDGE,
    $request['commitPHID'],
    PhabricatorObjectHasSubscriberEdgeType::EDGECONST,
    $request['auditorPHID']);


  // Wipe the row.
  queryfx(
    $conn_w,
    'DELETE FROM %T WHERE id = %d',
    $table->getTableName(),
    $id);
}

echo pht('Done.')."\n";
