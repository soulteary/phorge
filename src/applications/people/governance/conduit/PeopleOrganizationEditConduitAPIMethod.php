<?php

final class PeopleOrganizationEditConduitAPIMethod extends UserConduitAPIMethod {
  public function getAPIMethodName() { return 'people.organization.edit'; }
  public function getMethodDescription() { return pht('Apply a protected organization change.'); }
  protected function defineParamTypes() {
    return array('action' => 'required string', 'payload' => 'required dict');
  }
  protected function defineReturnType() { return 'dict'; }
  protected function defineErrorTypes() {
    return array('ERR-ORGANIZATION' => pht('The organization request could not be completed.'));
  }
  protected function execute(ConduitAPIRequest $request) {
    try {
      $payload = $request->getValue('payload');
      if (!is_array($payload)) { throw new InvalidArgumentException('organization_payload_refused'); }
      return PhorgeOrganizationEndpoint::write($request->getGovernanceActorContext(),
        $request->getValue('action'), $payload);
    } catch (Throwable $error) {
      throw new ConduitException('ERR-ORGANIZATION');
    }
  }
}
