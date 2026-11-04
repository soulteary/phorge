<?php

/** Protected People workflow records. Only the locked transfer service may write. */
final class PhorgeOrganizationHandover extends PhabricatorUserDAO {
  protected $handoverID;
  protected $transferID;
  protected $subjectPHID;
  protected $fromDepartmentID;
  protected $toDepartmentID;
  protected $responsiblePHID;
  protected $state;
  protected $startAt;
  protected $deadlineAt;
  protected $timezone;
  protected $startLocal;
  protected $deadlineLocal;
  protected $startOffset;
  protected $deadlineOffset;
  protected $cumulativeDays;
  protected $version;

  public function getTableName() { return 'organization_handover'; }
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
        'transferID' => 'varbytes64',
        'subjectPHID' => 'phid',
        'fromDepartmentID' => 'varbytes64',
        'toDepartmentID' => 'varbytes64',
        'responsiblePHID' => 'phid',
        'state' => 'varbytes16',
        'startAt' => 'uint64',
        'deadlineAt' => 'uint64',
        'timezone' => 'varbytes64',
        'startLocal' => 'varbytes32',
        'deadlineLocal' => 'varbytes32',
        'startOffset' => 'sint32',
        'deadlineOffset' => 'sint32',
        'cumulativeDays' => 'uint32',
        'version' => 'uint64',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_responsible' => array('columns' => array('responsiblePHID')),
        'key_identity' => array('columns' => array('handoverID'), 'unique' => true),
        'key_transfer' => array('columns' => array('transferID'), 'unique' => true),
        'key_due' => array('columns' => array('state', 'deadlineAt', 'id')),
        'key_subject' => array('columns' => array('subjectPHID', 'state')),
      ),
    ) + parent::getConfiguration();
  }
}
