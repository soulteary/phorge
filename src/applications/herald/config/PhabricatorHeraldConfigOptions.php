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
      $this->newOption('gorge.webhook.uri', 'string', null)
        ->setLocked(true)
        ->setSummary(pht('Base URI of the Gorge webhook service.'))
        ->setDescription(
          pht(
            'Base URI of the Gorge webhook service, a standalone service '.
            'which delivers Herald webhook requests in place of the `%s` '.
            'daemon worker.'.
            "\n\n".
            'Unlike the other `%s` options, this one is not the address of a '.
            'service which is called to do a piece of work: it is a handover '.
            'switch. The service does not receive requests from this server '.
            'at all. It polls the `%s` table directly, claims the rows in '.
            '`%s` status and writes the outcome back to them, so setting this '.
            'option is how this server is told to stop scheduling `%s` tasks '.
            'and leave those rows alone. Delivery moves whether or not the '.
            'address is reachable, which is why `%s` probes it and reports a '.
            'setup issue when it does not answer.'.
            "\n\n".
            'The handover is not complete while `%s` is enabled. Silent mode '.
            'is configuration of this server and the service can not read it, '.
            'so a silent install keeps delivering through the daemon, which '.
            'fails each request instead of sending it. Clearing this option '.
            'moves delivery back the same way, and requests which are still '.
            'in `%s` status are picked up by the daemon from then on.'.
            "\n\n".
            'Do not include a trailing slash: the service routes exactly, '.
            'and a doubled slash produces an "ERR_NOT_FOUND" error instead '.
            'of a result.',
            'HeraldWebhookWorker',
            'gorge',
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
