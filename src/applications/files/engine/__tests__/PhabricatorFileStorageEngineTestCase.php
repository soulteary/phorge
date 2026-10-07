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

  public function testGorgeUploadHandleAndDeletionBoundary() {
    $this->assertTrue(PhabricatorChunkedFileStorageEngine::isGorgeHandle(
      'gorge-upload/'.str_repeat('a', 32)));
    foreach (array('legacy-handle', 'gorge-upload/../file',
      'gorge-upload/'.str_repeat('a', 32)."\n") as $handle) {
      $this->assertFalse(PhabricatorChunkedFileStorageEngine::isGorgeHandle($handle));
    }
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.file.deletion-outbox', true);
    $this->assertTrue(PhabricatorFileGorgeDeletion::shouldQueue('gorge', 'blob/123'));
    $this->assertTrue(PhabricatorFileGorgeDeletion::shouldQueue('chunks',
      'gorge-upload/'.str_repeat('a', 32)));
    $this->assertFalse(PhabricatorFileGorgeDeletion::shouldQueue('chunks', 'legacy'));
    $this->assertFalse(PhabricatorFileGorgeDeletion::shouldQueue('blob', '123'));
  }

  public function testRealGorgeUploadContract() {
    $url = getenv('GORGE_TEST_FILE_URL');
    if (!$url) { $this->assertSkipped('Set GORGE_TEST_FILE_URL for real upload service.'); }
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.file.uri', $url);
    $env->overrideEnvConfig('gorge.file.token', getenv('GORGE_TEST_FILE_TOKEN'));
    $client = new PhabricatorGorgeFileStorageClient();
    $this->assertTrue($client->getUploadCapabilities()['enabled']);
    $id = bin2hex(Filesystem::readRandomBytes(16));
    $chunk = str_repeat('a', 4 * 1024 * 1024);
    $client->createUpload($id, strlen($chunk) + 3);
    try {
      $client->uploadChunk($id, 0, $chunk);
      $client->uploadChunk($id, 0, $chunk);
      $state = $client->getUpload($id);
      $this->assertTrue($state['chunks'][0]['complete']);
      $this->assertFalse($state['chunks'][1]['complete']);
      $client->uploadChunk($id, strlen($chunk), 'xyz');
      $state = $client->completeUpload($id);
      $this->assertEqual('complete', $state['state']);
      $this->assertEqual(hash('sha256', $chunk.'xyz'), $state['sha256']);
      $this->assertEqual('aaxyz', $client->readUploadRange($id,
        strlen($chunk) - 2, strlen($chunk) + 3));
    } finally {
      $client->cancelUpload($id);
    }
    $this->assertEqual(null, $client->getUploadForResume($id));
  }

  public function testGorgeUploadManifestValidation() {
    $id = str_repeat('a', 32);
    $valid = array('id' => $id, 'size' => 3, 'state' => 'uploading',
      'chunks' => array(array('byteStart' => 0, 'byteEnd' => 3, 'complete' => false)));
    $this->assertEqual($valid,
      PhabricatorGorgeFileStorageClient::validateUploadResponse($id, $valid));
    $invalid = array();
    $bad = $valid; $bad['id'] = str_repeat('b', 32); $invalid[] = $bad;
    $bad = $valid; $bad['chunks'] = array(); $invalid[] = $bad;
    $bad = $valid; $bad['chunks'][0]['byteEnd'] = 4; $invalid[] = $bad;
    $bad = $valid; $bad['chunks'][0]['complete'] = 'true'; $invalid[] = $bad;
    $bad = $valid; $bad['state'] = 'complete'; $invalid[] = $bad;
    $bad = $valid; $bad['chunks'][0]['complete'] = true;
    $bad['chunks'][0]['sha256'] = 'bad'; $invalid[] = $bad;
    foreach ($invalid as $bad) {
      $caught = false;
      try { PhabricatorGorgeFileStorageClient::validateUploadResponse($id, $bad); }
      catch (Exception $ex) { $caught = true; }
      $this->assertTrue($caught);
    }
    $valid['state'] = 'complete';
    $valid['sha256'] = hash('sha256', 'abc');
    $valid['chunks'][0]['complete'] = true;
    $valid['chunks'][0]['sha256'] = $valid['sha256'];
    $this->assertEqual($valid,
      PhabricatorGorgeFileStorageClient::validateUploadResponse($id, $valid));
  }

}
