<?php

final class PhorgeProjectContentGrantConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName() { return pht('Project Content Grants'); }
  public function getDescription() { return pht('Restricted explicit content grant preparation.'); }
  public function getIcon() { return 'fa-lock'; }
  public function getGroup() { return 'apps'; }
  public function getApplicationClassName() { return PhabricatorProjectApplication::class; }
  public function getOptions() {
    return array($this->newOption('governance.project-content-grants.enabled','bool',false)
      ->setLocked(true)->setSummary(pht('Enable restricted Project description grant preparation.'))
      ->setDescription(pht('Disabled by default. This prepares explicit grants with two independent administrators; it never grants native EDIT or enables content execution.')));
  }
}
