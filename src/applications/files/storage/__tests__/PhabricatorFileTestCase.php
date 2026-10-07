<?php

final class PhabricatorFileTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testRealGorgeAllocationRecovery() {
    $url = getenv('GORGE_TEST_FILE_URL');
    if (!$url) { $this->assertSkipped('Set GORGE_TEST_FILE_URL for allocation recovery.'); }
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.file.uri', $url);
    $env->overrideEnvConfig('gorge.file.token', getenv('GORGE_TEST_FILE_TOKEN'));
    $env->overrideEnvConfig('gorge.file.deletion-outbox', true);
    $client = new PhabricatorGorgeFileStorageClient();
    $viewer = $this->generateNewTestUser();
    foreach (array('complete', 'expired') as $state) {
      $id = bin2hex(Filesystem::readRandomBytes(16));
      $hash = hash('sha256', $id);
      $client->createUpload($id, 3);
      try {
        $file = PhabricatorFile::newChunkedFile(
          new PhabricatorChunkedFileStorageEngine(), 3, array(
            'name' => 'recovery-'.$state,
            'authorPHID' => $viewer->getPHID(),
            'viewPolicy' => PhabricatorPolicies::POLICY_USER,
            'chunkedHash' => PhabricatorChunkedFileStorageEngine::getChunkedHash($viewer, $hash)));
        $file->setStorageHandle('gorge-upload/'.$id)->saveAndIndex();
        $this->assertEqual(PhabricatorChunkedFileStorageEngine::getChunkedHash($viewer, $hash),
          $file->getContentHash());
        $found = id(new PhabricatorFileQuery())->setViewer($viewer)
          ->setRaisePolicyExceptions(true)
          ->withContentHashes(array($file->getContentHash()))->executeOne();
        $this->assertTrue((bool)$found);
        if ($state === 'complete') {
          $parts = id(new ConduitCall('file.querychunks', array('filePHID' => $file->getPHID())))
            ->setUser($viewer)->execute();
          $this->assertEqual(array(array('byteStart' => 0, 'byteEnd' => 3, 'complete' => false)), $parts);
          $other = $this->generateNewTestUser();
          $denied = false;
          try {
            id(new ConduitCall('file.uploadchunk', array('filePHID' => $file->getPHID(),
              'byteStart' => 0, 'data' => base64_encode('bad'), 'dataEncoding' => 'base64')))
              ->setUser($other)->execute();
          } catch (PhabricatorPolicyException $ex) { $denied = true; }
          $this->assertTrue($denied);
          $this->assertFalse($client->getUpload($id)['chunks'][0]['complete']);
          id(new ConduitCall('file.uploadchunk', array('filePHID' => $file->getPHID(),
            'byteStart' => 0, 'data' => base64_encode('abc'), 'dataEncoding' => 'base64')))
            ->setUser($viewer)->execute();
          $file->reload();
          $this->assertFalse((bool)$file->getIsPartial());
          $this->assertEqual('abc', $file->loadFileData());
          $this->assertEqual($file->getIntegrityHash(), $file->newIntegrityHash());
          $parts = id(new ConduitCall('file.querychunks', array('filePHID' => $file->getPHID())))
            ->setUser($viewer)->execute();
          $this->assertTrue($parts[0]['complete']);
          $this->assertEqual(hash('sha256', 'abc'), idx($file->getMetadata(), 'gorge.upload.sha256'));
          // Simulate PHP completion state being lost after Go committed it.
          $file->setIsPartial(1)->save();
        } else {
          $client->cancelUpload($id);
        }
        $result = id(new ConduitCall('file.allocate', array(
          'name' => $file->getName(), 'contentHash' => $hash, 'contentLength' => 3)))
          ->setUser($viewer)->execute();
        if ($state === 'complete') {
          $this->assertFalse($result['upload']);
          $this->assertEqual($file->getPHID(), $result['filePHID']);
          $file->reload();
          $this->assertEqual(hash('sha256', 'abc'), idx($file->getMetadata(), 'gorge.upload.sha256'));
        } else {
          $this->assertTrue($result['upload']);
          $this->assertEqual(null, $result['filePHID']);
          $this->assertEqual(null, id(new PhabricatorFile())->load($file->getID()));
          $intent = id(new PhabricatorFileGorgeDeletion())->loadOneWhere(
            'storageHandle = %s', 'gorge-upload/'.$id);
          $this->assertEqual('pending', $intent->getState());
        }
      } finally { $client->cancelUpload($id); }
    }
  }

  public function testRetirementCountsHistoricalAndMalformedChunkHandles() {
    $before = PhabricatorChunkedFileStorageEngine::countLegacyFileRecords();
    foreach (array('legacy', 'gorge-upload/'.str_repeat('a', 32),
      'gorge-upload/'.str_repeat('A', 32),
      'gorge-upload/'.str_repeat('a', 32)."\n",
      'gorge-upload/'.str_repeat('z', 32)) as $handle) {
      $file = PhabricatorFile::newChunkedFile(
        new PhabricatorChunkedFileStorageEngine(), 3, array('name' => 'retirement'));
      $file->setStorageHandle($handle)->save();
    }
    $this->assertEqual($before + 4,
      PhabricatorChunkedFileStorageEngine::countLegacyFileRecords());
  }

  public function testRealHistoricalLocalDiskMigration() {
    $url = getenv('GORGE_TEST_FILE_URL');
    if (!$url) { $this->assertSkipped('Set GORGE_TEST_FILE_URL for historical migration.'); }
    $root = Filesystem::createTemporaryDirectory();
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('storage.local-disk.path', $root);
    $env->overrideEnvConfig('gorge.file.uri', $url);
    $env->overrideEnvConfig('gorge.file.token', getenv('GORGE_TEST_FILE_TOKEN'));
    $env->overrideEnvConfig('gorge.file.deletion-outbox', true);
    $client = new PhabricatorGorgeFileStorageClient();
    try {
      foreach (array(true, false) as $copy) {
        $data = Filesystem::readRandomCharacters(64);
        $source = new PhabricatorTestStorageEngine();
        $file = PhabricatorFile::newFromFileData($data,
          array('name' => 'historical', 'storageEngines' => array($source)));
        $stored = $source->readFile($file->getStorageHandle());
        $handle = 'aa/bb/'.bin2hex(Filesystem::readRandomBytes(14));
        Filesystem::createDirectory($root.'/aa/bb', 0700, true);
        Filesystem::writeFile($root.'/'.$handle, $stored);
        $file->setStorageEngine('local-disk')->setStorageHandle($handle)->save();
        $this->assertEqual($data, $file->loadFileData());
        $file->migrateToEngine(new PhabricatorGorgeFileStorageEngine(), $copy);
        try {
          $file->reload();
          $this->assertEqual('gorge', $file->getStorageEngine());
          $this->assertEqual($data, $file->loadFileData());
          $this->assertEqual($file->getIntegrityHash(), $file->newIntegrityHash());
          $this->assertEqual($copy, Filesystem::pathExists($root.'/'.$handle));
        } finally {
          list($backend, $key) = explode('/', $file->getStorageHandle(), 2);
          $client->deleteFile($backend, $key);
        }
      }
    } finally { Filesystem::remove($root); }
  }

  public function testRealHistoricalBlobAndChunksMigration() {
    if (!getenv('GORGE_TEST_FILE_URL')) { $this->assertSkipped('Requires paired file service.'); }
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.file.uri', getenv('GORGE_TEST_FILE_URL'));
    $env->overrideEnvConfig('gorge.file.token', getenv('GORGE_TEST_FILE_TOKEN'));
    $env->overrideEnvConfig('gorge.file.deletion-outbox', true);
    $client = new PhabricatorGorgeFileStorageClient();
    foreach (array('blob', 'chunks') as $kind) {
      foreach (array(true, false) as $copy) {
        $data = Filesystem::readRandomCharacters(64);
        $source = new PhabricatorTestStorageEngine();
        if ($kind === 'blob') {
          $file = PhabricatorFile::newFromFileData($data,
            array('name'=>'historical-blob','storageEngines'=>array($source)));
          $blob = id(new PhabricatorFileStorageBlob())->setData(
            $source->readFile($file->getStorageHandle()))->save();
          $file->setStorageEngine('blob')->setStorageHandle((string)$blob->getID())->save();
        } else {
          $file = PhabricatorFile::newChunkedFile(new PhabricatorChunkedFileStorageEngine(),
            64, array('name'=>'historical-chunks'));
          $file->setIsPartial(0)->save();
          foreach (array(0,32) as $start) {
            $child = PhabricatorFile::newFromFileData(substr($data,$start,32),
              array('name'=>'historical-chunk','storageEngines'=>array($source)));
            $blob = id(new PhabricatorFileStorageBlob())->setData(
              $source->readFile($child->getStorageHandle()))->save();
            $child->setStorageEngine('blob')->setStorageHandle((string)$blob->getID())->save();
            PhabricatorFileChunk::initializeNewChunk($file->getStorageHandle(),$start,$start+32)
              ->setDataFilePHID($child->getPHID())->save();
          }
        }
        $old = $file->getStorageHandle();
        $this->assertEqual($data,$file->loadFileData());
        $file->migrateToEngine(new PhabricatorGorgeFileStorageEngine(),$copy);
        try {
          $file->reload();
          $this->assertEqual($data,$file->loadFileData());
          $this->assertEqual($file->getIntegrityHash(),$file->newIntegrityHash());
          if ($kind === 'blob') {
            $this->assertEqual($copy,(bool)id(new PhabricatorFileStorageBlob())->load($old));
          } else {
            $chunks = id(new PhabricatorFileChunk())->loadAllWhere('chunkHandle=%s',$old);
            $this->assertEqual($copy ? 2 : 0,count($chunks));
          }
        } finally {
          list($backend,$key) = explode('/',$file->getStorageHandle(),2);
          $client->deleteFile($backend,$key);
        }
      }
    }
    // A move of one historical reference must leave the shared blob readable.
    $data = Filesystem::readRandomCharacters(64);
    $source = new PhabricatorTestStorageEngine();
    $first = PhabricatorFile::newFromFileData($data,
      array('name'=>'historical-shared','storageEngines'=>array($source)));
    $blob = id(new PhabricatorFileStorageBlob())->setData($source->readFile($first->getStorageHandle()))->save();
    $first->setStorageEngine('blob')->setStorageHandle((string)$blob->getID())->save();
    $second = clone $first;
    $second->setID(null)->setPHID(null)->save();
    $first->migrateToEngine(new PhabricatorGorgeFileStorageEngine(),false);
    try {
      $this->assertEqual($data,$second->loadFileData());
      $second->delete();
      $this->assertEqual(null,id(new PhabricatorFileStorageBlob())->load($blob->getID()));
    } finally {
      list($backend,$key)=explode('/',$first->getStorageHandle(),2);
      $client->deleteFile($backend,$key);
    }
  }

  public function testRealHistoricalS3Migration() {
    if (!getenv('GORGE_TEST_S3_ENDPOINT')) { $this->assertSkipped('Requires real disposable S3 service.'); }
    $env=PhabricatorEnv::beginScopedEnv();
    foreach (array('amazon-s3.endpoint'=>getenv('GORGE_TEST_S3_ENDPOINT'),
      'amazon-s3.region'=>'us-east-1','amazon-s3.access-key'=>getenv('GORGE_TEST_S3_USER'),
      'amazon-s3.secret-key'=>getenv('GORGE_TEST_S3_PASSWORD'),
      'storage.s3.bucket'=>getenv('GORGE_TEST_S3_BUCKET'),
      'gorge.file.uri'=>getenv('GORGE_TEST_FILE_URL'),'gorge.file.token'=>getenv('GORGE_TEST_FILE_TOKEN'),
      'gorge.file.deletion-outbox'=>true) as $key=>$value) { $env->overrideEnvConfig($key,$value); }
    $future=function() {
      return id(new PhutilAWSS3Future())->addHeader('Host','127.0.0.1')
        ->setEndpoint(getenv('GORGE_TEST_S3_ENDPOINT'))
        ->setRegion('us-east-1')->setAccessKey(getenv('GORGE_TEST_S3_USER'))
        ->setSecretKey(new PhutilOpaqueEnvelope(getenv('GORGE_TEST_S3_PASSWORD')))
        ->setBucket(getenv('GORGE_TEST_S3_BUCKET'));
    };
    foreach (array(true,false) as $copy) {
      $data=Filesystem::readRandomCharacters(64);$source=new PhabricatorTestStorageEngine();
      $file=PhabricatorFile::newFromFileData($data,array('name'=>'historical-s3','storageEngines'=>array($source)));
      $old='historical/'.bin2hex(Filesystem::readRandomBytes(16));
      $future()->setParametersForPutObject($old,$source->readFile($file->getStorageHandle()))->resolve();
      $file->setStorageEngine('amazon-s3')->setStorageHandle($old)->save();
      try {
        $this->assertEqual($data,$file->loadFileData());
        $file->migrateToEngine(new PhabricatorGorgeFileStorageEngine(),$copy);
        $file->reload();
        $this->assertEqual($data,$file->loadFileData());
        $this->assertEqual($file->getIntegrityHash(),$file->newIntegrityHash());
        $remaining=$future()->setParametersForGetObject($old)->resolve();
        $this->assertEqual($copy,$remaining!==null);
      } finally {
        $future()->setParametersForDeleteObject($old)->resolve();
        if ($file->getStorageEngine()==='gorge') {
          list($backend,$key)=explode('/',$file->getStorageHandle(),2);
          id(new PhabricatorGorgeFileStorageClient())->deleteFile($backend,$key);
        }
      }
    }
  }

  public function testRealGorgeMigrationDeletionIntents() {
    $url = getenv('GORGE_TEST_FILE_URL');
    if (!$url) { $this->assertSkipped('Set GORGE_TEST_FILE_URL for migration intents.'); }
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.file.uri', $url);
    $env->overrideEnvConfig('gorge.file.token', getenv('GORGE_TEST_FILE_TOKEN'));
    $env->overrideEnvConfig('gorge.file.deletion-outbox', true);
    $client = new PhabricatorGorgeFileStorageClient();
    foreach (array('engine', 'copy', 'format') as $operation) {
      $file = PhabricatorFile::newFromFileData(Filesystem::readRandomCharacters(64),
        array('name' => 'migration-'.$operation,
          'storageEngines' => array(new PhabricatorGorgeFileStorageEngine())));
      $old = $file->getStorageHandle();
      $stale = clone $file;
      $handles = array($old);
      try {
        if ($operation === 'format') {
          $file->migrateToStorageFormat(new PhabricatorFileRawStorageFormat());
          $handles[] = $file->getStorageHandle();
        } else {
          $file->migrateToEngine(new PhabricatorTestStorageEngine(), $operation === 'copy');
        }
        $file->reload();
        $intent = id(new PhabricatorFileGorgeDeletion())->loadOneWhere('storageHandle = %s', $old);
        if ($operation === 'copy') { $this->assertEqual(null, $intent); }
        else { $this->assertEqual('pending', $intent->getState()); }
        $this->assertFalse($old === $file->getStorageHandle());
        list($backend, $key) = explode('/', $old, 2);
        $this->assertEqual(64, strlen($client->readFile($backend, $key)));
        if ($operation === 'format') {
          $new_handle = $file->getStorageHandle();
          $stale->delete();
          $current_intent = id(new PhabricatorFileGorgeDeletion())
            ->loadOneWhere('storageHandle = %s', $new_handle);
          $this->assertEqual('pending', $current_intent->getState());
          $this->assertEqual(null, id(new PhabricatorFile())->load($file->getID()));
        }
      } finally {
        foreach (array_unique($handles) as $handle) {
          list($backend, $key) = explode('/', $handle, 2);
          $client->deleteFile($backend, $key);
        }
      }
    }
  }

  public function testGorgeConfigurationFailureUsesFallbackPolicy() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig(
      'gorge.service-policies',
      array(
        'file' => 'fallback',
      ));

    PhabricatorGorgeServiceSpec::resetFallbackCounts();

    $gorge_engine = id(new PhabricatorTestStorageEngine())
      ->setEngineIdentifier('gorge')
      ->setFailConfiguration(true);
    $native_engine = new PhabricatorTestStorageEngine();

    $file = PhabricatorFile::newFromFileData(
      'fallback data',
      array(
        'name' => 'fallback.txt',
        'storageEngines' => array(
          $gorge_engine,
          $native_engine,
        ),
      ));

    $this->assertEqual('unit-test', $file->getStorageEngine());
    $this->assertEqual(
      array('file.write' => 1),
      PhabricatorGorgeServiceSpec::getFallbackCounts());

    PhabricatorGorgeServiceSpec::resetFallbackCounts();
    unset($env);
  }

  public function testFileDirectScramble() {
    // Changes to a file's view policy should scramble the file secret.

    $engine = new PhabricatorTestStorageEngine();
    $data = Filesystem::readRandomCharacters(64);

    $author = $this->generateNewTestUser();

    $params = array(
      'name' => 'test.dat',
      'viewPolicy' => PhabricatorPolicies::POLICY_USER,
      'authorPHID' => $author->getPHID(),
      'storageEngines' => array(
        $engine,
      ),
    );

    $file = PhabricatorFile::newFromFileData($data, $params);

    $secret1 = $file->getSecretKey();

    // First, change the name: this should not scramble the secret.
    $xactions = array();
    $xactions[] = id(new PhabricatorFileTransaction())
      ->setTransactionType(PhabricatorFileNameTransaction::TRANSACTIONTYPE)
      ->setNewValue('test.dat2');

    $engine = id(new PhabricatorFileEditor())
      ->setActor($author)
      ->setContentSource($this->newContentSource())
      ->applyTransactions($file, $xactions);

    $file = $file->reload();

    $secret2 = $file->getSecretKey();

    $this->assertEqual(
      $secret1,
      $secret2,
      pht('No secret scramble on non-policy edit.'));

    // Now, change the view policy. This should scramble the secret.
    $xactions = array();
    $xactions[] = id(new PhabricatorFileTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
      ->setNewValue($author->getPHID());

    $engine = id(new PhabricatorFileEditor())
      ->setActor($author)
      ->setContentSource($this->newContentSource())
      ->applyTransactions($file, $xactions);

    $file = $file->reload();
    $secret3 = $file->getSecretKey();

    $this->assertTrue(
      ($secret1 !== $secret3),
      pht('Changing file view policy should scramble secret.'));
  }

  public function testFileIndirectScramble() {
    // When a file is attached to an object like a task and the task view
    // policy changes, the file secret should be scrambled. This invalidates
    // old URIs if tasks get locked down.

    $engine = new PhabricatorTestStorageEngine();
    $data = Filesystem::readRandomCharacters(64);

    $author = $this->generateNewTestUser();

    $params = array(
      'name' => 'test.dat',
      'viewPolicy' => $author->getPHID(),
      'authorPHID' => $author->getPHID(),
      'storageEngines' => array(
        $engine,
      ),
    );

    $file = PhabricatorFile::newFromFileData($data, $params);
    $secret1 = $file->getSecretKey();

    $task = ManiphestTask::initializeNewTask($author);

    $xactions = array();
    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
      ->setNewValue(pht('File Scramble Test Task'));

    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(
        ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE)
      ->setNewValue('{'.$file->getMonogram().'}')
      ->setMetadataValue(
        'remarkup.control',
        array(
          'attachedFilePHIDs' => array(
            $file->getPHID(),
          ),
        ));

    id(new ManiphestTransactionEditor())
      ->setActor($author)
      ->setContentSource($this->newContentSource())
      ->applyTransactions($task, $xactions);

    $file = $file->reload();
    $secret2 = $file->getSecretKey();

    $this->assertEqual(
      $secret1,
      $secret2,
      pht(
        'File policy should not scramble when attached to '.
        'newly created object.'));

    $xactions = array();
    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
      ->setNewValue($author->getPHID());

    id(new ManiphestTransactionEditor())
      ->setActor($author)
      ->setContentSource($this->newContentSource())
      ->applyTransactions($task, $xactions);

    $file = $file->reload();
    $secret3 = $file->getSecretKey();

    $this->assertTrue(
      ($secret1 !== $secret3),
      pht('Changing attached object view policy should scramble secret.'));
  }


  public function testFileVisibility() {
    $engine = new PhabricatorTestStorageEngine();
    $data = Filesystem::readRandomCharacters(64);

    $author = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $users = array($author, $viewer);

    $params = array(
      'name' => 'test.dat',
      'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
      'authorPHID' => $author->getPHID(),
      'storageEngines' => array(
        $engine,
      ),
    );

    $file = PhabricatorFile::newFromFileData($data, $params);
    $filter = new PhabricatorPolicyFilter();

    // Test bare file policies.
    $this->assertEqual(
      array(
        true,
        false,
      ),
      $this->canViewFile($users, $file),
      pht('File Visibility'));

    // Create an object and test object policies.

    $object = ManiphestTask::initializeNewTask($author)
      ->setTitle(pht('File Visibility Test Task'))
      ->setViewPolicy(PhabricatorPolicies::getMostOpenPolicy())
      ->save();

    $this->assertTrue(
      $filter->hasCapability(
        $author,
        $object,
        PhabricatorPolicyCapability::CAN_VIEW),
      pht('Object Visible to Author'));

    $this->assertTrue(
      $filter->hasCapability(
        $viewer,
        $object,
        PhabricatorPolicyCapability::CAN_VIEW),
      pht('Object Visible to Others'));

    // Reference the file in a comment. This should not affect the file
    // policy.

    $file_ref = '{F'.$file->getID().'}';

    $xactions = array();
    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
      ->attachComment(
        id(new ManiphestTransactionComment())
          ->setContent($file_ref));

    id(new ManiphestTransactionEditor())
      ->setActor($author)
      ->setContentSource($this->newContentSource())
      ->applyTransactions($object, $xactions);

    // Test the referenced file's visibility.
    $this->assertEqual(
      array(
        true,
        false,
      ),
      $this->canViewFile($users, $file),
      pht('Referenced File Visibility'));

    // Attach the file to the object and test that the association opens a
    // policy exception for the non-author viewer.

    $xactions = array();
    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
      ->setMetadataValue(
        'remarkup.control',
        array(
          'attachedFilePHIDs' => array(
            $file->getPHID(),
          ),
        ))
      ->attachComment(
        id(new ManiphestTransactionComment())
          ->setContent($file_ref));

    id(new ManiphestTransactionEditor())
      ->setActor($author)
      ->setContentSource($this->newContentSource())
      ->applyTransactions($object, $xactions);

    // Test the attached file's visibility.
    $this->assertEqual(
      array(
        true,
        true,
      ),
      $this->canViewFile($users, $file),
      pht('Attached File Visibility'));

    // Create a "thumbnail" of the original file.
    $params = array(
      'name' => 'test.thumb.dat',
      'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
      'storageEngines' => array(
        $engine,
      ),
    );

    $xform = PhabricatorFile::newFromFileData($data, $params);

    id(new PhabricatorTransformedFile())
      ->setOriginalPHID($file->getPHID())
      ->setTransform('test-thumb')
      ->setTransformedPHID($xform->getPHID())
      ->save();

    // Test the thumbnail's visibility.
    $this->assertEqual(
      array(
        true,
        true,
      ),
      $this->canViewFile($users, $xform),
      pht('Attached Thumbnail Visibility'));
  }

  public function testFileVisibilityManually() {
    $author = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $author_and_viewer = array($author, $viewer);

    $engine = new PhabricatorTestStorageEngine();
    $params = array(
      'name' => 'test.dat',
      'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
      'authorPHID' => $author->getPHID(),
      'storageEngines' => array(
        $engine,
      ),
    );

    $data = Filesystem::readRandomCharacters(64);
    $file = PhabricatorFile::newFromFileData($data, $params);

    // Create an object.
    $object = ManiphestTask::initializeNewTask($author)
      ->setTitle(pht('Test Task'))
      ->setViewPolicy(PhabricatorPolicies::getMostOpenPolicy())
      ->save();

    // Test file's visibility before attachment.
    $this->assertEqual(
      array(
        true,
        false,
      ),
      $this->canViewFile($author_and_viewer, $file),
      pht('File Visibility Before Being Attached'));

    // Manually attach.
    $file->attachToObject($object->getPHID());

    // Test the referenced file's visibility.
    $this->assertEqual(
      array(
        true,
        true,
      ),
      $this->canViewFile($author_and_viewer, $file),
      pht('File Visibility After Being Attached'));

    // Try again. This should not explode.
    $file->attachToObject($object->getPHID());

    // Try again with this low-level. Again, this should not explode.
    PhabricatorFile::attachFileToObject($file->getPHID(), $object->getPHID());

    // Try again but using the wrong low-level usage.
    $is_wrong_usage = false;
    try {
      PhabricatorFile::attachFileToObject($object->getPHID(), $file->getPHID());
    } catch (Throwable $e) {
      $is_wrong_usage = true;
    }
    $this->assertEqual(
      true,
      $is_wrong_usage,
      pht('Check Attach Low-Level Validation'));
  }

  private function canViewFile(array $users, PhabricatorFile $file) {
    $results = array();
    foreach ($users as $user) {
      $results[] = (bool)id(new PhabricatorFileQuery())
        ->setViewer($user)
        ->withPHIDs(array($file->getPHID()))
        ->execute();
    }
    return $results;
  }

  public function testFileStorageReadWrite() {
    $engine = new PhabricatorTestStorageEngine();

    $data = Filesystem::readRandomCharacters(64);

    $params = array(
      'name' => 'test.dat',
      'storageEngines' => array(
        $engine,
      ),
    );

    $file = PhabricatorFile::newFromFileData($data, $params);

    // Test that the storage engine worked, and was the target of the write. We
    // don't actually care what the data is (future changes may compress or
    // encrypt it), just that it exists in the test storage engine.
    $engine->readFile($file->getStorageHandle());

    // Now test that we get the same data back out.
    $this->assertEqual($data, $file->loadFileData());
  }

  public function testFileStorageUploadDifferentFiles() {
    $engine = new PhabricatorTestStorageEngine();

    $data = Filesystem::readRandomCharacters(64);
    $other_data = Filesystem::readRandomCharacters(64);

    $params = array(
      'name' => 'test.dat',
      'storageEngines' => array(
        $engine,
      ),
    );

    $first_file = PhabricatorFile::newFromFileData($data, $params);

    $second_file = PhabricatorFile::newFromFileData($other_data, $params);

    // Test that the second file uses  different storage handle from
    // the first file.
    $first_handle = $first_file->getStorageHandle();
    $second_handle = $second_file->getStorageHandle();

    $this->assertTrue($first_handle != $second_handle);
  }

  public function testFileStorageUploadSameFile() {
    $engine = new PhabricatorTestStorageEngine();

    $data = Filesystem::readRandomCharacters(64);

    $hash = PhabricatorFile::hashFileContent($data);
    if ($hash === null) {
      $this->assertSkipped(pht('File content hashing is not available.'));
    }

    $params = array(
      'name' => 'test.dat',
      'storageEngines' => array(
        $engine,
      ),
    );

    $first_file = PhabricatorFile::newFromFileData($data, $params);

    $second_file = PhabricatorFile::newFromFileData($data, $params);

    // Test that the second file uses the same storage handle as
    // the first file.
    $handle = $first_file->getStorageHandle();
    $second_handle = $second_file->getStorageHandle();

    $this->assertEqual($handle, $second_handle);
  }

  public function testFileStorageDelete() {
    $engine = new PhabricatorTestStorageEngine();

    $data = Filesystem::readRandomCharacters(64);

    $params = array(
      'name' => 'test.dat',
      'storageEngines' => array(
        $engine,
      ),
    );

    $file = PhabricatorFile::newFromFileData($data, $params);
    $handle = $file->getStorageHandle();
    $file->delete();

    $caught = null;
    try {
      $engine->readFile($handle);
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue($caught instanceof Exception);
  }

  public function testFileStorageDeleteSharedHandle() {
    $engine = new PhabricatorTestStorageEngine();

    $data = Filesystem::readRandomCharacters(64);

    $params = array(
      'name' => 'test.dat',
      'storageEngines' => array(
        $engine,
      ),
    );

    $first_file = PhabricatorFile::newFromFileData($data, $params);
    $second_file = PhabricatorFile::newFromFileData($data, $params);
    $first_file->delete();

    $this->assertEqual($data, $second_file->loadFileData());
  }

  public function testReadWriteTtlFiles() {
    $engine = new PhabricatorTestStorageEngine();

    $data = Filesystem::readRandomCharacters(64);

    $ttl = (PhabricatorTime::getNow() + phutil_units('24 hours in seconds'));

    $params = array(
      'name' => 'test.dat',
      'ttl.absolute' => $ttl,
      'storageEngines' => array(
        $engine,
      ),
    );

    $file = PhabricatorFile::newFromFileData($data, $params);
    $this->assertEqual($ttl, $file->getTTL());
  }

  public function testFileTransformDelete() {
    // We want to test that a file deletes all its inbound transformation
    // records and outbound transformed derivatives when it is deleted.

    // First, we create a chain of transforms, A -> B -> C.

    $engine = new PhabricatorTestStorageEngine();

    $params = array(
      'name' => 'test.txt',
      'storageEngines' => array(
        $engine,
      ),
    );

    $a = PhabricatorFile::newFromFileData('a', $params);
    $b = PhabricatorFile::newFromFileData('b', $params);
    $c = PhabricatorFile::newFromFileData('c', $params);

    id(new PhabricatorTransformedFile())
      ->setOriginalPHID($a->getPHID())
      ->setTransform('test:a->b')
      ->setTransformedPHID($b->getPHID())
      ->save();

    id(new PhabricatorTransformedFile())
      ->setOriginalPHID($b->getPHID())
      ->setTransform('test:b->c')
      ->setTransformedPHID($c->getPHID())
      ->save();

    // Now, verify that A -> B and B -> C exist.

    $xform_a = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withTransforms(
        array(
          array(
            'originalPHID' => $a->getPHID(),
            'transform'    => true,
          ),
        ))
      ->execute();

    $this->assertEqual(1, count($xform_a));
    $this->assertEqual($b->getPHID(), head($xform_a)->getPHID());

    $xform_b = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withTransforms(
        array(
          array(
            'originalPHID' => $b->getPHID(),
            'transform'    => true,
          ),
        ))
      ->execute();

    $this->assertEqual(1, count($xform_b));
    $this->assertEqual($c->getPHID(), head($xform_b)->getPHID());

    // Delete "B".

    $b->delete();

    // Now, verify that the A -> B and B -> C links are gone.

    $xform_a = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withTransforms(
        array(
          array(
            'originalPHID' => $a->getPHID(),
            'transform'    => true,
          ),
        ))
      ->execute();

    $this->assertEqual(0, count($xform_a));

    $xform_b = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withTransforms(
        array(
          array(
            'originalPHID' => $b->getPHID(),
            'transform'    => true,
          ),
        ))
      ->execute();

    $this->assertEqual(0, count($xform_b));

    // Also verify that C has been deleted.

    $alternate_c = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($c->getPHID()))
      ->execute();

    $this->assertEqual(array(), $alternate_c);
  }

  public function testNewChunkedFile() {
    $engine = new PhabricatorTestStorageEngine();
    $file = PhabricatorFile::newChunkedFile($engine, 10, []);
    $this->assertTrue($file instanceof PhabricatorFile,
      pht('newChunkedFile returns a PhabricatorFile'));
  }

  public function testGorgeDeletionIntentCommitsWithLastReference() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.file.deletion-outbox', true);
    $engine = id(new PhabricatorTestStorageEngine())->setEngineIdentifier('gorge');
    $data = Filesystem::readRandomCharacters(32);
    $file = PhabricatorFile::newFromFileData($data,
      array('name' => 'outbox-test', 'storageEngines' => array($engine)));
    $copy = PhabricatorFile::newFileFromContentHash($file->getContentHash(),
      array('name' => 'outbox-copy'));
    $handle = $file->getStorageHandle();
    $file->delete();
    $intents = id(new PhabricatorFileGorgeDeletion())->loadAllWhere('storageHandle = %s', $handle);
    $this->assertEqual(0, count($intents));
    $copy->delete();
    $intents = id(new PhabricatorFileGorgeDeletion())->loadAllWhere('storageHandle = %s', $handle);
    $this->assertEqual(1, count($intents));
    $this->assertEqual('pending', head($intents)->getState());
    $this->assertEqual($data, $engine->readFile($handle));
  }

}
