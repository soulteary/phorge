<?php

final class PhorgePHPVersionTestCase extends PhutilTestCase {

  public function testSupportedSecurityPatchFloor() {
    foreach (array('8.5.12', '8.5.13', '8.5.99') as $version) {
      $this->assertTrue((bool)phorge_php_version_is_supported($version));
      phorge_assert_supported_php_version($version);
    }
    $this->assertTrue((bool)phorge_php_version_is_supported(PHP_VERSION));
  }

  public function testOtherBranchesAndUnpatchedVersionsAreRejected() {
    foreach (array('7.2.25', '8.3.36', '8.4.27', '8.5.0', '8.5.11',
      '8.5.12RC1', '8.5.13RC1', '8.5.13-dev', '8.5.invalid', '8.6.0', '9.0.0', '', 'invalid') as $version) {
      $this->assertFalse((bool)phorge_php_version_is_supported($version));
      $rejected = false;
      try {
        phorge_assert_supported_php_version($version);
      } catch (RuntimeException $ex) {
        $rejected = strpos($ex->getMessage(), 'PHP 8.5.x') !== false;
      }
      $this->assertTrue($rejected, 'The unsupported runtime was accepted.');
    }
  }
}
