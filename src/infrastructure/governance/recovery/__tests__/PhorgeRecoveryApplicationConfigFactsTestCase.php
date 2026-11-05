<?php

final class PhorgeRecoveryApplicationConfigFactsTestCase extends PhutilTestCase {
  public function testActualNativeSourcesAndPrivateBindingControls() {
    require_once dirname(__DIR__,5).'/support/governance/recovery/tests/application-config-controls.php';
    $checks=governance_recovery_config_facts_controls();
    $this->assertEqual(13,count($checks));
    $this->assertEqual(13,count(array_unique($checks)));
  }
}
