<?php

/** Separate readiness switch: an existing mail flag never enables new producers. */
final class PhorgeGovernanceNoticeConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName(){return pht('Governance Notifications');}
  public function getDescription(){return pht('Private notices for committed governance records.');}
  public function getIcon(){return 'fa-bell';}
  public function getGroup(){return 'apps';}
  public function getApplicationClassName(){return PhabricatorPeopleApplication::class;}
  public function getOptions(){return array(
    $this->newOption('governance.notifications.enabled','bool',false)->setLocked(true)
      ->setSummary(pht('Enable committed governance notices.'))
      ->setDescription(pht('Disabled by default. Notices do not grant organization, project or recovery authority.')));}
}
