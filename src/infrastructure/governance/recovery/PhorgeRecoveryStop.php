<?php

/** Read-only native DAO; the locked service owns the append-only write path. */
final class PhorgeRecoveryStop extends PhabricatorUserDAO {
  protected $nativeTransactionID;
  protected $subjectPHID;
  protected $actorPHID;
  protected $category;
  protected $reason;
  protected $operationID;
  protected $eventDigest;
  protected $requestDigest;
  protected $createdAt;

  public function getTableName() { return 'recovery_stop'; }
  public function getIDKey() { return 'nativeTransactionID'; }
  public function save() { throw new RuntimeException('recovery_stop_service_required'); }
  public function insert() { throw new RuntimeException('recovery_stop_service_required'); }
  public function update() { throw new RuntimeException('recovery_stop_history_immutable'); }
  public function replace() { throw new RuntimeException('recovery_stop_service_required'); }
  public function delete() { throw new RuntimeException('recovery_stop_history_immutable'); }
  protected function getConfiguration() {
    return array(self::CONFIG_TIMESTAMPS => false, self::CONFIG_IDS => self::IDS_MANUAL,
      self::CONFIG_COLUMN_SCHEMA => array('nativeTransactionID' => 'uint64',
        'subjectPHID' => 'phid', 'actorPHID' => 'phid', 'category' => 'varbytes32',
        'reason' => 'text', 'operationID' => 'varbytes128', 'eventDigest' => 'varbytes64',
        'requestDigest' => 'varbytes64', 'createdAt' => 'uint64'),
      self::CONFIG_KEY_SCHEMA => array(
        'PRIMARY' => array('columns' => array('nativeTransactionID'), 'unique' => true),
        'key_operation' => array('columns' => array('operationID'), 'unique' => true),
        'key_subject' => array('columns' => array('subjectPHID', 'nativeTransactionID')))) +
      parent::getConfiguration();
  }
}
