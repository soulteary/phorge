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
    // The diagnostics run INFORMATION_SCHEMA reads and ping probes across the
    // cluster, which can be slower than a single-row queue operation but are
    // still interactive: they back the "Database Servers" and setup-issue
    // console pages. Keep the ceiling modest so a stalled service fails fast
    // and lets the caller fall back to its native direct-SQL path.
    return 10;
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
   *   Each row carries camelCase keys: `host`, `port`, `connectionStatus`,
   *   `connectionLatencySec`, `connectionMessage`, `replicaStatus`,
   *   `replicaMessage`, `replicaDelaySec`.
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
   * @param map<string, wild> $params Optional query parameters.
   * @return wild The "data" section of the envelope, a list of `SchemaNode`
   *   trees. Each node carries camelCase keys: `refKey`, `database`, `table`,
   *   `column`, `key`, `issues`, `status`, `children`.
   */
  public function getSchemaDiff(array $params = array()) {
    return $this->callGet(self::PATH_SCHEMADIFF, $params);
  }

  /**
   * Read the flat list of schema issues.
   *
   * @param map<string, wild> $params Optional query parameters.
   * @return wild The "data" section of the envelope, a list of schema issue
   *   rows with camelCase keys (`refKey`, `database`, `table`, `column`,
   *   `key`, `issue`, `status`).
   */
  public function getSchemaIssues(array $params = array()) {
    return $this->callGet(self::PATH_SCHEMAISSUES, $params);
  }

  /**
   * Read the list of MySQL/setup issues detected across the cluster.
   *
   * @param map<string, wild> $params Optional query parameters.
   * @return wild The "data" section of the envelope, a list of setup issue
   *   rows. Each row carries camelCase keys: `key`, `name`, `summary`,
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
   * Read the migration/patch status per server.
   *
   * @return wild The "data" section of the envelope, a list of migration
   *   status rows with camelCase keys (`refKey`, `initialized`,
   *   `appliedPatches`, `missingPatches`, `totalExpected`).
   */
  public function getMigrationStatus() {
    return $this->callGet(self::PATH_MIGRATIONS);
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
