<?php

final class PhorgeProductProfileTestCase extends PhabricatorTestCase {

  public function testCollaborationApplicationsAreRuntimePolicy() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');

    $this->assertTrue(
      PhorgeProductProfile::disablesApplication(
        'PhabricatorDiffusionApplication'));
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
