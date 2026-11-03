<?php

/** Pure boundary checks only; actual native role/transaction proof is SQL. */
final class PhorgeOrganizationTransferAdapterTestCase extends PhutilTestCase {
  public function testPostconditionDoesNotIgnoreColumnsTypesOrUnexpectedWrites() {
    $method=new ReflectionMethod(PhorgeOrganizationTransferAdapter::class,'sameRow');
    $method->invoke(null,array('id'=>'2','version'=>'3','state'=>'active'),array('id'=>2,'version'=>3,'state'=>'active'));
    $this->assertTrue(true);
    $increment=new ReflectionMethod(PhorgeOrganizationTransferAdapter::class,'nextVersion');
    $this->assertEqual(2,$increment->invoke(null,'1'));$exhausted=false;
    try{$increment->invoke(null,(string)(PHP_INT_MAX-1));}catch(RuntimeException$error){$exhausted=$error->getMessage()==='organization_transfer_version_exhausted';}
    $this->assertTrue($exhausted);
    foreach(array(array('id'=>'2','version'=>'4','state'=>'active'),
      array('id'=>'2','version'=>'03','state'=>'active'),
      array('id'=>'2','version'=>true,'state'=>'active'),
      array('id'=>'2','version'=>'3','state'=>'active','extra'=>'unplanned'))as$actual){
      $refused=false;try{$method->invoke(null,$actual,array('id'=>2,'version'=>3,'state'=>'active'));}
      catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);
    }
  }
  public function testNativeCapabilityCannotSerializeOrExposeItsConnection() {
    $class=new ReflectionClass(PhorgeOrganizationTransferAdapter::class);
    $this->assertTrue($class->getConstructor()->isPrivate());
    $this->assertTrue($class->getMethod('__clone')->isPrivate());
    foreach(array('service','connection','nonce','final')as$name){$this->assertTrue($class->getProperty($name)->isPrivate());}
    $this->assertFalse($class->hasMethod('getConnection'));
    $this->assertExpiredLeaseRefusedBeforeAnyBorrowedSQL();
    $instance=$class->newInstanceWithoutConstructor();
    foreach(array(function()use($instance){serialize($instance);},
      function()use($instance){$instance->__unserialize(array('approved'=>true));})as$call){
      $refused=false;try{$call();}catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);
    }
  }
  private function assertExpiredLeaseRefusedBeforeAnyBorrowedSQL() {
    $stack_property=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');
    $cache_property=new ReflectionProperty(PhabricatorEnv::class,'cache');
    $old_stack=$stack_property->getValue();$old_cache=$cache_property->getValue();
    $stack=new PhabricatorConfigStackSource();
    $stack->pushSource(new PhabricatorConfigDictionarySource(array('governance.organization.enabled'=>true)));
    try{
      $stack_property->setValue(null,$stack);$cache_property->setValue(null,array());
      // Negative-only shape: no database connection or authenticated actor.
      $service=(new ReflectionClass(PhorgeOrganizationService::class))->newInstanceWithoutConstructor();
      $capability=(new ReflectionClass(PhorgeOrganizationTransferAdapter::class))->newInstanceWithoutConstructor();
      (new ReflectionProperty(PhorgeOrganizationTransferAdapter::class,'service'))->setValue($capability,WeakReference::create($service));
      (new ReflectionProperty(PhorgeOrganizationTransferAdapter::class,'nonce'))->setValue($capability,str_repeat('a',32));
      (new ReflectionProperty(PhorgeOrganizationTransferAdapter::class,'final'))->setValue($capability,array('ticket'=>array('transferID'=>'closed-lease')));
      $refused=false;try{$capability->assertFinal();}
      catch(RuntimeException$error){$refused=$error->getMessage()==='organization_transfer_locked_transaction_required';}
      $this->assertTrue($refused);
    }finally{$stack_property->setValue(null,$old_stack);$cache_property->setValue(null,$old_cache);}
  }

  public function testTransportEnvelopeRejectsCallerAuthorityAndUnboundedApprovalTime() {
    $intent=array('operation_id'=>'transfer-op','transfer_id'=>'transfer-ticket',
      'subject_phid'=>'PHID-USER-'.str_repeat('a',20),'responsible_phid'=>'PHID-USER-'.str_repeat('b',20),
      'from_department'=>'from','to_department'=>'to','primary_version'=>1,'from_version'=>1,'to_version'=>1,
      'reason'=>'Explicit transfer','acknowledge_no_project_permission_change'=>true);
    $input=array('request'=>$intent,'ttl_seconds'=>300);
    $checked=PhorgeOrganizationTransferRequest::envelope($input,true);
    $this->assertEqual(null,$checked['deadline_offset']);$this->assertEqual(300,$checked['ttl_seconds']);
    foreach(array(array('approved'=>true),array('ttl_seconds'=>0),array('ttl_seconds'=>86401),
      array('deadline_offset'=>'0'),array('actor'=>'untrusted'))as$change){
      $bad=array_replace($input,$change);$refused=false;
      try{PhorgeOrganizationTransferRequest::envelope($bad,true);}catch(InvalidArgumentException$error){$refused=true;}
      $this->assertTrue($refused);
    }
  }
}
