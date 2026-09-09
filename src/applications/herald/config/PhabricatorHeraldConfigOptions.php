<?php

final class PhabricatorHeraldConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Herald');
  }

  public function getDescription() {
    return pht('Configure Herald.');
  }

  public function getIcon() {
    return 'fa-bullhorn';
  }

  public function getGroup() {
    return 'apps';
  }

  public function getApplicationClassName() {
    return PhabricatorHeraldApplication::class;
  }

  public function getOptions() {
    return array(
      $this->newOption('gorge.webhook.owner', 'enum', 'auto')
        ->setLocked(true)
        ->setEnumOptions(
          array(
            'auto' => pht('Infer From Endpoint (Legacy)'),
            'phorge' => pht('Phorge'),
            'gorge' => pht('Gorge'),
          ))
        ->setSummary(pht('Select the Herald webhook delivery owner.'))
        ->setDescription(
          pht(
            'Select exactly one consumer for Herald webhook requests. The ' .
            'default deployment writes an explicit owner through its ' .
            'read-only deployment configuration. `%s` preserves the older ' .
            'behavior in which a nonempty `%s` implicitly selected Gorge.',
            'auto',
            'gorge.webhook.uri')),
      $this->newOption('gorge.webhook.uri', 'string', null)
        ->setLocked(true)
        ->setSummary(pht('Base URI of the Gorge webhook service.'))
        ->setDescription(
          pht(
            'Base URI of the Gorge webhook service, a standalone service '.
            'which delivers Herald webhook requests in place of the `%s` '.
            'daemon worker.'.
            "\n\n".
            'Delivery ownership is selected independently with `%s`. In ' .
            'legacy `%s` mode, this address also acts as the handover switch. ' .
            'The service does not receive requests from this server '.
            'at all. It polls the `%s` table directly, claims the rows in '.
            '`%s` status and writes the outcome back to them, so selecting '.
            'Gorge as owner stops this server from scheduling `%s` tasks '.
            'and leave those rows alone. Delivery moves whether or not the '.
            'address is reachable, which is why `%s` probes it and reports a '.
            'setup issue when it does not answer.'.
            "\n\n".
            'The handover is not complete while `%s` is enabled. Silent mode '.
            'is configuration of this server and the service can not read it, '.
            'so a silent install keeps delivering through the daemon, which '.
            'fails each request instead of sending it. Set the owner to '.
            '`phorge` before stopping Gorge; in legacy `auto` mode, clearing '.
            'this endpoint performs the same handback. Requests which are '.
            'still in `%s` status are picked up by the daemon from then on.'.
            "\n\n".
            'Do not include a trailing slash: the service routes exactly, '.
            'and a doubled slash produces an "ERR_NOT_FOUND" error instead '.
            'of a result.',
            'HeraldWebhookWorker',
            'gorge.webhook.owner',
            'auto',
            'herald_webhookrequest',
            'queued',
            'HeraldWebhookWorker',
            'PhabricatorGorgeWebhookSetupCheck',
            'phabricator.silent',
            'queued'))
        ->addExample('http://gorge-webhook:8160', pht('Compose service')),
      $this->newOption('gorge.webhook.token', 'string', null)
        ->setHidden(true)
        ->setDescription(
          pht(
            'Service token for the Gorge webhook service, sent with each '.
            'request in an "X-Service-Token" header. Leave this empty if '.
            'the service is configured without a token.'.
            "\n\n".
            'This only covers the diagnostic routes this server reads. '.
            'Delivery itself is not authenticated by it: the service reaches '.
            'the queue through the database, and the signature it puts on an '.
            'outbound payload comes from the HMAC key of the hook.')),
    );
  }

}
