<?php

final class PhabricatorMetaMTAGorgeDelivery extends PhabricatorMetaMTADAO {
  protected $deliveryID;
  protected $payloadHash;
  protected $payload;
  protected $state;
  protected $revision;
  protected $attempt;
  protected $nextAttempt;
  protected $startedEpoch;
  protected $result;
  protected $projectionPending = 0;
  protected $deadline = 0;
  protected $projectionNextAttempt = 0;
  protected $projectionAttempts = 0;
  protected $projectionLastError = '';
  public function getTableName() { return 'gorge_mail_delivery'; }
  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_IDS => self::IDS_MANUAL,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => null,
        'deliveryID' => 'bytes128',
        'payloadHash' => 'text64',
        'payload' => 'text',
        'state' => 'text32',
        'revision' => 'uint32',
        'attempt' => 'uint32',
        'nextAttempt' => 'uint64',
        'startedEpoch' => 'uint64',
        'result' => 'text',
        'projectionPending' => 'bool',
        'deadline' => 'uint64',
        'projectionNextAttempt' => 'uint64',
        'projectionAttempts' => 'uint32',
        'projectionLastError' => 'text255',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'projectionPending' => array('columns' => array('projectionPending', 'projectionNextAttempt', 'deliveryID')),
        'staleSubmission' => array('columns' => array('state', 'startedEpoch', 'deliveryID')),
        'expiredDelivery' => array('columns' => array('state', 'deadline', 'deliveryID')),
        'PRIMARY' => array('columns' => array('deliveryID'), 'unique' => true)),
    ) + parent::getConfiguration();
  }
}
