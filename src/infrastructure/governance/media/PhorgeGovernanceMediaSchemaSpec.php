<?php

/** Explicit native schema contracts for the digest-only media ledgers. */
final class PhorgeGovernanceMediaSchemaSpec extends PhabricatorConfigSchemaSpec {
  public function buildSchemata() {
    $this->buildRawSchema('user','governance_mediabrowser',array(
      'issuerIncarnation'=>'varbytes32?','browserDigest'=>'bytes64','generation'=>'uint64','expiresAt'=>'uint64'),
      array('PRIMARY'=>array('columns'=>array('browserDigest'),'unique'=>true)));
    $this->buildRawSchema('user','governance_mediaexchange',array(
      'issuerIncarnation'=>'varbytes32?','transactionDigest'=>'bytes64','browserDigest'=>'bytes64',
      'generation'=>'uint64','expiresAt'=>'uint64','codeDigest'=>'bytes64?',
      'userPHID'=>'phid?','sessionPHID'=>'phid?','credentialDigest'=>'bytes64?',
      'accountEpoch'=>'bytes64?','sessionExpiresAt'=>'uint64?',
      'audience'=>'varbytes32?','purpose'=>'varbytes32?','returnPath'=>'bytes?',
      'usedAt'=>'uint64?'),array(
      'PRIMARY'=>array('columns'=>array('transactionDigest'),'unique'=>true),
      'codeDigest'=>array('columns'=>array('codeDigest'),'unique'=>true),
      'browserDigest'=>array('columns'=>array('browserDigest'))));
    $this->buildRawSchema('user','governance_mediacredential',array(
      'secretDigest'=>'bytes64','issuerIncarnation'=>'varbytes32?','browserDigest'=>'bytes64','generation'=>'uint64',
      'userPHID'=>'phid','sessionPHID'=>'phid','credentialDigest'=>'bytes64',
      'accountEpoch'=>'bytes64','expiresAt'=>'uint64','audience'=>'varbytes32',
      'purpose'=>'varbytes32','revokedAt'=>'uint64?'),array(
      'PRIMARY'=>array('columns'=>array('secretDigest'),'unique'=>true),
      'browserDigest'=>array('columns'=>array('browserDigest'))));
  }
}
