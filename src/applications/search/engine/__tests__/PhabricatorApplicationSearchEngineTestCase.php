<?php

final class PhabricatorApplicationSearchEngineTestCase
  extends PhabricatorTestCase {

  public function testGetAllEngines() {
    PhabricatorApplicationSearchEngine::getAllEngines();
    $this->assertTrue(true);
  }

  public function testPageSizeClampedToMaximum() {
    $engine = new ManiphestTaskSearchEngine();

    $saved = $this->newSavedQueryWithLimit(1001);
    $this->assertEqual(1000, $engine->getPageSize($saved));
    $this->assertEqual(1001, $engine->getRequestedPageSize($saved));
  }

  public function testPageSizeBelowMaximumIsUnchanged() {
    $engine = new ManiphestTaskSearchEngine();

    $saved = $this->newSavedQueryWithLimit(10);
    $this->assertEqual(10, $engine->getPageSize($saved));
    $this->assertEqual(10, $engine->getRequestedPageSize($saved));
  }

  public function testPageSizeDefaultsWhenUnset() {
    $engine = new ManiphestTaskSearchEngine();

    $saved = new PhabricatorSavedQuery();
    $this->assertEqual(100, $engine->getPageSize($saved));
    $this->assertEqual(100, $engine->getRequestedPageSize($saved));
  }

  public function testPagerLimitsAndInvalidInputs() {
    $engine = new ManiphestTaskSearchEngine();
    foreach (array(0 => 100, -1 => 100, 1 => 1, 1000 => 1000,
                   50000 => 1000, PHP_INT_MAX => 1000) as $limit => $expected) {
      $saved = $this->newSavedQueryWithLimit($limit);
      $pager = $engine->newPagerForSavedQuery($saved);
      $this->assertEqual($expected, $pager->getPageSize());
    }
  }

  private function newSavedQueryWithLimit($limit) {
    $saved = new PhabricatorSavedQuery();
    $saved->setParameter('limit', $limit);
    return $saved;
  }

}
