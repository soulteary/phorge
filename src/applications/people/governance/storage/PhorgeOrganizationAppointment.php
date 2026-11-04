<?php

/** People-owned organization storage. No native policy inheritance is added. */
final class PhorgeOrganizationAppointment extends PhorgeRecoveryOrganizationProducerDAO {
  protected $unitID;
  protected $userPHID;
  protected $role;
  protected $state;
  protected $validFrom;
  protected $validUntil;
  protected $version;

  public function getTableName() { return 'organization_appointment'; }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'unitID' => 'varbytes64',
        'userPHID' => 'phid',
        'role' => 'varbytes16',
        'state' => 'varbytes16',
        'validFrom' => 'uint64',
        'validUntil' => 'uint64?',
        'version' => 'uint64',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_identity' => array('columns' => array('unitID', 'userPHID'), 'unique' => true),
      ),
    ) + parent::getConfiguration();
  }
}
