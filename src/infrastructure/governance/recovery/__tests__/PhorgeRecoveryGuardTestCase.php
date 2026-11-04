<?php

final class PhorgeRecoveryGuardTestCase extends PhutilTestCase {
  private function state() {
    return array('userPHID'=>'PHID-USER-'.str_repeat('a',20),
      'isApproved'=>true,'isDisabled'=>true,'isSystemAgent'=>false,'isMailingList'=>false);
  }

  public function testEmergencyDisableDoesNotReadRecoveryConfiguration() {
    // The predicate must be usable before feature/config/schema access. No
    // session or SQL source is needed to recognize a final disabled account.
    foreach(array(true,1,'1')as$value){
      $this->assertTrue(PhorgeGovernanceRecoveryGuard::isEmergencyDisabled(
        (new PhabricatorUser())->setIsDisabled($value)));
    }
    foreach(array(false,0,'0',null)as$value){
      $this->assertFalse(PhorgeGovernanceRecoveryGuard::isEmergencyDisabled(
        (new PhabricatorUser())->setIsDisabled($value)));
    }
    // Native User::readField normalizes this public getter to boolean. This
    // predicate is not a validator for raw SQL flag shapes or stored values.
    $this->assertTrue(PhorgeGovernanceRecoveryGuard::isEmergencyDisabled(
      (new PhabricatorUser())->setIsDisabled('true')));
    $translations=(new PhabricatorChineseTranslation())->getTranslationMap();
    foreach(array('Account Recovery Guard','Experimental native account restoration boundary.',
      'Refuse account restoration without complete source assessment.',
      'External recovery review incarnation.')as$key){
      $this->assertTrue(isset($translations[$key])&&is_string($translations[$key])&&$translations[$key]!==$key);
    }
  }

  public function testRestorationAndFirstApprovalRemainDistinctUnknownTransitions() {
    $before=$this->state();$after=$before;$after['isDisabled']=false;
    $result=PhorgeGovernanceRecoveryTransition::describe($before,$after);
    $this->assertEqual(array('native.enable'),$result['qualification_increase_candidates']);
    $this->assertFalse($result['can_execute']);
    $this->assertFalse($result['initial_approval_distinction_required']);
    $before['isDisabled']=false;$before['isApproved']=false;
    $after=$before;$after['isApproved']=true;
    $result=PhorgeGovernanceRecoveryTransition::describe($before,$after);
    $this->assertEqual(array('native.approval'),$result['qualification_increase_candidates']);
    $this->assertTrue($result['initial_approval_distinction_required']);
    $this->assertFalse($result['all_sources_reviewed']);
  }

  public function testIdentityKindExitIsNotSilentlyTreatedAsHumanEnable() {
    foreach(array('isSystemAgent','isMailingList')as$key){
      $before=$this->state();$before[$key]=true;$after=$before;$after[$key]=false;
      $result=PhorgeGovernanceRecoveryTransition::describe($before,$after);
      $this->assertTrue($result['identity_kind_changed']);
      $this->assertTrue($result['needs_source_assessment']);
      $this->assertFalse($result['can_execute']);
    }
    $before=$this->state();$after=$before;$after['userPHID']='PHID-USER-'.str_repeat('b',20);
    $refused=false;
    try{PhorgeGovernanceRecoveryTransition::describe($before,$after);}
    catch(InvalidArgumentException $error){$refused=$error->getMessage()==='recovery_subject_identity_changed';}
    $this->assertTrue($refused);
  }
}
