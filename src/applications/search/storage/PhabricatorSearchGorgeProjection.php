<?php

final class PhabricatorSearchGorgeProjection extends PhabricatorSearchDAO {
  protected $namespace;
  protected $objectPHID;
  protected $revision = 0;
  protected $serializerVersion = '';
  protected $sourceVersion = '';
  protected $payloadHash = '';
  protected $operation = '';
  protected $lastEventID = '';

  public function getTableName() { return 'search_gorgeprojection'; }
  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'id' => 'auto64', 'namespace' => 'varbytes64', 'revision' => 'sint64',
        'serializerVersion' => 'bytes128', 'sourceVersion' => 'varbytes512',
        'payloadHash' => 'varbytes64', 'operation' => 'varbytes16',
        'lastEventID' => 'bytes128'),
      self::CONFIG_KEY_SCHEMA => array(
        'object' => array('columns' => array('namespace', 'objectPHID'), 'unique' => true)),
    ) + parent::getConfiguration();
  }
}
