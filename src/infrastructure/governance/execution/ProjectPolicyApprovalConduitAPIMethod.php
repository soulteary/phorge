<?php

/** API-token-only producer. Current native auth is rechecked in the writer. */
final class ProjectPolicyApprovalConduitAPIMethod extends ProjectConduitAPIMethod {
  public function getAPIMethodName() { return 'project.approval'; }
  public function getMethodDescription() { return pht('Submit or review a restricted project policy approval.'); }
  public function isReadOnlyAPI() { return false; }
  protected function defineParamTypes() { return array('action'=>'required string','payload'=>'required dict'); }
  protected function defineReturnType() { return 'dict'; }
  protected function defineErrorTypes() { return array('ERR-PROJECT-APPROVAL'=>pht('The project approval request could not be completed.')); }
  protected function execute(ConduitAPIRequest $request) {
    try {
    $payload=$request->getValue('payload');
    if (!is_array($payload)) { throw new InvalidArgumentException('project_execution_payload_refused'); }
    return PhorgeProjectPolicyApprovalService::call($request->getGovernanceActorContext(),
      $request->getValue('action'),$payload);
    } catch (Throwable $error) { throw new ConduitException('ERR-PROJECT-APPROVAL'); }
  }
}
