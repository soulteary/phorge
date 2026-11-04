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
      foreach(array('willSaveObject','willWriteData','didWriteData','willDelete','didDelete')as$hook){
        $this->assertEqual(LiskDAO::class,(new ReflectionMethod($class,$hook))->getDeclaringClass()->getName());
      }
    }
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
}
