<?php

/**
 * Historical local-disk storage reader.
 *
 * New writes are owned by Gorge. Keep this engine registered so existing
 * `local-disk` handles remain readable/deletable until migration is complete.
 */
final class PhabricatorLocalDiskFileStorageEngine
  extends PhabricatorFileStorageEngine {

  public function getEngineIdentifier() {
    return 'local-disk';
  }

  public function getEnginePriority() {
    return 5;
  }

  public function canWriteFiles() {
    return false;
  }

  public function writeFile($data, array $params) {
    throw new PhabricatorFileStorageConfigurationException(
      pht(
        'The historical local-disk storage engine is read-only. New file '.
        'content must be written through the Gorge file storage engine.'));
  }

  public function readFile($handle) {
    $path = $this->getLocalDiskFileStorageFullPath($handle);
    return Filesystem::readFile($path);
  }

  public function deleteFile($handle) {
    $path = $this->getLocalDiskFileStorageFullPath($handle);
    if (Filesystem::pathExists($path)) {
      AphrontWriteGuard::willWrite();
      Filesystem::remove($path);
    }
  }

  private function getLocalDiskFileStorageRoot() {
    $root = PhabricatorEnv::getEnvConfig('storage.local-disk.path');

    if (!$root || $root == '/' || $root[0] != '/') {
      throw new PhabricatorFileStorageConfigurationException(
        pht(
          "Malformed local disk storage root. You must provide an absolute ".
          "path, and can not use '%s' as the root.",
          '/'));
    }

    return rtrim($root, '/');
  }

  private function getLocalDiskFileStorageFullPath($handle) {
    if (!preg_match('@^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{28}\z@', $handle)) {
      throw new Exception(
        pht(
          "Local disk filesystem handle '%s' is malformed!",
          $handle));
    }

    return $this->getLocalDiskFileStorageRoot().'/'.$handle;
  }

}
