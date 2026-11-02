<?php

/** Non-DAO protected transaction ledgers in the native People database. */
final class PhorgeOrganizationSchemaSpec extends PhabricatorConfigSchemaSpec {
  public function buildSchemata() {
    $this->buildRawSchema('user', 'organization_guard',
      array('id' => 'uint32', 'version' => 'uint64'),
      array('PRIMARY' => array('columns' => array('id'), 'unique' => true)));
    $this->buildRawSchema('user', 'organization_operation', array(
      'operationID' => 'varbytes128', 'actorPHID' => 'phid',
      'requestDigest' => 'varbytes64', 'resultJSON' => 'text', 'createdAt' => 'uint64'),
      array('PRIMARY' => array('columns' => array('operationID'), 'unique' => true)));
    $this->buildRawSchema('user', 'organization_audit', array(
      'id' => 'auto64', 'operationID' => 'varbytes128', 'actorPHID' => 'phid',
      'action' => 'varbytes32', 'unitID' => 'varbytes64', 'subjectPHID' => 'phid?',
      'beforeJSON' => 'text', 'afterJSON' => 'text', 'createdAt' => 'uint64'), array(
      'PRIMARY' => array('columns' => array('id'), 'unique' => true),
      'key_operation' => array('columns' => array('operationID'))));
    $this->buildRawSchema('user', 'organization_approval', array(
      'approvalID' => 'varbytes128', 'operationID' => 'varbytes128',
      'requestDigest' => 'varbytes64', 'bindingJSON' => 'text', 'state' => 'varbytes16',
      'approverPHID' => 'phid?', 'approverVersion' => 'uint64?',
      'approverDigest' => 'varbytes64?', 'approverBindingJSON' => 'text?',
      'approvedAt' => 'uint64?', 'consumedAt' => 'uint64?',
      'consumedByOperation' => 'varbytes128?', 'version' => 'uint64'), array(
      'PRIMARY' => array('columns' => array('approvalID'), 'unique' => true),
      'key_operation' => array('columns' => array('operationID')),
      'key_consumed' => array('columns' => array('consumedByOperation'))));
  }
}
