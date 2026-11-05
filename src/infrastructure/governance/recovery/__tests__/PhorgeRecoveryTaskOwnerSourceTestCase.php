<?php
/** Unregistered native semantic candidate, not full PolicyFilter/account-gate proof. */
final class PhorgeRecoveryTaskOwnerSourceTestCase extends PhutilTestCase {
  public function testActualTaskOwnerNotAuthorAndCapabilityArgumentBehavior(){
    $owner=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('u',20));$author=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));
    $task=(new ManiphestTask())->setOwnerPHID($owner->getPHID())->setAuthorPHID($author->getPHID());
    foreach($task->getCapabilities()as$cap){$this->assertTrue($task->hasAutomaticCapability($cap,$owner));$this->assertFalse($task->hasAutomaticCapability($cap,$author));}
    $this->assertTrue($task->hasAutomaticCapability('synthetic-unsupported-capability',$owner));$this->assertFalse(in_array('synthetic-unsupported-capability',$task->getCapabilities(),true));
    $owner->setIsDisabled(1);$this->assertTrue($task->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$owner));
    $task->setOwnerPHID(null);$this->assertFalse($task->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$owner));
  }
  public function testActualCommentEditLockPolicyIsSeparateFromOwnerFunction(){
    $stackProperty=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$cacheProperty=new ReflectionProperty(PhabricatorEnv::class,'cache');$old=$stackProperty->getValue();$oldCache=$cacheProperty->getValue();
    try{
      $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDictionarySource(array('maniphest.statuses'=>array('open'=>array('locked'=>false),'comments'=>array('locked'=>'comments'),'edits'=>array('locked'=>'edits')))));$stackProperty->setValue(null,$stack);$cacheProperty->setValue(null,array());
      $owner=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('u',20));$task=(new ManiphestTask())->setOwnerPHID($owner->getPHID())->setEditPolicy('users');
      foreach(array('open'=>array('users','users'),'comments'=>array('users','no-one'),'edits'=>array('no-one','no-one'))as$status=>$expect){$task->setStatus($status);$this->assertEqual($expect[0],$task->getPolicy(PhabricatorPolicyCapability::CAN_EDIT));$this->assertEqual($expect[1],$task->getPolicy(PhabricatorPolicyCapability::CAN_INTERACT));$this->assertTrue($task->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_EDIT,$owner));}
    }finally{$stackProperty->setValue(null,$old);$cacheProperty->setValue(null,$oldCache);}
  }
  public function testNativeTaskIdentityAndOwnerIndexSchemaContract(){
    $config=(new ReflectionMethod(ManiphestTask::class,'getConfiguration'))->invoke(new ManiphestTask());$keys=$config[LiskDAO::CONFIG_KEY_SCHEMA];$this->assertEqual(null,$keys['key_phid']);$this->assertEqual(array('columns'=>array('phid'),'unique'=>true),$keys['phid']);$this->assertEqual(array('columns'=>array('ownerPHID','status')),$keys['ownerPHID']);
    $method=new ReflectionMethod(PhorgeRecoveryTaskOwnerSource::class,'phid');$this->assertEqual('PHID-TASK-'.str_repeat('t',20),$method->invoke(null,'PHID-TASK-'.str_repeat('t',20),'TASK'));
    $refused=false;try{$method->invoke(null,'PHID-TASK-'.str_repeat('t',15),'TASK');}catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_task_owner_identity_unknown';}$this->assertTrue($refused);
  }
}
