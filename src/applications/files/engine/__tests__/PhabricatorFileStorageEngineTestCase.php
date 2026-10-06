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
    $target = id(new PhabricatorCorruptTestStorageEngine())->setAllowWrite(true);
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
