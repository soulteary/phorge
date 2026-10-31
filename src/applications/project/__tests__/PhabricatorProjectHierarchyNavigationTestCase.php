<?php

final class PhabricatorProjectHierarchyNavigationTestCase
  extends PhabricatorTestCase {

  public function testHierarchyQueryPaginatesRootsAndKeepsArchivedParents() {
    $engine = id(new PhabricatorProjectSearchEngine())
      ->setViewer(PhabricatorUser::getOmnipotentUser());
    $method = new ReflectionMethod($engine, 'buildSavedQueryFromBuiltin');
    $method->setAccessible(true);
    $query = $method->invoke($engine, 'hierarchy');
    $this->assertTrue($query->getParameter('isRoot'));
    $this->assertEqual('all', $query->getParameter('status'));
    $this->assertEqual('name', $query->getParameter('order'));
  }

  public function testExplicitAllQueryRemainsFlat() {
    $engine = id(new PhabricatorProjectSearchEngine())
      ->setViewer(PhabricatorUser::getOmnipotentUser());
    $method = new ReflectionMethod($engine, 'buildSavedQueryFromBuiltin');
    $method->setAccessible(true);
    $query = $method->invoke($engine, 'all');
    $this->assertEqual(null, $query->getParameter('isRoot'));
  }
}
