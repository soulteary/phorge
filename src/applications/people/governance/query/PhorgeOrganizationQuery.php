<?php

/** One protected projection for list, selectors, counts and bounded export. */
final class PhorgeOrganizationQuery extends Phobject {
  private $service;

  public function __construct(PhorgeGovernanceNativeActorContext $context) {
    $this->service = PhorgeOrganizationService::newForActor($context);
  }

  public function getHandoverApproval($id){return $this->service->readHandoverApproval($id);}
  public function getHandoverProjects($id){return $this->service->readHandoverProjects($id);}
  public function getDueHandovers($limit,$after){
    $cursor=$after===null?null:json_decode($after,true,4,JSON_THROW_ON_ERROR);
    return $this->service->readDueHandovers($limit,$cursor);
  }
  public function getTransfer($id){return $this->service->readTransfer($id);}

  public function readAuditPage($limit=100,$after=null,$retentionOnly=false) {
    return $this->service->readAuditPage($limit,$after,$retentionOnly);
  }
  public function getActorCapabilities() { return $this->service->readActorCapabilities(); }

  public function getUnitForManagement($id) { return $this->service->readUnitManagement($id); }
  public function getUnit($id) { return $this->service->readUnit($id); }
  public function listUnits($limit = 100, $after = null) {
    return $this->service->readUnits($limit, $after);
  }
  public function selectUnits($limit = 100, $after = null) {
    return $this->service->readUnits($limit, $after);
  }
  public function exportUnitsPage($limit = 100, $after = null) {
    return $this->service->readUnits($limit, $after);
  }
  public function searchUnits($query, $limit = 100, $after = null) {
    return $this->service->readUnits($limit,$after,$query);
  }
  public function searchSelectorUnits($query,$limit=100,$after=null,$kind=null) {
    return $this->service->readUnits($limit,$after,$query,true,$kind);
  }
  public function listUnassignedUsers($limit=100,$after=null) {
    return $this->service->readUnassignedUsers($limit,$after);
  }
  public function countUnits($query='') { return $this->service->countUnits($query); }
  public function countMembers($id) { return $this->service->countMembers($id); }
  public function listMembers($unitID, $limit = 100, $after = null) {
    return $this->service->readMembers($unitID, $limit, $after);
  }
  public function listUserTeams($subject, $limit = 100) {
    return $this->service->readUserTeams($subject, $limit);
  }
}
