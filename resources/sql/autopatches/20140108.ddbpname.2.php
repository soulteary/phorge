<?php

// The Drydock application has been removed, so DrydockBlueprint no longer
// exists. This patch still has to run against installations whose schema
// predates it, and all it ever wanted from that class was a connection to the
// drydock database and the table name, so declare the minimum here rather than
// keeping the application alive for one migration.
//
// Database "drydock", table "drydock_blueprint": the same values DrydockDAO
// and PhabricatorLiskDAO::getTableName() produced.
final class DrydockBlueprintNameMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'drydock';
  }

  public function getTableName() {
    return 'drydock_blueprint';
  }

}

echo pht('Adding names to Drydock blueprints.')."\n";

$table = new DrydockBlueprintNameMigrationDAO();
$conn_w = $table->establishConnection('w');

$iterator = new LiskRawMigrationIterator($conn_w, $table->getTableName());
foreach ($iterator as $row) {
  $id = $row['id'];

  echo pht('Populating blueprint %d...', $id)."\n";

  $name = $row['blueprintName'] ?? null;
  if (!strlen((string)$name)) {
    queryfx(
      $conn_w,
      'UPDATE %T SET blueprintName = %s WHERE id = %d',
      $table->getTableName(),
      pht('Blueprint %s', $id),
      $id);
  }
}

echo pht('Done.')."\n";
