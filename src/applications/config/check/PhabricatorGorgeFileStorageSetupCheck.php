<?php

final class PhabricatorGorgeFileStorageSetupCheck
  extends PhabricatorSetupCheck {

  const ENGINE_CLASS = 'PhabricatorGorgeFileStorageEngine';

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    if (PhabricatorGorgeServiceRegistry::getService('file')
        ->isDisabled()) {
      return;
    }

    $uri = PhabricatorGorgeFileStorageClient::getConfiguredURI();

    if ($uri === null) {
      return;
    }

    // Deploying the service, giving it a backend, and routing file writes to
    // it are three separate steps, and each has its own issue below. Only
    // ever raise one of them: they are stages of a single unfinished setup,
    // in the order the documentation performs them, so reporting all three at
    // once would put three banners on the config page for one problem. They
    // are also ordered by severity -- a service which does not answer is a
    // fault, a service with no backend is an unfinished deployment, and an
    // engine which is not selected is an unfinished switchover.
    if (!$this->checkReachable($uri)) {
      return;
    }

    if (!$this->checkReady($uri)) {
      return;
    }

    $this->checkEnabled($uri);
  }


  /**
   * Probe the service and report it if it does not answer.
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
      'The Gorge file storage service is configured, but does not respond to '.
      'a health check.');

    $message = pht(
      'This software is configured to store file data with the Gorge file '.
      'storage service at %s, but a request to %s did not succeed:'.
      "\n\n".
      '%s'.
      "\n\n".
      'Until the service responds, every upload routed to it fails and every '.
      'file already stored there can not be read, which shows up as broken '.
      'images and failed downloads rather than as an error on this page. '.
      'Check that the service is running and that %s names a host this '.
      'server can reach.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $health_uri),
      phutil_tag('pre', array(), $error),
      phutil_tag('tt', array(), 'gorge.file.uri'));

    $this->newIssue('gorge.file.unreachable')
      ->setName(pht('Gorge File Storage Service Unreachable'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.file.uri');

    return false;
  }


  /**
   * Report a service which is running but has no storage backend configured.
   *
   * This is the state which is easiest to reach and hardest to diagnose: the
   * container starts, its health check passes, Compose reports it healthy, and
   * every write still fails, because the service was never given a database,
   * a disk path or a bucket. Readiness is the one signal which separates that
   * from a working install, so it gets its own issue rather than being folded
   * into the reachability check above.
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
      'This server reaches the Gorge file storage service, but the service '.
      'reports that it is not ready to store files.');

    $message = pht(
      'This server can reach the Gorge file storage service at %s, but %s '.
      'reports that it is not ready:'.
      "\n\n".
      '%s'.
      "\n\n".
      'The service is ready once at least one storage backend is configured '.
      'on its own side, through its environment (%s, %s, or the %s and %s '.
      'variables) or its configuration file. Until then it accepts requests '.
      'and fails every one of them.'.
      "\n\n".
      'Note that the container health check probes %s rather than %s, so a '.
      'service in this state still reports as healthy to the orchestration.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), $ready_uri),
      phutil_tag('pre', array(), $reason),
      phutil_tag('tt', array(), 'GORGE_FILE_MYSQL_HOST'),
      phutil_tag('tt', array(), 'GORGE_FILE_LOCAL_DISK_PATH'),
      phutil_tag('tt', array(), 'GORGE_FILE_S3_BUCKET'),
      phutil_tag('tt', array(), 'GORGE_FILE_S3_*'),
      phutil_tag('tt', array(), '/healthz'),
      phutil_tag('tt', array(), '/readyz'));

    $this->newIssue('gorge.file.notready')
      ->setName(pht('Gorge File Storage Service Not Ready'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addRelatedPhabricatorConfig('gorge.file.uri');

    return false;
  }


  /**
   * Report a service which is deployed and ready but outranked.
   *
   * Setting `gorge.file.uri` does not route file writes to the service by
   * itself. The engine is discovered automatically and joins the list of
   * writable engines, but Phorge's own MySQL engine has a lower priority
   * number and so is offered every file first; the service only receives the
   * files that engine refuses, which with default configuration means files
   * between 1MB and 8MB.
   *
   * Without this check that is a silent outcome rather than a wrong one: no
   * error appears anywhere, and new files simply scatter across two engines
   * by size. It is worth a banner because the intent of setting the option is
   * unambiguous, and because the fix -- turning the other engines off -- is
   * not discoverable from anything else on the config page.
   *
   * Note that this only concerns new writes. The engine which stored a file
   * is recorded on the file, so files already written elsewhere keep being
   * read by whichever engine wrote them and no migration is required in
   * either direction.
   *
   * @param string $uri Base URI of the service.
   * @return void
   */
  private function checkEnabled($uri) {
    // Ask the same question the writer asks: loadWritableEngines() returns
    // the production engines which can accept a direct write, sorted by
    // priority, and loadStorageEngines() then offers a file to them in that
    // order. Anything ahead of this engine in that list gets every file it is
    // willing to accept before this engine is asked.
    $engines = PhabricatorFileStorageEngine::loadWritableEngines();

    $ahead = array();
    $found = false;
    foreach ($engines as $engine) {
      if ($engine instanceof PhabricatorGorgeFileStorageEngine) {
        $found = true;
        break;
      }

      $ahead[] = $engine->getEngineIdentifier();
    }

    // Bail if the engine is not in the list at all. That should not happen
    // here, since the option is set and being writable is all the engine asks
    // for, but reporting "everything is ahead of it" would be a misleading
    // way to describe an engine which was somehow excluded.
    if (!$found || !$ahead) {
      return;
    }

    $summary = pht(
      'The Gorge file storage service is deployed and ready, but other '.
      'storage engines still take precedence over it, so new files are '.
      'split between them by size.');

    $message = pht(
      'This server can reach the Gorge file storage service at %s and the '.
      'service is ready to store files, but these storage engines are '.
      'offered every new file before it is: %s.'.
      "\n\n".
      'Engines are selected by priority, and the Gorge engine has a higher '.
      'priority number than those, so it only receives the files they '.
      'refuse. With default configuration the MySQL engine takes everything '.
      'up to %s and the Gorge engine takes the rest up to %s, which means '.
      'new files scatter across both rather than anything failing. Setting '.
      '%s makes the service available; it does not route writes to it.'.
      "\n\n".
      'To send all new files to the service, turn the other engines off by '.
      'setting %s to %s and clearing %s and %s. Files which are already '.
      'stored elsewhere are unaffected: the engine which wrote a file is '.
      'recorded on the file and keeps serving it, so this is an incremental '.
      'switch and not a migration, and reversing it is the same two steps in '.
      'reverse.'.
      "\n\n".
      'Files larger than %s are stored in chunks whichever engine wins, and '.
      'the chunks themselves are stored through these same engines, so this '.
      'setting governs them as well.',
      phutil_tag('tt', array(), $uri),
      phutil_tag('tt', array(), implode(', ', $ahead)),
      phutil_format_bytes(
        PhabricatorEnv::getEnvConfig('storage.mysql-engine.max-size')),
      phutil_format_bytes(
        id(new PhabricatorGorgeFileStorageEngine())->getFilesizeLimit()),
      phutil_tag('tt', array(), 'gorge.file.uri'),
      phutil_tag('tt', array(), 'storage.mysql-engine.max-size'),
      phutil_tag('tt', array(), '0'),
      phutil_tag('tt', array(), 'storage.local-disk.path'),
      phutil_tag('tt', array(), 'storage.s3.bucket'),
      phutil_format_bytes(
        id(new PhabricatorChunkedFileStorageEngine())->getChunkSize()));

    $this->newIssue('gorge.file.disabled')
      ->setName(pht('Gorge File Storage Service Not In Use'))
      ->setSummary($summary)
      ->setMessage($message)
      ->addCommand(
        hsprintf(
          '<samp>%s $</samp><kbd>./bin/config set '.
          'storage.mysql-engine.max-size 0</kbd>',
          PlatformSymbols::getPlatformServerPath()))
      ->addRelatedPhabricatorConfig('storage.mysql-engine.max-size')
      ->addRelatedPhabricatorConfig('storage.local-disk.path')
      ->addRelatedPhabricatorConfig('storage.s3.bucket')
      ->addRelatedPhabricatorConfig('gorge.file.uri');
  }

}
