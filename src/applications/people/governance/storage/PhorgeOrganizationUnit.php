<?php

/** People-owned organization storage. No native policy inheritance is added. */
final class PhorgeOrganizationUnit extends PhabricatorUserDAO {
  protected $unitID;
  protected $kind;
  protected $state;
  protected $name;
  protected $parentID;
  protected $version;
  protected $visibilityModel;
  protected $visibilityVersion;
  protected $associatedDepartmentID;
  protected $contactUserPHID;

  public function getTableName() { return 'organization_unit'; }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'unitID' => 'varbytes64',
        'kind' => 'varbytes16',
        'state' => 'varbytes16',
        'name' => 'text255',
        'parentID' => 'varbytes64?',
        'version' => 'uint64',
        'visibilityModel' => 'varbytes32',
        'visibilityVersion' => 'uint64',
        'associatedDepartmentID' => 'varbytes64?',
        'contactUserPHID' => 'phid?',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_identity' => array('columns' => array('unitID'), 'unique' => true),
      ),
    ) + parent::getConfiguration();
  }
}
