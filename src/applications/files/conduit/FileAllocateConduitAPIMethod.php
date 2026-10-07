<?php

final class FileAllocateConduitAPIMethod
  extends FileConduitAPIMethod {

  public function getAPIMethodName() {
    return 'file.allocate';
  }

  public function getMethodDescription() {
    return pht('Prepare to upload a file.');
  }

  protected function defineParamTypes() {
    return array(
      'name' => 'string',
      'contentLength' => 'int',
      'contentHash' => 'optional string',
      'viewPolicy' => 'optional string',
      'deleteAfterEpoch' => 'optional int',
    );
  }

  protected function defineReturnType() {
    return 'map<string, wild>';
  }

  protected function execute(ConduitAPIRequest $request) {
    $viewer = $request->getUser();

    $hash = $request->getValue('contentHash');
    $name = $request->getValue('name');
    $view_policy = $request->getValue('viewPolicy');
    $length = $request->getValue('contentLength');

    $properties = array(
      'name' => $name,
      'authorPHID' => $viewer->getPHID(),
      'isExplicitUpload' => true,
    );

    if ($view_policy !== null) {
      $properties['viewPolicy'] = $view_policy;
    }

    $ttl = $request->getValue('deleteAfterEpoch');
    if ($ttl) {
      $properties['ttl.absolute'] = $ttl;
    }

    $file = null;
    if ($hash !== null) {
      $file = PhabricatorFile::newFileFromContentHash(
        $hash,
        $properties);
    }

    if ($hash !== null && !$file) {
      $chunked_hash = PhabricatorChunkedFileStorageEngine::getChunkedHash(
        $viewer,
        $hash);
      $file = id(new PhabricatorFileQuery())
        ->setViewer($viewer)
        ->withContentHashes(array($chunked_hash))
        ->executeOne();
    }

    if (phutil_nonempty_string($name) && !$hash && !$file) {
      if ($length > PhabricatorFileStorageEngine::getChunkThreshold()) {
        // If we don't have a hash, but this file is large enough to store in
        // chunks and thus may be resumable, try to find a partially uploaded
        // file by the same author with the same name and same length. This
        // allows us to resume uploads in Javascript where we can't efficiently
        // compute file hashes.
        $file = id(new PhabricatorFileQuery())
          ->setViewer($viewer)
          ->withAuthorPHIDs(array($viewer->getPHID()))
          ->withNames(array($name))
          ->withLengthBetween($length, $length)
          ->withIsPartial(true)
          ->setLimit(1)
          ->executeOne();
      }
    }

    if ($file && $file->getIsPartial() && $file->getStorageEngine() === 'chunks' &&
        PhabricatorChunkedFileStorageEngine::isGorgeHandle($file->getStorageHandle())) {
      PhabricatorPolicyFilter::requireCapability($viewer, $file,
        PhabricatorPolicyCapability::CAN_EDIT);
      $file = $this->reconcileGorgeUpload($file);
    }

    if ($file) {
      return array(
        'upload' => (bool)$file->getIsPartial(),
        'filePHID' => $file->getPHID(),
      );
    }

    // If there are any non-chunk engines which this file can fit into,
    // just tell the client to upload the file.
    $engines = PhabricatorFileStorageEngine::loadStorageEngines($length);
    if ($engines) {
      return array(
        'upload' => true,
        'filePHID' => null,
      );
    }

    // Otherwise, this is a large file and we want to perform a chunked
    // upload if we have a chunk engine available.
    $chunk_engines = PhabricatorFileStorageEngine::loadWritableChunkEngines();
    if ($chunk_engines) {
      $chunk_properties = $properties;

      if ($hash !== null) {
        $chunk_properties += array(
          'chunkedHash' => $chunked_hash,
        );
      }

      $chunk_engine = head($chunk_engines);
      $file = $chunk_engine->allocateChunks($length, $chunk_properties);

      return array(
        'upload' => true,
        'filePHID' => $file->getPHID(),
      );
    }

    // None of the storage engines can accept this file.
    if (PhabricatorFileStorageEngine::loadWritableEngines()) {
      $error = pht(
        'Unable to upload file: this file is too large for any '.
        'configured storage engine.');
    } else {
      $error = pht(
        'Unable to upload file: the server is not configured with any '.
        'writable storage engines.');
    }

    return array(
      'upload' => false,
      'filePHID' => null,
      'error' => $error,
    );
  }

  private function reconcileGorgeUpload(PhabricatorFile $file) {
    $handle = $file->getStorageHandle();
    $upload = id(new PhabricatorGorgeFileStorageClient())->getUploadForResume(
      PhabricatorChunkedFileStorageEngine::getGorgeUploadID($handle));
    if ($upload !== null && $upload['state'] !== 'complete') { return $file; }
    $file->openTransaction();
    try {
      $row = queryfx_one($file->establishConnection('w'),
        'SELECT * FROM %T WHERE id = %d FOR UPDATE',
        $file->getTableName(), $file->getID());
      if (!$row) {
        $file->saveTransaction();
        return null;
      }
      $file->loadFromArray($row);
      $result = $file;
      if ($file->getIsPartial() && $file->getStorageEngine() === 'chunks' &&
          $file->getStorageHandle() === $handle) {
        if ($upload === null) {
          $file->delete();
          $result = null;
        } else {
          if ($upload['size'] !== (int)$file->getByteSize()) {
            throw new Exception(pht('Gorge upload size does not match the file.'));
          }
          $file->setIsPartial(0);
          $metadata = $file->getMetadata();
          $metadata['gorge.upload.sha256'] = $upload['sha256'];
          $file->setMetadata($metadata);
          $file->setIntegrityHash('gorge-sha256:'.$upload['sha256']);
          $file->save();
        }
      }
      $file->saveTransaction();
      return $result;
    } catch (Throwable $ex) {
      $file->killTransaction();
      throw $ex;
    }
  }

}
