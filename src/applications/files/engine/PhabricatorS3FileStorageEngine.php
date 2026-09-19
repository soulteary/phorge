<?php

/**
 * Historical Amazon S3 storage reader.
 *
 * New writes are owned by Gorge. Keep this engine registered so existing
 * `amazon-s3` handles remain readable/deletable until migration is complete.
 */
final class PhabricatorS3FileStorageEngine
  extends PhabricatorFileStorageEngine {

  public function getEngineIdentifier() {
    return 'amazon-s3';
  }

  public function getEnginePriority() {
    return 100;
  }

  public function canWriteFiles() {
    return false;
  }

  public function writeFile($data, array $params) {
    throw new PhabricatorFileStorageConfigurationException(
      pht(
        'The historical Amazon S3 storage engine is read-only. New file '.
        'content must be written through the Gorge file storage engine.'));
  }

  public function readFile($handle) {
    $s3 = $this->newS3API();

    $profiler = PhutilServiceProfiler::getInstance();
    $call_id = $profiler->beginServiceCall(
      array(
        'type' => 's3',
        'method' => 'getObject',
      ));

    $result = $s3
      ->setParametersForGetObject($handle)
      ->resolve();

    $profiler->endServiceCall($call_id, array());

    return $result;
  }

  public function deleteFile($handle) {
    $s3 = $this->newS3API();

    AphrontWriteGuard::willWrite();
    $profiler = PhutilServiceProfiler::getInstance();
    $call_id = $profiler->beginServiceCall(
      array(
        'type' => 's3',
        'method' => 'deleteObject',
      ));

    $s3
      ->setParametersForDeleteObject($handle)
      ->resolve();

    $profiler->endServiceCall($call_id, array());
  }

  private function getBucketName() {
    $bucket = PhabricatorEnv::getEnvConfig('storage.s3.bucket');
    if (!$bucket) {
      throw new PhabricatorFileStorageConfigurationException(
        pht(
          "No '%s' specified!",
          'storage.s3.bucket'));
    }
    return $bucket;
  }

  private function newS3API() {
    $access_key = PhabricatorEnv::getEnvConfig('amazon-s3.access-key');
    $secret_key = PhabricatorEnv::getEnvConfig('amazon-s3.secret-key');
    $region = PhabricatorEnv::getEnvConfig('amazon-s3.region');
    $endpoint = PhabricatorEnv::getEnvConfig('amazon-s3.endpoint');

    return id(new PhutilAWSS3Future())
      ->setAccessKey($access_key)
      ->setSecretKey(new PhutilOpaqueEnvelope($secret_key))
      ->setRegion($region)
      ->setEndpoint($endpoint)
      ->setBucket($this->getBucketName());
  }

}
