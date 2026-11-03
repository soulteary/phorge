<?php
final class PhorgeGovernanceRecoveryConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName(){return pht('Account Recovery Guard');}
  public function getDescription(){return pht('Experimental native account restoration boundary.');}
  public function getIcon(){return 'fa-lock';}
  public function getGroup(){return 'apps';}
  public function getApplicationClassName(){return PhabricatorPeopleApplication::class;}
  public function getOptions(){return array($this->newOption('governance.account-recovery.enabled','bool',false)
    ->setLocked(true)->setSummary(pht('Refuse account restoration without complete source assessment.'))
    ->setDescription(pht('Disabled by default. The initial isolated profile blocks all eligibility increases for existing accounts. It does not implement a restoration approval or enable production recovery.')));}
}
