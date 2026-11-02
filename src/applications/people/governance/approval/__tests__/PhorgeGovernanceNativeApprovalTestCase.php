<?php

final class PhorgeGovernanceNativeApprovalTestCase extends PhutilTestCase {
  private function rejects($callable) {
    $rejected=false;
    try { $callable(); } catch (Throwable $error) { $rejected=true; }
    $this->assertTrue($rejected);
  }

  public function testNativeApprovalIntentMappingIsBoundedAndExplicit() {
    $method=new ReflectionMethod(PhorgeGovernanceNativeApprovalAdapter::class,'params');
    $subject='PHID-USER-aaaaaaaaaaaaaaaaaaaa';
    $this->assertEqual(array('subject'=>$subject,'valid_from'=>10,'valid_until'=>20),
      $method->invoke(null,'appointment.grant',$subject,10,20));
    $this->assertEqual(array('subject'=>$subject,'valid_from'=>null,'valid_until'=>null),
      $method->invoke(null,'appointment.revoke',$subject,null,null));
    foreach (array(array('member.add',$subject,10,20),array('appointment.grant',$subject,'10',20),
      array('appointment.grant',$subject,20,20),array('appointment.revoke',$subject,0,null),
      array('appointment.grant',$subject.' ',10,20)) as $values) {
      $this->rejects(function() use ($method,$values) { return $method->invoke(null,...$values); });
    }
  }

  public function testNativeApprovalCannotBeConstructedFromCallerProof() {
    $class=new ReflectionClass(PhorgeGovernanceNativeApprovalAdapter::class);
    $this->assertTrue($class->isFinal());
    $this->assertTrue($class->getConstructor()->isPrivate());
    $this->assertTrue($class->getMethod('__clone')->isPrivate());
    $object=$class->newInstanceWithoutConstructor();
    $this->rejects(function() use ($object) { return serialize($object); });
    $this->rejects(function() use ($object) { return $object->__unserialize(array('authorized'=>true)); });
    $this->assertEqual(array('scope'=>'native-organization-approval'),$object->__debugInfo());
    $this->rejects(function() { return PhorgeGovernanceNativeApprovalAdapter::forLockedService(
      (object)array('authorized'=>true,'actor'=>'PHID-USER-aaaaaaaaaaaaaaaaaaaa')); });
  }
}
