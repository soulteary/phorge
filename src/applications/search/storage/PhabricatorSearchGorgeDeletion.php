<?php

final class PhabricatorSearchGorgeDeletion extends PhabricatorSearchDAO {
  protected $namespace;
  protected $objectPHID;
  protected $objectClass;
  protected $sourceVersion;
  protected $completedEpoch;
  protected $nextAttempt = 0;

  public function getTableName() { return 'search_gorgedeletion'; }
  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => 'auto64', 'namespace' => 'bytes64',
        'objectClass' => 'bytes128', 'sourceVersion' => 'bytes512',
        'completedEpoch' => 'uint64?', 'nextAttempt' => 'uint64'),
      self::CONFIG_KEY_SCHEMA => array(
        'object' => array('columns' => array('namespace', 'objectPHID'),
          'unique' => true),
        'pending' => array('columns' => array('completedEpoch', 'nextAttempt', 'id'))),
    ) + parent::getConfiguration();
  }
}
