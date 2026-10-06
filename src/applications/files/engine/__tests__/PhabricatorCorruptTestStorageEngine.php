<?php

/** Named fixture: anonymous engine classes are not supported by the class map. */
final class PhabricatorCorruptTestStorageEngine extends PhabricatorFileStorageEngine {
  private $allowWrite = false;
  public function setAllowWrite($allow) { $this->allowWrite = $allow; return $this; }
  public function getEngineIdentifier() { return 'migration-contract'; }
  public function getEnginePriority() { return 1000; }
  public function canWriteFiles() { return $this->allowWrite; }
  public function writeFile($data, array $params) { return 'corrupt-target'; }
  public function readFile($handle) { return 'corrupt bytes'; }
  public function deleteFile($handle) { throw new Exception('Unexpected delete'); }
  public function newIntegrityHash($data, PhabricatorFileStorageFormat $format) {
    return hash('sha256', $data);
  }
}
