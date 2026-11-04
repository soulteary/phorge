<?php

final class PhorgeRecoveryPolicySourceTestCase extends PhutilTestCase {
  public function testByteBudgetAcceptsOnlyCanonicalBoundedIntegers() {
    foreach (array(0,'0',1,'1',16384,'16384') as $value) {
      $this->assertEqual((int)$value,PhorgeRecoveryRestorePolicySource::boundedBytes($value));
    }
    foreach (array(null,false,true,0.0,1.0,'00','01','1e3','-1','16385',16385,'9999999',array()) as $value) {
      $refused=false;
      try {PhorgeRecoveryRestorePolicySource::boundedBytes($value);}
      catch (RuntimeException $error) {
        if (get_class($error)!==RuntimeException::class || $error->getMessage()!=='recovery_policy_byte_budget') {
          throw $error;
        }
        $refused=true;
      }
      $this->assertTrue($refused);
    }
  }
}
