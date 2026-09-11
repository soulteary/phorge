<?php

/**
 * Read-only configuration owned by the deployment rather than by Phorge.
 *
 * The source is loaded after the database source, so values in this file are
 * authoritative for the lifetime of a process. This is intentional: service
 * endpoints and consumer ownership describe the running topology and must not
 * be shadowed by a stale value written through the Config UI.
 *
 * The file is optional. Set PHORGE_DEPLOYMENT_CONFIG to an explicit path, or
 * place it at conf/local/deployment.json. Once a file exists, malformed JSON
 * is fatal instead of being ignored: silently dropping this source could
 * reactivate a native webhook or task-queue consumer.
 */
final class PhabricatorDeploymentConfigSource
  extends PhabricatorConfigProxySource {

  const ENV_PATH = 'PHORGE_DEPLOYMENT_CONFIG';

  private $path;

  public static function getDefaultPath() {
    $root = dirname(phutil_get_library_root('phabricator'));
    return $root.'/conf/local/deployment.json';
  }

  public static function getConfiguredPath() {
    $path = getenv(self::ENV_PATH);
    if ($path === false || !strlen($path)) {
      return self::getDefaultPath();
    }

    return $path;
  }

  public static function newOptionalSource($config_optional = false) {
    // A shared configuration volume may still contain deployment.json after
    // an operator deliberately starts a container with the legacy control
    // plane. The runtime mode is the authority in that case; do not let the
    // retained file keep Gorge ownership active in the legacy container.
    if (getenv('PHORGE_CONTROL_PLANE') === 'legacy') {
      return null;
    }

    $path = self::getConfiguredPath();
    if (!Filesystem::pathExists($path)) {
      // Source installs do not need this file. An explicit deployment control
      // plane does: falling back to local or database values may reactivate a
      // native webhook or task-queue consumer. Setup scripts such as
      // "bin/storage" initialize with optional configuration so a first
      // install can create the database before the deployment file exists.
      if (getenv('PHORGE_CONTROL_PLANE') === 'deployment' &&
          !$config_optional) {
        throw new Exception(
          pht('Deployment configuration "%s" does not exist.', $path));
      }

      return null;
    }

    return new self($path);
  }

  /**
   * Return a content version for the deployment configuration on disk.
   *
   * Long-running daemon overseers use this value to notice an atomic file
   * replacement. Do not use metadata like mtime or size here: a credential
   * rotation may preserve both, particularly when two writes occur in the
   * same filesystem timestamp interval.
   */
  public static function getCurrentConfigVersion() {
    if (getenv('PHORGE_CONTROL_PLANE') === 'legacy') {
      return null;
    }

    $path = self::getConfiguredPath();
    if (!Filesystem::pathExists($path)) {
      return null;
    }

    try {
      $raw = Filesystem::readFile($path);
    } catch (FilesystemException $ex) {
      throw new Exception(
        pht('Deployment configuration "%s" could not be read.', $path),
        0,
        $ex);
    }

    return sha1($raw);
  }

  public function __construct($path) {
    $this->path = $path;

    try {
      $raw = Filesystem::readFile($path);
    } catch (FilesystemException $ex) {
      throw new Exception(
        pht('Deployment configuration "%s" could not be read.', $path),
        0,
        $ex);
    }

    $json = ltrim($raw);
    if (!strlen($json) || $json[0] !== '{') {
      throw new Exception(
        pht('Deployment configuration "%s" must be a JSON object.', $path));
    }

    try {
      $config = phutil_json_decode($raw);
    } catch (PhutilJSONParserException $ex) {
      throw new Exception(
        pht('Deployment configuration "%s" is not valid JSON.', $path),
        0,
        $ex);
    }

    if (!is_array($config)) {
      throw new Exception(
        pht('Deployment configuration "%s" must be a JSON object.', $path));
    }

    // A nonempty JSON list also decodes to a PHP array. Configuration keys
    // are strings, so reject sequential numeric keys explicitly.
    if ($config && array_keys($config) === range(0, count($config) - 1)) {
      throw new Exception(
        pht('Deployment configuration "%s" must be a JSON object.', $path));
    }

    foreach ($config as $key => $value) {
      if (!is_string($key) || !strlen($key)) {
        throw new Exception(
          pht(
            'Deployment configuration "%s" contains an invalid key.',
            $path));
      }
    }

    $this->setSource(new PhabricatorConfigDictionarySource($config));
  }

  public function canWrite() {
    return false;
  }

  public function getReadablePath() {
    return Filesystem::readablePath($this->path);
  }

}
