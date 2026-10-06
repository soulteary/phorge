<?php

final class PhorgeProductProfileTestCase extends PhabricatorTestCase {

  public function testCollaborationProfileHasNoRuntimeApplicationToggles() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');

    $this->assertEqual(
      array(),
      PhorgeProductProfile::getManagedApplicationClasses());
    $this->assertFalse(
      PhorgeProductProfile::disablesApplication(
        'PhabricatorManiphestApplication'));

    unset($env);
  }

  public function testFullProfileDoesNotDisableApplications() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'full');

    foreach (PhorgeProductProfile::getManagedApplicationClasses() as $class) {
      $this->assertFalse(PhorgeProductProfile::disablesApplication($class));
    }

    unset($env);
  }

}
