<?php

/** Physical byte deletion; business references remain owned by Phorge. */
final class PhabricatorFileGorgeDeletion extends PhabricatorFileDAO {
  protected $eventID;
  protected $storageEngine;
  protected $storageHandle;
  protected $state = 'pending';
  protected $attempts = 0;
  protected $nextAttempt = 0;
  protected $lastError = '';

  public function getTableName() {
    return 'file_gorgedeletion';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => 'auto64',
        'eventID' => 'bytes128',
        'storageEngine' => 'text32',
        'storageHandle' => 'text255',
        'state' => 'text16',
        'attempts' => 'uint32',
        'nextAttempt' => 'uint64',
        'lastError' => 'text',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'eventID' => array('columns' => array('eventID'), 'unique' => true),
        'pending' => array('columns' => array('state', 'nextAttempt', 'id')),
      ),
    ) + parent::getConfiguration();
  }

  public static function shouldQueue($engine, $handle) {
    return PhabricatorEnv::getEnvConfig('gorge.file.deletion-outbox') &&
      ($engine === 'gorge' || ($engine === 'chunks' &&
        PhabricatorChunkedFileStorageEngine::isGorgeHandle($handle)));
  }

  public static function queueUnused(PhabricatorFile $file) {
    $conn = $file->establishConnection('w');
    $used = queryfx_one($conn,
      'SELECT id FROM %T WHERE storageEngine = %s AND storageHandle = %s LIMIT 1 FOR UPDATE',
      $file->getTableName(), $file->getStorageEngine(), $file->getStorageHandle());
    if (!$used) {
      $event = 'file-delete/'.$file->getPHID().'/'.substr(hash('sha256',
        $file->getStorageEngine().'/'.$file->getStorageHandle()), 0, 32);
      queryfx($conn,
        'INSERT INTO %T (eventID, storageEngine, storageHandle, state, attempts, nextAttempt, lastError) VALUES (%s, %s, %s, %s, 0, 0, %s) ON DUPLICATE KEY UPDATE eventID = eventID',
        id(new self())->getTableName(), $event, $file->getStorageEngine(),
        $file->getStorageHandle(), 'pending', '');
    }
  }
}
