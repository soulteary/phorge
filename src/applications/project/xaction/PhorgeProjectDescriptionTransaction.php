<?php

/** Dedicated native modular transaction. Ordinary callers have no sealed scope. */
final class PhorgeProjectDescriptionTransaction extends PhabricatorProjectTransactionType {
  const TRANSACTIONTYPE='governance:project-description';
  private function storageTransaction() {
    return(new ReflectionProperty(PhabricatorModularTransactionType::class,'storage'))->getValue($this);
  }
  private function field($object) {
    return PhorgeProjectDescriptionExecutor::field($this->getEditor(),$object,$this->storageTransaction());
  }
  public function generateOldValue($object){return$this->field($object)->getOldValueForApplicationTransactions();}
  public function generateNewValue($object,$value){return$this->field($object)->getNewValueFromApplicationTransactions($this->storageTransaction());}
  public function getRequiredCapabilities($object,PhabricatorApplicationTransaction $xaction) {
    PhorgeProjectDescriptionExecutor::requireScope($this->getEditor(),$object,$xaction);
    return PhabricatorPolicyCapability::CAN_VIEW;
  }
  public function validateTransactions($object,array $xactions) {
    if(!$xactions){return array();} // Native editor validates every registered type.
    if(count($xactions)!==1){throw new RuntimeException('description_validation_closure_refused');}
    $f=PhorgeProjectDescriptionExecutor::field($this->getEditor(),$object,head($xactions));
    return$f->validateApplicationTransactions($this->getEditor(),PhabricatorTransactions::TYPE_CUSTOMFIELD,$xactions);
  }
  public function applyInternalEffects($object,$value){return$this->field($object)->applyApplicationTransactionInternalEffects($this->storageTransaction());}
  public function applyExternalEffects($object,$value){return$this->field($object)->applyApplicationTransactionExternalEffects($this->storageTransaction());}
  public function newRemarkupChanges() {
    // The native built-in description customfield returns one legacy block,
    // whose conversion deliberately has null old value. Preserve that behavior.
    return array($this->newRemarkupChange()->setOldValue(null)->setNewValue($this->getNewValue()));
  }
  public function hasChangeDetailView(){return true;}
  public function newChangeDetailView() {
    return(new PhabricatorApplicationTransactionTextDiffDetailView())->setViewer($this->getViewer())
      ->setOldText($this->getOldValue())->setNewText($this->getNewValue());
  }
  public function getMailDiffSectionHeader(){return pht('CHANGES TO PROJECT DESCRIPTION');}
  public function getTitle(){return pht('%s updated the project description.',$this->renderAuthor());}
  public function getTitleForFeed(){return pht('%s updated the description of %s.',$this->renderAuthor(),$this->renderObject());}
}
