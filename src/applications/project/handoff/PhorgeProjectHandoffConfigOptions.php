<?php

final class PhorgeProjectHandoffConfigOptions extends PhabricatorApplicationConfigOptions {
  public function getName() { return pht('Project Permission Handoff'); }
  public function getDescription() { return pht('Explicit, reviewed project policy handoff.'); }
  public function getGroup() { return 'apps'; }
  public function getApplicationClassName() { return PhabricatorProjectApplication::class; }
  public function getOptions() {
    return array($this->newOption('projects.permission-handoff.enabled','bool',false)
      ->setLocked(true)->setDescription(pht(
        'Allow current system administrators who can edit a project to explicitly hand its view and edit policies to an eligible user. Native membership and ancestor policies remain unchanged.')));
  }
}
