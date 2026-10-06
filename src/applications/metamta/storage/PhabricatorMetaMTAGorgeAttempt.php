<?php

final class PhabricatorMetaMTAGorgeAttempt extends PhabricatorMetaMTADAO {
  protected $deliveryID;
  protected $attempt;
  protected $startedEpoch;
  protected $finishedEpoch;
  protected $outcome;
  public function getTableName() { return 'gorge_mail_attempt'; }
  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => 'uint64',
        'deliveryID' => 'bytes128',
        'attempt' => 'uint32',
        'startedEpoch' => 'uint64',
        'finishedEpoch' => 'uint64?',
        'outcome' => 'text32',
      ),
      self::CONFIG_KEY_SCHEMA => array('deliveryAttempt' => array('columns' => array('deliveryID', 'attempt'), 'unique' => true)),
    ) + parent::getConfiguration();
  }
}
