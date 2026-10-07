<?php

final class PhabricatorWorkerGorgeSchedulerControl
  extends PhabricatorWorkerDAO {

  protected $owner;
  protected $epoch;
  protected $databaseID;

  public function getTableName() {
    return 'worker_gorgeschedulercontrol';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_IDS => self::IDS_MANUAL,
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'owner' => 'varbytes16',
        'epoch' => 'uint64',
        'databaseID' => 'varbytes36',
      ),
    ) + parent::getConfiguration();
  }

  public static function runPHP(callable $callback) {
    $table = new self();
    $table->openTransaction();
    try {
      $control = self::lockOwner();
      if (!$control || !in_array($control['owner'],
          array('php', 'paused', 'gorge'), true)) {
        throw new Exception(pht('Scheduler control is missing or invalid.'));
      }
      $ran = ($control['owner'] === 'php');
      if ($ran) {
        $callback();
      }
      $table->saveTransaction();
      return $ran;
    } catch (Throwable $ex) {
      $table->killTransaction();
      throw $ex;
    }
  }

  public static function lockOwner() {
    $table = new self();
    return queryfx_one(
      $table->establishConnection('w'),
      'SELECT owner, epoch, databaseID FROM %T WHERE id = 1 FOR UPDATE',
      $table->getTableName());
  }
}
