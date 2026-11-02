<?php

/** Protected metadata history only; no snapshots, exports or destructive action. */
final class PhorgeOrganizationHistoryController extends PhabricatorPeopleController {
  public function shouldRequireAdmin() { return false; }
  public function handleRequest(AphrontRequest $request) {
    if(PhabricatorEnv::getEnvConfig('governance.organization.enabled')!==true){return new Aphront404Response();}
    try{
      if($request->isHTTPPost()||($_SERVER['REQUEST_METHOD']??'')!=='GET'){
        throw new RuntimeException('organization_audit_read_only');}
      $context=PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
      $result=(new PhorgeOrganizationQuery($context))->readAuditPage($request->getInt('limit',100),
        $request->getStr('after')?:null,$request->getBool('retention_only'));
      if($request->getBool('json')){return (new AphrontJSONResponse())->setContent(array('result'=>$result));}
      $rows=array();foreach($result['rows']as$row){$rows[]=array($row['id'],$row['operation_id'],$row['actor_phid'],
        $row['action'],$row['unit_id'],$row['subject_phid'],$row['created_at'],
        $row['retention_candidate']?pht('Retained; Older Than 365 Days'):pht('Retained'));}
      $box=(new PHUIObjectBoxView())->setHeaderText(pht('Restricted Organization History'))
        ->appendChild(phutil_tag('p',array(),pht('Only current system administrators can read this history. Records are retained; this view never deletes history or includes private snapshots.')))
        ->appendChild((new AphrontTableView($rows))->setHeaders(array(pht('ID'),pht('Operation'),pht('Actor'),pht('Action'),
          pht('Team'),pht('User'),pht('Created'),pht('Retention Status'))));
      $page=$this->newPage()->setTitle(pht('Restricted Organization History'))->appendChild($box);
      if($result['next_cursor']!==null){$uri=(new PhutilURI($this->getApplicationURI('organization/history/')))
          ->setQueryParam('after',$result['next_cursor'])->setQueryParam('retention_only',$request->getBool('retention_only')?1:0);
        $page->appendChild(phutil_tag('a',array('href'=>(string)$uri),pht('Next History Page')));}
      return $page;
    }catch(Throwable$error){return (new AphrontJSONResponse())->setHTTPResponseCode(403)
      ->setContent(array('error'=>pht('The organization request could not be completed.')));}
  }
}
