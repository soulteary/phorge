<?php

/** The exceptional self-lockout permission exists only in a sealed dispatch. */
final class PhorgeProjectPermissionHandoffTransaction extends PhabricatorProjectTransactionType {
  const TRANSACTIONTYPE='project:permission-handoff';
  public function generateOldValue($object) {
    return array('view'=>$object->getViewPolicy(),'edit'=>$object->getEditPolicy());
  }
  public function validateTransactions($object,array $xactions) {
    if(!$xactions){return array();}
    if(count($xactions)!==1){throw new RuntimeException('handoff_single_transaction_required');}
    PhorgeProjectHandoffService::validateTransaction($this->getEditor(),$object,head($xactions));
    return array();
  }
  public function applyInternalEffects($object,$value) {
    PhorgeProjectHandoffService::applyValue($this->getEditor(),$object,$value);
  }
  public function shouldTryMFA($object,PhabricatorApplicationTransaction $xaction) {
    // Conduit uses its current native API token, like ordinary project edits;
    // an interactive challenge can only be completed by a real Web request.
    return $this->getEditor()->getRequest()!==null;
  }
  public function getTitle() {
    return pht('%s handed the project view and edit policies to %s.',
      $this->renderAuthor(),$this->renderHandle($this->getNewValue()['recipientPHID']));
  }
  public function getIcon() { return 'fa-exchange'; }
}
