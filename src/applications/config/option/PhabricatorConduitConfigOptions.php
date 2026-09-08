<?php

final class PhabricatorConduitConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Conduit');
  }

  public function getDescription() {
    return pht('Options relating to the Conduit API.');
  }

  public function getIcon() {
    return 'fa-plug';
  }

  public function getGroup() {
    return 'core';
  }

  public function getApplicationClassName() {
    return PhabricatorConduitApplication::class;
  }

  public function getOptions() {
    return array(
      $this->newOption('gorge.conduit.uri', 'string', null)
        ->setLocked(true)
        ->setSummary(pht('Base URI of the Gorge conduit gateway.'))
        ->setDescription(
          pht(
            'Base URI of the Gorge conduit gateway, a standalone service '.
            'which reverse-proxies the Conduit API: it authenticates and '.
            'rate limits callers and forwards "/api/{method}" to the upstream '.
            'Conduit, collecting authentication, rate limiting and auditing '.
            'behind a single gateway.'.
            "\n\n".
            'Setting this option lets Phorge reach Conduit through the '.
            'gateway via %s. Leave it empty to keep calling Conduit '.
            'directly.'.
            "\n\n".
            'Do not include a trailing slash: the gateway routes exactly, '.
            'and a doubled slash produces a route mismatch instead of a '.
            'forwarded call.',
            'PhabricatorGorgeConduitClient'))
        ->addExample('http://gorge-conduit:8150', pht('Compose service')),
      $this->newOption('gorge.conduit.token', 'string', null)
        ->setHidden(true)
        ->setDescription(
          pht(
            'Service token for the Gorge conduit gateway, sent with each '.
            'request in an "X-Service-Token" header. Leave this empty if '.
            'the gateway is configured without a token.')),
    );
  }

}
