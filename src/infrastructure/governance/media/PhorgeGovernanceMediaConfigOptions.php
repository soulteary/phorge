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
      $this->newOption('governance.media-exchange.issuer-key', 'string', null)
        ->setLocked(true)->setHidden(true)
        ->setSummary(pht('External media issuer key.'))
        ->setDescription(pht('A separate random media-only key held outside database backups. Native session and API credentials are not rotated by this setting.')),
      $this->newOption('governance.media-exchange.incarnation', 'string', null)
        ->setLocked(true)
        ->setSummary(pht('External media issuer incarnation.'))
        ->setDescription(pht('A new media-only incarnation is required after media-state recovery. Never restore it from the business database backup.')),
      $this->newOption('governance.media-exchange.enabled', 'bool', false)
        ->setLocked(true)
        ->setSummary(pht('Enable the session-bound media exchange backend.'))
        ->setDescription(pht(
          'Disabled by default. This backend does not enable private-file '.
          'guards or public authentication routes.')),
    );
  }
}
