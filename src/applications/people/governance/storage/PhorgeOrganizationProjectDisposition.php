<?php

/** Protected People workflow records. Only the locked transfer service may write. */
final class PhorgeOrganizationProjectDisposition extends PhabricatorUserDAO {
  protected $handoverID;
  protected $projectPHID;
  protected $decision;
  protected $manualStatus;
  protected $manualActorPHID;
  protected $manualAt;
  protected $observedDigest;
  protected $note;
  protected $version;

  public function getTableName() { return 'organization_projectdisposition'; }
  public function save() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function insert() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function update() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function replace() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function delete() { throw new RuntimeException('organization_transfer_locked_writer_required'); }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'handoverID' => 'varbytes64',
        'projectPHID' => 'phid',
        'decision' => 'varbytes16',
        'manualStatus' => 'varbytes16',
        'manualActorPHID' => 'phid?',
        'manualAt' => 'uint64?',
        'observedDigest' => 'varbytes64?',
        'note' => 'text',
        'version' => 'uint64',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_identity' => array('columns' => array('handoverID', 'projectPHID'), 'unique' => true),
      ),
    ) + parent::getConfiguration();
  }
}
