<?php

final class PhorgeRecoveryStopSchemaSpec extends PhabricatorConfigSchemaSpec {
  public function buildSchemata() {
    // The native DAO defines recovery_stop; only the non-DAO audit is raw.
    $this->buildRawSchema('user', 'recovery_stop_audit', array(
      'nativeTransactionID' => 'uint64', 'operationID' => 'varbytes128',
      'actorPHID' => 'phid', 'requestDigest' => 'varbytes64',
      'eventDigest' => 'varbytes64', 'createdAt' => 'uint64'), array(
      'PRIMARY' => array('columns' => array('nativeTransactionID'), 'unique' => true),
      'key_operation' => array('columns' => array('operationID'), 'unique' => true)));
  }
}
