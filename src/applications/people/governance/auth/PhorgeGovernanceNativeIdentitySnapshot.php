<?php

/** Sealed current-source receipt; not a standalone execution grant. */
final class PhorgeGovernanceNativeIdentitySnapshot extends Phobject {
  private $source;
  private $data;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('governance_identity_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('governance_identity_serialization_refused'); }
  public function __debugInfo() { return array('scope' => 'native-locked-identity'); }
  public static function newFromSource(PhorgeGovernanceNativeIdentitySource $source) {
    $snapshot = new self();
    $snapshot->source = $source;
    $snapshot->data = $source->getSnapshotData();
    return $snapshot;
  }
  public function getCredentialType() { return $this->data['credentialType']; }
  public function getUserPHID() { return $this->data['userPHID']; }
  public function getSessionPHID() { return $this->data['sessionPHID']; }
  public function getCredentialDigest() { return $this->data['credentialDigest']; }
  public function getAccountEpoch() { return $this->data['accountEpoch']; }
  public function getExpiresAt() { return $this->data['expiresAt']; }
  public function isSystemAdmin() { return $this->data['isAdmin']; }
  public function getBindingDigest() { return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR)); }
  public function getSource() { return $this->source; }
}
