<?php

// Persistent queue acceptance receipts, including after task archival.
final class PhabricatorWorkerGorgeInbox extends PhabricatorWorkerDAO {
  protected $eventID;
  protected $payloadHash;
  protected $response;

  public function getTableName() {
    return 'worker_gorgeinbox';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_IDS => self::IDS_MANUAL,
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => null,
        'eventID' => 'bytes128',
        'payloadHash' => 'text64',
        'response' => 'text',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'PRIMARY' => array('columns' => array('eventID'), 'unique' => true),
      ),
    ) + parent::getConfiguration();
  }
}
