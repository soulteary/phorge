<?php

final class PhabricatorPeopleProfileCrumbsTestCase extends PhabricatorTestCase {

  public function testDeniedProfileCanBuildCrumbsWithoutAUser() {
    $controller = new PhabricatorPeopleProfileViewController();
    $crumbs = $controller->buildApplicationCrumbsForEditEngine();
    $this->assertTrue($crumbs instanceof PHUICrumbsView);
    $html = (string)hsprintf('%s', $crumbs);
    $this->assertFalse(strpos($html, '/p/') !== false);
    $this->assertEqual(null, $controller->getUser());
  }

  public function testAssignedProfileRetainsNativeUserCrumb() {
    $user = id(new PhabricatorUser())->setUsername('profile_contract');
    $controller = id(new PhabricatorPeopleProfileViewController())
      ->setUser($user);
    $html = (string)hsprintf('%s',
      $controller->buildApplicationCrumbsForEditEngine());
    $this->assertTrue(strpos($html, '/p/profile_contract/') !== false);
    $this->assertTrue(strpos($html, 'profile_contract') !== false);
    $this->assertEqual($user, $controller->getUser());
  }
}
