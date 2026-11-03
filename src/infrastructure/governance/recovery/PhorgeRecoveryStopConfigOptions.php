<?php

final class PhorgeRecoveryStopConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName() { return pht('Account Stop Audit'); }
  public function getDescription() { return pht('Administrator classification of committed account stops.'); }
  public function getIcon() { return 'fa-lock'; }
  public function getGroup() { return 'apps'; }
  public function getApplicationClassName() { return PhabricatorPeopleApplication::class; }
  public function getOptions() {
    return array($this->newOption('governance.recovery-stop-ledger.enabled', 'bool', false)
      ->setLocked(true)
      ->setSummary(pht('Record classifications of previously committed account stops.'))
      ->setDescription(pht('Disabled by default. Recording a stop never enables an account or authorizes recovery.')));
  }
}
