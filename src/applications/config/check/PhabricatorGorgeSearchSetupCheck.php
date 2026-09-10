<?php

final class PhabricatorGorgeSearchSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    if (PhabricatorGorgeServiceRegistry::getService('search')
        ->isDisabled()) {
      return;
    }

    foreach ($this->getGorgeSearchURIs() as $host_key => $uri) {
      // Liveness and readiness are two separate failures with two separate
      // fixes, and a service which does not answer at all can not report
      // whether it is ready, so only ever raise one of them per host.
      if (!$this->checkReachable($host_key, $uri)) {
        continue;
      }

      $this->checkReady($host_key, $uri);
    }
  }


  /**
   * Find the endpoint of every search host which routes through the service.
   *
   * There is no global option to read: this integration is configured one
   * `cluster.search` entry at a time, and an entry may list several hosts, so
   * each host is checked separately.
   *
   * The configuration is read here rather than through
   * @{class:PhabricatorSearchService} because that class throws on a
   * configuration it does not understand, and a malformed entry must not turn
   * the config page -- the page which would report that the entry is
   * malformed -- into a stack trace. The cost is that the URI is assembled
   * twice, here and in @{class:PhabricatorGorgeSearchHost}; the defaults are
   * shared to keep the two from drifting.
   *
   * @return map<string, string> Host key to base URI, trailing slash removed.
   */
  private function getGorgeSearchURIs() {
    $services = PhabricatorEnv::getEnvConfig('cluster.search');
    if (!is_array($services)) {
      return array();
    }

    $type = PhabricatorGorgeFulltextStorageEngine::ENGINE_TYPE;
    $default_port = PhabricatorGorgeSearchHost::DEFAULT_PORT;

    $results = array();
    foreach ($services as $index => $spec) {
      if (!is_array($spec)) {
        continue;
      }

      if (idx($spec, 'type') !== $type) {
        continue;
      }

      // An entry may either list its hosts or describe a single host with the
      // same keys at the top level; PhabricatorSearchService accepts both and
      // merges the entry over each host, so read it the same way.
      $hosts = idx($spec, 'hosts');
      if (!is_array($hosts) || !$hosts) {
        $hosts = array($spec);
      }

      foreach ($hosts as $host_index => $host_spec) {
        if (!is_array($host_spec)) {
          continue;
        }

        // Read the entry first and the host second. That looks backwards, but
        // it is what PhabricatorSearchService::newHost() does -- it merges
        // with "$entry + $host", and the left operand of a PHP array union
        // wins -- so a key given at both levels resolves to the entry's
        // value. Reading it the other way around here would probe a URI the
        // engine would never use.
        $host = idx($spec, 'host', idx($host_spec, 'host'));
        if (!phutil_nonempty_string($host)) {
          continue;
        }

        $protocol = idx($spec, 'protocol', idx($host_spec, 'protocol', 'http'));
        $port = idx($spec, 'port', idx($host_spec, 'port', $default_port));
        $path = idx($spec, 'path', idx($host_spec, 'path', ''));

        $uri = $protocol.'://'.$host.':'.$port;
        if (phutil_nonempty_string($path)) {
          $uri = $uri.'/'.trim($path, '/');
        }

        $results[$index.'.'.$host_index] = rtrim($uri, '/');
      }
    }

    return $results;
  }


  /**
   * Probe the service and report it if it does not answer.
   *
   * @param string $host_key Key of the host being checked.
   * @param string $uri Base URI of the service.
   * @return bool True if the service answered.
   */
  private function checkReachable($host_key, $uri) {
    // The probe routes are the ones which are neither authenticated nor
    // wrapped in a response envelope, so a bare 200 is all we look for.
    $health_uri = $uri.'/healthz';

    // A host which does not resolve can take longer than this to fail; see the
    // note in PhabricatorGorgeServiceClient::newRequestFuture() for why that
    // can not be bounded any tighter here.
    $future = id(new HTTPSFuture($health_uri))
      ->setTimeout(5);

    try {
      $future->resolvex();
      return true;
    } catch (Exception $ex) {
      $error = $ex->getMessage();
    }

    $summary = pht(
      'Fulltext search is routed to the Gorge search service, but the '.
      'service at "%s" does not respond to a health check.',
      $uri);

    $message = pht(
      'This software is configured to run fulltext search through the Gorge '.
      'search service at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Until the service responds, every search fails and every document '.
      'which is edited fails to reindex, so results go stale as well as '.
      'missing. Check that the service is running and that the %s entry '.
      'which names this host names one this server can reach.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'cluster.search'));

    $this->newIssue('gorge.search.unreachable.'.$host_key)
      ->setName(pht('Gorge Search Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addPhabricatorConfig('cluster.search');

    return false;
  }


  /**
   * Report a service which is running but has no search backend configured.
   *
   * This is the state which is easiest to reach and hardest to diagnose: the
   * container starts, its health check passes, Compose reports it healthy, and
   * every search still fails, because the service was never given an
   * Elasticsearch or Meilisearch host. Readiness is the one signal which
   * separates that from a working install, so it gets its own issue rather
   * than being folded into the reachability check above.
   *
   * @param string $host_key Key of the host being checked.
   * @param string $uri Base URI of the service.
   * @return void
   */
  private function checkReady($host_key, $uri) {
    $ready_uri = $uri.'/readyz';

    $future = id(new HTTPSFuture($ready_uri))
      ->setTimeout(5);

    list($status, $body) = $future->resolve();

    $reason = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      $code = $status->getStatusCode();

      if ($code == 200) {
        return;
      }

      // Every build of the service serves this route, so a 404 is not the
      // signature of a build too old to have it: the service registers the
      // route unconditionally, and the standalone service which came before
      // it served the route as an alias of its health check, answering 200. A
      // 404 is a request which did not reach a search service instead, which
      // is a different failure with a different fix, so report it as one.
      if ($code == 404) {
        $this->raiseRouteIssue($host_key, $uri, $ready_uri, $body);
        return;
      }

      // The service answers 503 with {"status": "unavailable", "reason": ...},
      // and the reason names the misconfiguration, so surface it rather than
      // the status code.
      try {
        $response = phutil_json_decode($body);
        $reason = idx($response, 'reason');
      } catch (PhutilJSONParserException $ex) {
        // Continue: fall back to the raw body below.
      }

      if (!phutil_nonempty_string($reason)) {
        $reason = (string)$body;
      }
    } else if ($status instanceof Exception) {
      // Reaching here means /healthz answered a moment ago but /readyz did
      // not, so the service is going down, or is slow enough that the probe
      // timed out. Either way it can not be assumed ready.
      $reason = $status->getMessage();
    }

    if (!phutil_nonempty_string($reason)) {
      $reason = pht('(The service did not say why.)');
    }

    $summary = pht(
      'This server reaches the Gorge search service at "%s", but the service '.
      'reports that it is not ready to answer searches.',
      $uri);

    $message = pht(
      'This server can reach the Gorge search service at %s, but %s reports '.
      'that it is not ready:'.
      "\n\n".
      '%s'.
      "\n\n".
      'The service is ready once at least one readable search backend is '.
      'configured on its own side, through its environment (%s, or %s and '.
      'the %s or %s variables) or its configuration file. Until then it '.
      'accepts requests and fails every one of them, so searches return an '.
      'error and reindexing work stays in the queue.'.
      "\n\n".
      'Note that the service deliberately does not dial its backends to '.
      'answer this probe: it reports ready when a backend is configured, not '.
      'when that backend is up. A misconfigured Elasticsearch host still '.
      'reads as ready here and fails at query time instead.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $ready_uri),
      phutil_tag('pre', array(), $reason),
      phutil_tag('tt', array(), 'GORGE_SEARCH_BACKENDS'),
      phutil_tag('tt', array(), 'GORGE_SEARCH_ENGINE'),
      phutil_tag('tt', array(), 'ES_*'),
      phutil_tag('tt', array(), 'MEILI_*'));

    $this->newIssue('gorge.search.notready.'.$host_key)
      ->setName(pht('Gorge Search Service Not Ready'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addPhabricatorConfig('cluster.search');
  }


  /**
   * Report a URI which answers a health check but has no readiness route.
   *
   * Every build of the service serves the readiness route, so a 404 there
   * describes the configuration rather than the service: either the request
   * did not reach the service, or whatever answered it is not a search
   * service. That makes a 404 the clearest signal available that the `path`
   * of the entry does not match the prefix the proxy in front of the service
   * expects, which is otherwise a silent misconfiguration.
   *
   * A wrong `path` usually trips the reachability check above first, since
   * `/healthz` is misrouted along with everything else. Reaching here means
   * the health check alone is routed -- a proxy is often configured with an
   * exact-match rule for it -- or that whatever answered it is some other
   * service.
   *
   * @param string $host_key Key of the host being checked.
   * @param string $uri Base URI of the service.
   * @param string $ready_uri Readiness URI which answered 404.
   * @param string $body Body of the 404 response.
   * @return void
   */
  private function raiseRouteIssue($host_key, $uri, $ready_uri, $body) {
    $reason = (string)$body;
    if (!phutil_nonempty_string(trim($reason))) {
      $reason = pht('(The response had no body.)');
    }

    $summary = pht(
      'This server reaches a service at "%s", but that service does not '.
      'serve the readiness route of the Gorge search service.',
      $uri);

    $message = pht(
      'This server can reach a service at %s, but a request to %s answered '.
      '%s instead of reporting readiness:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Every build of the search service serves this route, so a %s does '.
      'not mean the service is too old to answer it: the request reached '.
      'something which is not the search service, or did not reach it at '.
      'all. Check the %s key of the %s entry which names this host, since a '.
      'prefix which does not match the one the proxy in front of the '.
      'service expects delivers requests to a route which does not exist; '.
      'then check that %s and %s name a search service rather than another '.
      'service or a proxy which answers health checks of its own.'.
      "\n\n".
      'The search routes are reached the same way as this one, so they are '.
      'likely misrouted as well, which fails every search and every attempt '.
      'to reindex an edited document. Readiness is unknown until this is '.
      'fixed, so a service with no search backend configured can be hiding '.
      'behind it.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $ready_uri),
      phutil_tag('tt', array(), '404'),
      phutil_tag('pre', array(), $reason),
      phutil_tag('tt', array(), '404'),
      phutil_tag('tt', array(), 'path'),
      phutil_tag('tt', array(), 'cluster.search'),
      phutil_tag('tt', array(), 'host'),
      phutil_tag('tt', array(), 'port'));

    $this->newIssue('gorge.search.misrouted.'.$host_key)
      ->setName(pht('Gorge Search Service Misrouted'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addPhabricatorConfig('cluster.search');
  }

}
