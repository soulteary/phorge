<?php

/** Value/version negatives only. Locked native SQL proof is a separate fixture. */
final class PhorgeGovernanceNativeNoncredentialIdentitySourceTestCase extends PhutilTestCase {
  private function identity(){return array('userPHID'=>'PHID-USER-'.str_repeat('a',20),'eligible'=>true,
    'isAdmin'=>false,'accountEpoch'=>null,'digest'=>str_repeat('b',64));}
  public function testFirstIssuerEpochOneNormalizationDoesNotIgnoreRealChanges(){
    $method=new ReflectionMethod(PhorgeGovernanceNativeNoncredentialIdentitySource::class,'facts');
    $before=$method->invoke(null,$this->identity(),'0','1');$initialized=$this->identity();$initialized['accountEpoch']=str_repeat('c',64);
    $after=$method->invoke(null,$initialized,'0','1');$this->assertEqual($before,$after);
    $this->assertEqual(2,$after['source_version']);$this->assertFalse(isset($after['accountEpoch']));
    $this->assertFalse($after===$method->invoke(null,$initialized,'1','1'));
    $this->assertFalse($after===$method->invoke(null,$initialized,'0','2'));
    $disabled=$initialized;$disabled['eligible']=false;$disabled['digest']=str_repeat('d',64);
    $this->assertFalse($after===$method->invoke(null,$disabled,'2','1'));
    $this->assertFalse($method->invoke(null,$disabled,'2','1')['eligible']);
  }
  public function testGenerationValuesRemainCanonicalAndBounded(){
    $method=new ReflectionMethod(PhorgeGovernanceNativeNoncredentialIdentitySource::class,'counter');
    $this->assertEqual('0',$method->invoke(null,'0',true));
    $this->assertEqual('18446744073709551615',$method->invoke(null,'18446744073709551615',false));
    foreach(array('0','01','-1','18446744073709551616','999999999999999999999',1,true,null)as$value){$refused=false;
      try{$method->invoke(null,$value,false);}catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);}
    $facts=new ReflectionMethod(PhorgeGovernanceNativeNoncredentialIdentitySource::class,'facts');
    foreach(array(array('eligible'=>1),array('isAdmin'=>'0'),array('digest'=>'x'),array('accountEpoch'=>true),array('unknown'=>true))as$change){$refused=false;
      try{$facts->invoke(null,array_replace($this->identity(),$change),'0','1');}catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);}
  }
  public function testStoredBindingVersionSelectsLegacyWithoutReinterpretation(){
    foreach(array('Transfer'=>'native-organization-transfer','Handover'=>'native-organization-handover')as$name=>$prefix){
      $method=new ReflectionMethod('PhorgeOrganization'.$name.'Adapter','sourceVersion');
      $this->assertEqual(1,$method->invoke(null,$prefix.'-v1'));$this->assertEqual(2,$method->invoke(null,$prefix.'-v2'));
      foreach(array($prefix.'-v3',$prefix.'-v1 ',true,1,null)as$value){$refused=false;
        try{$method->invoke(null,$value);}catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);}
    }
  }
  public function testFactHelperCannotConstructActorsOrCredentials(){
    $class=new ReflectionClass(PhorgeGovernanceNativeNoncredentialIdentitySource::class);
    $this->assertTrue($class->getConstructor()->isPrivate());$this->assertTrue($class->getMethod('facts')->isPrivate());
    foreach(array('newFromWebRequest','newFromAuthenticatedAPIToken','lockCredentialBinding','initializeIssuerEpoch','getCredentialEnvelope')as$name){$this->assertFalse($class->hasMethod($name));}
    $refused=false;try{serialize($class->newInstanceWithoutConstructor());}catch(RuntimeException$error){$refused=true;}$this->assertTrue($refused);
  }
}
