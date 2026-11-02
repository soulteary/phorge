<?php

/** Native tokenizer adapter; a cached viewer alone can never load its results. */
final class PhorgeOrganizationDatasource extends PhabricatorTypeaheadDatasource {
  private $actorContext;
  public function setActorContext(PhorgeGovernanceNativeActorContext $context) {
    $this->actorContext=$context;return $this;
  }
  public function getTokenizerNormalizerMode() { return 'literal-casefold-v1'; }
  public function getDatasourceURI() { return '/people/organization/typeahead/'; }
  public function getBrowseURI() { return null; }
  public function getBrowseTitle() { return pht('Organization Directory'); }
  public function getPlaceholderText() { return pht('Search visible organization units...'); }
  public function getDatasourceApplicationClass() { return PhabricatorPeopleApplication::class; }
  public function renderTokens(array $values) {
    if($this->actorContext===null){throw new RuntimeException('organization_credential_context_required');}
    $query=new PhorgeOrganizationQuery($this->actorContext);$tokens=array();
    foreach($values as $key=>$value){$row=$query->getUnit($value);
      if($row){$result=(new PhabricatorTypeaheadResult())->setName($row['name'])->setPHID($row['unit_id'])
        ->setIcon($row['kind']==='team'?'fa-users':'fa-building');
        $tokens[$key]=PhabricatorTypeaheadTokenView::newFromTypeaheadResult($result);
      }else{$tokens[$key]=$this->newInvalidToken(pht('Unavailable Organization Unit'))->setKey($value);}}
    return $tokens;
  }
  public function loadResults() {
    if($this->actorContext===null){throw new RuntimeException('organization_credential_context_required');}
    $rows=(new PhorgeOrganizationQuery($this->actorContext))->searchSelectorUnits($this->getRawQuery(),100);
    $results=array();foreach($rows as $row){$results[]=id(new PhabricatorTypeaheadResult())
      ->setName($row['name'])->setPHID($row['unit_id'])->setPriorityString($row['name'])
      ->setURI('/people/organization/?unit_id='.rawurlencode($row['unit_id']))
      ->setIcon($row['kind']==='team'?'fa-users':'fa-building');}
    return $results;
  }
}
