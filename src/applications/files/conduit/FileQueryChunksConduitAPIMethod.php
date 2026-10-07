<?php

final class FileQueryChunksConduitAPIMethod
  extends FileConduitAPIMethod {

  public function getAPIMethodName() {
    return 'file.querychunks';
  }

  public function getMethodDescription() {
    return pht('Get information about file chunks.');
  }

  public function isReadOnlyAPI() {
    return true;
  }

  protected function defineParamTypes() {
    return array(
      'filePHID' => 'phid',
    );
  }

  protected function defineReturnType() {
    return 'list<wild>';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR-BAD-PHID' => pht('Must pass a PHID.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $viewer = $request->getUser();

    $file_phid = $request->getValue('filePHID');
    if (!$file_phid) {
      throw new ConduitException('ERR-BAD-PHID');
    }
    $file = $this->loadFileByPHID($viewer, $file_phid);
    if (PhabricatorChunkedFileStorageEngine::isGorgeHandle($file->getStorageHandle()) &&
        $file->getStorageEngine() === 'chunks') {
      $upload = id(new PhabricatorGorgeFileStorageClient())->getUpload(
        PhabricatorChunkedFileStorageEngine::getGorgeUploadID($file->getStorageHandle()));
      if ($upload['size'] !== (int)$file->getByteSize()) {
        throw new Exception(pht('Gorge upload size does not match the file.'));
      }
      $results = array();
      foreach ($upload['chunks'] as $chunk) {
        $results[] = array_select_keys($chunk, array('byteStart', 'byteEnd', 'complete'));
      }
      return $results;
    }
    $chunks = $this->loadFileChunks($viewer, $file);

    $results = array();
    foreach ($chunks as $chunk) {
      $results[] = array(
        'byteStart' => $chunk->getByteStart(),
        'byteEnd' => $chunk->getByteEnd(),
        'complete' => (bool)$chunk->getDataFilePHID(),
      );
    }

    return $results;
  }

}
