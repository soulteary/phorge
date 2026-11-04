<?php

/** Native schema declarations for the isolated, non-executable review ledger. */
final class PhorgeRecoveryRestoreSchemaSpec extends PhabricatorConfigSchemaSpec {
  public function buildSchemata() {
    $this->buildRawSchema('user', 'recovery_restore_request', array(
      'requestID'=>'varbytes64','operationID'=>'varbytes128','subjectPHID'=>'phid',
      'nativeTransactionID'=>'uint64','requesterPHID'=>'phid',
      'requesterBindingJSON'=>'text','approverPHID'=>'phid?',
      'approverBindingJSON'=>'text?','reviewState'=>'varbytes32','permitState'=>'varbytes32',
      'requestDigest'=>'varbytes64','bindingDigest'=>'varbytes64','bindingJSON'=>'text',
      'createdAt'=>'uint64','expiresAt'=>'uint64','reviewedAt'=>'uint64?','version'=>'uint64'), array(
      'PRIMARY'=>array('columns'=>array('requestID'),'unique'=>true),
      'key_operation'=>array('columns'=>array('operationID'),'unique'=>true),
      'key_subject'=>array('columns'=>array('subjectPHID','nativeTransactionID'))));
    $this->buildRawSchema('user', 'recovery_restore_operation', array(
      'operationID'=>'varbytes128','requestID'=>'varbytes64','action'=>'varbytes32',
      'actorPHID'=>'phid','requestDigest'=>'varbytes64','resultJSON'=>'text','createdAt'=>'uint64'), array(
      'PRIMARY'=>array('columns'=>array('operationID'),'unique'=>true),
      'key_request'=>array('columns'=>array('requestID'))));
    $this->buildRawSchema('user', 'recovery_restore_audit', array(
      'id'=>'auto64','requestID'=>'varbytes64','operationID'=>'varbytes128',
      'actorPHID'=>'phid','action'=>'varbytes32','bindingDigest'=>'varbytes64','createdAt'=>'uint64'), array(
      'PRIMARY'=>array('columns'=>array('id'),'unique'=>true),
      'key_request'=>array('columns'=>array('requestID','id')),
      'key_operation'=>array('columns'=>array('operationID'),'unique'=>true)));
  }
}
