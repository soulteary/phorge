<?php

final class PeopleOrganizationQueryConduitAPIMethod extends UserConduitAPIMethod {
  public function getAPIMethodName() { return 'people.organization.query'; }
  public function getMethodDescription() { return pht('Read the protected organization directory.'); }
  public function isReadOnlyAPI() { return true; }
  protected function defineParamTypes() {
    return array('mode' => 'optional string', 'unitID' => 'optional string',
      'userPHID' => 'optional phid', 'limit' => 'optional int', 'after' => 'optional string');
  }
  protected function defineReturnType() { return 'wild'; }
  protected function defineErrorTypes() {
    return array('ERR-ORGANIZATION' => pht('The organization request could not be completed.'));
  }
  protected function execute(ConduitAPIRequest $request) {
    try {
      return PhorgeOrganizationEndpoint::read($request->getGovernanceActorContext(),
        $request->getValue('mode', 'list'), $request->getValue('unitID'),
        $request->getValue('userPHID'), $request->getValue('limit', 100),
        $request->getValue('after'));
    } catch (Throwable $error) {
      throw new ConduitException('ERR-ORGANIZATION');
    }
  }
}
