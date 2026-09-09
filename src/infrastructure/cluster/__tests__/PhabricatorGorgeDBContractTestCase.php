<?php

/**
 * Consumer half of the cross-repository db-api contract test.
 *
 * This case reads the same canonical JSON the Go producer generates — the
 * files under `__tests__/data/gorge-contract/` are a byte-for-byte copy of
 * `gorge/tests/contract/dbapi/canonical/`, kept identical by the integration
 * workflow's `scripts/check-contract-fixtures.sh` — and drives each of the
 * pure translation methods the Gorge consumers were split into:
 *
 *   - @{method:PhabricatorDatabaseRef::applyGorgeServerRow}
 *   - @{method:PhabricatorConfigSchemaQuery::newServerSchemaFromGorgeNode}
 *   - @{method:PhabricatorGorgeDBClient::newSetupIssueFromRow}
 *   - @{method:PhabricatorGorgeDBClient::missingPatchesForStatus}
 *
 * It asserts the fields and the semantics that reach the objects the console
 * renders, not merely that no exception was thrown. So if the Go side renames a
 * wire field, the canonical fixture changes and the Go guard fails; if this
 * adapter reads the wrong key or drops a property, the object it builds from
 * the unchanged fixture is missing that value and the assertions here fail.
 * Between the two, every field this console depends on is pinned on both sides.
 */
final class PhabricatorGorgeDBContractTestCase extends PhabricatorTestCase {

  private function readFixture($name) {
    $path = dirname(__FILE__).'/data/gorge-contract/'.$name;
    return phutil_json_decode(Filesystem::readFile($path));
  }

  public function testServersFixtureMapsOntoRefs() {
    $servers = $this->readFixture('servers.json');
    $by_key = ipull($servers, null, 'refKey');

    // A healthy master: okay connection, okay replication, a latency, no delay.
    $master_row = idx($by_key, 'db1:3306');
    $this->assertTrue(is_array($master_row), pht('servers.json has db1:3306.'));

    $master = id(new PhabricatorDatabaseRef())
      ->setHost('db1')
      ->setPort(3306)
      ->setIsMaster(true);
    PhabricatorDatabaseRef::applyGorgeServerRow($master, $master_row);

    $this->assertEqual(
      PhabricatorDatabaseRef::STATUS_OKAY,
      $master->getConnectionStatus(),
      pht('Master connectionStatus is read from the wire.'));
    $this->assertEqual(
      PhabricatorDatabaseRef::REPLICATION_OKAY,
      $master->getReplicaStatus(),
      pht('Master replicationStatus is read from the wire.'));
    $this->assertEqual(
      0.004,
      (float)$master->getConnectionLatency(),
      pht('connectionLatencySec is read from the wire.'));
    $this->assertEqual(
      null,
      $master->getReplicaDelay(),
      pht('A master that is not replicating carries no delay.'));

    // A lagging replica: the delay and message are the fields that regressed
    // when the consumer read `replicaDelaySec`/`replicaStatus` instead of
    // `secondsBehindMaster`/`replicationStatus`, so pin them explicitly.
    $replica_row = idx($by_key, 'db2:3306');
    $this->assertTrue(
      is_array($replica_row),
      pht('servers.json has db2:3306.'));

    $replica = id(new PhabricatorDatabaseRef())
      ->setHost('db2')
      ->setPort(3306)
      ->setIsMaster(false);
    PhabricatorDatabaseRef::applyGorgeServerRow($replica, $replica_row);

    $this->assertEqual(
      'replica-slow',
      $replica->getReplicaStatus(),
      pht('Replica replicationStatus is read from the wire.'));
    $this->assertEqual(
      12,
      $replica->getReplicaDelay(),
      pht('secondsBehindMaster is read as the replica delay.'));
    $this->assertEqual(
      'replica is behind',
      $replica->getReplicaMessage(),
      pht('replicaMessage is read from the wire.'));
  }

  public function testSchemaDiffFixtureBuildsFullSchema() {
    $nodes = $this->readFixture('schema-diff.json');
    $this->assertTrue(is_array($nodes) && count($nodes) === 1);

    $node = head($nodes);

    $ref = id(new PhabricatorDatabaseRef())
      ->setHost('db1')
      ->setPort(3306);

    $server = PhabricatorConfigSchemaQuery::newServerSchemaFromGorgeNode(
      $ref,
      $node);

    $database = $server->getDatabase('phorge_meta_data');
    $this->assertTrue(
      (bool)$database,
      pht('The database name is read from `databaseName`.'));
    $this->assertEqual('utf8mb4', $database->getCharacterSet());
    $this->assertEqual('utf8mb4_bin', $database->getCollation());

    $table = $database->getTable('patch_status');
    $this->assertTrue(
      (bool)$table,
      pht('The table name is read from `tableName`.'));
    $this->assertEqual('InnoDB', $table->getEngine());
    $this->assertEqual('utf8mb4_bin', $table->getCollation());

    // The auto_increment primary key column: type, nullability and
    // auto_increment must all survive the mapping.
    $id_column = $table->getColumn('id');
    $this->assertTrue(
      (bool)$id_column,
      pht('The column name is read from `columnName`.'));
    $this->assertEqual('int(10) unsigned', $id_column->getColumnType());
    $this->assertEqual(false, $id_column->getNullable());
    $this->assertEqual(
      true,
      $id_column->getAutoIncrement(),
      pht('autoIncrement is read onto the column schema.'));

    $patch_column = $table->getColumn('patch');
    $this->assertEqual('varchar(255)', $patch_column->getColumnType());
    $this->assertEqual('utf8mb4', $patch_column->getCharacterSet());
    $this->assertEqual('utf8mb4_bin', $patch_column->getCollation());
    $this->assertEqual(false, $patch_column->getAutoIncrement());

    // The composite, prefixed, unique key: column order, the `(prefix)` suffix
    // and uniqueness must survive so the index comparison is not misled.
    $key = $table->getKey('key_patch');
    $this->assertTrue(
      (bool)$key,
      pht('The index name is read from the key `name`.'));
    $this->assertEqual(
      array('patch(64)', 'kind'),
      $key->getColumnNames(),
      pht('Composite/prefixed columnNames survive in order.'));
    $this->assertEqual(true, (bool)$key->getUnique());
    $this->assertEqual('BTREE', $key->getIndexType());
  }

  public function testSetupIssuesFixtureBuildsIssues() {
    $issues = $this->readFixture('setup-issues.json');
    $by_key = ipull($issues, null, 'issueKey');

    // The fatal issue: key, name, summary, message and fatality must all reach
    // the PhabricatorSetupIssue. A consumer that read `key` instead of
    // `issueKey` would key this under "gorge.db.unknown" and lose grouping.
    $fatal_row = idx($by_key, 'mysql.innodb');
    $this->assertTrue(is_array($fatal_row));

    $fatal = PhabricatorGorgeDBClient::newSetupIssueFromRow($fatal_row);
    $this->assertEqual('mysql.innodb', $fatal->getIssueKey());
    $this->assertEqual(
      'MySQL InnoDB Engine Not Available',
      $fatal->getName());
    $this->assertEqual('InnoDB is required.', $fatal->getSummary());
    $this->assertEqual(
      'The "InnoDB" engine is not available in MySQL.',
      $fatal->getMessage());
    $this->assertEqual(
      true,
      (bool)$fatal->getIsFatal(),
      pht('isFatal is read onto the issue.'));

    $warn_row = idx($by_key, 'mysql.max_allowed_packet');
    $this->assertTrue(is_array($warn_row));

    $warn = PhabricatorGorgeDBClient::newSetupIssueFromRow($warn_row);
    $this->assertEqual('mysql.max_allowed_packet', $warn->getIssueKey());
    $this->assertEqual(
      false,
      (bool)$warn->getIsFatal(),
      pht('A non-fatal issue stays non-fatal.'));
  }

  public function testSetupIssueWithoutKeyFallsBackToUnknown() {
    // The old bug read `key`, which the wire never had, so every issue
    // collapsed to the unknown fallback. Confirm the fallback still exists but
    // is reached only when `issueKey` is genuinely absent.
    $issue = PhabricatorGorgeDBClient::newSetupIssueFromRow(
      array('key' => 'mysql.version', 'name' => 'legacy'));
    $this->assertEqual('gorge.db.unknown', $issue->getIssueKey());
  }

  public function testMigrationsFixtureDiffsAppliedPatches() {
    $statuses = $this->readFixture('migrations-status.json');
    $status = head($statuses);

    // Nothing missing when every expected patch is applied.
    $applied = ipull(
      idx($status, 'appliedPatches'),
      null);
    $all_applied = array_fuse($applied);
    $this->assertEqual(
      array(),
      PhabricatorGorgeDBClient::missingPatchesForStatus(
        $status,
        $all_applied),
      pht('No patches are missing when all applied are expected.'));

    // A patch the master has not applied shows up as missing, proving the diff
    // reads `appliedPatches` rather than a legacy field.
    $expect = $all_applied;
    $expect['phabricator:9999.new.sql'] = 'phabricator:9999.new.sql';
    $missing = PhabricatorGorgeDBClient::missingPatchesForStatus(
      $status,
      $expect);
    $this->assertEqual(
      array('phabricator:9999.new.sql'),
      array_values($missing),
      pht('An unapplied expected patch is reported missing.'));
  }

  public function testMigrationStatusUninitializedIsNotADiff() {
    // An uninitialized master returns null (its bare state is the service's own
    // storage.upgrade issue), not an empty or full missing list.
    $missing = PhabricatorGorgeDBClient::missingPatchesForStatus(
      array('initialized' => false),
      array('phabricator:0001.legacy.sql' => 'phabricator:0001.legacy.sql'));
    $this->assertEqual(null, $missing);
  }

}
