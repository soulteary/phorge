<?php

final class HeraldWebhookCallManagementWorkflow
  extends HeraldWebhookManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('call')
      ->setExamples('**call** --id __id__ [--object __object__]')
      ->setSynopsis(pht('Call a webhook.'))
      ->setArguments(
        array(
          array(
            'name' => 'id',
            'param' => 'id',
            'help' => pht('Webhook ID to call'),
          ),
          array(
            'name' => 'object',
            'param' => 'object',
            'help' => pht('Submit transactions for a particular object.'),
          ),
          array(
            'name' => 'silent',
            'help' => pht('Set the "silent" flag on the request.'),
          ),
          array(
            'name' => 'secure',
            'help' => pht('Set the "secure" flag on the request.'),
          ),
          array(
            'name' => 'count',
            'param' => 'N',
            'help' => pht('Make a total of __N__ copies of the call.'),
          ),
          array(
            'name' => 'background',
            'help' => pht(
              'Instead of making calls in the foreground, add the tasks '.
              'to the daemon queue.'),
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $viewer = $this->getViewer();

    $id = $args->getArg('id');
    if (!$id) {
      throw new PhutilArgumentUsageException(
        pht(
          'Specify a webhook to call with "--id".'));
    }

    $count = $args->getArg('count');
    if ($count === null) {
      $count = 1;
    }

    if ($count <= 0) {
      throw new PhutilArgumentUsageException(
        pht(
          'Specified "--count" must be larger than 0.'));
    }

    $hook = id(new HeraldWebhookQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->executeOne();
    if (!$hook) {
      throw new PhutilArgumentUsageException(
        pht(
          'Unable to load specified webhook ("%s").',
          $id));
    }

    $object_name = $args->getArg('object');
    if ($object_name === null) {
      $object = $hook;
    } else {
      $objects = id(new PhabricatorObjectQuery())
        ->setViewer($viewer)
        ->withNames(array($object_name))
        ->execute();
      if (!$objects) {
        throw new PhutilArgumentUsageException(
          pht(
            'Unable to load specified object ("%s").',
            $object_name));
      }
      $object = head($objects);
    }

    $is_background = $args->getArg('background');
    $saw_failure = false;

    $xaction_query =
      PhabricatorApplicationTransactionQuery::newQueryForObject($object);

    $xactions = $xaction_query
      ->withObjectPHIDs(array($object->getPHID()))
      ->setViewer($viewer)
      ->setLimit(10)
      ->execute();

    $application_phid = id(new PhabricatorHeraldApplication())->getPHID();

    if ($is_background) {
      echo tsprintf(
        "%s\n",
        pht(
          'Queueing webhook calls...'));
      $progress_bar = id(new PhutilConsoleProgressBar())
        ->setTotal($count);
    } else {
      echo tsprintf(
        "%s\n",
        pht(
          'Calling webhook...'));
      PhabricatorWorker::setRunAllTasksInProcess(true);
    }

    for ($ii = 0; $ii < $count; $ii++) {
      $request = HeraldWebhookRequest::initializeNewWebhookRequest($hook)
        ->setObjectPHID($object->getPHID())
        ->setIsTestAction(true)
        ->setIsSilentAction((bool)$args->getArg('silent'))
        ->setIsSecureAction((bool)$args->getArg('secure'))
        ->setTriggerPHIDs(array($application_phid))
        ->setTransactionPHIDs(mpull($xactions, 'getPHID'))
        ->save();

      $request->queueCall();

      if ($is_background) {
        $progress_bar->update(1);
      } else {
        $request->reload();

        // "Run in process" only reaches the wire while this server is the one
        // delivering. When delivery has been handed to the Gorge webhook
        // service, queueCall() above declined to schedule anything and the
        // request is sitting in the queue waiting to be claimed, so there is
        // no status code to report yet -- and reporting the empty one as a
        // success would describe a call which has not happened.
        if (PhabricatorGorgeWebhookClient::isDeliveryDelegated()) {
          echo tsprintf(
            "%s\n",
            pht(
              'Queued webhook request ("%s") for delivery by the Gorge '.
              'webhook service. It is delivered out of process, so the '.
              'result is not available here; see the request in the web '.
              'interface for its outcome.',
              $request->getPHID()));
        } else if (
          $request->getStatus() === HeraldWebhookRequest::STATUS_FAILED) {
          // A failure in the in-process worker is archived rather than
          // rethrown, so the request row is the only record of what happened.
          // Reporting it as a success here -- which is what printing the
          // error code as an HTTP status did -- describes the opposite of
          // the outcome for a command whose whole purpose is diagnosis.
          $saw_failure = true;
          echo tsprintf(
            "%s\n",
            pht(
              'Webhook request ("%s") failed: %s error "%s".',
              $request->getPHID(),
              $request->getErrorType(),
              $request->getErrorCode()));
        } else if (
          $request->getStatus() === HeraldWebhookRequest::STATUS_QUEUED) {
          $saw_failure = true;
          echo tsprintf(
            "%s\n",
            pht(
              'Webhook request ("%s") is still queued: nothing delivered '.
              'it in process.',
              $request->getPHID()));
        } else {
          echo tsprintf(
            "%s\n",
            pht(
              'Success, got HTTP %s from webhook.',
              $request->getErrorCode()));
        }
      }
    }

    if ($is_background) {
      $progress_bar->done();
    }

    return $saw_failure ? 1 : 0;
  }

}
