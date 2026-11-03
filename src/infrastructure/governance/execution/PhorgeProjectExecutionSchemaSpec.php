<?php

/** Native schema accounting for protected, non-DAO execution ledgers. */
final class PhorgeProjectExecutionSchemaSpec extends PhabricatorConfigSchemaSpec {
  public function buildSchemata() {
    $this->buildRawSchema('project', 'governance_executionguard',
      array('id' => 'uint32', 'version' => 'uint64'),
      array('PRIMARY' => array('columns' => array('id'), 'unique' => true)));
    $this->buildRawSchema('project', 'governance_projectapproval', array(
      'approvalID' => 'varbytes64', 'operationID' => 'varbytes64',
      'targetPHID' => 'varbytes64', 'bindingJSON' => 'text',
      'bindingDigest' => 'varbytes64', 'reason' => 'varbytes512',
      'state' => 'varbytes16', 'approverBindingJSON' => 'text?',
      'approvedAt' => 'uint64?', 'consumedAt' => 'uint64?',
      'nativeGroupID' => 'varbytes64?', 'version' => 'uint64'), array(
      'PRIMARY' => array('columns' => array('approvalID'), 'unique' => true),
      'key_target' => array('columns' => array('targetPHID')),
      'key_operation' => array('columns' => array('operationID'), 'unique' => true)));
    $this->buildRawSchema('project', 'governance_executionaudit', array(
      'id' => 'auto64', 'approvalID' => 'varbytes64',
      'operationID' => 'varbytes64', 'targetPHID' => 'varbytes64',
      'actorPHID' => 'varbytes64', 'action' => 'varbytes32',
      'dataJSON' => 'text', 'createdAt' => 'uint64'), array(
      'PRIMARY' => array('columns' => array('id'), 'unique' => true),
      'key_approval' => array('columns' => array('approvalID')),
      'key_operation' => array('columns' => array('operationID'))));
    // These protected ledgers use migration-defined types outside the native
    // shorthand vocabulary. Describe the actual types without altering them.
    $database = $this->getDatabase('project');
    foreach (array(
      'governance_projectapproval' => array('bindingJSON', 'approverBindingJSON'),
      'governance_executionaudit' => array('dataJSON')) as $table => $columns) {
      foreach ($columns as $column) {
        $database->getTable($table)->getColumn($column)
          ->setColumnType('mediumtext');
      }
    }
    $database->getTable('governance_projectapproval')->getColumn('reason')
      ->setColumnType('varbinary(2048)');
  }
}
