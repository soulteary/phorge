<?php

/** People-owned organization storage. No native policy inheritance is added. */
final class PhorgeOrganizationPrimaryDepartment extends PhorgeRecoveryOrganizationProducerDAO {
  protected $userPHID;
  protected $unitID;
  protected $version;

  public function getTableName() { return 'organization_primarydepartment'; }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'userPHID' => 'phid',
        'unitID' => 'varbytes64?',
        'version' => 'uint64',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_identity' => array('columns' => array('userPHID'), 'unique' => true),
      ),
    ) + parent::getConfiguration();
  }
}
