<?php

// Mail preparation events share the mail record transaction.
final class PhabricatorMetaMTAGorgeOutbox extends PhabricatorMetaMTADAO {
  protected $eventID;
  protected $payload;
  protected $attempts = 0;
  protected $nextAttempt = 0;
  protected $deliveredEpoch;
  protected $queueTaskID;
  protected $lastError = '';

  public function getTableName() {
    return 'metamta_gorgeoutbox';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => 'auto64',
        'eventID' => 'bytes128',
        'payload' => 'text',
        'attempts' => 'uint32',
        'nextAttempt' => 'uint64',
        'deliveredEpoch' => 'uint64?',
        'queueTaskID' => 'uint64?',
        'lastError' => 'text',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'eventID' => array('columns' => array('eventID'), 'unique' => true),
        'pending' => array('columns' => array('deliveredEpoch', 'nextAttempt', 'id')),
      ),
    ) + parent::getConfiguration();
  }
}
