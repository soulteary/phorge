<?php

/** Native MetaMTA storage; cross-application producers use their original writer. */
final class PhorgeGovernanceNoticeSchemaSpec extends PhabricatorConfigSchemaSpec {
  public function buildSchemata(){
    $this->buildRawSchema('metamta','governance_notice_event',array(
      'eventID'=>'bytes64','schemaVersion'=>'uint32','sourceKind'=>'varbytes32','sourceID'=>'uint64',
      'operationDigest'=>'bytes64','actorDigest'=>'bytes64','action'=>'varbytes32','metadataJSON'=>'text',
      'metadataDigest'=>'bytes64','createdAt'=>'uint64','state'=>'varbytes16'),array(
      'PRIMARY'=>array('columns'=>array('eventID'),'unique'=>true),
      'source'=>array('columns'=>array('sourceKind','sourceID','action'),'unique'=>true)));
    $this->buildRawSchema('metamta','governance_notice_delivery',array(
      'eventID'=>'bytes64','recipientPHID'=>'phid','mailPHID'=>'phid?',
      'state'=>'varbytes16','createdAt'=>'uint64','updatedAt'=>'uint64'),array(
      'PRIMARY'=>array('columns'=>array('eventID','recipientPHID'),'unique'=>true),
      'mail'=>array('columns'=>array('mailPHID'),'unique'=>true)));
  }
}
