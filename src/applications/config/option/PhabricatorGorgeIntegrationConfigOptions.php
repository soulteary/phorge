<?php

final class PhabricatorGorgeIntegrationConfigOptions
  extends PhabricatorApplicationConfigOptions {
  public function getGroup() { return 'core'; }
  public function getName() { return pht('Gorge Integrations'); }
  public function getDescription() { return pht('Opt-in inbound, SMS, connector and fact execution.'); }
  public function getOptions() {
    return array(
      $this->newOption('gorge.integrations', 'wild', array())
        ->setLocked(true)
        ->setSummary(pht('Authenticated Gorge integrations endpoint and enabled domains.'))
        ->setDescription(pht(
          'Use uri/token, inbound provider names, sms adapter-key to target-key '.
          'mapping, connectors target mappings, and fact boolean. Disabled '.
          'domains retain the legacy path. Tokens must match the Gorge service.')),
    );
  }
}
