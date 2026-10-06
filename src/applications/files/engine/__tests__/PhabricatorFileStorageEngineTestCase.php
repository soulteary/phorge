<?php

final class PhabricatorFileStorageEngineTestCase extends PhabricatorTestCase {

  public function testMigrationCorruptionKeepsOriginalHandle() {
    $source = new PhabricatorTestStorageEngine();
    $handle = $source->writeFile('original bytes', array());
    $file = id(new PhabricatorFile())
      ->makeEphemeral()
      ->setID(777)
      ->setName('migration-contract')
      ->setStorageEngine('unit-test')
      ->setStorageHandle($handle)
      ->setStorageFormat('raw');
    $target = new class extends PhabricatorFileStorageEngine {
      public function getEngineIdentifier() { return 'migration-contract'; }
      public function getEnginePriority() { return 1000; }
      public function canWriteFiles() { return true; }
      public function writeFile($data, array $params) { return 'corrupt-target'; }
      public function readFile($handle) { return 'corrupt bytes'; }
      public function deleteFile($handle) { throw new Exception('Unexpected delete'); }
      public function newIntegrityHash($data, PhabricatorFileStorageFormat $format) {
        return hash('sha256', $data);
      }
    };
    $caught = false;
    try {
      $file->migrateToEngine($target, true);
    } catch (Exception $ex) {
      $caught = (strpos($ex->getMessage(), 'readback integrity') !== false);
    }
    $this->assertTrue($caught);
    $this->assertEqual('unit-test', $file->getStorageEngine());
    $this->assertEqual($handle, $file->getStorageHandle());
    $this->assertEqual('original bytes', $source->readFile($handle));
  }

  public function testLoadAllEngines() {
    PhabricatorFileStorageEngine::loadAllEngines();
    $this->assertTrue(true);
  }

}
