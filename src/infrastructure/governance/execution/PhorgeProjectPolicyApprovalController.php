<?php

/** POST-only trusted web producer; no request-supplied actor or risk label. */
final class PhorgeProjectPolicyApprovalController extends PhabricatorProjectController {
  public function handleRequest(AphrontRequest $request) {
    if (!PhorgeProjectExecutionCatalog::enabled()) { return new Aphront404Response(); }
    if (!$request->isHTTPPost()) { return new Aphront400Response(); }
    try {
      $request->validateCSRF();
      $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      $raw=$request->getStr('payload');
      if (strlen($raw)>8192) { throw new InvalidArgumentException('project_execution_payload_refused'); }
      $payload=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
      if (!is_array($payload)) { throw new InvalidArgumentException('project_execution_payload_refused'); }
      $result=PhorgeProjectPolicyApprovalService::call($context,$request->getStr('action'),$payload);
      return (new AphrontJSONResponse())->setContent(array('result'=>$result));
    } catch (Throwable $error) {
      return (new AphrontJSONResponse())->setHTTPResponseCode(403)
        ->setContent(array('error'=>pht('The project approval request could not be completed.')));
    }
  }
}
