<?php

final class PhorgeOrganizationCompoundTestCase extends PhutilTestCase {
  private function example() {
    return array('action'=>'member.add-and-appoint','approval_id'=>'compound-approval',
      'operation_id'=>'compound-operation','team'=>'compound-team','target_user'=>'PHID-USER-aaaaaaaaaaaaaaaaaaaa',
      'expected_team_version'=>1,'expected_member_version'=>0,'expected_appointment_version'=>0,
      'impact_acknowledged'=>true,'member_from'=>0,'member_until'=>200,
      'appointment_from'=>10,'appointment_until'=>100);
  }
  public function testExplicitCompoundValueContractAndDetachedInput() {
    $value=1;$input=$this->example();$input['expected_team_version']=&$value;
    $copy=PhorgeOrganizationRequest::compound($input);$value=99;
    $this->assertEqual(1,$copy['expected_team_version']);
    $input=$this->example();$input['ttl_seconds']=120;
    $this->assertEqual(120,PhorgeOrganizationRequest::compound($input,true)['ttl_seconds']);
  }
  public function testAcknowledgementAndPeriodContainmentRequired() {
    foreach(array(array('impact_acknowledged',false),array('impact_acknowledged',1),
      array('member_from',11),array('member_until',90),array('appointment_until',null),
      array('expected_team_version',0),array('expected_member_version','0'))as$change){
      $input=$this->example();$input[$change[0]]=$change[1];$refused=false;
      try{PhorgeOrganizationRequest::compound($input);}catch(InvalidArgumentException$error){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testRevocationCompoundHasNoCallerReplacementPeriod() {
    $input=$this->example();$input['action']='appointment.revoke-and-end';
    foreach(array('member_from','member_until','appointment_from','appointment_until')as$key){$input[$key]=null;}
    $expected=$input;ksort($expected);
    $this->assertEqual($expected,PhorgeOrganizationRequest::compound($input));
    $unknown=$input;$unknown['actor']='PHID-USER-bbbbbbbbbbbbbbbbbbbb';$unknown_refused=false;
    try{PhorgeOrganizationRequest::compound($unknown);}catch(InvalidArgumentException$error){$unknown_refused=true;}
    $this->assertTrue($unknown_refused);
    $input['member_from']=0;$refused=false;
    try{PhorgeOrganizationRequest::compound($input);}catch(InvalidArgumentException$error){$refused=true;}
    $this->assertTrue($refused);
  }
  public function testDerivedApprovalParticipantsNeverReplaceBindingAuthority() {
    $subject='PHID-USER-aaaaaaaaaaaaaaaaaaaa';$actor='PHID-USER-bbbbbbbbbbbbbbbbbbbb';
    $data=array('parameters'=>array('subject'=>$subject),
      'requester'=>array('userPHID'=>$actor),'executor'=>array('userPHID'=>$actor));
    $row=PhorgeGovernanceNativeApprovalAdapter::participantMetadata($data);
    PhorgeGovernanceNativeApprovalAdapter::assertParticipantMetadata($row,$data,true);
    $this->assertEqual($subject,$row['sourceSubjectPHID']);
    $legacy=array('sourceParticipantVersion'=>0,'sourceSubjectPHID'=>null,
      'sourceRequesterPHID'=>null,'sourceExecutorPHID'=>null);
    PhorgeGovernanceNativeApprovalAdapter::assertParticipantMetadata($legacy,$data,false);
    foreach(array($legacy,array_replace($row,array('sourceParticipantVersion'=>true)),
      array_replace($row,array('sourceParticipantVersion'=>2)),
      array_replace($row,array('sourceSubjectPHID'=>$actor)),
      array_replace($row,array('sourceRequesterPHID'=>$subject)),
      array_replace($row,array('sourceExecutorPHID'=>null))) as $bad) {
      $refused=false;
      try { PhorgeGovernanceNativeApprovalAdapter::assertParticipantMetadata($bad,$data,true); }
      catch(RuntimeException $error) { $refused=true; }
      $this->assertTrue($refused);
    }
    $legacy['sourceSubjectPHID']=$subject;$refused=false;
    try { PhorgeGovernanceNativeApprovalAdapter::assertParticipantMetadata($legacy,$data,false); }
    catch(RuntimeException $error) { $refused=true; }
    $this->assertTrue($refused);
  }
}
