<?php

final class PhorgeGovernanceMediaConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() { return pht('Private Media Exchange'); }
  public function getDescription() { return pht('Session-bound private media exchange.'); }
  public function getIcon() { return 'fa-lock'; }
  public function getGroup() { return 'apps'; }
  public function getApplicationClassName() { return PhabricatorFilesApplication::class; }

  public function getOptions() {
    return array(
      $this->newOption('governance.media-exchange.enabled', 'bool', false)
        ->setLocked(true)
        ->setSummary(pht('Enable the session-bound media exchange backend.'))
        ->setDescription(pht(
          'Disabled by default. This backend does not enable private-file '.
          'guards or public authentication routes.')),
    );
  }
}
