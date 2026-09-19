<?php

final class AlmanacDrydockPoolServiceType extends AlmanacServiceType {

  const SERVICETYPE = 'drydock.pool';

  public function getServiceTypeShortName() {
    return pht('Drydock Pool');
  }

  public function getServiceTypeName() {
    return pht('Drydock: Resource Pool');
  }

  public function getServiceTypeDescription() {
    return pht(
      'Defines a pool of hosts which Drydock can allocate.');
  }

  public function isCreatableServiceType() {
    // Drydock has been removed from this install, so nothing allocates from
    // a pool any more. The type stays loadable so services created before the
    // removal still resolve and remain visible, but a new one would be inert.
    return false;
  }

}
