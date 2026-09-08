<?php

final class PhabricatorGorgeDBSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  public function getExecutionOrder() {
    // Run alongside the other database checks, but this one only probes the
    // Gorge service over HTTP and does not open management connections, so it
    // is cheap.
    return 500;
  }

  protected function executeChecks() {
    $uri = PhabricatorGorgeDBClient::getConfiguredURI();

    // The first stage is implicit: if the service is not configured, the
    // database console reads status with direct SQL as it always has, so there
    // is nothing to report.
    if ($uri === null) {
      return;
    }

    if (!$this->checkReachable($uri)) {
      return;
    }

    $this->checkReady($uri);
  }


  /**
   * Probe the service and report it if it does not answer.
   *
   * Once the service is configured, the database console reads server health,
   * schema diffs and setup issues from it. A service which does not answer
   * makes the "Database Servers" and schema pages fail to load, so this is the
   * check which matters most of the two.
   *
   * @param string $uri Base URI of the service.
   * @return bool True if the service answered.
   */
  private function checkReachable($uri) {
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
      'The Gorge database service is configured, but does not respond to a '.
      'health check.');

    $message = pht(
      'This software is configured to front the database cluster with the '.
      'Gorge database service at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Setting %s routes the database console (server health, schema diffs '.
      'and setup issues) through the service instead of opening management '.
      'connections to each host, so while the service is down those pages '.
      'can not load their data.'.
      "\n\n".
      'Check that the service is running and that %s names a host this '.
      'server can reach. To hand the console back to direct SQL in the '.
      'meantime, clear %s and the native path resumes.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.db.uri'),
      phutil_tag('tt', array(), 'gorge.db.uri'),
      phutil_tag('tt', array(), 'gorge.db.uri'));

    $this->newIssue('gorge.db.unreachable')
      ->setName(pht('Gorge Database Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.db.uri');

    return false;
  }


  /**
   * Report a service which is running but can not reach the database.
   *
   * The service connects to this install's MySQL cluster, and it is
   * configured with its own copy of the connection parameters. Getting those
   * wrong produces a process which starts, answers its health check and
   * reports healthy to the orchestration while being unable to reach the
   * database. Readiness is the one signal which separates that from a working
   * install.
   *
   * @param string $uri Base URI of the service.
   * @return bool True if the service reported itself ready.
   */
  private function checkReady($uri) {
    $ready_uri = $uri.'/readyz';

    $future = id(new HTTPSFuture($ready_uri))
      ->setTimeout(5);

    list($status, $body) = $future->resolve();

    $reason = null;
    if ($status instanceof HTTPFutureHTTPResponseStatus) {
      if ($status->getStatusCode() == 200) {
        return true;
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
      'This server reaches the Gorge database service, but the service '.
      'reports that it is not ready to serve the database console.');

    $message = pht(
      'This server can reach the Gorge database service at %s, but %s '.
      'reports that it is not ready:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Readiness here means the service can connect to the master database. '.
      'It is configured with its own connection settings (%s, %s, %s, %s and '.
      '%s), and %s must name the same prefix as %s does on this side, because '.
      'the service selects the %s database on connect.'.
      "\n\n".
      'While the service is in this state, the database console can not load '.
      'its data, exactly as if the service were down.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $ready_uri),
      phutil_tag('pre', array(), $reason),
      phutil_tag('tt', array(), 'GORGE_DB_MYSQL_HOST'),
      phutil_tag('tt', array(), 'GORGE_DB_MYSQL_PORT'),
      phutil_tag('tt', array(), 'GORGE_DB_MYSQL_USER'),
      phutil_tag('tt', array(), 'GORGE_DB_MYSQL_PASS'),
      phutil_tag('tt', array(), 'GORGE_DB_NAMESPACE'),
      phutil_tag('tt', array(), 'GORGE_DB_NAMESPACE'),
      phutil_tag('tt', array(), 'storage.default-namespace'),
      phutil_tag('tt', array(), '{namespace}_meta_data'));

    $this->newIssue('gorge.db.notready')
      ->setName(pht('Gorge Database Service Not Ready'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.db.uri')
      ->addRelatedPhabricatorConfig('storage.default-namespace');

    return false;
  }

}
