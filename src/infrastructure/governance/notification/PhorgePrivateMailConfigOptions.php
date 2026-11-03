<?php
final class PhorgePrivateMailConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName(){return pht('Private Notifications');}
  public function getDescription(){return pht('Authenticated link-only business notifications.');}
  public function getIcon(){return 'fa-envelope';}
  public function getGroup(){return 'apps';}
  public function getApplicationClassName(){return PhabricatorMetaMTAApplication::class;}
  public function getOptions(){return array($this->newOption('governance.private-mail.enabled','bool',false)
    ->setLocked(true)->setSummary(pht('Use authenticated links for private business mail.'))
    ->setDescription(pht('Disabled by default. Requires a paired Gorge per-attempt authorization callback.')));}
}
