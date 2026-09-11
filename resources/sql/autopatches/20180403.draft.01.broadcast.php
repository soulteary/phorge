<?php

// The Differential revision models have been removed. This patch still has to
// run against installations whose schema predates it, and all it ever wanted
// from them was a connection to the differential database and a table name, so
// declare the minimum here.
//
// Database "differential": the value DifferentialDAO::getApplicationName()
// produced. Table names are the ones PhabricatorLiskDAO::getTableName()
// produced for the deleted classes.
final class DifferentialDraftBroadcastMigrationDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'differential';
  }

  public function getTableName() {
    return 'differential_revision';
  }

}

$table = new DifferentialDraftBroadcastMigrationDAO();
$conn = $table->establishConnection('w');

// DifferentialRevisionStatus::DRAFT and
// DifferentialRevision::PROPERTY_SHOULD_BROADCAST. Both were stored strings,
// so the literals match what rows written before the removal carry.
$draft_status = 'draft';
$broadcast_key = 'draft.broadcast';

$drafts = queryfx_all(
  $conn,
  'SELECT id, properties FROM %T WHERE status = %s',
  $table->getTableName(),
  $draft_status);

foreach ($drafts as $draft) {
  $raw_properties = $draft['properties'];
  if (strlen((string)$raw_properties)) {
    $properties = phutil_json_decode($raw_properties);
  } else {
    $properties = array();
  }

  $properties[$broadcast_key] = false;

  queryfx(
    $conn,
    'UPDATE %T SET properties = %s WHERE id = %d',
    $table->getTableName(),
    phutil_json_encode($properties),
    $draft['id']);
}
