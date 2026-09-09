<?php

/**
 * HTTP client for the Gorge webhook delivery service.
 *
 * Gorge is a Go service which takes over Herald webhook delivery: it polls
 * `{namespace}_herald.herald_webhookrequest` for rows in "queued" status,
 * claims them, signs the payload with HMAC-SHA256 and POSTs it, then writes
 * the outcome back into the same row. Nothing about the request is handed to
 * it over HTTP -- the table is the queue -- so this client does not deliver
 * anything. It exists for two reasons only: to answer "is delivery delegated
 * to the service?" for the guards which stop the PHP daemon from delivering
 * the same rows, and to read the two diagnostic routes the service exposes so
 * that @{class:PhabricatorGorgeWebhookSetupCheck} can say something useful on
 * the config page.
 *
 * That is a deliberate departure from the other Gorge clients, which are the
 * only path to the functionality they front. Here the functionality happens
 * whether or not anything ever constructs this class, which makes the
 * "configured" predicate below far more load-bearing than the request methods
 * are.
 *
 * Request building and envelope parsing live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share.
 */
final class PhabricatorGorgeWebhookClient
  extends PhabricatorGorgeServiceClient {

  const PATH_STATS = '/api/webhook/stats';
  const PATH_HOOKS = '/api/webhook/hooks';

  public function __construct() {
    $service = PhabricatorGorgeServiceRegistry::getService('webhook');
    $uri = $service->getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge webhook '.
          'service, but it is not set.',
          'gorge.webhook.uri'));
    }

    $this->setURI($uri);
    $this->setToken($service->getConfiguredToken());
  }

  protected static function getServiceName() {
    return pht('Gorge webhook service');
  }

  protected function getDefaultTimeout() {
    // Shorter than the mailer's 30 seconds and the file storage client's 30:
    // both routes behind this client are counting queries against one table
    // and neither is on a path which does real work. The only caller is a
    // setup check, which renders a config page, so waiting is worse here than
    // failing.
    return 10;
  }


/* -(  Configuration  )------------------------------------------------------ */


  /**
   * Read the configured base URI of the service.
   *
   * @return string|null Base URI with any trailing slash removed, or null if
   *   the service is not configured.
   */
  public static function getConfiguredURI() {
    return PhabricatorGorgeServiceRegistry::getService('webhook')
      ->getConfiguredURI();
  }

  public static function isConfigured() {
    return (self::getConfiguredURI() !== null);
  }


  /**
   * Decide whether webhook delivery belongs to the service rather than to us.
   *
   * This is the predicate behind both halves of the guard which keeps a
   * delivery from happening twice: @{method:HeraldWebhookRequest::queueCall}
   * declines to schedule a task when it holds, and
   * @{method:HeraldWebhookWorker::doWork} returns without calling out when it
   * holds. Both sites ask this method rather than reading the option, so that
   * the queue path and the "bin/webhook call" path can not drift apart.
   *
   * The second clause is the surprising one, and it is not an optimization:
   * `phabricator.silent` is a configuration option of this server, and the
   * service never reads this server's configuration. It only sees the
   * per-request "silent" flag which the editor recorded in the row's
   * properties, which describes one transaction and not the install. So an
   * install which is in silent mode and delegates delivery would have its
   * webhooks delivered anyway, which is precisely the thing silent mode
   * exists to prevent.
   *
   * Keeping silent installs on the PHP path fixes that without the service
   * having to learn anything: the worker fails those requests with
   * `ERROR_SILENT` and leaves them in "failed" status, and the service only
   * ever claims rows in "queued" status, so it does not touch them. Silent
   * mode therefore behaves exactly as it did before the service existed.
   *
   * @return bool True if the service owns delivery.
   */
  public static function isDeliveryDelegated() {
    $service = PhabricatorGorgeServiceRegistry::getService('webhook');
    if (!$service->isOwnedBy('gorge')) {
      return false;
    }

    if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      return false;
    }

    return true;
  }


/* -(  Diagnostics  )-------------------------------------------------------- */


  /**
   * Read the service's view of the delivery queue.
   *
   * @return wild The "data" section of the envelope, with counts of queued,
   *   sent and failed requests.
   */
  public function getStats() {
    $uri = $this->getURI().self::PATH_STATS;

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }


  /**
   * Read the service's view of the configured hooks.
   *
   * @return wild The "data" section of the envelope, with a count of the
   *   hooks the service can see.
   */
  public function getHooks() {
    $uri = $this->getURI().self::PATH_HOOKS;

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }

}
