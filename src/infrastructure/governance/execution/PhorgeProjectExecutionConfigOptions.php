<?php

final class PhorgeProjectExecutionConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName() { return pht('Project Approval Execution'); }
  public function getDescription() { return pht('Experimental native project approval boundary.'); }
  public function getIcon() { return 'fa-lock'; }
  public function getGroup() { return 'apps'; }
  public function getApplicationClassName() { return PhabricatorProjectApplication::class; }
  public function getOptions() {
    return array($this->newOption('governance.project-approval.enabled','bool',false)
      ->setLocked(true)->setSummary(pht('Enable the restricted project approval profile.'))
      ->setDescription(pht('Disabled by default. Only supported native transaction closures may execute. Existing native object and Space capabilities still apply.')));
  }
}
