<?php

/**
 * HTTP client for the Gorge database API service.
 *
 * Gorge is a Go service which fronts the MySQL cluster: it owns the cluster
 * topology, connection pooling and schema diagnostics, and exposes them over
 * an authenticated HTTP API rooted at `/api/db`. When the service is
 * configured (`gorge.db.uri` is set), the PHP side reads server health,
 * schema diffs and setup issues from the service instead of opening its own
 * management connections to every database host; when it is not configured,
 * the callers fall back to their native direct-SQL implementation and this
 * client is never constructed.
 *
 * Like the other Gorge domains, this is an "active client": the PHP side
 * initiates every request over HTTP. @{method:isConfigured} is the switch each
 * caller checks before choosing the Go path over its native SQL fallback:
 *
 *   - @{class:PhabricatorDatabaseRef} reads per-server connection/replica
 *     status from `/api/db/servers`,
 *   - @{class:PhabricatorDatabaseSetupCheck} and
 *     @{class:PhabricatorMySQLSetupCheck} read `/api/db/setup-issues`,
 *   - @{class:PhabricatorConfigSchemaQuery} reads `/api/db/schema-diff` and
 *     `/api/db/charset-info`.
 *
 * Request building and envelope parsing live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share. Response fields are read as camelCase, matching the platform
 * contract the Go service emits (`refKey`, `isFatal`, and so on).
 */
final class PhabricatorGorgeDBClient
  extends PhabricatorGorgeServiceClient {

  const PATH_SERVERS    = '/api/db/servers';
  const PATH_HEALTH     = '/api/db/servers/%s/health';
  const PATH_SCHEMADIFF = '/api/db/schema-diff';
  const PATH_SCHEMAISSUES = '/api/db/schema-issues';
  const PATH_SETUPISSUES  = '/api/db/setup-issues';
  const PATH_CHARSETINFO   = '/api/db/charset-info';
  const PATH_MIGRATIONS    = '/api/db/migrations/status';
  const PATH_META          = '/api/db/meta';

  // The wire-contract major version this consumer is written for. The service
  // reports its own version on /api/db/meta as "major.minor"; the console
  // refuses to switch over unless the major matches, because a different major
  // means a field this adapter reads may have been removed or changed meaning.
  // Bump this only alongside the changes in go/internal/contracts/dbapi.go that
  // this adapter is updated to read.
  const CONTRACT_MAJOR = 1;

  public function __construct() {
    $uri = self::getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge '.
          'database service, but it is not set.',
          'gorge.db.uri'));
    }

    $this->setURI($uri);
    $this->setToken(
      PhabricatorEnv::getEnvConfigIfExists('gorge.db.token'));
  }

  protected static function getServiceName() {
    return pht('Gorge database service');
  }

  protected function getDefaultTimeout() {
    return self::getTimeoutBudgetForPath(null);
  }


  /**
   * Central request-timeout budget, in seconds, keyed by route.
   *
   * The rule is one-directional and load-bearing: the PHP client's ceiling
   * must be slightly LONGER than the server-side query timeout the Go service
   * enforces for the same work, never shorter. If the client gives up first,
   * it abandons a request the service is still willing to answer, turns a
   * slow-but-succeeding diagnostic into a fallback to native SQL, and — worse
   * for the operator — hides the specific error the service was about to
   * return (access denied, unreachable node) behind a generic client timeout.
   *
   * The server-side ceilings live in go/internal/dbapi (schema.go:
   * QueryTimeoutSec 30; setup.go and migration.go: 10). The largest of those,
   * the 30-second `INFORMATION_SCHEMA` walk behind `/schema-diff`, is what the
   * default has to clear, so the default is 35: 30 plus a few seconds for the
   * connection, the round trip and the service's own overhead. A route whose
   * server-side work is bounded much lower does not need to wait that long,
   * but overshooting is safe (a healthy service answers well before the
   * ceiling) while undershooting is the bug this method exists to prevent, so
   * the cheaper routes simply share the default rather than each pinning a
   * number that would have to be kept in lockstep with the Go side.
   *
   * @param string|null $path Route path, or null for the default.
   * @return int Timeout in seconds.
   */
  public static function getTimeoutBudgetForPath($path) {
    // 30s is the longest server-side query timeout in the Go service
    // (schema.go). Clear it with margin so the client never gives up on a
    // request the service would still answer.
    return 35;
  }


/* -(  Configuration  )------------------------------------------------------ */


  /**
   * Read the configured base URI of the service.
   *
   * @return string|null Base URI with any trailing slash removed, or null if
   *   the service is not configured.
   */
  public static function getConfiguredURI() {
    $uri = PhabricatorEnv::getEnvConfigIfExists('gorge.db.uri');

    if (!phutil_nonempty_string($uri)) {
      return null;
    }

    // Trailing slashes matter: the service routes exactly, and a doubled
    // slash produces an "ERR_NOT_FOUND" envelope rather than a result.
    return rtrim($uri, '/');
  }

  /**
   * Decide whether the database cluster is fronted by the service.
   *
   * This is the guard behind every Go path in the database console:
   * @{class:PhabricatorDatabaseRef}, the two setup checks and
   * @{class:PhabricatorConfigSchemaQuery} all ask this method before choosing
   * the service over their native direct-SQL implementation, so that an
   * install which has not configured the service keeps working exactly as
   * before.
   *
   * @return bool True if the service fronts the database cluster.
   */
  public static function isConfigured() {
    return (self::getConfiguredURI() !== null);
  }


/* -(  Diagnostics  )-------------------------------------------------------- */


  /**
   * List the cluster servers with connection and replica status.
   *
   * @return wild The "data" section of the envelope, a list of server rows.
   *   Each row carries camelCase keys: `refKey`, `host`, `port`, `user`,
   *   `isMaster`, `disabled`, `isIndividual`, `isDefaultPartition`,
   *   `connectionStatus`, `connectionLatencySec`, `connectionMessage`,
   *   `replicationStatus`, `replicaMessage`, `secondsBehindMaster`.
   */
  public function getServers() {
    return $this->callGet(self::PATH_SERVERS);
  }

  /**
   * Read the health of a single server by ref key ("host:port").
   *
   * @param string $ref Ref key identifying the server.
   * @return wild The "data" section of the envelope.
   */
  public function getServerHealth($ref) {
    $path = sprintf(self::PATH_HEALTH, phutil_escape_uri((string)$ref));
    return $this->callGet($path);
  }

  /**
   * Read the actual schema of each server as a tree.
   *
   * @param map<string, wild> $params Optional query parameters. The
   *   `databases` key, a comma-separated list of expected database names,
   *   lets the service report an existing-but-restricted database as an
   *   accessDenied node rather than confusing it with an absent one.
   * @return wild The "data" section of the envelope, a list of `SchemaNode`
   *   trees. Each node carries camelCase keys: `refKey`, `databaseName`,
   *   `tableName`, `columnName`, `characterSet`, `collation`, `engine`,
   *   `columnType`, `nullable`, `autoIncrement`, `accessDenied`, `keys`,
   *   `issues`, `status`, `children`. Each entry of `keys` is a `SchemaKey`
   *   with `name`, `columnNames`, `unique`, `indexType`.
   */
  public function getSchemaDiff(array $params = array()) {
    return $this->callGet(self::PATH_SCHEMADIFF, $params);
  }

  /**
   * Read the flat list of schema issues.
   *
   * @param map<string, wild> $params Optional query parameters.
   * @return wild The "data" section of the envelope, a list of schema issue
   *   rows with camelCase keys (`refKey`, `databaseName`, `tableName`,
   *   `columnName`, `issueKey`, `expected`, `actual`, `issue`, `status`).
   */
  public function getSchemaIssues(array $params = array()) {
    return $this->callGet(self::PATH_SCHEMAISSUES, $params);
  }

  /**
   * Read the list of MySQL/setup issues detected across the cluster.
   *
   * @param map<string, wild> $params Optional query parameters.
   * @return wild The "data" section of the envelope, a list of setup issue
   *   rows. Each row carries camelCase keys: `issueKey`, `name`, `summary`,
   *   `message`, `isFatal`, `refKey`.
   */
  public function getSetupIssues(array $params = array()) {
    return $this->callGet(self::PATH_SETUPISSUES, $params);
  }

  /**
   * Read the expected charset/collation configuration per server.
   *
   * @return wild The "data" section of the envelope, a list of charset rows.
   *   Each row carries camelCase keys: `refKey`, `charsetDefault`,
   *   `charsetSort`, `charsetFulltext`, `collateText`, `collateSort`,
   *   `collateFulltext`.
   */
  public function getCharsetInfo() {
    return $this->callGet(self::PATH_CHARSETINFO);
  }

  /**
   * Read the migration/patch status per master.
   *
   * @return wild The "data" section of the envelope, a list of migration
   *   status rows with camelCase keys (`refKey`, `initialized`,
   *   `appliedPatches`, `clusterStateDigest`). The service reports only the
   *   observed applied patch keys; it owns no expected list, so the caller
   *   diffs `appliedPatches` against `PhabricatorSQLPatchList` itself.
   *   `clusterStateDigest` is the SHA-256 of the committed `cluster.databases`
   *   state, for the multi-master desync check.
   */
  public function getMigrationStatus() {
    return $this->callGet(self::PATH_MIGRATIONS);
  }

  /**
   * Read the service's capability and contract metadata.
   *
   * This is the handshake the console makes before it routes anything through
   * the service: the `contractVersion` it must be able to read, the
   * `namespace` it must match against `storage.default-namespace`, the
   * `topologySource` (`file` or `single-node`), and the `capabilities` list.
   * The endpoint runs no query and names no host, so it is the one route that
   * can not fail on the cluster.
   *
   * @return wild The "data" section of the envelope: a map with camelCase keys
   *   `contractVersion`, `namespace`, `topologySource`, `capabilities`.
   */
  public function getMeta() {
    return $this->callGet(self::PATH_META);
  }

  /**
   * Build a fresh setup issue from one decoded `/api/db/setup-issues` row.
   *
   * This is the pure translation from the service's `SetupIssue` wire shape to
   * a @{class:PhabricatorSetupIssue}, split out from the two setup checks so it
   * can be exercised directly against the canonical contract fixtures. It reads
   * exactly the camelCase keys the Go `contracts.SetupIssue` emits —
   * `issueKey`, `name`, `summary`, `message`, `isFatal` — so a consumer that
   * read `key` instead of `issueKey` (the pre-alignment bug that collapsed
   * every issue to "gorge.db.unknown") makes the fixture test fail.
   *
   * The returned issue carries no group and is not registered with any check:
   * grouping is the caller's decision (MySQL-config keys belong to
   * @{class:PhabricatorMySQLSetupCheck}, the rest to
   * @{class:PhabricatorDatabaseSetupCheck}), so the two checks call this and
   * then apply their own group and registration. The `unknownKey` default is
   * only used when the row omits `issueKey` entirely; a present-but-empty key
   * is left as-is so the mismatch is visible rather than masked.
   *
   * @param map<string, wild> $issue_data One decoded setup-issue row.
   * @param string $unknown_key Key to use when the row names none.
   * @return PhabricatorSetupIssue Populated, ungrouped, unregistered issue.
   */
  public static function newSetupIssueFromRow(
    array $issue_data,
    $unknown_key = 'gorge.db.unknown') {

    $key = idx($issue_data, 'issueKey', $unknown_key);

    $issue = id(new PhabricatorSetupIssue())
      ->setIssueKey($key)
      ->setName(idx($issue_data, 'name', $key));

    $summary = idx($issue_data, 'summary');
    if (phutil_nonempty_string($summary)) {
      $issue->setSummary($summary);
    }

    $message = idx($issue_data, 'message');
    if (phutil_nonempty_string($message)) {
      $issue->setMessage($message);
    }

    if (idx($issue_data, 'isFatal')) {
      $issue->setIsFatal(true);
    }

    return $issue;
  }

  /**
   * Compute the patches missing from one migration-status row.
   *
   * This is the pure half of the `storage.patch` check: given one decoded
   * `/api/db/migrations/status` row and the canonical expected patch map from
   * @{class:PhabricatorSQLPatchList}, it returns the patch keys the master has
   * not applied. It reads exactly the camelCase keys the Go
   * `contracts.MigrationStatus` emits — `initialized` and `appliedPatches` —
   * so a consumer that read a legacy `patch` field, or that treated an
   * uninitialized master as up to date, makes the fixture test fail.
   *
   * Returns null (rather than an empty array) when there is nothing to diff:
   * an uninitialized master, whose bare state the service's own
   * `storage.upgrade` issue already covers. A malformed `appliedPatches` (a
   * present value that is not a list) throws, matching the caller's contract
   * check.
   *
   * @param map<string, wild> $status One decoded migration-status row.
   * @param map<string, wild> $all_patches Expected patches, keyed by patch key.
   * @return list<string>|null Missing patch keys, or null when not applicable.
   */
  public static function missingPatchesForStatus(
    array $status,
    array $all_patches) {

    if (!idx($status, 'initialized')) {
      return null;
    }

    $applied_list = idx($status, 'appliedPatches');
    if (!is_array($applied_list)) {
      throw new Exception(
        pht(
          'The Gorge database service returned a migration status without '.
          'a valid "%s" list.',
          'appliedPatches'));
    }

    $applied = array_fuse($applied_list);
    $diff = array_diff_key($all_patches, $applied);

    return array_keys($diff);
  }

  /**
   * Verify the service speaks a contract this install can read, before the
   * console switches any page over to it.
   *
   * Returns null when the service is compatible. Otherwise returns a
   * self-describing problem the caller renders as a setup issue and then falls
   * back to native SQL for, rather than reading fields that may have moved:
   *
   *   - the contract major version differs from CONTRACT_MAJOR, so a field
   *     this adapter reads may have been removed or changed meaning, or
   *   - the service's namespace does not equal this install's
   *     `storage.default-namespace`, so it is reporting on databases named
   *     with a different prefix than Phorge reads.
   *
   * The check is deliberately permissive about additions: an unknown extra
   * capability or a newer minor version is compatible.
   *
   * @param string $expected_namespace This install's storage namespace.
   * @return map<string, string>|null Problem description, or null when
   *   compatible. Keys: `code` (one of "version", "namespace"), `summary`,
   *   `detail`.
   */
  public function checkContractCompatibility($expected_namespace) {
    $meta = $this->getMeta();

    $version = idx($meta, 'contractVersion');
    if (!phutil_nonempty_string($version)) {
      return array(
        'code' => 'version',
        'summary' => pht(
          'The Gorge database service did not report a contract version.'),
        'detail' => pht(
          'The service at %s answered its capability endpoint without a '.
          '"contractVersion". This install can only read contract major '.
          'version %d, and without a version it can not confirm the service '.
          'speaks it, so it will keep reading the database console with '.
          'native SQL.',
          $this->getURI(),
          self::CONTRACT_MAJOR),
      );
    }

    // The version is "major.minor"; only the major gates compatibility.
    $major = (int)head(explode('.', $version));
    if ($major !== self::CONTRACT_MAJOR) {
      return array(
        'code' => 'version',
        'summary' => pht(
          'The Gorge database service speaks an incompatible contract '.
          'version.'),
        'detail' => pht(
          'The service at %s reports contract version %s, but this install '.
          'reads contract major version %d. A different major version means '.
          'a field this software reads may have been removed or changed '.
          'meaning, so rather than read the wrong data, the database console '.
          'keeps using native SQL. Align the service image with this install '.
          'before enabling it.',
          $this->getURI(),
          $version,
          self::CONTRACT_MAJOR),
      );
    }

    $namespace = idx($meta, 'namespace');
    if (phutil_nonempty_string($expected_namespace) &&
        $namespace !== $expected_namespace) {
      return array(
        'code' => 'namespace',
        'summary' => pht(
          'The Gorge database service is configured for a different '.
          'namespace than this install.'),
        'detail' => pht(
          'The service at %s reports namespace "%s", but this install\'s '.
          '%s is "%s". The service names the databases it inspects '.
          '"{namespace}_meta_data" and so on, so a mismatched namespace means '.
          'it is reporting on databases this install does not read. The '.
          'database console keeps using native SQL until %s on the service '.
          '(%s) matches %s here.',
          $this->getURI(),
          (string)$namespace,
          'storage.default-namespace',
          $expected_namespace,
          'GORGE_DB_NAMESPACE',
          (string)$namespace,
          $expected_namespace),
      );
    }

    return null;
  }


/* -(  Requests  )----------------------------------------------------------- */


  /**
   * Issue an authenticated GET and unwrap the envelope.
   *
   * @param string $path Route path, appended to the base URI.
   * @param map<string, wild> $params Optional query parameters.
   * @return wild The "data" section of the envelope.
   */
  private function callGet($path, array $params = array()) {
    $uri = $this->getURI().$path;

    if ($params) {
      $uri .= '?'.http_build_query($params, '', '&');
    }

    $future = $this->newRequestFuture($uri);

    return self::parseResponseEnvelope($uri, $future->resolve());
  }

}
