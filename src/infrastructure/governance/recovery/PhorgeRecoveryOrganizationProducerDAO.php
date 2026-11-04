<?php

/** Neutral producer serialization; never an organization authorization grant. */
abstract class PhorgeRecoveryOrganizationProducerDAO extends PhabricatorUserDAO {
  public function update() {
    if(!PhorgeGovernanceRecoveryGuard::enabled()){return parent::update();}
    $scope=PhorgeRecoveryOrganizationProducerFence::begin($this,'UPDATE');
    try{$result=parent::update();$scope->finish();return$result;}
    catch(Throwable$error){$scope->abort($error);}
  }

  protected function insertRecordIntoDatabase($mode) {
    if(!PhorgeGovernanceRecoveryGuard::enabled()){return parent::insertRecordIntoDatabase($mode);}
    $scope=PhorgeRecoveryOrganizationProducerFence::begin($this,$mode);
    try{$result=parent::insertRecordIntoDatabase($mode);$scope->finish();return$result;}
    catch(Throwable$error){$scope->abort($error);}
  }

  public function delete() {
    if(!PhorgeGovernanceRecoveryGuard::enabled()){return parent::delete();}
    $scope=PhorgeRecoveryOrganizationProducerFence::begin($this,'DELETE');
    try{$result=parent::delete();$scope->finish();return$result;}
    catch(Throwable$error){$scope->abort($error);}
  }
}
