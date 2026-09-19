<?php

/**
 * Historical MySQL blob storage reader.
 *
 * New writes are owned by Gorge. Keep this engine registered so existing
 * `blob` handles remain readable/deletable until data migration is complete.
 */
final class PhabricatorMySQLFileStorageEngine
  extends PhabricatorFileStorageEngine {

  public function getEngineIdentifier() {
    return 'blob';
  }

  public function getEnginePriority() {
    return 1;
  }

  public function canWriteFiles() {
    return false;
  }

  public function hasFilesizeLimit() {
    return true;
  }

  public function getFilesizeLimit() {
    return PhabricatorEnv::getEnvConfig('storage.mysql-engine.max-size');
  }

  public function writeFile($data, array $params) {
    throw new PhabricatorFileStorageConfigurationException(
      pht(
        'The historical MySQL blob storage engine is read-only. New file '.
        'content must be written through the Gorge file storage engine.'));
  }

  public function readFile($handle) {
    return $this->loadFromMySQLFileStorage($handle)->getData();
  }

  public function deleteFile($handle) {
    $this->loadFromMySQLFileStorage($handle)->delete();
  }

  private function loadFromMySQLFileStorage($handle) {
    $blob = id(new PhabricatorFileStorageBlob())->load($handle);
    if (!$blob) {
      throw new Exception(pht("Unable to load MySQL blob file '%s'!", $handle));
    }
    return $blob;
  }

}
