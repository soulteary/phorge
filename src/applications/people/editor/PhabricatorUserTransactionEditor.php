<?php

final class PhabricatorUserTransactionEditor
  extends PhabricatorApplicationTransactionEditor {

  private $noticeAdminGrantContext;

  public function assertNoticeAdminGrantActive(PhabricatorLiskDAO $object,PhabricatorApplicationTransaction $xaction,$phase='final'){
    if($this->noticeAdminGrantContext!==array($phase,spl_object_id($object),spl_object_id($xaction))){
      throw new RuntimeException('notice_native_admin_phase_ended');}
  }

  public function getEditorApplicationClass() {
    return PhabricatorPeopleApplication::class;
  }

  public function getEditorObjectsDescription() {
    return pht('Users');
  }

  protected function didApplyInternalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {
    $xactions=parent::didApplyInternalEffects($object,$xactions);
    // User grant internal effects only mutate the in-memory role. Check the
    // actual engines/MDL before User or USER transaction persistence occurs.
    foreach($xactions as$xaction){
      if($xaction->getTransactionType()===PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE&&
        $xaction->getOldValue()===false&&$xaction->getNewValue()===true){
        if($this->noticeAdminGrantContext!==null){throw new RuntimeException('notice_native_admin_phase_reentered');}
        $this->noticeAdminGrantContext=array('preflight',spl_object_id($object),spl_object_id($xaction));
        try{PhorgeGovernanceNoticeOutbox::preflightNativeAdminGrant($this,$object,$xaction);}
        finally{$this->noticeAdminGrantContext=null;}
      }
    }
    return$xactions;
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {
    $xactions = parent::applyFinalEffects($object, $xactions);
    // Native validation, internal effects and USER transaction persistence have
    // completed, but the original User writer transaction is still open.
    // Demotion/no-effect must not acquire a new notice-storage dependency.
    foreach ($xactions as $xaction) {
      if ($xaction->getTransactionType() === PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE &&
          $xaction->getOldValue() === false && $xaction->getNewValue() === true) {
        if($this->noticeAdminGrantContext!==null){throw new RuntimeException('notice_native_admin_phase_reentered');}
        $this->noticeAdminGrantContext=array('final',spl_object_id($object),spl_object_id($xaction));
        try{PhorgeGovernanceNoticeOutbox::nativeAdminGrant($this, $object, $xaction);}
        finally{$this->noticeAdminGrantContext=null;}
      }
    }
    return $xactions;
  }

  protected function shouldPublishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    return array();
  }

  protected function getMailCC(PhabricatorLiskDAO $object) {
    return array();
  }

}
