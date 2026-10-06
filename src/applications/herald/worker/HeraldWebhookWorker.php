<?php

/**
 * Compatibility sink for PHP webhook tasks created before Gorge ownership.
 *
 * Gorge is now the only webhook delivery consumer. Keep this worker class so
 * tasks which were already persisted before an upgrade still deserialize, but
 * never perform an outbound HTTP request here.
 */
final class HeraldWebhookWorker
  extends PhabricatorWorker {

  protected function doWork() {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $data = $this->getTaskData();
    $request_phid = idx($data, 'webhookRequestPHID');

    $request = id(new HeraldWebhookRequestQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($request_phid))
      ->executeOne();
    if (!$request) {
      throw new PhabricatorWorkerPermanentFailureException(
        pht(
          'Unable to load webhook request ("%s"). It may have been '.
          'garbage collected.',
          $request_phid));
    }

    if ($request->getStatus() !== HeraldWebhookRequest::STATUS_QUEUED) {
      // Gorge or an earlier worker already resolved the row. There is no work
      // left for this compatibility task.
      return;
    }

    // Global silent mode belongs to Phorge configuration and is not visible to
    // the Go consumer. Preserve the historical invariant by converting queued
    // rows to a terminal silent failure before Gorge can claim them.
    if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      $request
        ->setStatus(HeraldWebhookRequest::STATUS_FAILED)
        ->setErrorType(HeraldWebhookRequest::ERRORTYPE_HOOK)
        ->setErrorCode(HeraldWebhookRequest::ERROR_SILENT)
        ->setLastRequestResult(HeraldWebhookRequest::RESULT_NONE)
        ->setLastRequestEpoch(0)
        ->save();
      return;
    }

    if (PhabricatorGorgeWebhookClient::isDeliveryDelegated()) {
      // Leave the row queued. gorge-webhook owns the claim, retry, HMAC and
      // result-write protocol and will consume it from herald_webhookrequest.
      return;
    }

    // Native HTTP delivery has been retired. Fail closed instead of leaving a
    // queued row with no owner or silently reviving the removed PHP consumer.
    $request
      ->setStatus(HeraldWebhookRequest::STATUS_FAILED)
      ->setErrorType(HeraldWebhookRequest::ERRORTYPE_HOOK)
      ->setErrorCode('native-retired')
      ->setLastRequestResult(HeraldWebhookRequest::RESULT_NONE)
      ->setLastRequestEpoch(0)
      ->save();

    throw new PhabricatorWorkerPermanentFailureException(
      pht(
        'Native PHP webhook delivery has been retired. Configure the Gorge '.
        'webhook service as the delivery owner before queueing webhooks.'));
  }

}
