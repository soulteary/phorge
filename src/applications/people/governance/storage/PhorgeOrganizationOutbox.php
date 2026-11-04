<?php

/** Protected People workflow records. Only the locked transfer service may write. */
final class PhorgeOrganizationOutbox extends PhabricatorUserDAO {
  protected $eventID;
  protected $eventType;
  protected $transferID;
  protected $handoverID;
  protected $sourceVersion;
  protected $state;
  protected $attempts;
  protected $createdAt;
  protected $dispatchedAt;

  public function getTableName() { return 'organization_outbox'; }
  public function save() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function insert() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function update() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function replace() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function delete() { throw new RuntimeException('organization_transfer_locked_writer_required'); }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'eventID' => 'varbytes128',
        'eventType' => 'varbytes32',
        'transferID' => 'varbytes64?',
        'handoverID' => 'varbytes64?',
        'sourceVersion' => 'uint64',
        'state' => 'varbytes16',
        'attempts' => 'uint32',
        'createdAt' => 'uint64',
        'dispatchedAt' => 'uint64?',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_transfer' => array('columns' => array('transferID')),
        'key_handover' => array('columns' => array('handoverID')),
        'key_identity' => array('columns' => array('eventID'), 'unique' => true),
        'key_pending' => array('columns' => array('state', 'id')),
      ),
    ) + parent::getConfiguration();
  }
}
