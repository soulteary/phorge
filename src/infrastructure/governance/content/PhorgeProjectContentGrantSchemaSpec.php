<?php

/** Protected native Project metadata; no raw DAO writer or feature activation. */
final class PhorgeProjectContentGrantSchemaSpec extends PhabricatorConfigSchemaSpec {
  public function buildSchemata() {
    $this->buildRawSchema('project','governance_contentgrant',array(
      'grantID'=>'varbytes64','targetPHID'=>'varbytes64','operation'=>'varbytes32',
      'subjectKind'=>'varbytes16','subjectID'=>'varbytes64','validFrom'=>'uint64',
      'validUntil'=>'uint64','revokedAt'=>'uint64?','version'=>'uint64'),array(
      'PRIMARY'=>array('columns'=>array('grantID'),'unique'=>true),
      'key_scope'=>array('columns'=>array('targetPHID','operation','subjectKind','subjectID'))));
    $this->buildRawSchema('project','governance_contentapproval',array(
      'approvalID'=>'varbytes64','targetPHID'=>'varbytes64','bindingJSON'=>'text',
      'bindingDigest'=>'varbytes64','state'=>'varbytes16','approverBindingJSON'=>'text?',
      'appliedAt'=>'uint64?','version'=>'uint64'),array(
      'PRIMARY'=>array('columns'=>array('approvalID'),'unique'=>true),
      'key_target'=>array('columns'=>array('targetPHID'))));
    $this->buildRawSchema('project','governance_contentaudit',array(
      'id'=>'auto64','approvalID'=>'varbytes64','grantID'=>'varbytes64',
      'targetPHID'=>'varbytes64','actorPHID'=>'varbytes64','action'=>'varbytes32',
      'createdAt'=>'uint64'),array('PRIMARY'=>array('columns'=>array('id'),'unique'=>true),
      'key_approval'=>array('columns'=>array('approvalID')),'key_grant'=>array('columns'=>array('grantID'))));
    foreach (array('bindingJSON','approverBindingJSON') as $column) {
      $this->getDatabase('project')->getTable('governance_contentapproval')->getColumn($column)->setColumnType('mediumtext');
    }
  }
}
