<?php

/** Form parsing only. Actual authorization/CSRF/render proof is the native fixture. */
final class PhorgeOrganizationTransferControllerTestCase extends PhutilTestCase {
  public function testFormNumbersDoNotSilentlyCoerceToImmediateTransfer(){
    $method=new ReflectionMethod(PhorgeOrganizationTransferController::class,'integer');$controller=new PhorgeOrganizationTransferController();
    foreach(array('0'=>0,'14'=>14,'-18000'=>-18000)as$value=>$number){
      $request=(new AphrontRequest('fixture.invalid','/'))->setRequestData(array('days'=>(string)$value));
      $this->assertEqual($number,$method->invoke($controller,$request,'days'));
    }
    foreach(array('abc','',true,false,array(),1.5,'01','-0','9999999999999999999999999999')as$value){
      $request=(new AphrontRequest('fixture.invalid','/'))->setRequestData(array('days'=>$value));$refused=false;
      try{$method->invoke($controller,$request,'days');}catch(InvalidArgumentException$error){$refused=true;}$this->assertTrue($refused);
    }
    $this->assertEqual(14,$method->invoke($controller,(new AphrontRequest('fixture.invalid','/'))->setRequestData(array()),'days',14));
  }
  public function testSingleSelectorRequiresExactlyOneStringValue(){
    $method=new ReflectionMethod(PhorgeOrganizationTransferController::class,'one');$controller=new PhorgeOrganizationTransferController();
    $request=(new AphrontRequest('fixture.invalid','/'))->setRequestData(array('subject'=>array('PHID-USER-'.str_repeat('a',20))));
    $this->assertEqual('PHID-USER-'.str_repeat('a',20),$method->invoke($controller,$request,'subject'));
    foreach(array(array(),array('a','b'),array(true),array(array()))as$value){$refused=false;
      $request=(new AphrontRequest('fixture.invalid','/'))->setRequestData(array('subject'=>$value));
      try{$method->invoke($controller,$request,'subject');}catch(InvalidArgumentException$error){$refused=true;}$this->assertTrue($refused);}
  }
}
