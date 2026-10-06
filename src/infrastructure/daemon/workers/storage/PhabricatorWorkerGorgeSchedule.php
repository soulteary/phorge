<?php

final class PhabricatorWorkerGorgeSchedule extends PhabricatorWorkerDAO {

  protected $triggerID;
  protected $scheduledVersion;
  protected $retryAfter;
  protected $lastEvaluatedEpoch;

  public function getTableName() {
    return 'worker_gorgeschedule';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_IDS => self::IDS_MANUAL,
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => null,
        'triggerID' => 'id',
        'scheduledVersion' => 'uint32',
        'retryAfter' => 'uint64',
        'lastEvaluatedEpoch' => 'uint64',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'PRIMARY' => array('columns' => array('triggerID'), 'unique' => true),
      ),
    ) + parent::getConfiguration();
  }
}
