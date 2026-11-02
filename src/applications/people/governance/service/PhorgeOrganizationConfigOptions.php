<?php

final class PhorgeOrganizationConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() { return pht('Organization Directory'); }
  public function getDescription() { return pht('Protected team directory.'); }
  public function getIcon() { return 'fa-users'; }
  public function getGroup() { return 'apps'; }
  public function getApplicationClassName() { return PhabricatorPeopleApplication::class; }

  public function getOptions() {
    return array(
      $this->newOption('governance.organization.enabled', 'bool', false)
        ->setLocked(true)
        ->setSummary(pht('Enable the protected organization directory.'))
        ->setDescription(pht(
          'Disabled by default. Organization membership does not change native '.
          'project policies or grant native object capabilities.')),
    );
  }
}
