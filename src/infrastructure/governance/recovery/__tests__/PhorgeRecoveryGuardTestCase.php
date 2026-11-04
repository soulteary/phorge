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
    foreach(array(false,0,'0',null,'true','false','0.0','00',2,1.0)as$value){
      $this->assertFalse(PhorgeGovernanceRecoveryGuard::isEmergencyDisabled(
        (new PhabricatorUser())->setIsDisabled($value)));
    }
    $malformed=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20))
      ->setIsApproved(1)->setIsDisabled('0.0')->setIsSystemAgent(0)->setIsMailingList(0);
    $this->assertTrue($malformed->getIsDisabled());
    $refused=false;
    try{PhorgeGovernanceRecoveryTransition::describe(
      PhorgeGovernanceRecoveryGuard::state($malformed),PhorgeGovernanceRecoveryGuard::state($malformed));}
    catch(InvalidArgumentException$error){$refused=$error->getMessage()==='recovery_native_flag_refused';}
    $this->assertTrue($refused);
    // Real native configuration reads: only explicit canonical false is OFF.
    // A constructor-free native proxy has no source and throws in getKeys();
    // do not simulate that failure by returning a caller-supplied boolean.
    $stack_property=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');
    $cache_property=new ReflectionProperty(PhabricatorEnv::class,'cache');
    $old_stack=$stack_property->getValue();$old_cache=$cache_property->getValue();
    try {
      foreach(array(false,true)as$flag){
        $stack=(new PhabricatorConfigStackSource())->pushSource(
          new PhabricatorConfigDictionarySource(array('governance.account-recovery.enabled'=>$flag)));
        $stack_property->setValue(null,$stack);$cache_property->setValue(null,array());
        $this->assertEqual($flag,PhorgeGovernanceRecoveryGuard::enabled());
      }
      foreach(array(new PhabricatorConfigDictionarySource(array()),
        (new ReflectionClass(PhabricatorConfigLocalSource::class))->newInstanceWithoutConstructor())as$source){
        $stack_property->setValue(null,(new PhabricatorConfigStackSource())->pushSource($source));
        $cache_property->setValue(null,array());
        // Emergency disable recognition remains independent of broken config.
        $this->assertTrue(PhorgeGovernanceRecoveryGuard::isEmergencyDisabled(
          (new PhabricatorUser())->setIsDisabled('1')));
        $refused=false;
        try{PhorgeGovernanceRecoveryGuard::enabled();}
        catch(Exception$error){$refused=true;}
        $this->assertTrue($refused);
      }
      foreach(array(null,0,'0','false')as$flag){
        $stack_property->setValue(null,(new PhabricatorConfigStackSource())->pushSource(
          new PhabricatorConfigDictionarySource(array('governance.account-recovery.enabled'=>$flag))));
        $cache_property->setValue(null,array());$refused=false;
        try{PhorgeGovernanceRecoveryGuard::enabled();}
        catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_feature_flag_unknown';}
        $this->assertTrue($refused);
      }
    }finally{$stack_property->setValue(null,$old_stack);$cache_property->setValue(null,$old_cache);}
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
