<?php

final class DifferentialChangesetPHIDType extends PhabricatorPHIDType {

  const TYPECONST = 'DCNG';

  public function getTypeName() {
    return pht('Diff Changeset');
  }

  public function newObject() {
    return new DifferentialChangeset();
  }

  public function getPHIDTypeApplicationClass() {
    return PhabricatorFilesApplication::class;
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new DifferentialChangesetQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $changeset = $objects[$phid];
      $handle->setName(pht('Changeset %d', $changeset->getID()));
    }
  }

}
