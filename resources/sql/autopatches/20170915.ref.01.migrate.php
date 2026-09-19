<?php

// The ref cursor and ref position models are gone; their tables are not. This
// patch already read the fields it cares about with a raw query, because they
// were about to be dropped, so it needed the models only for connections and
// table names.
final class PhabricatorRefCursorMigrationCursorDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_refcursor';
  }

}

final class PhabricatorRefCursorMigrationPositionDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository_refposition';
  }

}

$table = new PhabricatorRefCursorMigrationCursorDAO();
$conn = $table->establishConnection('w');

$map = array();
foreach (new LiskRawMigrationIterator($conn, $table->getTableName())
  as $ref) {

  $repository_phid = $ref['repositoryPHID'];
  $ref_type = $ref['refType'];
  $ref_hash = $ref['refNameHash'];

  $ref_key = "{$repository_phid}/{$ref_type}/{$ref_hash}";

  if (!isset($map[$ref_key])) {
    $map[$ref_key] = array(
      'id' => $ref['id'],
      'type' => $ref_type,
      'hash' => $ref_hash,
      'repositoryPHID' => $repository_phid,
      'positions' => array(),
    );
  }

  // NOTE: When this migration runs, the table will have "commitIdentifier" and
  // "isClosed" fields. Later, it won't. The raw iterator reads whatever
  // columns exist, so take them from the row it already produced.

  $map[$ref_key]['positions'][] = array(
    'identifier' => $ref['commitIdentifier'],
    'isClosed' => (int)$ref['isClosed'],
  );
}

// Now, write all the position rows.
$position_table = new PhabricatorRefCursorMigrationPositionDAO();
foreach ($map as $ref_key => $spec) {
  $id = $spec['id'];
  foreach ($spec['positions'] as $position) {
    queryfx(
      $conn,
      'INSERT IGNORE INTO %T (cursorID, commitIdentifier, isClosed)
        VALUES (%d, %s, %d)',
      $position_table->getTableName(),
      $id,
      $position['identifier'],
      $position['isClosed']);
  }
}

// Finally, delete all the redundant RefCursor rows (rows with the same name)
// so we can add proper unique keys in the next migration.
foreach ($map as $ref_key => $spec) {
  queryfx(
    $conn,
    'DELETE FROM %T WHERE refType = %s
      AND refNameHash = %s
      AND repositoryPHID = %s
      AND id != %d',
    $table->getTableName(),
    $spec['type'],
    $spec['hash'],
    $spec['repositoryPHID'],
    $spec['id']);
}
