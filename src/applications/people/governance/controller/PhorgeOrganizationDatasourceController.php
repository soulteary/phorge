<?php

final class PhorgeOrganizationDatasourceController extends PhabricatorPeopleController {
  public function shouldRequireAdmin() { return false; }
  public function handleRequest(AphrontRequest $request) {
    if(PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==true){return new Aphront404Response();}
    try{
      $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      $source=(new PhorgeOrganizationDatasource())->setActorContext($context)
        ->setRawQuery($request->getStr('raw',$request->getStr('q','')));
      return id(new AphrontAjaxResponse())->setContent(array_values(mpull($source->loadResults(),'getWireFormat')));
    }catch(Throwable $error){return id(new AphrontJSONResponse())->setHTTPResponseCode(403)
      ->setContent(array('error'=>pht('The organization request could not be completed.')));}
  }
}
