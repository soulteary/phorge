<?php

final class PhorgeTaskContentGrantConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName() { return pht('Task Content Grants'); }
  public function getDescription() { return pht('Restricted explicit content grant preparation.'); }
  public function getIcon() { return 'fa-lock'; }
  public function getGroup() { return 'apps'; }
  public function getApplicationClassName() { return PhabricatorManiphestApplication::class; }
  public function getOptions() {
    return array($this->newOption('governance.task-content-grants.enabled','bool',false)
      ->setLocked(true)->setSummary(pht('Enable restricted Task description grant preparation.'))
      ->setDescription(pht('Disabled by default. This prepares explicit grants with two independent administrators; it never grants native EDIT or enables content execution.')));
  }
}
