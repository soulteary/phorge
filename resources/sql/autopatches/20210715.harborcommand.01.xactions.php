<?php

// See T13072. Turn the old "process a command" transaction into modular
// transactions that each handle one particular type of command.

// The Harbormaster application has been removed, so the
// HarbormasterBuildTransaction model no longer exists. This patch still has
// to run against installations whose schema predates it, and it wanted that
// class only for a connection and the table name. HarbormasterDAO is retained
// for the legacy "harbormaster" database.
final class HarbormasterCommandMigrationXactionDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildtransaction';
  }

}

$xactions_table = new HarbormasterCommandMigrationXactionDAO();
$xactions_conn = $xactions_table->establishConnection('w');
$row_iterator = new LiskRawMigrationIterator(
  $xactions_conn,
  $xactions_table->getTableName());

$map = array(
  '"pause"' => 'message/pause',
  '"abort"' => 'message/abort',
  '"resume"' => 'message/resume',
  '"restart"' => 'message/restart',
);

foreach ($row_iterator as $row) {
  if ($row['transactionType'] !== 'harbormaster:build:command') {
    continue;
  }

  $raw_value = $row['newValue'];

  if (isset($map[$raw_value])) {
    queryfx(
      $xactions_conn,
      'UPDATE %R SET transactionType = %s WHERE id = %d',
      $xactions_table,
      $map[$raw_value],
      $row['id']);
  }
}
