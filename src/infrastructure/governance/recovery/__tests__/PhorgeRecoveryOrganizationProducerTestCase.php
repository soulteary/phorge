<?php

final class PhorgeRecoveryOrganizationProducerTestCase extends PhutilTestCase {
  public function testFixedFinalDAOsHaveOnlyAuditedScalarHooks() {
    foreach(array(PhorgeOrganizationUnit::class=>'organization_unit',
      PhorgeOrganizationMember::class=>'organization_member',
      PhorgeOrganizationAppointment::class=>'organization_appointment',
      PhorgeOrganizationPrimaryDepartment::class=>'organization_primarydepartment')as$class=>$table){
      $this->assertEqual($table,PhorgeRecoveryOrganizationProducerFence::tableForClass($class));
      $this->assertTrue((new ReflectionClass($class))->isFinal());
      $this->assertTrue(is_subclass_of($class,PhorgeRecoveryOrganizationProducerDAO::class));
      $object=new $class();
      $this->assertFalse($object->getConfigOption(LiskDAO::CONFIG_TIMESTAMPS));
      $this->assertTrue(in_array($object->getConfigOption(LiskDAO::CONFIG_AUX_PHID),array(null,false),true));
      foreach(array('willSaveObject'=>LiskDAO::class,'willWriteData'=>PhabricatorLiskDAO::class,
        'didWriteData'=>LiskDAO::class,'willDelete'=>LiskDAO::class,'didDelete'=>LiskDAO::class)as$hook=>$declaring){
        $this->assertEqual($declaring,(new ReflectionMethod($class,$hook))->getDeclaringClass()->getName());
      }
      PhorgeRecoveryOrganizationProducerFence::assertNativeScalarHooks($object);
    }
    // Exercise the actual native config cache on the exact final DAO. No
    // serializer or arbitrary callback is executed, and every old entry is
    // restored by reference/value after each negative control.
    $metadata=new ReflectionProperty(LiskDAO::class,'liskMetadata');
    $original=$metadata->getValue(null);$unit=new PhorgeOrganizationUnit();
    try{
      $unit->getConfigOption(LiskDAO::CONFIG_TIMESTAMPS);$baseline=$metadata->getValue(null);
      foreach(array(LiskDAO::CONFIG_SERIALIZATION,PhabricatorLiskDAO::CONFIG_APPLICATION_SERIALIZERS)as$key){
        $bad=$baseline;$bad[PhorgeOrganizationUnit::class]['config'][$key]=array('name'=>new PhutilOpaqueEnvelope('synthetic-only'));
        $metadata->setValue(null,$bad);$refused=false;
        try{PhorgeRecoveryOrganizationProducerFence::assertNativeScalarHooks($unit);}
        catch(RuntimeException$error){$refused=get_class($error)===RuntimeException::class&&$error->getMessage()==='recovery_org_producer_hooks_unknown';}
        $this->assertTrue($refused);$metadata->setValue(null,$baseline);
      }
    }finally{$metadata->setValue(null,$original);}
    foreach(array(PhorgeOrganizationTransfer::class,PhabricatorUser::class,'UnknownProducer',null,array())as$class){
      $refused=false;
      try{PhorgeRecoveryOrganizationProducerFence::tableForClass($class);}
      catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_org_producer_unknown';}
      $this->assertTrue($refused);
    }
  }

  public function testRawDataCannotInvokeObjectConversionUnderDirectoryMutex() {
    $unit=(new PhorgeOrganizationUnit())->setUnitID('synthetic')->setName('Synthetic unit')
      ->setVersion('1')->setVisibilityVersion('1');
    PhorgeRecoveryOrganizationProducerFence::assertScalarModelData($unit);
    foreach(array(array(),new PhutilOpaqueEnvelope('synthetic-only'),1.5)as$bad){
      $unit->setName($bad);$refused=false;
      try{PhorgeRecoveryOrganizationProducerFence::assertScalarModelData($unit);}
      catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_org_producer_scalar_required';}
      $this->assertTrue($refused);
    }
    $unit->setName(str_repeat('x',4097));$refused=false;
    try{PhorgeRecoveryOrganizationProducerFence::assertScalarModelData($unit);}
    catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_org_producer_row_budget';}
    $this->assertTrue($refused);
  }
  public function testGenerationArithmeticIsCanonicalAndCannotWrap() {
    foreach(array('1'=>'2','9'=>'10','99'=>'100','9223372036854775807'=>'9223372036854775808',
      '18446744073709551614'=>'18446744073709551615')as$before=>$after){
      $this->assertEqual($after,PhorgeRecoveryOrganizationProducerFence::nextGuardVersion((string)$before));
    }
    foreach(array('18446744073709551615','18446744073709551616','0','01',true,null,array(),1.5)as$bad){
      $refused=false;try{PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($bad);}
      catch(RuntimeException$error){$refused=true;}
      catch(InvalidArgumentException$error){$refused=true;}
      $this->assertTrue($refused);
    }
  }

}
