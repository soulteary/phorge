<?php

/** Always-on native configuration generation; not an authorization grant. */
final class PhorgeProjectConfigEpoch extends PhabricatorConfigEntryDAO {
  protected $namespace;
  protected $configKey;
  protected $epoch;
  public function save() { throw new RuntimeException('configuration_generation_internal_writer_required'); }
  public function insert() { throw new RuntimeException('configuration_generation_internal_writer_required'); }
  public function update() { throw new RuntimeException('configuration_generation_internal_writer_required'); }
  public function replace() { throw new RuntimeException('configuration_generation_internal_writer_required'); }
  public function delete() { throw new RuntimeException('configuration_generation_internal_writer_required'); }
  public function getTableName() { return 'governance_configepoch'; }
  public function getIDKey() { return 'configKey'; }
  protected function getConfiguration() {
    return array(self::CONFIG_TIMESTAMPS=>false,self::CONFIG_IDS=>self::IDS_MANUAL,
      self::CONFIG_COLUMN_SCHEMA=>array('namespace'=>'varbytes64','configKey'=>'varbytes64','epoch'=>'uint64'),
      self::CONFIG_KEY_SCHEMA=>array('PRIMARY'=>array('columns'=>array('namespace','configKey'),'unique'=>true)))+parent::getConfiguration();
  }
}
