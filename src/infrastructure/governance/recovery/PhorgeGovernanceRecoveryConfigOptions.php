<?php
final class PhorgeGovernanceRecoveryConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName(){return pht('Account Recovery Guard');}
  public function getDescription(){return pht('Experimental native account restoration boundary.');}
  public function getIcon(){return 'fa-lock';}
  public function getGroup(){return 'apps';}
  public function getApplicationClassName(){return PhabricatorPeopleApplication::class;}
  public function getOptions(){return array($this->newOption('governance.account-recovery.enabled','bool',false)
    ->setLocked(true)->setSummary(pht('Refuse account restoration without complete source assessment.'))
    ->setDescription(pht('Disabled by default. The initial isolated profile blocks all eligibility increases for existing accounts. It does not implement a restoration approval or enable production recovery.')),
    $this->newOption('governance.account-recovery.owned-rr-scope.enabled','bool',false)
      ->setLocked(true)->setSummary(pht('Isolated owned native RR transaction mechanics.'))
      ->setDescription(pht('Disabled by default. No route, restoration grant or production source lease is installed. Ordinary native writer isolation is unchanged.')),
    $this->newOption('governance.account-recovery.session-lease.enabled','bool',false)
      ->setLocked(true)->setSummary(pht('Isolated selected AuthSession INSERT lease.'))
      ->setDescription(pht('Disabled by default. Same native User writer capture through observed commit or rollback for one producer only; no restoration provider or authorization grant.')),
    $this->newOption('governance.account-recovery.incarnation','string',null)
      ->setLocked(true)->setSummary(pht('External recovery review incarnation.'))
      ->setDescription(pht('Bind isolated restoration reviews to separately supplied recovery configuration. A database-only restore must not restore this value. This option does not enable restoration or certify restore lineage.')));}
}
