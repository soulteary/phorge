<?php

final class PhabricatorDatabaseSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_IMPORTANT;
  }

  public function getExecutionOrder() {
    // This must run after basic PHP checks, but before most other checks.
    return 500;
  }

  protected function executeChecks() {
    // When the Gorge database service fronts the cluster, it runs the version,
    // engine, storage-initialization and patch-status diagnostics against the
    // hosts and returns them as setup issues, so consume those instead of
    // opening management connections from the web tier. When it is not
    // configured, fall back to the native direct-SQL checks below.
    if (PhabricatorGorgeDBClient::shouldUseService()) {
      $this->executeGorgeChecks();
      return;
    }

    $this->executeNativeChecks();
  }

  private function executeGorgeChecks() {
    $client = new PhabricatorGorgeDBClient();
    $issues = $client->getSetupIssues();

    if (!is_array($issues)) {
      return;
    }

    // The MySQL-configuration issues (small max_allowed_packet, missing
    // strict mode, and so on) are surfaced by PhabricatorMySQLSetupCheck; skip
    // them here so each check owns its own set of keys, matching the native
    // split where MySQL warnings live in the "MySQL" group.
    $mysql_keys = self::getMySQLConfigIssueKeys();

    foreach ($issues as $issue_data) {
      if (!is_array($issue_data)) {
        throw new Exception(
          pht(
            'The Gorge database service returned a malformed setup issue in '.
            'its "%s" response.',
            '/api/db/setup-issues'));
      }

      $key = idx($issue_data, 'issueKey', 'gorge.db.unknown');

      if (isset($mysql_keys[$key])) {
        continue;
      }

      // Build the issue through the shared pure translator (tested directly
      // against the canonical fixtures) and then apply this check's own group
      // and registration, so the wire-to-issue mapping stays identical across
      // both setup checks while grouping stays each check's decision.
      $issue = PhabricatorGorgeDBClient::newSetupIssueFromRow($issue_data);
      if ($this->getDefaultGroup()) {
        $issue->setGroup($this->getDefaultGroup());
      }
      $this->addIssue($issue);
    }

    // The service returns only what it can observe: version, engine and
    // storage-initialization issues. The patch diff, the replication warnings
    // and the cluster-state agreement check all depend on state Phorge owns —
    // the canonical patch list, the configured roles, and the local cluster
    // configuration — so they are reconstructed here from the service's
    // observations rather than delegated to it.
    $this->executeGorgePatchCheck();
    $this->executeGorgeReplicationChecks();
    $this->executeGorgeClusterStateCheck();
  }

  /**
   * Rebuild the `storage.patch` check over the service's migration status.
   *
   * The service reports the applied patch keys per master but owns no expected
   * list; @{class:PhabricatorSQLPatchList} is that canonical list, so the diff
   * is computed here. A master that is not initialized is left to the
   * `storage.upgrade` issue the service already emits.
   */
  private function executeGorgePatchCheck() {
    $client = new PhabricatorGorgeDBClient();
    $statuses = $client->getMigrationStatus();

    if (!is_array($statuses)) {
      throw new Exception(
        pht(
          'The Gorge database service returned a malformed "%s" response: '.
          'expected a list of migration statuses.',
          '/api/db/migrations/status'));
    }

    $all = PhabricatorSQLPatchList::buildAllPatches();

    foreach ($statuses as $status) {
      if (!is_array($status)) {
        throw new Exception(
          pht(
            'The Gorge database service returned a malformed migration '.
            'status row in its "%s" response.',
            '/api/db/migrations/status'));
      }

      if (!idx($status, 'initialized')) {
        // Not yet initialized: the service's own "storage.upgrade" issue
        // covers this, and there is no patch ledger to diff against.
        continue;
      }

      $missing = PhabricatorGorgeDBClient::missingPatchesForStatus(
        $status,
        $all);
      if (!$missing) {
        continue;
      }

      $ref_key = idx($status, 'refKey', '');
      $message = pht(
        'Run the storage upgrade script to upgrade databases (host "%s" is '.
        'out of date). Missing patches: %s.',
        $ref_key,
        implode(', ', $missing));

      $this->newIssue('storage.patch')
        ->setName(pht('Upgrade MySQL Schema'))
        ->setIsFatal(true)
        ->setMessage($message)
        ->addCommand(
          hsprintf(
            '<samp>%s $</samp><kbd>./bin/storage upgrade</kbd>',
            PlatformSymbols::getPlatformServerPath()));

      // One missing-patch issue is enough to tell the operator to upgrade.
      return;
    }
  }

  /**
   * Rebuild the replication warnings over the service's per-server view.
   *
   * Each ref's replica status is filled from `/api/db/servers` by
   * @{method:PhabricatorDatabaseRef::queryAll} (via its `queryRefsViaGorge`
   * path); the role a host is expected to play is Phorge's own configuration,
   * so the "replicating master" and "nonreplicating replica" judgements are
   * made here against that configuration. The pure decision lives in
   * @{method:computeGorgeReplicationIssues} so it can be unit tested against
   * already-populated refs without a live MySQL cluster.
   */
  private function executeGorgeReplicationChecks() {
    // queryAll() routes through queryRefsViaGorge when the service is in use,
    // which populates each ref's replica status from /api/db/servers. It reads
    // the same servers list through the request cache path, so this does not
    // add a second fetch per request.
    $refs = PhabricatorDatabaseRef::queryAll();

    foreach (self::computeGorgeReplicationIssues($refs) as $spec) {
      $issue = $this->newIssue($spec['key'])
        ->setName($spec['name'])
        ->setMessage($spec['message']);
      if (!empty($spec['fatal'])) {
        $issue->setIsFatal(true);
      }
    }
  }

  /**
   * Pure replication decision over already-populated refs.
   *
   * Given the refs @{method:PhabricatorDatabaseRef::queryAll} produced (each
   * carrying a replica status filled from the service), return the setup issue
   * specifications the replication check should raise, without touching MySQL
   * or `PhabricatorSetupCheck` state. Each returned spec is a map with `key`,
   * `name`, `message` and an optional `fatal` flag.
   *
   * The semantics match the native path exactly:
   *
   *   - a master that is itself replicating (`master-replica`) is a fatal
   *     `db.master.replicating`;
   *   - a non-master that is not replicating (`replica-none` or
   *     `not-replicating`) is a non-fatal `db.replica.not-replicating`;
   *   - `replica-slow` is not escalated here (the "Database Servers" console
   *     surfaces lag);
   *   - a missing "REPLICATION CLIENT" grant (`replication-client`, a
   *     connection status, never a replica status) is not misjudged as broken
   *     replication;
   *   - disabled nodes are excluded.
   *
   * @param list<PhabricatorDatabaseRef> $refs Already-populated refs.
   * @return list<map<string, wild>> Issue specifications.
   */
  public static function computeGorgeReplicationIssues(array $refs) {
    $specs = array();

    foreach ($refs as $ref) {
      if ($ref->getDisabled()) {
        continue;
      }

      switch ($ref->getReplicaStatus()) {
        case PhabricatorDatabaseRef::REPLICATION_MASTER_REPLICA:
          $specs[] = array(
            'key' => 'db.master.replicating',
            'name' => pht('Replicating Master'),
            'fatal' => true,
            'message' => pht(
              'Database host "%s" is configured as a master, but is '.
              'replicating another host. This is dangerous and can mangle or '.
              'destroy data. Only replicas should be replicating. Stop '.
              'replication on the host or adjust configuration.',
              $ref->getRefKey()),
          );
          break;
        case PhabricatorDatabaseRef::REPLICATION_REPLICA_NONE:
        case PhabricatorDatabaseRef::REPLICATION_NOT_REPLICATING:
          if (!$ref->getIsMaster()) {
            $specs[] = array(
              'key' => 'db.replica.not-replicating',
              'name' => pht('Nonreplicating Replica'),
              'message' => pht(
                'Database replica "%s" is listed as a replica, but is not '.
                'currently replicating. You are vulnerable to data loss if '.
                'the master fails.',
                $ref->getRefKey()),
            );
          }
          break;
      }
    }

    return $specs;
  }

  /**
   * Rebuild the `db.state.desync` check over the service's state digest.
   *
   * With more than one master, Phorge requires every master to carry the same
   * committed `cluster.databases` state. The service never returns that state
   * (it names hosts), only its SHA-256 digest as `clusterStateDigest` plus a
   * `clusterStatePresent` marker; the expected state is the local
   * configuration, so its digest is computed here with the same bytes
   * @{method:PhabricatorDatabaseRef::getPartitionStateForCommit} commits and
   * compared against the reported one. The per-status decision lives in the
   * pure @{method:isClusterStateStatusSynchronized}.
   */
  private function executeGorgeClusterStateCheck() {
    $masters = PhabricatorDatabaseRef::getAllMasterDatabaseRefs();
    if (count($masters) <= 1) {
      // A single master (or none) can never disagree with itself.
      return;
    }

    $client = new PhabricatorGorgeDBClient();
    $statuses = $client->getMigrationStatus();
    if (!is_array($statuses)) {
      throw new Exception(
        pht(
          'The Gorge database service returned a malformed "%s" response: '.
          'expected a list of migration statuses.',
          '/api/db/migrations/status'));
    }

    $expect_state = null;
    foreach ($masters as $master) {
      $expect_state = $master->getPartitionStateForCommit();
      break;
    }
    if ($expect_state === null) {
      return;
    }

    foreach ($statuses as $status) {
      if (!is_array($status)) {
        throw new Exception(
          pht(
            'The Gorge database service returned a malformed migration '.
            'status row in its "%s" response.',
            '/api/db/migrations/status'));
      }

      // A malformed present-digest throws out of the pure helper; a false or
      // missing presence marker, or a present-but-differing digest, is a
      // desync.
      if (self::isClusterStateStatusSynchronized($status, $expect_state)) {
        continue;
      }

      $ref_key = idx($status, 'refKey', '');
      $message = pht(
        'Database host "%s" has a configured cluster state which disagrees '.
        'with the state on this host ("%s"). Run `bin/storage partition` '.
        'to commit local state to the cluster. This host may have started '.
        'with an out-of-date configuration.',
        $ref_key,
        php_uname('n'));

      $this->newIssue('db.state.desync')
        ->setName(pht('Cluster Configuration Out of Sync'))
        ->setMessage($message)
        ->setIsFatal(true);
      return;
    }
  }

  /**
   * Pure per-master cluster-state agreement decision.
   *
   * Given one decoded `/api/db/migrations/status` row and this install's
   * expected committed `cluster.databases` state, decide whether that master
   * agrees with the local state. This does no HTTP and no MySQL, so it can be
   * exercised directly against fixtures and in-test literals. The caller is
   * responsible for only invoking it when there is more than one master; a
   * single master can never disagree with itself.
   *
   * The four input classes:
   *
   *   - missing: `clusterStatePresent` is false, or the field is entirely
   *     absent. The master has committed no state row, so with more than one
   *     master it can not be confirmed in agreement — return false (a desync).
   *     This deliberately replaces the older silent skip on an empty digest,
   *     which let a genuinely diverged master pass unnoticed.
   *   - malformed: `clusterStatePresent` is true but `clusterStateDigest` is
   *     not a 64-character lowercase hexadecimal SHA-256. The service promised
   *     a digest and did not deliver a well-formed one, so this throws rather
   *     than silently skipping.
   *   - matching: present with a digest equal (via `hash_equals`) to the
   *     expected one — return true.
   *   - mismatching: present with a well-formed but differing digest — return
   *     false (a desync).
   *
   * @param map<string, wild> $status One decoded migration-status row.
   * @param string $expected_state Expected committed cluster state (raw bytes).
   * @return bool True if this master agrees with the local state.
   */
  public static function isClusterStateStatusSynchronized(
    array $status,
    $expected_state) {

    $present = idx($status, 'clusterStatePresent');
    if (!$present) {
      // False or entirely missing: no committed state to compare, which with
      // more than one master is itself a diagnosable desync.
      return false;
    }

    $actual_digest = idx($status, 'clusterStateDigest');
    if (!phutil_nonempty_string($actual_digest) ||
        !preg_match('/^[0-9a-f]{64}$/', $actual_digest)) {
      throw new Exception(
        pht(
          'The Gorge database service reported that host "%s" has a cluster '.
          'state present, but its "%s" is not a well-formed SHA-256 digest. '.
          'Refusing to silently skip a master whose state can not be '.
          'compared.',
          idx($status, 'refKey', ''),
          'clusterStateDigest'));
    }

    $expect_digest = hash('sha256', $expected_state);

    return hash_equals($expect_digest, $actual_digest);
  }

  private static function getMySQLConfigIssueKeys() {
    return array(
      'mysql.max_allowed_packet' => true,
      'sql_mode.strict' => true,
      'mysql.ft_stopword_file' => true,
      'mysql.ft_min_word_len' => true,
      'mysql.innodb_buffer_pool_size' => true,
      'mysql.utf8mb4' => true,
      'mysql.clock' => true,
      'mysql.local_infile' => true,
    );
  }

  private function executeNativeChecks() {
    $host = PhabricatorEnv::getEnvConfig('mysql.host');
    $matches = null;
    if (preg_match('/^([^:]+):(\d+)$/', $host, $matches)) {
      $host = $matches[1];
      $port = $matches[2];

      $this->newIssue('storage.mysql.hostport')
        ->setName(pht('Deprecated mysql.host Format'))
        ->setSummary(
          pht(
            'Move port information from `%s` to `%s` in your config.',
            'mysql.host',
            'mysql.port'))
        ->setMessage(
          pht(
            'Your `%s` configuration contains a port number, but this usage '.
            'is deprecated. Instead, put the port number in `%s`.',
            'mysql.host',
            'mysql.port'))
        ->addPhabricatorConfig('mysql.host')
        ->addPhabricatorConfig('mysql.port')
        ->addCommand(
          hsprintf(
            '<samp>%s $</samp><kbd>./bin/config set mysql.host %s</kbd>',
            PlatformSymbols::getPlatformServerPath(),
            $host))
        ->addCommand(
          hsprintf(
            '<samp>%s $</samp><kbd>./bin/config set mysql.port %s</kbd>',
            PlatformSymbols::getPlatformServerPath(),
            $port));
    }

    $refs = PhabricatorDatabaseRef::queryAll();
    $refs = mpull($refs, null, 'getRefKey');

    // Test if we can connect to each database first. If we can not connect
    // to a particular database, we only raise a warning: this allows new web
    // nodes to start during a disaster, when some databases may be correctly
    // configured but not reachable.

    $connect_map = array();
    $any_connection = false;
    foreach ($refs as $ref_key => $ref) {
      $conn_raw = $ref->newManagementConnection();

      try {
        queryfx($conn_raw, 'SELECT 1');
        $database_exception = null;
        $any_connection = true;
      } catch (AphrontInvalidCredentialsQueryException $ex) {
        $database_exception = $ex;
      } catch (AphrontConnectionQueryException $ex) {
        $database_exception = $ex;
      }

      if ($database_exception) {
        $connect_map[$ref_key] = $database_exception;
        unset($refs[$ref_key]);
      }
    }

    if ($connect_map) {
      // This is only a fatal error if we could not connect to anything. If
      // possible, we still want to start if some database hosts can not be
      // reached.
      $is_fatal = !$any_connection;

      foreach ($connect_map as $ref_key => $database_exception) {
        $issue = PhabricatorSetupIssue::newDatabaseConnectionIssue(
          $database_exception,
          $is_fatal);
        $this->addIssue($issue);
      }
    }

    foreach ($refs as $ref_key => $ref) {
      if ($this->executeRefChecks($ref)) {
        return;
      }
    }
  }

  private function executeRefChecks(PhabricatorDatabaseRef $ref) {
    $conn_raw = $ref->newManagementConnection();

    $versions = queryfx_one($conn_raw, 'SELECT VERSION() as v');
    $server_string = $versions['v'];
    if (phutil_nonempty_string($server_string)) {
      $matches = array();
      if (preg_match('/^(\d+\.\d+\.\d+)/', $server_string, $matches)) {
        $server_version = $matches[1];
        $is_maria_db = stripos($server_string, 'MariaDB');
        // Keep $min_version in sync with 'installation_guide.diviner'!
        if ($is_maria_db) {
          $software_name = 'MariaDB';
          $min_version = '10.5.1';
        } else {
          $software_name = 'MySQL';
          $min_version = '8.0.0';
        }
        if (version_compare($server_version, $min_version, '<')) {
          $message = pht(
            'You are running %s version "%s", which is older than the '.
            'minimum required version, "%s". Update to at least "%s".',
            $software_name,
            $server_version,
            $min_version,
            $min_version);

          $this->newIssue('mysql.version')
            ->setName(pht('Update %s', $software_name))
            ->setMessage($message)
            ->setIsFatal(true);

          return true;
        }
      }
    }

    $ref_key = $ref->getRefKey();

    $engines = queryfx_all($conn_raw, 'SHOW ENGINES');
    $engines = ipull($engines, 'Support', 'Engine');

    $innodb = idx($engines, 'InnoDB');
    if ($innodb != 'YES' && $innodb != 'DEFAULT') {
      $message = pht(
        'The "InnoDB" engine is not available in MySQL (on host "%s"). '.
        'Enable InnoDB in your MySQL configuration.'.
        "\n\n".
        '(If you already created tables, MySQL incorrectly used some other '.
        'engine to create them. You need to convert them or drop and '.
        'reinitialize them.)',
        $ref_key);

      $this->newIssue('mysql.innodb')
        ->setName(pht('MySQL InnoDB Engine Not Available'))
        ->setMessage($message)
        ->setIsFatal(true);

      return true;
    }

    $namespace = PhabricatorEnv::getEnvConfig('storage.default-namespace');

    $databases = queryfx_all($conn_raw, 'SHOW DATABASES');
    $databases = ipull($databases, 'Database', 'Database');

    if (empty($databases[$namespace.'_meta_data'])) {
      $message = pht(
        'Run the storage upgrade script to setup databases (host "%s" has '.
        'not been initialized).',
        $ref_key);

      $this->newIssue('storage.upgrade')
        ->setName(pht('Setup MySQL Schema'))
        ->setMessage($message)
        ->setIsFatal(true)
        ->addCommand(
          hsprintf(
            '<samp>%s $</samp><kbd>./bin/storage upgrade</kbd>',
            PlatformSymbols::getPlatformServerPath()));

      return true;
    }

    $conn_meta = $ref->newApplicationConnection(
      $namespace.'_meta_data');

    $applied = queryfx_all($conn_meta, 'SELECT patch FROM patch_status');
    $applied = ipull($applied, 'patch', 'patch');

    $all = PhabricatorSQLPatchList::buildAllPatches();
    $diff = array_diff_key($all, $applied);

    if ($diff) {
      $message = pht(
        'Run the storage upgrade script to upgrade databases (host "%s" is '.
        'out of date). Missing patches: %s.',
        $ref_key,
        implode(', ', array_keys($diff)));

      $this->newIssue('storage.patch')
        ->setName(pht('Upgrade MySQL Schema'))
        ->setIsFatal(true)
        ->setMessage($message)
        ->addCommand(
          hsprintf(
            '<samp>%s $</samp><kbd>./bin/storage upgrade</kbd>',
            PlatformSymbols::getPlatformServerPath()));

      return true;
    }

    // NOTE: It's possible that replication is broken but we have not been
    // granted permission to "SHOW REPLICA STATUS" so we can't figure it out.
    // We allow this kind of configuration and survive these checks, trusting
    // that operations knows what they're doing. This issue is shown on the
    // "Database Servers" console.

    switch ($ref->getReplicaStatus()) {
      case PhabricatorDatabaseRef::REPLICATION_MASTER_REPLICA:
        $message = pht(
          'Database host "%s" is configured as a master, but is replicating '.
          'another host. This is dangerous and can mangle or destroy data. '.
          'Only replicas should be replicating. Stop replication on the '.
          'host or adjust configuration.',
          $ref->getRefKey());

        $this->newIssue('db.master.replicating')
          ->setName(pht('Replicating Master'))
          ->setIsFatal(true)
          ->setMessage($message);

        return true;
      case PhabricatorDatabaseRef::REPLICATION_REPLICA_NONE:
      case PhabricatorDatabaseRef::REPLICATION_NOT_REPLICATING:
        if (!$ref->getIsMaster()) {
          $message = pht(
            'Database replica "%s" is listed as a replica, but is not '.
            'currently replicating. You are vulnerable to data loss if '.
            'the master fails.',
            $ref->getRefKey());

          // This isn't a fatal because it can normally only put data at risk,
          // not actually do anything destructive or unrecoverable.

          $this->newIssue('db.replica.not-replicating')
            ->setName(pht('Nonreplicating Replica'))
            ->setMessage($message);
        }
        break;
    }

    // If we have more than one master, we require that the cluster database
    // configuration written to each database node is exactly the same as the
    // one we are running with.
    $masters = PhabricatorDatabaseRef::getAllMasterDatabaseRefs();
    if (count($masters) > 1) {
      $state_actual = queryfx_one(
        $conn_meta,
        'SELECT stateValue FROM %T WHERE stateKey = %s',
        PhabricatorStorageManagementAPI::TABLE_HOSTSTATE,
        'cluster.databases');
      if ($state_actual) {
        $state_actual = $state_actual['stateValue'];
      }

      $state_expect = $ref->getPartitionStateForCommit();

      if ($state_expect !== $state_actual) {
        $message = pht(
          'Database host "%s" has a configured cluster state which disagrees '.
          'with the state on this host ("%s"). Run `bin/storage partition` '.
          'to commit local state to the cluster. This host may have started '.
          'with an out-of-date configuration.',
          $ref->getRefKey(),
          php_uname('n'));

        $this->newIssue('db.state.desync')
          ->setName(pht('Cluster Configuration Out of Sync'))
          ->setMessage($message)
          ->setIsFatal(true);
        return true;
      }
    }
  }

}
