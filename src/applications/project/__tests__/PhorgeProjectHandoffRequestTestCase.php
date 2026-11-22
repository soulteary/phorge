<?php

final class PhorgeProjectHandoffRequestTestCase extends PhutilTestCase {
  private function payload() {
    return array('projectPHID'=>'PHID-PROJ-aaaaaaaaaaaaaaaaaaaa',
      'recipientPHID'=>'PHID-USER-bbbbbbbbbbbbbbbbbbbb','operationID'=>'operation_123456789');
  }
  public function testClosedPreviewShapeRejectsAuthorityAndSourceInjection() {
    $value=$this->payload();$parsed=PhorgeProjectHandoffRequest::parse($value,false);
    $this->assertEqual($value['projectPHID'],$parsed['projectPHID']);
    foreach(array('actor','isAdmin','members','viewPolicy','parentPHID','spacePHID','expectedDigest')as$key){
      $bad=$value;$bad[$key]='injected';$this->assertException(InvalidArgumentException::class,
        function()use($bad){PhorgeProjectHandoffRequest::parse($bad,false);});}
  }
  public function testConfirmationIsExplicitAndTyped() {
    $value=$this->payload()+array('acknowledge'=>true,'expiresAt'=>100,'expectedDigest'=>str_repeat('a',64));
    $this->assertEqual(true,PhorgeProjectHandoffRequest::parse($value,true)['acknowledge']);
    foreach(array('acknowledge'=>1,'expiresAt'=>'100','expectedDigest'=>'bad')as$key=>$badValue){
      $bad=$value;$bad[$key]=$badValue;$this->assertException(InvalidArgumentException::class,
        function()use($bad){PhorgeProjectHandoffRequest::parse($bad,true);});}
  }
  public function testIdentityOperationAndInputReferenceBounds() {
    foreach(array('projectPHID'=>'1','recipientPHID'=>'PHID-PROJ-bbbbbbbbbbbbbbbbbbbb',
      'operationID'=>str_repeat('x',81))as$key=>$badValue){$bad=$this->payload();$bad[$key]=$badValue;
      $this->assertException(InvalidArgumentException::class,function()use($bad){PhorgeProjectHandoffRequest::parse($bad,false);});}
    $recipient='PHID-USER-bbbbbbbbbbbbbbbbbbbb';$value=$this->payload();$value['recipientPHID']=&$recipient;
    $parsed=PhorgeProjectHandoffRequest::parse($value,false);$recipient='PHID-USER-cccccccccccccccccccc';
    $this->assertEqual('PHID-USER-bbbbbbbbbbbbbbbbbbbb',$parsed['recipientPHID']);
  }
  public function testDisabledHandoffRefusesBeforeDatabaseLookup() {
    $scope=PhabricatorEnv::beginScopedEnv();$scope->overrideEnvConfig('projects.permission-handoff.enabled',false);
    $this->assertEqual(false,PhorgeProjectHandoffService::enabled());
    $context=(new ReflectionClass(PhorgeGovernanceNativeActorContext::class))->newInstanceWithoutConstructor();
    $this->assertException(RuntimeException::class,function()use($context){
      PhorgeProjectHandoffService::preview($context,$this->payload());
    });
  }
  public function testAbsentHandoffDoesNotChangeNativeEditorValidation() {
    $type=new PhorgeProjectPermissionHandoffTransaction();
    $this->assertEqual(array(),$type->validateTransactions(new PhabricatorProject(),array()));
  }
}
