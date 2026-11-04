<?php

/** Protected People workflow records. Only the locked transfer service may write. */
final class PhorgeOrganizationTransfer extends PhabricatorUserDAO {
  protected $transferID;
  protected $operationID;
  protected $subjectPHID;
  protected $responsiblePHID;
  protected $requesterPHID;
  protected $executorPHID;
  protected $action;
  protected $state;
  protected $requestDigest;
  protected $bindingJSON;
  protected $approverPHID;
  protected $approverBindingJSON;
  protected $approvedAt;
  protected $expiresAt;
  protected $consumedAt;
  protected $version;
  protected $createdAt;

  public function getTableName() { return 'organization_transfer'; }
  public function save() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function insert() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function update() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function replace() { throw new RuntimeException('organization_transfer_locked_writer_required'); }
  public function delete() { throw new RuntimeException('organization_transfer_locked_writer_required'); }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'transferID' => 'varbytes64',
        'operationID' => 'varbytes128',
        'subjectPHID' => 'phid',
        'responsiblePHID' => 'phid',
        'requesterPHID' => 'phid',
        'executorPHID' => 'phid',
        'action' => 'varbytes16',
        'state' => 'varbytes16',
        'requestDigest' => 'varbytes64',
        'bindingJSON' => 'text',
        'approverPHID' => 'phid?',
        'approverBindingJSON' => 'text?',
        'approvedAt' => 'uint64?',
        'expiresAt' => 'uint64',
        'consumedAt' => 'uint64?',
        'version' => 'uint64',
        'createdAt' => 'uint64',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_responsible' => array('columns' => array('responsiblePHID')),
        'key_requester' => array('columns' => array('requesterPHID')),
        'key_executor' => array('columns' => array('executorPHID')),
        'key_approver' => array('columns' => array('approverPHID')),
        'key_identity' => array('columns' => array('transferID'), 'unique' => true),
        'key_subject' => array('columns' => array('subjectPHID', 'state')),
        'key_operation' => array('columns' => array('operationID')),
      ),
    ) + parent::getConfiguration();
  }
}
