<?php

/** Pure event shape only: no caller data constitutes authorization. */
final class PhorgeGovernanceNoticeEventTestCase extends PhutilTestCase {
  public function testNoticeSwitchIsActuallyRegisteredFalseAndLocked(){
    $key='governance.notifications.enabled';$options=PhabricatorApplicationConfigOptions::loadAllOptions();
    $this->assertTrue(isset($options[$key]));$option=$options[$key];
    $this->assertEqual('bool',$option->getType());$this->assertTrue($option->getDefault()===false);
    $this->assertTrue($option->getLocked()===true);
    $this->assertEqual(PhorgeGovernanceNoticeConfigOptions::class,get_class($option->getGroup()));
    $defaults=(new PhabricatorConfigDefaultSource())->getKeys(array($key));
    $this->assertTrue(array_key_exists($key,$defaults)&&$defaults[$key]===false);
  }
  private function row(){return array('source_kind'=>'organization','source_id'=>'1','operation_id'=>'synthetic-op',
    'actor_phid'=>'PHID-USER-'.str_repeat('a',20),'action'=>'unit.create','created_at'=>1);}
  public function testStableEventAndBoundedMetadataOnlyQueuePayload(){
    $row=$this->row();$a=PhorgeGovernanceNoticeEventValue::fromAudit($row);
    $this->assertEqual($a,PhorgeGovernanceNoticeEventValue::fromAudit(array_reverse($row,true)));
    $json=json_encode($a['metadata']);$this->assertTrue(!str_contains($json,$row['operation_id'])&&!str_contains($json,$row['actor_phid']));
    $queue=PhorgeGovernanceNoticeEventValue::queuePayload($a);$payload=json_decode($queue['task']['data'],true);
    $this->assertEqual(array('version'=>1,'event_id'=>$a['event_id']),$payload);
    $this->assertEqual('PhorgeGovernanceNoticeWorker',$queue['task']['taskClass']);
    $row['source_id']='2';$this->assertTrue($a['event_id']!==PhorgeGovernanceNoticeEventValue::fromAudit($row)['event_id']);
  }
  public function testUnknownFieldsSourcesAndNumericAliasesRefuse(){
    foreach(array(array('body'=>'private'),array('source_kind'=>'Organization'),array('source_id'=>'01'),
      array('source_id'=>1),array('source_id'=>'18446744073709551616'),array('action'=>'restore.complete'),
      array('created_at'=>true),array('email'=>'arbitrary@example.invalid'))as$delta){
      $failed=false;try{PhorgeGovernanceNoticeEventValue::fromAudit(array_replace($this->row(),$delta));}
      catch(InvalidArgumentException$error){$failed=true;}$this->assertTrue($failed);
    }
    $event=PhorgeGovernanceNoticeEventValue::fromAudit($this->row());$event['metadata']['audience']='anonymous';
    $failed=false;try{PhorgeGovernanceNoticeEventValue::queuePayload($event);}catch(InvalidArgumentException$error){$failed=true;}
    $this->assertTrue($failed);
  }
  public function testQueuedTaskCannotImportSourceAuthorityOrCoerceVersion(){
    $binding=array('event_id'=>str_repeat('a',64),'recipient'=>'PHID-USER-'.str_repeat('a',20),
      'email'=>PhabricatorPHID::generateNewPHID(PhabricatorPeopleUserEmailPHIDType::TYPECONST),
      'account_hash'=>str_repeat('b',64),'address_hash'=>str_repeat('c',64),'source_kind'=>'organization','target'=>null);
    PhorgeGovernanceNoticeMailPolicy::validateBinding($binding);
    $bad=$binding;$bad['email']='PHID-UEMA-'.str_repeat('a',20);$failed=false;
    try{PhorgeGovernanceNoticeMailPolicy::validateBinding($bad);}catch(InvalidArgumentException $error){$failed=true;}
    $this->assertTrue($failed);
    $data=array('version'=>1,'event_id'=>str_repeat('a',64));
    $this->assertEqual($data,PhorgeGovernanceNoticeEventValue::validateTask($data));
    foreach(array(array('version'=>true),array('version'=>'1'),array('event_id'=>str_repeat('A',64)),
      array('allowed'=>true),array('body'=>'private'),array('recipient'=>'untrusted'))as$delta){
      $failed=false;try{PhorgeGovernanceNoticeEventValue::validateTask(array_replace($data,$delta));}
      catch(InvalidArgumentException $error){$failed=true;}$this->assertTrue($failed);
    }
  }
}
