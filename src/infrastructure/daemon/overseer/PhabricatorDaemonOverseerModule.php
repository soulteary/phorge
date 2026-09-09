<?php

/**
 * Overseer module.
 *
 * The primary purpose of this overseer module is to poll for configuration
 * changes and reload daemons when the configuration changes.
 */
final class PhabricatorDaemonOverseerModule
  extends PhutilDaemonOverseerModule {

  private $configVersion;

  public function shouldReloadDaemons() {
    if ($this->shouldThrottle('reload', 10)) {
      return false;
    }

    return $this->updateConfigVersion();
  }

  /**
   * Calculate a version number for the current Phabricator configuration.
   *
   * The version number has no real meaning and does not provide any real
   * indication of whether a configuration entry has been changed. The config
   * version is intended to be a rough indicator that "something has changed",
   * which indicates to the overseer that the daemons should be reloaded.
   *
   * @return string
   */
  private function loadConfigVersion() {
    $conn_r = id(new PhabricatorConfigEntry())->establishConnection('r');
    $database_version = head(queryfx_one(
      $conn_r,
      'SELECT MAX(id) FROM %T',
      id(new PhabricatorConfigTransaction())->getTableName()));

    // Deployment configuration is loaded into memory when each daemon
    // process starts. Include the file content in the version observed by
    // the overseer so an atomic endpoint or token rotation sends SIGHUP to
    // the current workers and replaces them with processes using the new
    // configuration. A content digest also detects same-size writes within a
    // single filesystem timestamp interval.
    $deployment_version =
      PhabricatorDeploymentConfigSource::getCurrentConfigVersion();

    return $database_version.':'.$deployment_version;
  }

  /**
   * Check and update the configuration version.
   *
   * @return bool  True if the daemons should restart, otherwise false.
   */
  private function updateConfigVersion() {
    $old_version = $this->configVersion;
    $new_version = $this->loadConfigVersion();

    $this->configVersion = $new_version;

    // Don't trigger a reload if we're loading the config for the very
    // first time.
    if ($old_version === null) {
      return false;
    }

    return ($old_version != $new_version);
  }

}
