<?php

/** One protected projection for list, selectors, counts and bounded export. */
final class PhorgeOrganizationQuery extends Phobject {
  private $service;

  public function __construct(PhorgeGovernanceNativeActorContext $context) {
    $this->service = PhorgeOrganizationService::newForActor($context);
  }

  public function getActorCapabilities() { return $this->service->readActorCapabilities(); }

  public function listUnits($limit = 100, $after = null) {
    return $this->service->readUnits($limit, $after);
  }
  public function selectUnits($limit = 100, $after = null) {
    return $this->service->readUnits($limit, $after);
  }
  public function exportUnitsPage($limit = 100, $after = null) {
    return $this->service->readUnits($limit, $after);
  }
  public function countUnits() { return $this->service->countUnits(); }
  public function listMembers($unitID, $limit = 100) {
    return $this->service->readMembers($unitID, $limit);
  }
  public function listUserTeams($subject, $limit = 100) {
    return $this->service->readUserTeams($subject, $limit);
  }
}
