<?php
/** Native candidate tests; registration is source-only, map/runtime acceptance pending. */
final class PhorgeRecoverySpacesSourceFactsTestCase extends PhutilTestCase {
  private function row(){return array('id'=>1,'phid'=>'PHID-SPCE-'.str_repeat('s',20),'namespaceName'=>'Synthetic Space','viewPolicy'=>'users','editPolicy'=>'admin','isDefaultNamespace'=>1,'dateCreated'=>1,'dateModified'=>1,'description'=>'','isArchived'=>1);}
  public function testNativeNamespaceSchemaAndNoAutomaticGrant(){
    $row=$this->row();$space=(new PhabricatorSpacesNamespace())->loadFromArray($row);
    $schema=(new ReflectionMethod(PhabricatorSpacesNamespace::class,'getConfiguration'))->invoke($space);
    $this->assertEqual(array('columns'=>array('isDefaultNamespace'),'unique'=>true),$schema[LiskDAO::CONFIG_KEY_SCHEMA]['key_default']);
    $this->assertEqual('users',$space->getPolicy(PhabricatorPolicyCapability::CAN_VIEW));
    $this->assertFalse($space->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,new PhabricatorUser()));
    $this->assertEqual('spaces',(new PhabricatorSpacesNamespace())->getApplicationName());
  }
  public function testNativePolicyFilterDefaultAndExplicitMissingSelection(){
    $property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$original=$property->getValue();
    try{
      $cache=new PhutilInRequestKeyValueCache();$property->setValue(null,$cache);$cache->setKey(PhabricatorSpacesNamespaceQuery::KEY_ALL,array());
      $filter=new PhabricatorPolicyFilter();$method=new ReflectionMethod(PhabricatorPolicyFilter::class,'canViewerSeeObjectsInSpace');$viewer=PhabricatorUser::getOmnipotentUser();
      $this->assertTrue($method->invoke($filter,$viewer,null));
      $this->assertFalse($method->invoke($filter,$viewer,'PHID-SPCE-'.str_repeat('m',20)));
      $row=$this->row();$space=(new PhabricatorSpacesNamespace())->loadFromArray($row);
      $cache->setKey(PhabricatorSpacesNamespaceQuery::KEY_ALL,array($row['phid']=>$space));$cache->deleteKeys(array(PhabricatorSpacesNamespaceQuery::KEY_DEFAULT));
      $this->assertTrue($method->invoke($filter,$viewer,null));$this->assertTrue($method->invoke($filter,$viewer,$row['phid']));
      $this->assertFalse($method->invoke($filter,$viewer,'PHID-SPCE-'.str_repeat('m',20)));
      $this->assertEqual($space,PhabricatorSpacesNamespaceQuery::getDefaultSpace());
      // These selection checks use the native omnipotent viewer, not ordinary VIEW proof.
      $this->assertTrue((bool)$space->getIsArchived());$this->assertEqual(1,count(PhabricatorSpacesNamespaceQuery::getAllSpaces()));
    }finally{$property->setValue(null,$original);}
  }
  public function testActualCacheRowsBoundPrivatelyAndUnknownProviderRefused(){
    $property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$original=$property->getValue();
    try{
      $row=$this->row();$space=(new PhabricatorSpacesNamespace())->loadFromArray($row);$method=new ReflectionMethod(PhorgeRecoverySpacesSourceFacts::class,'cacheFacts');
      $cache=new PhutilInRequestKeyValueCache();$property->setValue(null,$cache);$cache->setKey('spaces.all',array($row['phid']=>$space));
      $facts=$method->invoke(null,array($row['phid']=>$row));$this->assertEqual('native-request-cache',$facts['state']);
      $space->setNamespaceName('changed');$refused=false;try{$method->invoke(null,array($row['phid']=>$row));}catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_spaces_cache_rows_changed';}$this->assertTrue($refused);
      $property->setValue(null,new stdClass());$refused=false;try{$method->invoke(null,array());}catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_spaces_cache_provider_unknown';}$this->assertTrue($refused);
    }finally{$property->setValue(null,$original);}
  }
}
