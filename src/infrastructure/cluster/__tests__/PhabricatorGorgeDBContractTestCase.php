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


/* -(  Contract metadata handshake  )---------------------------------------- */


  public function testValidateContractMetaAcceptsCurrentContract() {
    // The meta fixture is contract 1.1, namespace "phorge", full capabilities:
    // the exact shape a compatible service reports.
    $meta = $this->readFixture('meta.json');
    $this->assertEqual(
      null,
      PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge'),
      pht('A current, correctly-namespaced service is compatible.'));
  }

  public function testValidateContractMetaRejectsMajorMismatch() {
    $meta = $this->newMetaLiteral('2.0');
    $problem = PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge');
    $this->assertTrue(
      is_array($problem),
      pht('A different major version is incompatible.'));
    $this->assertEqual('version', $problem['code']);
  }

  public function testValidateContractMetaRejectsBelowMinimumMinor() {
    // 1.0 is a matching major but below the 1.1 minimum this consumer needs.
    $meta = $this->newMetaLiteral('1.0');
    $problem = PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge');
    $this->assertTrue(
      is_array($problem),
      pht('A minor below the minimum is incompatible.'));
    $this->assertEqual('version', $problem['code']);
  }

  public function testValidateContractMetaAcceptsHigherMinor() {
    // A newer minor within the same major is forward-compatible.
    $meta = $this->newMetaLiteral('1.2');
    $this->assertEqual(
      null,
      PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge'),
      pht('A higher minor within the major is compatible.'));
  }

  public function testValidateContractMetaRejectsMalformedVersions() {
    // Strict "major.minor" parsing: none of these lenient forms may pass, and
    // in particular the old "(int)head(explode('.', ...))" cast that accepted
    // "1garbage" and "1" must be gone.
    $bad_versions = array('', '1garbage', '1', '1.x', '1.', '.1', 'v1.1');
    foreach ($bad_versions as $version) {
      $meta = $this->newMetaLiteral($version);
      $problem = PhabricatorGorgeDBClient::validateContractMeta(
        $meta,
        'phorge');
      $this->assertTrue(
        is_array($problem),
        pht('Version "%s" is rejected as malformed.', $version));
      $this->assertEqual(
        'version',
        $problem['code'],
        pht('Version "%s" is a version problem.', $version));
    }

    // A missing contractVersion key entirely is likewise incompatible.
    $meta = $this->newMetaLiteral('1.1');
    unset($meta['contractVersion']);
    $problem = PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge');
    $this->assertTrue(is_array($problem));
    $this->assertEqual('version', $problem['code']);
  }

  public function testValidateContractMetaRejectsNamespaceMismatch() {
    $meta = $this->newMetaLiteral('1.1');
    $meta['namespace'] = 'somethingelse';
    $problem = PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge');
    $this->assertTrue(
      is_array($problem),
      pht('A mismatched namespace is incompatible.'));
    $this->assertEqual('namespace', $problem['code']);
  }

  public function testValidateContractMetaRejectsMissingCapability() {
    // Drop one required capability; the rest are present.
    $meta = $this->newMetaLiteral('1.1');
    $meta['capabilities'] = array(
      'servers',
      'schema-diff',
      'setup-issues',
      'charset-info',
      // 'migrations-status' intentionally omitted.
    );
    $problem = PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge');
    $this->assertTrue(
      is_array($problem),
      pht('A service missing a required capability is incompatible.'));
    $this->assertEqual('capability', $problem['code']);
  }

  public function testValidateContractMetaAllowsUnknownCapability() {
    // All required capabilities present, plus an unknown extra one: allowed.
    $meta = $this->newMetaLiteral('1.1');
    $meta['capabilities'][] = 'some-future-capability';
    $this->assertEqual(
      null,
      PhabricatorGorgeDBClient::validateContractMeta($meta, 'phorge'),
      pht('An unknown extra capability does not break compatibility.'));
  }

  private function newMetaLiteral($version) {
    return array(
      'contractVersion' => $version,
      'namespace' => 'phorge',
      'topologySource' => 'file',
      'capabilities' => array(
        'servers',
        'schema-diff',
        'setup-issues',
        'charset-info',
        'migrations-status',
      ),
    );
  }


/* -(  Replication decision  )----------------------------------------------- */


  public function testReplicationMasterReplicatingIsFatal() {
    $ref = $this->newRefFromServerRow(
      'db1:3306',
      true,
      array('connectionStatus' => 'okay', 'replicationStatus' => 'master-replica'));

    $specs = PhabricatorDatabaseSetupCheck::computeGorgeReplicationIssues(
      array($ref));
    $this->assertEqual(1, count($specs));
    $this->assertEqual('db.master.replicating', $specs[0]['key']);
    $this->assertTrue(
      (bool)idx($specs[0], 'fatal'),
      pht('A replicating master is a fatal issue.'));
  }

  public function testReplicationReplicaNoneIsNonFatal() {
    $ref = $this->newRefFromServerRow(
      'db2:3306',
      false,
      array('connectionStatus' => 'okay', 'replicationStatus' => 'replica-none'));

    $specs = PhabricatorDatabaseSetupCheck::computeGorgeReplicationIssues(
      array($ref));
    $this->assertEqual(1, count($specs));
    $this->assertEqual('db.replica.not-replicating', $specs[0]['key']);
    $this->assertEqual(
      false,
      (bool)idx($specs[0], 'fatal'),
      pht('A nonreplicating replica is a non-fatal warning.'));
  }

  public function testReplicationNotReplicatingIsNonFatal() {
    $ref = $this->newRefFromServerRow(
      'db3:3306',
      false,
      array(
        'connectionStatus' => 'okay',
        'replicationStatus' => 'not-replicating',
      ));

    $specs = PhabricatorDatabaseSetupCheck::computeGorgeReplicationIssues(
      array($ref));
    $this->assertEqual(1, count($specs));
    $this->assertEqual('db.replica.not-replicating', $specs[0]['key']);
    $this->assertEqual(false, (bool)idx($specs[0], 'fatal'));
  }

  public function testReplicationSlowReplicaIsNeitherIssue() {
    $ref = $this->newRefFromServerRow(
      'db4:3306',
      false,
      array(
        'connectionStatus' => 'okay',
        'replicationStatus' => 'replica-slow',
        'secondsBehindMaster' => 120,
      ));

    $specs = PhabricatorDatabaseSetupCheck::computeGorgeReplicationIssues(
      array($ref));
    $this->assertEqual(
      array(),
      $specs,
      pht('Slow replication is not escalated by the replication check.'));
  }

  public function testReplicationClientMissingGrantIsNotAFalsePositive() {
    // A missing "REPLICATION CLIENT" grant is a *connection* status, and the
    // ref never receives a replica status, so it must not be misjudged as a
    // broken replica.
    $ref = $this->newRefFromServerRow(
      'db5:3306',
      false,
      array('connectionStatus' => 'replication-client'));

    $specs = PhabricatorDatabaseSetupCheck::computeGorgeReplicationIssues(
      array($ref));
    $this->assertEqual(
      array(),
      $specs,
      pht('A missing REPLICATION CLIENT grant is not a replication issue.'));
  }

  public function testReplicationHealthyClusterHasNoIssue() {
    $master = $this->newRefFromServerRow(
      'db1:3306',
      true,
      array('connectionStatus' => 'okay'));
    $replica = $this->newRefFromServerRow(
      'db2:3306',
      false,
      array('connectionStatus' => 'okay', 'replicationStatus' => 'okay'));

    $specs = PhabricatorDatabaseSetupCheck::computeGorgeReplicationIssues(
      array($master, $replica));
    $this->assertEqual(
      array(),
      $specs,
      pht('A healthy master/replica pair raises no replication issue.'));
  }

  public function testReplicationExcludesDisabledNodes() {
    // A disabled replica that is not replicating must be excluded entirely.
    $ref = $this->newRefFromServerRow(
      'db6:3306',
      false,
      array('connectionStatus' => 'okay', 'replicationStatus' => 'replica-none'));
    $ref->setDisabled(true);

    $specs = PhabricatorDatabaseSetupCheck::computeGorgeReplicationIssues(
      array($ref));
    $this->assertEqual(
      array(),
      $specs,
      pht('Disabled nodes do not participate in the replication check.'));
  }

  private function newRefFromServerRow($ref_key, $is_master, array $server) {
    list($host, $port) = explode(':', $ref_key);

    $ref = id(new PhabricatorDatabaseRef())
      ->setHost($host)
      ->setPort((int)$port)
      ->setIsMaster($is_master)
      ->setDisabled(false);

    // Route the server row through the same translator the live path uses, so
    // these tests exercise the real wire-to-ref mapping.
    PhabricatorDatabaseRef::applyGorgeServerRow($ref, $server);

    return $ref;
  }


/* -(  Cluster-state agreement decision  )----------------------------------- */


  public function testClusterStatePresentFalseIsDesync() {
    // Multi-master path: the caller only invokes the helper when masters > 1.
    // A present=false master has committed no state and can not be confirmed
    // in agreement, so it is a desync (replacing the old silent skip).
    $status = array(
      'refKey' => 'db1:3306',
      'clusterStatePresent' => false,
    );
    $this->assertEqual(
      false,
      PhabricatorDatabaseSetupCheck::isClusterStateStatusSynchronized(
        $status,
        'expected-state'),
      pht('A present=false master is a desync under multiple masters.'));
  }

  public function testClusterStateMissingFieldIsSafeFailure() {
    // The field entirely absent must fail safe (treated as a desync), not be
    // silently ignored.
    $status = array('refKey' => 'db1:3306');
    $this->assertEqual(
      false,
      PhabricatorDatabaseSetupCheck::isClusterStateStatusSynchronized(
        $status,
        'expected-state'),
      pht('A missing clusterStatePresent field fails safe as a desync.'));
  }

  public function testClusterStateMatchingDigestIsSynchronized() {
    $expected_state = 'expected-state';
    $status = array(
      'refKey' => 'db1:3306',
      'clusterStatePresent' => true,
      'clusterStateDigest' => hash('sha256', $expected_state),
    );
    $this->assertEqual(
      true,
      PhabricatorDatabaseSetupCheck::isClusterStateStatusSynchronized(
        $status,
        $expected_state),
      pht('A present, matching digest is synchronized.'));
  }

  public function testClusterStateMismatchingDigestIsDesync() {
    $status = array(
      'refKey' => 'db1:3306',
      'clusterStatePresent' => true,
      'clusterStateDigest' => hash('sha256', 'some-other-state'),
    );
    $this->assertEqual(
      false,
      PhabricatorDatabaseSetupCheck::isClusterStateStatusSynchronized(
        $status,
        'expected-state'),
      pht('A present, differing digest is a desync.'));
  }

  public function testClusterStateMalformedDigestThrows() {
    $status = array(
      'refKey' => 'db1:3306',
      'clusterStatePresent' => true,
      'clusterStateDigest' => 'not-a-valid-sha256',
    );

    $caught = null;
    try {
      PhabricatorDatabaseSetupCheck::isClusterStateStatusSynchronized(
        $status,
        'expected-state');
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue(
      $caught instanceof Exception,
      pht('A present-but-malformed digest throws rather than skipping.'));
  }

  public function testClusterStateSingleMasterEarlyReturnsWithoutHelper() {
    // The single-master early return is the caller's responsibility; this
    // documents that a lone present=false master, which the helper would treat
    // as a desync, is never fed to the helper because the check returns early
    // when there is at most one master. We assert the helper contract the
    // caller relies on: present=false is only a desync in the multi-master
    // context the caller gates on.
    $status = array(
      'refKey' => 'db1:3306',
      'clusterStatePresent' => false,
    );
    $this->assertEqual(
      false,
      PhabricatorDatabaseSetupCheck::isClusterStateStatusSynchronized(
        $status,
        'expected-state'),
      pht('The helper itself reports present=false as unsynchronized; the '.
        'single-master early return in executeGorgeClusterStateCheck is what '.
        'prevents a false desync for a lone master.'));
  }

}
