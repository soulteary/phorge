<?php

final class PhorgeOrganizationTransferRequestTestCase extends PhutilTestCase {
  private function proposal() {
    return array('operation_id'=>'transfer-propose','transfer_id'=>'transfer-1',
      'subject_phid'=>'PHID-USER-'.str_repeat('a',20),'responsible_phid'=>'PHID-USER-'.str_repeat('b',20),
      'from_department'=>'department-a','to_department'=>'department-b',
      'primary_version'=>1,'from_version'=>'2','to_version'=>3,'reason'=>'Explicit reviewed transfer',
      'acknowledge_no_project_permission_change'=>true);
  }
  private function refused(callable $call) {
    $seen=false;try{$call();}catch(InvalidArgumentException $error){$seen=true;}$this->assertTrue($seen);
  }
  public function testDetachedProposalUses14ByDefaultAndExactVersions() {
    $source=$this->proposal();$from='department-a';$days=14;
    $source['from_department']=&$from;$source['handover_days']=&$days;
    $result=PhorgeOrganizationTransferRequest::proposal($source);$from='replaced';$days=30;
    $this->assertEqual('department-a',$result['from_department']);$this->assertEqual(14,$result['handover_days']);
    $this->assertEqual(2,$result['from_version']);
    $this->assertEqual(14,PhorgeOrganizationTransferRequest::proposal($this->proposal())['handover_days']);
    foreach(array('approved'=>true,'timezone'=>'UTC','actor_phid'=>'PHID-USER-'.str_repeat('c',20))as$key=>$value){
      $input=$this->proposal();$input[$key]=$value;$this->refused(function()use($input){PhorgeOrganizationTransferRequest::proposal($input);});}
    foreach(array(0,'01',true)as$version){$input=$this->proposal();$input['primary_version']=$version;
      $this->refused(function()use($input){PhorgeOrganizationTransferRequest::proposal($input);});}
    $input=$this->proposal();$input['to_department']=$input['from_department'];
    $this->refused(function()use($input){PhorgeOrganizationTransferRequest::proposal($input);});
  }
  public function testExtensionAndTerminationCannotSmuggleSourcesOrApproval() {
    $input=array('operation_id'=>'extend-operation','transfer_id'=>'transfer-2','handover_id'=>'handover-1',
      'expected_version'=>2,'reason'=>'Reviewed extension','acknowledge_no_project_permission_change'=>true,'handover_days'=>30);
    $result=PhorgeOrganizationTransferRequest::extension($input);
    $this->assertEqual(30,$result['handover_days']);$this->assertEqual(null,$result['exception_reason']);
    $bad=$input;$bad['handover_days']=31;$this->refused(function()use($bad){PhorgeOrganizationTransferRequest::extension($bad);});
    $bad=$input;$bad['approved']=true;$this->refused(function()use($bad){PhorgeOrganizationTransferRequest::extension($bad);});
    unset($input['handover_days']);
    $this->assertEqual(2,PhorgeOrganizationTransferRequest::termination($input)['expected_version']);
    $input['deadline_at']=1;$this->refused(function()use($input){PhorgeOrganizationTransferRequest::termination($input);});
  }
  public function testManualDispositionIntentIsNotNativePermissionProof() {
    $input=array('operation_id'=>'disposition-operation','handover_id'=>'handover-1','handover_version'=>2,
      'project_phid'=>'PHID-PROJ-'.str_repeat('c',20),'decision'=>'remove','manual_status'=>'pending','expected_version'=>0,'note'=>'Needs manual native review');
    $result=PhorgeOrganizationTransferRequest::disposition($input);
    $this->assertEqual('remove',$result['decision']);$this->assertEqual('pending',$result['manual_status']);
    $this->assertFalse(array_key_exists('observed_digest',$result));
    $input['observed_digest']=str_repeat('a',64);$this->refused(function()use($input){PhorgeOrganizationTransferRequest::disposition($input);});
    foreach(array(new PhorgeOrganizationTransfer(),new PhorgeOrganizationHandover(),
      new PhorgeOrganizationProjectDisposition(),new PhorgeOrganizationOutbox())as$record){
      $seen=false;try{$record->save();}catch(RuntimeException$error){$seen=$error->getMessage()==='organization_transfer_locked_writer_required';}
      $this->assertTrue($seen);
    }
  }
}
