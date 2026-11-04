<?php

/** Server-captured review facts. This object is not an execution lease. */
final class PhorgeRecoveryRestoreAssessment extends Phobject {
  private $binding;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('recovery_assessment_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('recovery_assessment_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'partial-native-recovery-facts'); }

  public static function capture(PhorgeGovernanceNativeActorContext $context,
    AphrontDatabaseConnection $conn, $subject, $stop) {
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    PhorgeRecoveryStopRequest::user($subject);
    $stop=PhorgeRecoveryStopRequest::transactionID($stop);
    $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
    if (!$actor->isSystemAdmin()) { throw new RuntimeException('recovery_restore_admin_required'); }
    $identity=PhorgeGovernanceNativeNoncredentialIdentitySource::lockIdentityPHID($subject,$conn);
    $user=queryfx_one($conn,'SELECT phid,isDisabled FROM %R WHERE phid=%s LOCK IN SHARE MODE',
      new PhabricatorUser(),$subject);
    if (!$user || $user['phid']!==$subject || !in_array($user['isDisabled'],array(1,'1'),true)) {
      throw new RuntimeException('recovery_restore_subject_not_stopped');
    }
    $classification=queryfx_one($conn,'SELECT nativeTransactionID,subjectPHID,category,eventDigest '.
      'FROM recovery_stop WHERE nativeTransactionID=%s LOCK IN SHARE MODE',$stop);
    $event=queryfx_one($conn,'SELECT id,objectPHID,authorPHID,transactionType,oldValue,newValue FROM %R '.
      'WHERE objectPHID=%s AND transactionType=%s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
      new PhabricatorUserTransaction(),$subject,PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
    if (!$classification || $classification['subjectPHID']!==$subject || !$event ||
        PhorgeRecoveryStopRequest::transactionID($classification['nativeTransactionID'])!==$stop ||
        !in_array($classification['category'],array('technical-pause','offboarding','security'),true) ||
        !is_string($classification['eventDigest']) ||
        !hash_equals($classification['eventDigest'],PhorgeRecoveryStopRequest::event($event,$subject,$stop))) {
      throw new RuntimeException('recovery_restore_stop_generation_unknown');
    }
    $families=array();
    foreach (PhorgeGovernanceRecoverySourceInventory::requiredFamilies() as $family) {
      $families[$family]=array('status'=>'uncovered','complete'=>false);
    }
    $families['account-stop']=array('status'=>'partial','complete'=>false);
    $media=array('family'=>'media-credentials','status'=>'uncovered','complete'=>false,
      'reason'=>'disabled-media-configuration-is-not-an-absence-proof');
    if (PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')===true) {
      $media=PhorgeRecoveryRestoreMediaSource::capture($context,$conn,$subject);
      $families['media-credentials']=array('status'=>'partial','complete'=>false);
    }
    $organization=PhorgeRecoveryRestoreOrganizationSource::capture($conn,$subject);
    $families['organization']=array('status'=>'partial','complete'=>false);
    $credentials=PhorgeRecoveryNativeCaptureScope::captureForRestoration($context,$conn,$subject);
    $families['web-credentials']=array('status'=>'partial','complete'=>false);
    $families['api-credentials']=array('status'=>'partial','complete'=>false);
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();
    $lineage=array('provider_schema'=>1,'configured_incarnation_digest'=>$profile['incarnation_digest'],
      'scope'=>'isolated-current-configuration-binding-only',
      'physical_restore_rehearsal_proved'=>false,'complete'=>false);
    $families['restore-lineage']=array('status'=>'partial','complete'=>false);
    $inventory=PhorgeGovernanceRecoverySourceInventory::inspectRuntime();
    $assessment=new self();
    $assessment->binding=array('schema_version'=>2,'action'=>'native.enable','subject'=>$subject,
      'native_stop_transaction'=>$stop,'category'=>$classification['category'],
      'event_digest'=>$classification['eventDigest'],'identity'=>$identity,
      'runtime_inventory_digest'=>hash('sha256',json_encode($inventory,JSON_THROW_ON_ERROR)),
      'families'=>$families,'media'=>$media,'organization'=>$organization,'credentials'=>$credentials,'restore_lineage'=>$lineage,
      'selected_producer_profile'=>'native-authsession-insert-fence-v1-not-all-producers',
      'native_web_API_disposition'=>'unreviewed-no-new-bulk-revocation-policy',
      'old_media_credential_disposition'=>'must-not-revive',
      'all_sources_complete'=>false,'restoration_authorized'=>false);
    $current=PhorgeGovernanceNativeIdentitySource::revalidate($actor,$conn);
    if (!$current->isSystemAdmin()) { throw new RuntimeException('recovery_restore_admin_required'); }
    return $assessment;
  }

  public function getBinding() { return $this->binding; }
  public function getDigest() { return PhorgeRecoveryRestoreRequest::digest($this->binding); }

  public function requireCompleteForExecution() {
    // This is evaluated from an internally recaptured assessment, never an
    // incoming boolean, a stored approval alone or a caller-supplied family set.
    foreach (PhorgeGovernanceRecoverySourceInventory::requiredFamilies() as $family) {
      if (($this->binding['families'][$family]['complete']??null)!==true) {
        throw new RuntimeException('recovery_restore_sources_incomplete');
      }
    }
    throw new RuntimeException('recovery_restore_execution_provider_unavailable');
  }
}
